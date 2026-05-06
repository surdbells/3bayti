<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Auth;

use Bayti\Api\Domain\User\User;
use DateTimeImmutable;
use Firebase\JWT\BeforeValidException;
use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Ramsey\Uuid\Uuid;

/**
 * Issue + verify JWT access and refresh tokens.
 *
 * This is the core of the auth system. Wrapper around firebase/php-jwt
 * with 3bayti-specific policy: HS256 only, fixed issuer, separate
 * audience claims for access vs refresh, role + pwd_changed_at claims
 * baked into access tokens.
 *
 * Design choices
 * --------------
 * - HS256 (symmetric). Single backend service today; no need for
 *   asymmetric crypto. RS256 considered for M5 if we split auth
 *   into a separate microservice that other services need to verify
 *   tokens from without sharing the secret.
 *
 * - Audience claim differs per token type ('access' vs 'refresh').
 *   verifyAccessToken() rejects tokens with aud != 'access', and
 *   vice versa for refresh. Without this, an attacker who got hold
 *   of a refresh token (longer-lived) could send it as an access
 *   token and authenticate against any endpoint until the refresh
 *   token expired. The audience claim closes that hole.
 *
 * - jti (JWT ID) is a UUID for both tokens. For refresh tokens,
 *   it's the lookup key into user_refresh_tokens. For access tokens,
 *   it's not stored server-side (would defeat statelessness) but
 *   it's included for log correlation: 'access token X was issued
 *   alongside refresh token Y'.
 *
 * - Access tokens carry role flags + pwd_changed_at. Roles let
 *   middleware do role checks without a DB hit. pwd_changed_at lets
 *   us invalidate access tokens after a password change without
 *   needing a server-side denylist (auth middleware compares the
 *   token's pwd_changed_at to the user's current value and rejects
 *   tokens that are stale).
 *
 * Error model
 * -----------
 * verifyAccessToken / verifyRefreshToken return a TokenClaims on
 * success and null on ANY failure. Caller doesn't get to distinguish
 * 'expired' vs 'malformed' vs 'wrong signature' from the return
 * value alone. Reason: error messages that distinguish failure
 * modes are oracle leaks (an attacker can probe to learn things
 * about the token format). If the controller wants finer-grained
 * info for logging, it can pass an out-parameter or use the named
 * verifyOrThrow* variants — but the default UX is opaque.
 */
final class JwtService
{
    public const ALGORITHM = 'HS256';
    public const AUDIENCE_ACCESS = 'access';
    public const AUDIENCE_REFRESH = 'refresh';

    public function __construct(
        private readonly JwtSettings $settings,
    ) {
    }

    // -------------------------------------------------------------------
    // Issuance
    // -------------------------------------------------------------------

    /**
     * Issue a fresh access + refresh token pair for a user.
     * Caller is responsible for persisting the refresh token's hash
     * via RefreshTokenRepository.
     */
    public function issueTokenPair(User $user): TokenPair
    {
        $now = new DateTimeImmutable();

        // Access token — short-lived, role-laden, stateless.
        $accessExp = $now->modify("+{$this->settings->accessTtlSeconds} seconds");
        $accessJti = (string) Uuid::uuid7();
        $accessPayload = $this->buildAccessPayload($user, $now, $accessExp, $accessJti);
        $accessToken = JWT::encode($accessPayload, $this->settings->signingSecret, self::ALGORITHM);

        // Refresh token — long-lived, opaque, server-validated.
        $refreshExp = $now->modify("+{$this->settings->refreshTtlSeconds} seconds");
        $refreshJti = (string) Uuid::uuid7();
        $refreshPayload = $this->buildRefreshPayload($user, $now, $refreshExp, $refreshJti);
        $refreshToken = JWT::encode($refreshPayload, $this->settings->signingSecret, self::ALGORITHM);

        return new TokenPair(
            accessToken: $accessToken,
            accessTokenExpiresAt: $accessExp,
            refreshToken: $refreshToken,
            refreshTokenExpiresAt: $refreshExp,
            refreshTokenJti: $refreshJti,
        );
    }

    // -------------------------------------------------------------------
    // Verification
    // -------------------------------------------------------------------

    /**
     * Verify an access token. Returns null on any failure (invalid
     * signature, expired, wrong audience, malformed, etc.).
     *
     * Does NOT check pwd_changed_at against the database — that's
     * the AuthMiddleware's job (it has the User looked up anyway).
     * Also does NOT check the user's is_active state — also middleware.
     * This method's job is: 'is the token cryptographically valid
     * AND not expired AND for the right audience'.
     */
    public function verifyAccessToken(string $token): ?TokenClaims
    {
        return $this->verify($token, self::AUDIENCE_ACCESS);
    }

    /**
     * Verify a refresh token. Returns null on failure.
     *
     * Does NOT check the database for revocation — that's done by
     * the /v3/auth/refresh handler (which needs the row anyway to
     * mark it revoked + issue a new pair).
     */
    public function verifyRefreshToken(string $token): ?TokenClaims
    {
        return $this->verify($token, self::AUDIENCE_REFRESH);
    }

