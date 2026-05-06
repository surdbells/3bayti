<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpRateLimitException;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\ResetInput;
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
 * POST /v3/auth/reset
 *
 * Initiates password reset. Looks up the user by email, sends an
 * OTP to their REGISTERED phone (not user-supplied), returns a
 * verification_id for the next step.
 *
 * Response (always 200, regardless of whether email is registered)
 * ----------------------------------------------------------------
 *   { "verification_id": "..." }
 *
 * Anti-enumeration: identical response shape whether email exists
 * or not. The fake-vid path uses a 'fake-' prefix; an attacker
 * COULD detect this prefix post-hoc, but our anti-enumeration goal
 * is to make BULK probing expensive, not impossible. The /confirm
 * step is rate-limited at MessageCentral's side, so probing whether
 * a vid is fake one-at-a-time costs ~5 attempts before being
 * blocked anyway.
 *
 * Edge cases
 * ----------
 *   - User exists, is_active=false → fake vid (deactivated accounts
 *     can't reset; would be confusing to reveal that state)
 *   - User exists, is_phone_verified=false → fake vid (registration
 *     not completed; reset doesn't make sense)
 *
 * Why not 200 with no body
 * ------------------------
 * We need to return SOME verification_id to the frontend so it
 * can drive the /reset/confirm flow. An empty response would force
 * the frontend to ask for the vid in a separate step, awkward UX.
 * Returning a fake vid that just won't validate is the cleanest
 * way to keep one consistent response shape.
 */
final class ResetController
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
        $input = $this->validator->parse($request, ResetInput::class);

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $user = $users->findByEmail($input->email);

        // Bail-with-fake conditions:
        //   - No such user
        //   - User is deactivated (is_active=false)
        //   - User hasn't completed registration (is_phone_verified=false)
        // Any of these → return a consistent-looking fake vid.
        if ($user === null || !$user->isActive() || !$user->isPhoneVerified()) {
            return $this->ok([
                'verification_id' => 'fake-' . bin2hex(random_bytes(12)),
            ]);
        }

        try {
            $verificationId = $this->otp->send(
                phone: $user->getPhone(),
                purpose: OtpAttempt::PURPOSE_PASSWORD_RESET,
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
