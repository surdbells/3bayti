<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\RegisterInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/register
 *
 * Creates a new user account in unverified state and sends a
 * registration OTP. Returns the verification_id for the client
 * to present to /v3/auth/confirm along with the user's typed code.
 *
 * Response
 * --------
 *   201 Created
 *   {
 *     "verification_id": "mc-abc123",
 *     "user": { "email": "...", "phone": "...", "is_phone_verified": false }
 *   }
 *
 * The user payload is INTENTIONALLY MINIMAL — it does NOT include
 * the user id, role flags, or other fields. The full payload comes
 * back from /v3/auth/confirm once the phone is verified, alongside
 * the token pair. Until then the user is in a half-state:
 *
 *   - User row exists in DB
 *   - is_phone_verified = false
 *   - cannot log in via /login (would get phone-not-verified error
 *     once we add that check; for now login works but the response
 *     surfaces is_phone_verified so the frontend can route them
 *     to /confirm instead of home)
 *
 * Failure modes
 * -------------
 *   - 409 CONFLICT_EMAIL_TAKEN — email already registered
 *   - 409 CONFLICT_PHONE_TAKEN — phone already registered
 *   - 422 VALIDATION_FAILED   — body shape errors
 *   - 429 OTP_RATE_LIMITED    — phone hit hourly cap
 *   - 502 OTP_PROVIDER_ERROR  — MessageCentral down/refusing
 *
 * Race condition handling
 * -----------------------
 * Between the availability check and the INSERT, two concurrent
 * registrations could create the same email/phone. The Postgres
 * UNIQUE constraints catch this; we translate Doctrine's
 * UniqueConstraintViolationException into a 409 with the right
 * error code based on which constraint fired.
 *
 * Transactional ordering
 * ----------------------
 * 1. INSERT User row (catches email/phone conflict via UNIQUE).
 * 2. Call OtpService::send (which talks to CPaaS first, then INSERTs
 *    its own audit row — service-internal transaction).
 *
 * Why NOT wrap (1) and (2) in one transaction:
 *   - OtpService::send opens its own transaction internally; nesting
 *     leads to surprising savepoint behavior.
 *   - If OTP send fails, the user row is intentionally LEFT IN PLACE.
 *     The user can call /send-otp to retry without re-registering.
 *     This is also what the legacy backend does.
 *
 * Tradeoff: a successful register that loses the OTP send leaves an
 * orphaned User row in is_phone_verified=false. A nightly cleanup
 * job (M5) deletes such rows older than 30 days. For now they just
 * accumulate slowly — fine until volume picks up.
 */
final class RegisterController
{
    use Responder;
    use RequestContext;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly OtpService $otp,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->validator->parse($request, RegisterInput::class);

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);

        // Pre-flight conflict check — friendlier error if we already
        // know the email/phone is taken (saves a trip to OTP).
        // The DB UNIQUE constraint is the actual race-safe guard.
        if (!$users->isEmailAvailable($input->email)) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_EMAIL_TAKEN,
                'That email address is already registered.',
            );
        }
        if (!$users->isPhoneAvailable($input->phone)) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_PHONE_TAKEN,
                'That phone number is already registered.',
            );
        }

        // Build the user record. Phone unverified; account active by
        // default (so login flow doesn't see it as disabled, just as
        // not-yet-phone-verified). Customer role only — vendor flag
        // is set later via vendor onboarding (M1.7+).
        $user = new User(
            email: $input->email,
            phone: $input->phone,
            passwordHash: password_hash($input->password, PASSWORD_BCRYPT),
            countryCode: $input->country_code,
        );
        $user->setName($input->first_name, $input->last_name);

        try {
            $users->save($user);
        } catch (UniqueConstraintViolationException $e) {
            // Race winner already inserted. Tell the user the right
            // thing — but we can't easily tell email vs phone from the
            // exception class alone. Inspect the message.
            throw $this->classifyUniqueViolation($e);
        }

        // Fire the OTP. If this fails, the User row stays in place
        // (deliberate — see class docblock).
        try {
            $verificationId = $this->otp->send(
                to: $input->phone,
                purpose: OtpAttempt::PURPOSE_REGISTRATION,
                channel: OtpAttempt::CHANNEL_SMS,
                user: $user,
                requestedIp: $this->extractIp($request),
            );
        } catch (OtpProviderException $e) {
            throw HttpException::upstreamFailure(
                ErrorCodes::OTP_PROVIDER_ERROR,
                'Could not send verification code. Please try again in a moment.',
            );
        }

        return $this->created([
            'verification_id' => $verificationId,
            'user' => [
                'email' => $user->getEmail(),
                'phone' => $user->getPhone(),
                'country_code' => $user->getCountryCode(),
                'is_phone_verified' => false,
            ],
        ]);
    }

    /**
     * Decide whether a Postgres UNIQUE violation was on email or
     * phone, returning the right HttpException. If we can't tell
     * (extremely unlikely — would mean an unknown index name), fall
     * back to a generic 409.
     */
    private function classifyUniqueViolation(UniqueConstraintViolationException $e): HttpException
    {
        // Postgres error message includes the constraint name when
        // the violation is a UNIQUE/exclusion constraint. The default
        // names in our schema are like 'users_email_key' and
        // 'users_phone_key' (Postgres auto-names UNIQUE columns).
        $msg = strtolower($e->getMessage());
        if (str_contains($msg, 'email')) {
            return HttpException::conflict(
                ErrorCodes::CONFLICT_EMAIL_TAKEN,
                'That email address is already registered.',
            );
        }
        if (str_contains($msg, 'phone')) {
            return HttpException::conflict(
                ErrorCodes::CONFLICT_PHONE_TAKEN,
                'That phone number is already registered.',
            );
        }
        return new HttpException(
            status: 409,
            errorCode: ErrorCodes::CONFLICT_EMAIL_TAKEN, // best guess
            publicMessage: 'A user with these details already exists.',
        );
    }
}
