<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\VerifyResult;
use Bayti\Api\Http\Controllers\Auth\Dto\ConfirmInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\RequestContext;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Otp\OtpProviderException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/confirm
 *
 * Verifies a registration OTP. On success:
 *   - User.is_phone_verified = true
 *   - Issue access + refresh token pair
 *   - Persist refresh-token row
 *   - Return same response shape as /v3/auth/login
 *
 * Failure response
 * ----------------
 * All verification failures (wrong code, expired, consumed, unknown
 * vid, OTP attached to a user that no longer exists) collapse to a
 * single 401 with OTP_VERIFICATION_FAILED. Distinguishing these
 * publicly leaks information about the OTP's state.
 *
 * Why we don't surface "OTP expired, please request a new one"
 * ------------------------------------------------------------
 * That's a UX concern, the FRONTEND can detect expiry by tracking
 * its own timer (the verification_id is returned with no expiry,
 * but the frontend knows the policy is 5 minutes). When the user
 * waits past 5 minutes and tries to confirm, the frontend should
 * proactively show "your code expired, request a new one" and
 * offer the resend button. The backend just says "verification
 * failed".
 *
 * Use case for /v3/auth/confirm
 * -----------------------------
 * This endpoint is REGISTRATION-specific. It marks is_phone_verified
 * to true. Password-reset OTPs go through /v3/auth/reset/confirm
 * (M1.4.4) which has different post-verification logic (set new
 * password, invalidate all refresh tokens). Phone-change OTPs are
 * post-M1.
 *
 * The OtpAttempt's `purpose` field guards against cross-flow abuse
 *, confirm() pulls the row by verification_id and verifies the
 * purpose is PURPOSE_REGISTRATION before proceeding.
 */
final class ConfirmController
{
    use Responder;
    use RequestContext;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
        private readonly OtpService $otp,
        private readonly JwtService $jwt,
        private readonly UserSerializer $userSerializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $input = $this->validator->parse($request, ConfirmInput::class);

        // Look up the OtpAttempt FIRST so we can confirm:
        //   - It exists
        //   - It's for the registration purpose
        //   - It's bound to a user (registration OTPs always are after register())
        // We do this before delegating to OtpService::verify so we avoid
        // calling MessageCentral on attempts we know we'll reject.
        $attempt = $this->otp->findAttempt($input->verification_id);
        if ($attempt === null) {
            throw $this->verificationFailed();
        }

        if ($attempt->getPurpose() !== \Bayti\Api\Domain\User\OtpAttempt::PURPOSE_REGISTRATION) {
            // Cross-flow abuse guard: a password-reset OTP being
            // submitted to /confirm. Same generic 401.
            throw $this->verificationFailed();
        }

        $user = $attempt->getUser();
        if ($user === null) {
            // Defensive: registration OTPs are bound to a user at
            // /register time. If we see one without a user attached,
            // something's wrong with the audit trail. Reject.
            throw $this->verificationFailed();
        }

        // Now delegate the actual code check to OtpService → MessageCentral.
        try {
            $result = $this->otp->verify($input->verification_id, $input->code);
        } catch (OtpProviderException $e) {
            throw HttpException::upstreamFailure(
                ErrorCodes::OTP_PROVIDER_ERROR,
                'Could not verify code right now. Please try again in a moment.',
                $e,
            );
        }

        if ($result !== VerifyResult::Success) {
            throw $this->verificationFailed();
        }

        // OTP confirmed. Mark user phone-verified, issue tokens, persist
        // refresh-token row. All in one transaction.
        $pair = $this->jwt->issueTokenPair($user);

        $this->em->wrapInTransaction(function () use ($user, $pair, $request): void {
            $user->markPhoneVerified();
            $user->recordLogin($this->extractIp($request));

            /** @var RefreshTokenRepository $refreshRepo */
            $refreshRepo = $this->em->getRepository(RefreshToken::class);
            $refreshRow = new RefreshToken(
                user: $user,
                jti: $pair->refreshTokenJti,
                tokenHash: $pair->refreshTokenHash(),
                expiresAt: $pair->refreshTokenExpiresAt,
                issuedIp: $this->extractIp($request),
                userAgent: $this->extractUserAgent($request),
            );
            $refreshRepo->save($refreshRow, flush: false);

            $this->em->flush();
        });

        return $this->ok([
            'access_token' => $pair->accessToken,
            'access_token_expires_at' => $pair->accessTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'refresh_token' => $pair->refreshToken,
            'refresh_token_expires_at' => $pair->refreshTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'user' => $this->userSerializer->publicProfile($user),
        ]);
    }

    private function verificationFailed(): HttpException
    {
        return HttpException::unauthorized(
            ErrorCodes::OTP_VERIFICATION_FAILED,
            'Verification failed. Please check the code and try again.',
        );
    }
}
