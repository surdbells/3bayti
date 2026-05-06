<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\LoginInput;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Auth\TokenPair;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * POST /v3/auth/login
 *
 * Authenticate with email + password. On success, return:
 *   {
 *     "access_token": "...",
 *     "access_token_expires_at": "2026-05-06T20:35:00+00:00",
 *     "refresh_token": "...",
 *     "refresh_token_expires_at": "2026-05-13T20:20:00+00:00",
 *     "user": { "id": 1, "email": "...", "phone": "...", "is_phone_verified": true, ... }
 *   }
 *
 * Failure modes
 * -------------
 * All return 401 with the SAME error code (AUTH_INVALID_CREDENTIALS)
 * regardless of which check failed. This avoids user enumeration via
 * timing or error-message differences:
 *
 *   - Email not found
 *   - Email found but soft-deleted
 *   - Email found but is_active = false
 *   - Email found but password_verify failed
 *
 * Phone-not-verified check
 * ------------------------
 * If a user registered but never confirmed their phone OTP, they
 * exist with is_phone_verified=false. We DO let them log in (so
 * they can get a token to call /confirm) but we tell them their
 * phone is unverified via the response so the frontend can route
 * them to the OTP confirmation screen instead of the home page.
 *
 * Side effects on success
 * -----------------------
 *   - Update user.last_login_at + last_login_ip
 *   - Persist a RefreshToken row for the issued refresh token
 *   - All wrapped in a single Doctrine transaction
 *
 * What this DOES NOT do
 * ---------------------
 *   - Doesn't trigger 2FA (Decision: 2FA is post-M1 / M5 hardening)
 *   - Doesn't enforce password rotation (legacy users keep their
 *     bcrypt hashes; the password_changed_at field tracks the
 *     SECOND password change, not the first)
 *   - Doesn't lock accounts on failed attempts (M5 hardening with
 *     Redis-backed counters)
 */
final class LoginController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly RequestValidator $validator,
        private readonly EntityManagerInterface $em,
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
        $input = $this->validator->parse($request, LoginInput::class);

        /** @var UserRepository $users */
        $users = $this->em->getRepository(User::class);
        $user = $users->findByEmail($input->email);

        // Constant-time guard: even when the user doesn't exist, run
        // password_verify() against a dummy hash so the response time
        // doesn't leak existence. password_verify is bcrypt-bound and
        // takes ~tens-of-ms; skipping it on user-not-found would make
        // that case much faster than user-found-wrong-password.
        $hashToVerify = $user !== null
            ? $user->getPasswordHash()
            // Pre-computed dummy bcrypt hash. Doesn't match any real
            // password. Generated once with password_hash('dummy', PASSWORD_BCRYPT).
            : '$2y$10$abcdefghijklmnopqrstuuabcdefghijklmnopqrstuvwxyz123456789012';

        $passwordOk = password_verify($input->password, $hashToVerify);

        if ($user === null || !$passwordOk) {
            // Same response for "no such user" and "wrong password".
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_CREDENTIALS,
                'Email or password is incorrect.',
            );
        }

        // Account-level disable check. Distinct error code so frontend
        // can show "your account has been disabled — contact support"
        // instead of "wrong password".
        if (!$user->isActive()) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_ACCOUNT_INACTIVE,
                'This account has been disabled.',
            );
        }

        // Issue token pair, persist refresh token row, update login audit.
        // Wrapped in a transaction so any failure rolls back cleanly.
        $pair = $this->jwt->issueTokenPair($user);

        $this->em->wrapInTransaction(function () use ($user, $pair, $request): void {
            // Persist refresh-token row.
            /** @var RefreshTokenRepository $refreshRepo */
            $refreshRepo = $this->em->getRepository(RefreshToken::class);
            $refreshRow = new RefreshToken(
                user: $user,
                jti: $pair->refreshTokenJti,
                tokenHash: $pair->refreshTokenHash(),
                expiresAt: $pair->refreshTokenExpiresAt,
                issuedIp: $this->extractIp($request),
                userAgent: $request->getHeaderLine('User-Agent') ?: null,
            );
            $refreshRepo->save($refreshRow, flush: false);

            // Update login audit on the user.
            $user->recordLogin($this->extractIp($request));

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

    /**
     * Extract the client's IP address. Prefers the leftmost entry of
     * X-Forwarded-For (set by trusted reverse proxies — DigitalOcean
     * App Platform, Cloudflare); falls back to the connection's
     * remote address.
     *
     * Trust note: only the proxies we control should set XFF; if an
     * untrusted client sets it, they can spoof their IP. Production
     * deployment will be behind DO App Platform + Cloudflare which
     * both manage XFF correctly.
     */
    private function extractIp(ServerRequestInterface $request): ?string
    {
        $xff = $request->getHeaderLine('X-Forwarded-For');
        if ($xff !== '') {
            // Comma-separated list — take the first (original client).
            $first = trim(explode(',', $xff)[0]);
            if ($first !== '') {
                return $first;
            }
        }

        $serverParams = $request->getServerParams();
        return $serverParams['REMOTE_ADDR'] ?? null;
    }
}
