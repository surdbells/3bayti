<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\OtpAttempt;
use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\VerifyResult;
use Bayti\Api\Http\Controllers\Auth\Dto\OtpLoginVerifyInput;
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
 * POST /v3/auth/otp-login/verify
 *
 * Passwordless-login step 2. Verifies the login OTP and, on success,
 * issues the SAME login envelope as /v3/auth/login:
 *   - access + refresh token pair
 *   - persisted refresh-token row
 *   - user public profile
 *
 * Request:  { verification_id, code }
 *
 * Failure response
 * ----------------
 * All verification failures (unknown vid, wrong/expired/consumed code,
 * wrong purpose, missing bound user, or a now-inactive user) collapse
 * to a single 401 OTP_VERIFICATION_FAILED. Distinguishing these
 * publicly would leak OTP state / account existence.
 *
 * Cross-flow guard
 * ----------------
 * The attempt MUST carry PURPOSE_LOGIN_2FA. A registration- or
 * reset-purpose OTP replayed here is rejected with the same uniform
 * 401 — mirroring ConfirmController's purpose check.
 *
 * Re-check active at verify time
 * ------------------------------
 * The user could have been disabled between send and verify. We
 * re-assert is_active before minting a session (same 401, no leak).
 */
final class OtpLoginVerifyController
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
        $input = $this->validator->parse($request, OtpLoginVerifyInput::class);

        // Look up the attempt FIRST so we can reject cross-flow /
        // unbound submissions without calling MessageCentral.
        $attempt = $this->otp->findAttempt($input->verification_id);
        if ($attempt === null) {
            throw $this->verificationFailed();
        }

        if ($attempt->getPurpose() !== OtpAttempt::PURPOSE_LOGIN_2FA) {
            // Cross-flow abuse guard: a registration / reset OTP being
            // replayed here. Same generic 401.
            throw $this->verificationFailed();
        }

        $user = $attempt->getUser();
        if ($user === null) {
            // Login OTPs are always bound to a user at /otp-login/send.
            // An unbound row means a broken audit trail — reject.
            throw $this->verificationFailed();
        }

        // Now delegate the actual code check (SMS → MessageCentral,
        // email → local hash compare).
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

        // Account could have been disabled between send and verify.
        // Same uniform 401 — don't leak the disabled state here.
        if (!$user->isActive()) {
            throw $this->verificationFailed();
        }

        // OTP confirmed. Issue tokens, persist refresh-token row, update
        // login audit. All in one transaction — identical to
        // LoginController / ConfirmController.
        $pair = $this->jwt->issueTokenPair($user);

        $this->em->wrapInTransaction(function () use ($user, $pair, $request): void {
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
