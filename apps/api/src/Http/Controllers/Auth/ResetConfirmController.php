<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\VerifyResult;
use Bayti\Api\Http\Controllers\Auth\Dto\ResetConfirmInput;
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
 * POST /v3/auth/reset/confirm
 *
 * Completes password reset:
 *   1. Verify OTP against MessageCentral
 *   2. Set new password (which updates password_changed_at →
 *      invalidates ALL old access tokens via AuthMiddleware
 *      pwd_changed_at staleness check)
 *   3. Revoke ALL refresh tokens for the user (logout-everywhere
 *      per Decision A.6.2)
 *   4. Issue a fresh access + refresh token pair
 *   5. Persist the new refresh-token row
 *
 * Steps 2-5 happen in a single Doctrine transaction. The OTP itself
 * is consumed (step 1) in OtpService::verify's own flush; consuming
 * an OTP without changing the password is harmless — the user just
 * retries with a fresh OTP.
 *
 * Response (200) — same shape as /v3/auth/login + /v3/auth/confirm.
 *
 * Why issue tokens on success
 * ---------------------------
 * The user has just proven possession of their phone (via OTP) and
 * shown intent to authenticate (by setting a new password). Treating
 * reset-confirm as a fresh login lets them proceed without an extra
 * /login round-trip.
 *
 * Cross-purpose guard
 * -------------------
 * The OtpAttempt's purpose MUST be 'password_reset'. A 'registration'
 * OTP submitted here is rejected with the same uniform 401, defending
 * against cross-flow abuse.
 */
final class ResetConfirmController
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
        $input = $this->validator->parse($request, ResetConfirmInput::class);

        // Look up the OtpAttempt FIRST so we can reject without
        // hitting MessageCentral when:
        //   - unknown verification_id
        //   - row's purpose isn't password_reset (cross-flow abuse)
        //   - row has no bound user (defensive)
        $attempt = $this->otp->findAttempt($input->verification_id);
        if ($attempt === null) {
            throw $this->verificationFailed();
        }
        if ($attempt->getPurpose() !== OtpAttempt::PURPOSE_PASSWORD_RESET) {
            throw $this->verificationFailed();
        }

        $user = $attempt->getUser();
        if ($user === null) {
            throw $this->verificationFailed();
        }

        // Verify the code via OtpService → MessageCentral.
        try {
            $result = $this->otp->verify($input->verification_id, $input->code);
        } catch (OtpProviderException) {
            throw HttpException::upstreamFailure(
                ErrorCodes::OTP_PROVIDER_ERROR,
                'Could not verify code right now. Please try again in a moment.',
            );
        }

        if ($result !== VerifyResult::Success) {
            throw $this->verificationFailed();
        }

        // OTP verified. Set new password + logout-everywhere + issue
        // fresh tokens — all in one transaction.
        $pair = $this->jwt->issueTokenPair($user);
        $newPasswordHash = password_hash($input->new_password, PASSWORD_BCRYPT);
        $clientIp = $this->extractIp($request);
        $userAgent = $this->extractUserAgent($request);

        $this->em->wrapInTransaction(function () use ($user, $pair, $newPasswordHash, $clientIp, $userAgent): void {
            // setPasswordHash() also bumps user.password_changed_at,
            // which makes ALL old access tokens stale via the
            // AuthMiddleware pwd_changed_at check.
            $user->setPasswordHash($newPasswordHash);

            // Revoke all refresh tokens — including the requesting
            // client's. We then issue a fresh pair, so the user stays
            // logged in on THIS device. All others are kicked out
            // (Decision A.6.2).
            /** @var RefreshTokenRepository $refreshRepo */
            $refreshRepo = $this->em->getRepository(RefreshToken::class);
            $refreshRepo->revokeAllForUser($user, 'password_changed');

            // Persist the new refresh-token row.
            $refreshRow = new RefreshToken(
                user: $user,
                jti: $pair->refreshTokenJti,
                tokenHash: $pair->refreshTokenHash(),
                expiresAt: $pair->refreshTokenExpiresAt,
                issuedIp: $clientIp,
                userAgent: $userAgent,
            );
            $refreshRepo->save($refreshRow, flush: false);

            $user->recordLogin($clientIp);

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
