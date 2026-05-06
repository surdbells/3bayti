<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Auth;

use DateTimeImmutable;

/**
 * The pair of tokens issued together at login or refresh.
 *
 * Returned from JwtService::issueTokenPair(). Caller is responsible
 * for:
 *   - Sending both tokens to the client in the auth response
 *   - Persisting the refresh token's hash via RefreshTokenRepository
 *     (using the jti + tokenHash + expiresAt fields exposed below)
 *
 * Why both tokens at once
 * ------------------------
 * Login and refresh both need to issue both tokens atomically. If
 * we exposed two separate methods, callers could forget to issue
 * one half, leaving sessions in inconsistent state. Single method,
 * single pair.
 */
final class TokenPair
{
    public function __construct(
        public readonly string $accessToken,
        public readonly DateTimeImmutable $accessTokenExpiresAt,
        public readonly string $refreshToken,
        public readonly DateTimeImmutable $refreshTokenExpiresAt,
        public readonly string $refreshTokenJti,
    ) {
    }

    /**
     * SHA-256 of the refresh token, for storing in the
     * `user_refresh_tokens.token_hash` column. The raw token is
     * sent to the client; the hash is what we keep server-side,
     * preventing trivial impersonation if the DB is ever leaked.
     */
    public function refreshTokenHash(): string
    {
        return hash('sha256', $this->refreshToken);
    }
}
