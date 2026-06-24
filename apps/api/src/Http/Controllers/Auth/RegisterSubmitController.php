<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\RegisterSubmitInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/register/submit
 *
 * Phone-first registration step 3 of 4. With a verified phone (proven
 * by the registration_token), creates the account and sends an
 * email-OTP to verify the email.
 *
 *   Request:  { registration_token, email, password, first_name?, last_name? }
 *   Response: 200 { verification_id }   (email-OTP verification id)
 *
 * The phone + country_code come from the TOKEN, not the request body —
 * a client cannot register an account for a phone it didn't verify.
 *
 * The created User is:
 *   - is_phone_verified = TRUE  (proven in step 2)
 *   - is_email_verified = FALSE (proven in step 4)
 *   - password hashed (bcrypt)
 *   - country_code from the token
 *
 * Failure modes
 * -------------
 *   - 401 AUTH_INVALID_TOKEN   — registration token missing/expired/bad
 *   - 409 CONFLICT_EMAIL_TAKEN — email already registered
 *   - 409 CONFLICT_PHONE_TAKEN — phone taken since step 1 (race)
 *   - 422 VALIDATION_FAILED    — body shape errors
 *   - 429 OTP_RATE_LIMITED     — email hit hourly cap
 *   - 502 OTP_PROVIDER_ERROR   — mail transport failed
 *
 * Like /register, the User row is intentionally LEFT IN PLACE if the
 * email-OTP send fails — the user can resend without re-registering
 * (and a nightly cleanup removes never-verified rows).
 */
final class RegisterSubmitController
{
    use Responder;
    use RequestContext;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly OtpService $otp,
        private readonly JwtService $jwt,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->validator->parse($request, RegisterSubmitInput::class);

        // Verify the registration token and extract the bound phone.
        // null on any failure → uniform 401 (no oracle leak about why).
        $claims = $this->jwt->verifyRegistrationToken($input->registration_token);
        if ($claims === null) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Your registration session has expired. Please start again.',
            );
        }
        $phone = $claims['phone'];
        $countryCode = $claims['country_code'];

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);

        if (!$users->isEmailAvailable($input->email)) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_EMAIL_TAKEN,
                'That email address is already registered.',
            );
        }
        // Defensive re-check: the phone could have been taken between
        // step 1 and now.
        if (!$users->isPhoneAvailable($phone)) {
            throw HttpException::conflict(
                ErrorCodes::CONFLICT_PHONE_TAKEN,
                'That phone number is already registered.',
            );
        }

        // Phone is already proven verified (step 2); email is not yet.
        $user = new User(
            email: $input->email,
            phone: $phone,
            passwordHash: password_hash($input->password, PASSWORD_BCRYPT),
            countryCode: $countryCode,
        );
        $user->setName($input->first_name, $input->last_name);
        $user->markPhoneVerified();

        try {
            $users->save($user);
        } catch (UniqueConstraintViolationException $e) {
            throw $this->classifyUniqueViolation($e);
        }

        // Send the email-OTP. If this fails the User row stays in place
        // (deliberate — the user can resend).
        try {
            $verificationId = $this->otp->send(
                to: $input->email,
                purpose: OtpAttempt::PURPOSE_REGISTRATION,
                channel: OtpAttempt::CHANNEL_EMAIL,
                user: $user,
                requestedIp: $this->extractIp($request),
            );
        } catch (OtpProviderException) {
            throw HttpException::upstreamFailure(
                ErrorCodes::OTP_PROVIDER_ERROR,
                'Could not send the verification email. Please try again in a moment.',
            );
        }

        return $this->ok([
            'verification_id' => $verificationId,
        ]);
    }

    private function classifyUniqueViolation(UniqueConstraintViolationException $e): HttpException
    {
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
            errorCode: ErrorCodes::CONFLICT_EMAIL_TAKEN,
            publicMessage: 'A user with these details already exists.',
        );
    }
}
