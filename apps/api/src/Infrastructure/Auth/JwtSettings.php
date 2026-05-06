<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Auth;

/**
 * Configuration for JwtService.
 *
 * Built from env vars (in DI). Centralised so the policy is in one
 * place: signing secret, TTLs, issuer name, allowed signing alg.
 *
 * On-disk config files (.env, DO App Platform secrets) feed into a
 * factory in config/di.php that returns this object.
 */
final class JwtSettings
{
    /**
     * @param string $signingSecret HS256 signing key. Must be at
     *                              least 32 bytes; we recommend 64
     *                              (use bin2hex(random_bytes(64))).
     *                              Refusing to start with a weak key
     *                              is the single most important
     *                              defense for a JWT setup.
     * @param int $accessTtlSeconds Access-token lifetime. Per
     *                              Decision A.6.1 = 900 (15 min).
     * @param int $refreshTtlSeconds Refresh-token lifetime. Per
     *                              Decision A.6.1 = 604800 (7 days).
     * @param string $issuer        iss claim value. Stable identifier
     *                              for this service. Default
     *                              '3bayti-api'.
     * @param int $clockSkewSeconds Tolerance for clock drift between
     *                              issuer and verifier. Default 5
     *                              (firebase/php-jwt's own default).
     *                              Helpful when the API is deployed
     *                              across multiple machines whose
     *                              clocks aren't perfectly NTP-synced.
     */
    public function __construct(
        public readonly string $signingSecret,
        public readonly int $accessTtlSeconds = 900,
        public readonly int $refreshTtlSeconds = 604800,
        public readonly string $issuer = '3bayti-api',
        public readonly int $clockSkewSeconds = 5,
    ) {
        if (strlen($signingSecret) < 32) {
            throw new \InvalidArgumentException(
                'JWT signing secret must be at least 32 bytes. ' .
                'Generate one with: php -r "echo bin2hex(random_bytes(64));"'
            );
        }
        if ($accessTtlSeconds < 60) {
            throw new \InvalidArgumentException('Access token TTL must be at least 60 seconds.');
        }
        if ($refreshTtlSeconds < $accessTtlSeconds) {
            throw new \InvalidArgumentException(
                'Refresh token TTL must be longer than access token TTL.'
            );
        }
    }

    /** Convenience factory for tests + dev environments. */
    public static function forTesting(): self
    {
        return new self(
            signingSecret: str_repeat('test-secret-', 4), // 48 bytes
            accessTtlSeconds: 900,
            refreshTtlSeconds: 604800,
            issuer: 'test-3bayti-api',
        );
    }
}
