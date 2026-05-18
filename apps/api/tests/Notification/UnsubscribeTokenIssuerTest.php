<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Notification;

use Bayti\Api\Infrastructure\Auth\JwtSettings;
use Bayti\Api\Notification\UnsubscribeTokenIssuer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for UnsubscribeTokenIssuer (M3.2.X.11-E).
 *
 * Token-based unsubscribe is security-sensitive: a misissued
 * or under-validated token could let an attacker opt out an
 * arbitrary user, or worse, satisfy a different action's audience
 * if the action claim isn't validated.
 *
 * The tests cover:
 *   - Roundtrip: issue → verify → original user id recovered
 *   - Tampering: modified payload → verify rejects
 *   - Wrong action: token with different action claim → rejected
 *   - Expiry: stale token → rejected (cryptographically valid
 *             but past exp)
 *   - Malformed input → opaque null return
 */
#[CoversClass(UnsubscribeTokenIssuer::class)]
final class UnsubscribeTokenIssuerTest extends TestCase
{
    private UnsubscribeTokenIssuer $issuer;

    protected function setUp(): void
    {
        $this->issuer = new UnsubscribeTokenIssuer(JwtSettings::forTesting());
    }

    #[Test]
    public function roundtripRecoversOriginalUserId(): void
    {
        $token = $this->issuer->issue(userId: 12345);
        self::assertSame(12345, $this->issuer->verify($token));
    }

    #[Test]
    public function tamperedTokenRejected(): void
    {
        $token = $this->issuer->issue(userId: 12345);
        // Flip a character in the payload section
        $parts = explode('.', $token);
        $parts[1] = strtr($parts[1], ['A' => 'B', 'a' => 'b']);
        $tampered = implode('.', $parts);

        self::assertNull($this->issuer->verify($tampered));
    }

    #[Test]
    public function malformedTokenReturnsNull(): void
    {
        self::assertNull($this->issuer->verify('not-a-jwt'));
        self::assertNull($this->issuer->verify(''));
        self::assertNull($this->issuer->verify('a.b.c'));
    }

    #[Test]
    public function expiredTokenRejected(): void
    {
        // Issue a token with a past 'now' so iat + exp are both in the past
        $longAgo = new \DateTimeImmutable('-31 days');
        $token = $this->issuer->issue(userId: 12345, now: $longAgo);

        self::assertNull($this->issuer->verify($token));
    }

    #[Test]
    public function tokenWithWrongActionRejected(): void
    {
        // Build a JWT manually with action='something_else' but a
        // valid signature using the same secret. Verify must reject
        // it because the action claim doesn't match.
        $settings = JwtSettings::forTesting();
        $payload = [
            'sub' => '12345',
            'action' => 'reset_password',  // wrong action
            'iat' => time(),
            'exp' => time() + 300,
        ];
        $maliciousToken = \Firebase\JWT\JWT::encode(
            $payload,
            $settings->signingSecret,
            'HS256',
        );

        self::assertNull($this->issuer->verify($maliciousToken));
    }

    #[Test]
    public function nonNumericSubjectRejected(): void
    {
        // Manually craft a token with non-numeric sub
        $settings = JwtSettings::forTesting();
        $payload = [
            'sub' => 'alice@example.com',  // not a numeric user id
            'action' => UnsubscribeTokenIssuer::ACTION,
            'iat' => time(),
            'exp' => time() + 300,
        ];
        $weirdToken = \Firebase\JWT\JWT::encode(
            $payload,
            $settings->signingSecret,
            'HS256',
        );

        self::assertNull($this->issuer->verify($weirdToken));
    }
}
