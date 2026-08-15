<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\VerifyResult;
use Bayti\Api\Http\Controllers\Auth\Dto\RegisterConfirmEmailInput;
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
 * POST /v3/auth/register/confirm-email
 *
 * Phone-first registration step 4 of 4 (final). Verifies the email-OTP
 * and, on success, marks the email verified and logs the user in.
 *
 *   Request:  { verification_id, code }
 *   Response: 200 {
 *     access_token, access_token_expires_at,
 *     refresh_token, refresh_token_expires_at,
 *     user
 *   }   — SAME shape as /v3/auth/confirm and /v3/auth/login.
 *
 * The OtpAttempt MUST be a registration-purpose, EMAIL-channel row
 * bound to a user (the account created at /register/submit). A
 * password-reset OTP or an SMS-channel OTP submitted here is rejected
 * with the same uniform 401 (cross-flow / cross-channel guard).
 *
 * On failure → 401 OTP_VERIFICATION_FAILED.
 *
 * Mirrors ConfirmController exactly except: it asserts the email
 * channel and marks is_email_verified (ConfirmController marks
 * is_phone_verified for the SMS confirm).
 */
final class RegisterConfirmEmailController
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
        $input = $this->validator->parse($request, RegisterConfirmEmailInput::class);

        $attempt = $this->otp->findAttempt($input->verification_id);
        if ($attempt === null) {
            throw $this->verificationFailed();
        }
        if ($attempt->getPurpose() !== OtpAttempt::PURPOSE_REGISTRATION) {
            throw $this->verificationFailed();
        }
        if (!$attempt->isEmailChannel()) {
            throw $this->verificationFailed();
        }

        $user = $attempt->getUser();
        if ($user === null) {
            // The email-OTP row is bound to the user at /register/submit
            // time; a missing binding means something's wrong. Reject.
            throw $this->verificationFailed();
        }

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

        // Email confirmed. Mark verified, issue tokens, persist the
        // refresh-token row + login audit — all in one transaction.
        $pair = $this->jwt->issueTokenPair($user);

        $this->em->wrapInTransaction(function () use ($user, $pair, $request): void {
            $user->markEmailVerified();
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