    // -------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function buildAccessPayload(
        User $user,
        DateTimeImmutable $iat,
        DateTimeImmutable $exp,
        string $jti,
    ): array {
        $payload = [
            'iss' => $this->settings->issuer,
            'sub' => (string) $user->getId(),
            'aud' => self::AUDIENCE_ACCESS,
            'iat' => $iat->getTimestamp(),
            'exp' => $exp->getTimestamp(),
            'jti' => $jti,

            // 3bayti claims
            'email' => $user->getEmail(),
            'roles' => $this->extractActiveRoles($user),
        ];

        // pwd_changed_at — only present when the user has actually
        // changed their password. Tokens for never-changed-password
        // users (new accounts) skip the field; AuthMiddleware treats
        // a missing claim as 'always valid', a present claim as
        // 'must match the current value'.
        if ($user->getPasswordChangedAt() !== null) {
            $payload['pwd_changed_at'] = $user->getPasswordChangedAt()->getTimestamp();
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRefreshPayload(
        User $user,
        DateTimeImmutable $iat,
        DateTimeImmutable $exp,
        string $jti,
    ): array {
        // Refresh tokens stay minimal — just the standard claims.
        // The lookup happens in user_refresh_tokens by jti, where we
        // also fetch the user_id. Including roles/email in refresh
        // tokens would make their payload bigger without benefit
        // (caller can't trust those values without the DB anyway,
        // since refresh tokens outlive role changes).
        return [
            'iss' => $this->settings->issuer,
            'sub' => (string) $user->getId(),
            'aud' => self::AUDIENCE_REFRESH,
            'iat' => $iat->getTimestamp(),
            'exp' => $exp->getTimestamp(),
            'jti' => $jti,
        ];
    }

    /** @return string[] */
    private function extractActiveRoles(User $user): array
    {
        $roles = [];
        if ($user->isCustomer()) { $roles[] = 'customer'; }
        if ($user->isVendor())   { $roles[] = 'vendor'; }
        if ($user->isAdmin())    { $roles[] = 'admin'; }
        if ($user->isFinance())  { $roles[] = 'finance'; }
        if ($user->isSupport())  { $roles[] = 'support'; }
        if ($user->isSubAdmin()) { $roles[] = 'sub_admin'; }
        return $roles;
    }

    /**
     * Generic verify path — used by both access and refresh.
     * Returns null on ANY failure (no oracle leak).
     */
    private function verify(string $token, string $expectedAudience): ?TokenClaims
    {
        // firebase/php-jwt's clock-skew leeway is set globally as a
        // static property. Set it before each decode.
        JWT::$leeway = $this->settings->clockSkewSeconds;

        try {
            $decoded = JWT::decode(
                $token,
                new Key($this->settings->signingSecret, self::ALGORITHM),
            );
        } catch (ExpiredException | SignatureInvalidException | BeforeValidException $e) {
            // Specific JWT errors: fail silently with null. Each of
            // these would be useful for logging upstream but never
            // for distinguishing 'why' to the client.
            return null;
        } catch (\Throwable $e) {
            // Anything else (malformed JSON, missing fields,
            // unexpected exceptions): also null.
            return null;
        }

        // Cast firebase/php-jwt's stdClass to our typed TokenClaims.
        // Verify required claims are present and well-formed; bail if not.
        if (!isset($decoded->iss, $decoded->sub, $decoded->aud, $decoded->iat, $decoded->exp, $decoded->jti)) {
            return null;
        }

        // Issuer check — protects against tokens from other issuers
        // signed with leaked secrets.
        if ($decoded->iss !== $this->settings->issuer) {
            return null;
        }

        // Audience check — distinguishes access from refresh.
        if ($decoded->aud !== $expectedAudience) {
            return null;
        }

        // Subject must parse as a positive int (user id).
        if (!is_string($decoded->sub) && !is_int($decoded->sub)) {
            return null;
        }
        $userId = (int) $decoded->sub;
        if ($userId <= 0) {
            return null;
        }

        try {
            $issuedAt = (new DateTimeImmutable())->setTimestamp((int) $decoded->iat);
            $expiresAt = (new DateTimeImmutable())->setTimestamp((int) $decoded->exp);
        } catch (\Throwable) {
            return null;
        }

        $passwordChangedAt = null;
        if (isset($decoded->pwd_changed_at) && is_int($decoded->pwd_changed_at)) {
            try {
                $passwordChangedAt = (new DateTimeImmutable())->setTimestamp($decoded->pwd_changed_at);
            } catch (\Throwable) {
                return null;
            }
        }

        $roles = [];
        if (isset($decoded->roles) && is_array($decoded->roles)) {
            // Filter to known role strings to defend against a
            // crafted token with weird role values (which couldn't
            // happen with a valid signature, but defense in depth).
            $known = ['customer', 'vendor', 'admin', 'finance', 'support', 'sub_admin'];
            foreach ($decoded->roles as $r) {
                if (is_string($r) && in_array($r, $known, true)) {
                    $roles[] = $r;
                }
            }
        }

        $email = isset($decoded->email) && is_string($decoded->email) ? $decoded->email : null;

        return new TokenClaims(
            issuer: $this->settings->issuer,
            userId: $userId,
            audience: (string) $decoded->aud,
            issuedAt: $issuedAt,
            expiresAt: $expiresAt,
            jti: (string) $decoded->jti,
            email: $email,
            passwordChangedAt: $passwordChangedAt,
            roles: $roles,
        );
    }
}
