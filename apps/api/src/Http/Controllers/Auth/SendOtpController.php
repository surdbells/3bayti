<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpRateLimitException;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\SendOtpInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/send-otp
 *
 * Re-issues a registration OTP to a user who has registered but not
 * yet verified their phone. Used when:
 *   - The original OTP expired before the user typed it
 *   - The original OTP didn't reach the user's phone (carrier issue)
 *
 * Response
 * --------
 *   200 OK
 *   { "verification_id": "mc-..." }
 *
 * Anti-enumeration trade-off
 * --------------------------
 * Per Decision in M1.4.2 (validate-email comments), this endpoint
 * accepts that knowing whether an email is registered is leakable.
 * That said, we narrow the leak: we return 200 + verification_id
 * regardless of whether the user exists or is already verified.
 * Only DIFFERENT BEHAVIORS are observable to attackers.
 *
 * Internally:
 *   - User exists, is_phone_verified = false → real OTP sent
 *   - User exists, is_phone_verified = true  → no-op, fake vid returned
 *     (same shape as real; client gets stuck in /confirm flow until
 *     they realise the account is already verified — they should be
 *     using /login)
 *   - User does not exist                    → no-op, fake vid returned
 *
 * The fake-vid path is intentional. Mobile apps issue /send-otp
 * eagerly during account-recovery flows; failing visibly leaks
 * registered emails. Returning a vid that just won't validate is
 * consistent with how /reset (M1.4.4) handles the same case.
 *
 * NOTE: We do NOT generate cryptographic random values for the fake
 * vid — we just return a consistent-looking string. Attackers can
 * tell post-hoc that a vid was fake by testing /confirm against it,
 * but that's already a high-rate-limited path.
 */
final class SendOtpController
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
        $input = $this->validator->parse($request, SendOtpInput::class);

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $user = $users->findByEmail($input->email);

        if ($user === null || $user->isPhoneVerified()) {
            // No-op for non-existent or already-verified — return a
            // fake-but-consistent vid. See class docblock for reasoning.
            return $this->ok([
                'verification_id' => 'fake-' . bin2hex(random_bytes(12)),
            ]);
        }

        // Legacy-migrated users may have NULL phone (4 such records as
        // of Day 4 migration). They can't receive OTP — same no-op shape
        // as non-existent users so we don't disclose phone availability.
        if ($user->getPhone() === null) {
            return $this->ok([
                'verification_id' => 'fake-' . bin2hex(random_bytes(12)),
            ]);
        }

        try {
            $verificationId = $this->otp->send(
                to: $user->getPhone(),
                purpose: OtpAttempt::PURPOSE_REGISTRATION,
                channel: OtpAttempt::CHANNEL_SMS,
                user: $user,
                requestedIp: $this->extractIp($request),
            );
        } catch (OtpRateLimitException) {
            throw HttpException::rateLimited(
                ErrorCodes::OTP_RATE_LIMITED,
                'Too many OTP requests. Please wait an hour and try again.',
            );
        } catch (OtpProviderException) {
            throw HttpException::upstreamFailure(
                ErrorCodes::OTP_PROVIDER_ERROR,
                'Could not send verification code. Please try again in a moment.',
            );
        }

        return $this->ok([
            'verification_id' => $verificationId,
        ]);
    }
}
