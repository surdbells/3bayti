<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Auth;

use DateTimeImmutable;

/**
 * Decoded JWT claims, normalised into a typed structure.
 *
 * The firebase/php-jwt library returns a generic stdClass; this
 * wrapper does the field extraction once with type checks, so callers
 * (auth middleware, /v3/auth/refresh handler, controllers needing
 * the user id) get IDE autocomplete + null safety + early failure
 * when a malformed token sneaks past signature verification.
 *
 * What's in a 3bayti JWT
 * ---------------------
 * Standard RFC 7519 claims:
 *   iss  - issuer ('3bayti-api')
 *   sub  - subject (user id, as a string)
 *   aud  - audience ('access' or 'refresh')
 *   iat  - issued-at (unix timestamp)
 *   exp  - expiry (unix timestamp)
 *   jti  - JWT id (uuid)
 *
 * 3bayti-specific claims (access tokens only — refresh tokens stay
 * minimal, since they're DB-validated):
 *   email            - user's email at issuance
 *   pwd_changed_at   - unix timestamp of last password change. Lets
 *                      auth middleware reject access tokens issued
 *                      before a password change.
 *   roles            - array of active role flags, e.g.
 *                      ['customer', 'vendor']. Middleware uses this
 *                      for role checks without a DB query.
 *
 * Why pwd_changed_at as a claim
 * -----------------------------
 * Refresh tokens can be revoked server-side (DB lookup). Access
 * tokens can't (they're stateless). But after a password change we
 * want to invalidate old access tokens too. Solution: every access
 * token carries the user's pwd_changed_at; auth middleware compares
 * it to the user's current value and rejects tokens with a stale
 * (older) value. The mismatch happens within at-most one access-token
 * lifetime (15 min) of the password change.
 */
final class TokenClaims
{
    /**
     * @param string[] $roles List of active role names: any of
     *                        'customer', 'vendor', 'admin',
     *                        'finance', 'support', 'sub_admin'.
     */
    public function __construct(
        public readonly string $issuer,
        public readonly int $userId,
        public readonly string $audience,
        public readonly DateTimeImmutable $issuedAt,
        public readonly DateTimeImmutable $expiresAt,
        public readonly string $jti,
        public readonly ?string $email = null,
        public readonly ?DateTimeImmutable $passwordChangedAt = null,
        public readonly array $roles = [],
        /** Admin user id when this token was minted for an impersonation session. */
        public readonly ?int $impersonatorId = null,
    ) {
    }

    public function isAccessToken(): bool { return $this->audience === JwtService::AUDIENCE_ACCESS; }
    public function isRefreshToken(): bool { return $this->audience === JwtService::AUDIENCE_REFRESH; }

    /**
     * Has the user got the named role at issuance time?
     * Trustworthy ONLY for access tokens (refresh tokens don't carry roles).
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    public function isExpired(?DateTimeImmutable $now = null): bool
    {
        $now ??= new DateTimeImmutable();
        return $this->expiresAt <= $now;
    }
}
