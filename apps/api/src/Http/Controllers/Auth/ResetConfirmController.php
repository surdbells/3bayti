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
use Bayti\Api\Infrastructure\Auth\TokenPair;
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
 *   2. Set new password (which updates password_changed_at; access
 *      tokens minted before it fail the AuthMiddleware staleness check
 *      once and their clients recover via /v3/auth/refresh)
 *   3. Issue a fresh access + refresh token pair AFTER step 2, so the
 *      access token carries the new pwd_changed_at and is valid on
 *      first use
 *   4. Persist the new refresh-token row: in the caller's session
 *      family when the request carries a valid access token of this same
 *      user (a signed-in device resetting its password), otherwise a new
 *      family (see callerFamilyId)
 *
 * No session is revoked. Product rule: a customer stays signed in on
 * every device until they explicitly log out, so resetting a forgotten
 * password on one device must not sign any other device out. Ending
 * other sessions is the separate, explicit /v3/auth/logout-all.
 *
 * Steps 2-4 happen in a single Doctrine transaction. The OTP itself
 * is consumed (step 1) in OtpService::verify's own flush; consuming
 * an OTP without changing the password is harmless, the user just
 * retries with a fresh OTP.
 *
 * Response (200), same shape as /v3/auth/login + /v3/auth/confirm.
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

        // OTP verified. Set new password + issue fresh tokens, in one
        // transaction. The bcrypt hash is computed outside it (slow).
        $newPasswordHash = password_hash($input->new_password, PASSWORD_BCRYPT);
        $clientIp = $this->extractIp($request);
        $userAgent = $this->extractUserAgent($request);
        $familyId = $this->callerFamilyId($request, $user);

        /** @var TokenPair $pair */
        $pair = $this->em->wrapInTransaction(function () use ($user, $newPasswordHash, $clientIp, $userAgent, $familyId): TokenPair {
            // setPasswordHash() also bumps user.password_changed_at.
            // Older access tokens fail the AuthMiddleware staleness check
            // once and their clients recover via /refresh. No refresh
            // token is revoked: every other device stays signed in.
            $user->setPasswordHash($newPasswordHash);

            // Issue the pair AFTER the hash change so the access token
            // carries the NEW pwd_changed_at and passes AuthMiddleware on
            // first use. The claim is whole seconds, and so is the stored
            // timestamp.
            $pair = $this->jwt->issueTokenPair($user, familyId: $familyId);

            /** @var RefreshTokenRepository $refreshRepo */
            $refreshRepo = $this->em->getRepository(RefreshToken::class);

            // Persist the new refresh-token row.
            $refreshRow = new RefreshToken(
                user: $user,
                jti: $pair->refreshTokenJti,
                tokenHash: $pair->refreshTokenHash(),
                expiresAt: $pair->refreshTokenExpiresAt,
                issuedIp: $clientIp,
                userAgent: $userAgent,
                familyId: $pair->familyId,
            );
            $refreshRepo->save($refreshRow, flush: false);

            $user->recordLogin($clientIp);

            $this->em->flush();

            return $pair;
        });

        return $this->ok([
            'access_token' => $pair->accessToken,
            'access_token_expires_at' => $pair->accessTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'refresh_token' => $pair->refreshToken,
            'refresh_token_expires_at' => $pair->refreshTokenExpiresAt->format(\DateTimeInterface::ATOM),
            'user' => $this->userSerializer->publicProfile($user),
        ]);
    }

    /**
     * The session family of a caller that is still signed in as $user:
     * the `sid` claim of a valid access token in the Authorization header.
     * The new pair then joins it, so the device keeps ONE family and its
     * later logout also ends the tokens it held before the reset (see
     * ChangePasswordController). Null (a new family) when there is no
     * bearer, it is invalid or expired, it belongs to someone else, or it
     * predates the sid claim.
     *
     * Read directly rather than through OptionalAuthMiddleware: this route
     * is anonymous and only needs the signed claim, not the user lookup.
     */
    private function callerFamilyId(ServerRequestInterface $request, User $user): ?string
    {
        if (!preg_match('/^\s*Bearer\s+(.+?)\s*$/i', $request->getHeaderLine('Authorization'), $m)) {
            return null;
        }
        $claims = $this->jwt->verifyAccessToken($m[1]);
        if ($claims === null || $claims->userId !== $user->getId()) {
            return null;
        }
        return $claims->sessionFamilyId;
    }

    private function verificationFailed(): HttpException
    {
        return HttpException::unauthorized(
            ErrorCodes::OTP_VERIFICATION_FAILED,
            'Verification failed. Please check the code and try again.',
        );
    }
}
