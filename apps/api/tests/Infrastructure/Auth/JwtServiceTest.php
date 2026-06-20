<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Infrastructure\Auth;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Auth\JwtSettings;
use Bayti\Api\Infrastructure\Auth\TokenClaims;
use DateTimeImmutable;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

#[CoversClass(JwtService::class)]
#[CoversClass(JwtSettings::class)]
#[CoversClass(TokenClaims::class)]
final class JwtServiceTest extends TestCase
{
    private JwtService $jwt;
    private JwtSettings $settings;

    protected function setUp(): void
    {
        $this->settings = JwtSettings::forTesting();
        $this->jwt = new JwtService($this->settings);
    }

    // -------------------------------------------------------------------
    // Settings invariants
    // -------------------------------------------------------------------

    #[Test]
    public function settingsRejectShortSecret(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 32 bytes');
        new JwtSettings(signingSecret: 'too-short');
    }

    #[Test]
    public function settingsRejectAccessTtlBelow60Seconds(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JwtSettings(
            signingSecret: str_repeat('x', 32),
            accessTtlSeconds: 30,
        );
    }

    #[Test]
    public function settingsRejectRefreshTtlShorterThanAccess(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JwtSettings(
            signingSecret: str_repeat('x', 32),
            accessTtlSeconds: 900,
            refreshTtlSeconds: 600, // shorter — invalid
        );
    }

    // -------------------------------------------------------------------
    // Issuance
    // -------------------------------------------------------------------

    #[Test]
    public function issueTokenPairReturnsTwoDifferentTokens(): void
    {
        $user = $this->makeUser();
        $pair = $this->jwt->issueTokenPair($user);

        self::assertNotEmpty($pair->accessToken);
        self::assertNotEmpty($pair->refreshToken);
        self::assertNotSame($pair->accessToken, $pair->refreshToken);
    }

    #[Test]
    public function issuedAccessTokenContainsRoles(): void
    {
        $user = $this->makeUser();
        $user->setRoles(vendor: true);

        $pair = $this->jwt->issueTokenPair($user);
        $claims = $this->jwt->verifyAccessToken($pair->accessToken);

        self::assertNotNull($claims);
        self::assertContains('customer', $claims->roles);
        self::assertContains('vendor', $claims->roles);
        self::assertNotContains('admin', $claims->roles);
        self::assertTrue($claims->hasRole('vendor'));
        self::assertFalse($claims->hasRole('admin'));
    }

    #[Test]
    public function issuedAccessTokenContainsPasswordChangedAtWhenSet(): void
    {
        $user = $this->makeUser();
        $user->setPasswordHash('newhash'); // sets passwordChangedAt
        $pwdChanged = $user->getPasswordChangedAt();
        self::assertNotNull($pwdChanged);

        $pair = $this->jwt->issueTokenPair($user);
        $claims = $this->jwt->verifyAccessToken($pair->accessToken);

        self::assertNotNull($claims);
        self::assertNotNull($claims->passwordChangedAt);
        // Within 1 second tolerance because of timestamp truncation
        // through the JWT layer.
        self::assertEqualsWithDelta(
            $pwdChanged->getTimestamp(),
            $claims->passwordChangedAt->getTimestamp(),
            1,
        );
    }

    #[Test]
    public function issuedAccessTokenOmitsPasswordChangedAtWhenNull(): void
    {
        $user = $this->makeUser(); // never changed password
        $pair = $this->jwt->issueTokenPair($user);
        $claims = $this->jwt->verifyAccessToken($pair->accessToken);

        self::assertNotNull($claims);
        self::assertNull($claims->passwordChangedAt);
    }

    #[Test]
    public function tokenPairExposesRefreshTokenHash(): void
    {
        $pair = $this->jwt->issueTokenPair($this->makeUser());
        self::assertSame(
            hash('sha256', $pair->refreshToken),
            $pair->refreshTokenHash(),
        );
    }

    #[Test]
    public function tokenPairCarriesRefreshJti(): void
    {
        $pair = $this->jwt->issueTokenPair($this->makeUser());
        $claims = $this->jwt->verifyRefreshToken($pair->refreshToken);

        self::assertNotNull($claims);
        self::assertSame($pair->refreshTokenJti, $claims->jti);
    }

    // -------------------------------------------------------------------
    // Verification — happy path
    // -------------------------------------------------------------------

    #[Test]
    public function verifyAccessTokenRoundTrip(): void
    {
        $user = $this->makeUser();
        $pair = $this->jwt->issueTokenPair($user);

        $claims = $this->jwt->verifyAccessToken($pair->accessToken);

        self::assertNotNull($claims);
        self::assertSame((int) $user->getId() ?: 1, $claims->userId);
        self::assertSame($this->settings->issuer, $claims->issuer);
        self::assertSame(JwtService::AUDIENCE_ACCESS, $claims->audience);
        self::assertTrue($claims->isAccessToken());
        self::assertFalse($claims->isRefreshToken());
        self::assertSame($user->getEmail(), $claims->email);
    }

    #[Test]
    public function verifyRefreshTokenRoundTrip(): void
    {
        $pair = $this->jwt->issueTokenPair($this->makeUser());

        $claims = $this->jwt->verifyRefreshToken($pair->refreshToken);

        self::assertNotNull($claims);
        self::assertSame(JwtService::AUDIENCE_REFRESH, $claims->audience);
        self::assertTrue($claims->isRefreshToken());
        self::assertFalse($claims->isAccessToken());
        // Refresh tokens stay minimal — no roles, no email.
        self::assertSame([], $claims->roles);
        self::assertNull($claims->email);
    }

    // -------------------------------------------------------------------
    // Verification — failure modes
    // -------------------------------------------------------------------

    #[Test]
    public function verifyRejectsAccessTokenAsRefresh(): void
    {
        // Cross-audience attack: an access token sent to the refresh
        // endpoint, hoping the verifier doesn't check audience.
        $pair = $this->jwt->issueTokenPair($this->makeUser());
        self::assertNull($this->jwt->verifyRefreshToken($pair->accessToken));
    }

    #[Test]
    public function verifyRejectsRefreshTokenAsAccess(): void
    {
        // Reverse: refresh token sent in Authorization header.
        $pair = $this->jwt->issueTokenPair($this->makeUser());
        self::assertNull($this->jwt->verifyAccessToken($pair->refreshToken));
    }

    #[Test]
    public function verifyRejectsExpiredToken(): void
    {
        // Build a token that already expired.
        $user = $this->makeUser();
        $now = (new DateTimeImmutable())->modify('-1 hour');
        $exp = $now->modify('+15 minutes'); // ~45 min in the past
        $payload = [
            'iss' => $this->settings->issuer,
            'sub' => '1',
            'aud' => JwtService::AUDIENCE_ACCESS,
            'iat' => $now->getTimestamp(),
            'exp' => $exp->getTimestamp(),
            'jti' => (string) Uuid::uuid7(),
        ];
        $token = JWT::encode($payload, $this->settings->signingSecret, JwtService::ALGORITHM);

        self::assertNull($this->jwt->verifyAccessToken($token));
    }

    #[Test]
    public function verifyRejectsTokenSignedWithWrongSecret(): void
    {
        $payload = [
            'iss' => $this->settings->issuer,
            'sub' => '1',
            'aud' => JwtService::AUDIENCE_ACCESS,
            'iat' => time(),
            'exp' => time() + 900,
            'jti' => (string) Uuid::uuid7(),
        ];
        $wrongSecret = str_repeat('attacker-key-', 4);
        $forged = JWT::encode($payload, $wrongSecret, JwtService::ALGORITHM);

        self::assertNull($this->jwt->verifyAccessToken($forged));
    }

    #[Test]
    public function verifyRejectsTokenWithWrongIssuer(): void
    {
        $payload = [
            'iss' => 'other-issuer',
            'sub' => '1',
            'aud' => JwtService::AUDIENCE_ACCESS,
            'iat' => time(),
            'exp' => time() + 900,
            'jti' => (string) Uuid::uuid7(),
        ];
        $token = JWT::encode($payload, $this->settings->signingSecret, JwtService::ALGORITHM);

        self::assertNull($this->jwt->verifyAccessToken($token));
    }

    #[Test]
    public function verifyRejectsMalformedToken(): void
    {
        self::assertNull($this->jwt->verifyAccessToken('not.a.valid.jwt'));
        self::assertNull($this->jwt->verifyAccessToken(''));
        self::assertNull($this->jwt->verifyAccessToken('garbage'));
    }

    #[Test]
    public function verifyRejectsTokenMissingRequiredClaims(): void
    {
        // A token with only some standard claims — missing jti.
        $payload = [
            'iss' => $this->settings->issuer,
            'sub' => '1',
            'aud' => JwtService::AUDIENCE_ACCESS,
            'iat' => time(),
            'exp' => time() + 900,
            // no jti
        ];
        $token = JWT::encode($payload, $this->settings->signingSecret, JwtService::ALGORITHM);

        self::assertNull($this->jwt->verifyAccessToken($token));
    }

    #[Test]
    public function verifyRejectsTokenWithNonPositiveSubject(): void
    {
        $payload = [
            'iss' => $this->settings->issuer,
            'sub' => '0',
            'aud' => JwtService::AUDIENCE_ACCESS,
            'iat' => time(),
            'exp' => time() + 900,
            'jti' => (string) Uuid::uuid7(),
        ];
        $token = JWT::encode($payload, $this->settings->signingSecret, JwtService::ALGORITHM);

        self::assertNull($this->jwt->verifyAccessToken($token));
    }

    #[Test]
    public function verifyTokenWithCraftedRolesFiltersUnknownValues(): void
    {
        // Even though attackers can't forge a valid signature without
        // the secret, a defense-in-depth check filters role values to
        // a known set — protecting against future bugs that might
        // cause unexpected values to slip in.
        $payload = [
            'iss' => $this->settings->issuer,
            'sub' => '1',
            'aud' => JwtService::AUDIENCE_ACCESS,
            'iat' => time(),
            'exp' => time() + 900,
            'jti' => (string) Uuid::uuid7(),
            'email' => 'a@b.com',
            'roles' => ['customer', 'super_user', 'root', 'admin', 12345, null],
        ];
        $token = JWT::encode($payload, $this->settings->signingSecret, JwtService::ALGORITHM);

        $claims = $this->jwt->verifyAccessToken($token);
        self::assertNotNull($claims);
        self::assertContains('customer', $claims->roles);
        self::assertContains('admin', $claims->roles);
        self::assertNotContains('super_user', $claims->roles);
        self::assertNotContains('root', $claims->roles);
    }

    // -------------------------------------------------------------------
    // Registration token (phone-first registration)
    // -------------------------------------------------------------------

    #[Test]
    public function registrationTokenRoundTripsPhoneAndCountry(): void
    {
        $token = $this->jwt->issueRegistrationToken('+971501234567', 'AE');
        $claims = $this->jwt->verifyRegistrationToken($token);

        self::assertNotNull($claims);
        self::assertSame('+971501234567', $claims['phone']);
        self::assertSame('AE', $claims['country_code']);
    }

    #[Test]
    public function registrationTokenIsNotAcceptedAsAccessToken(): void
    {
        // Distinct audience — a registration token must NOT authenticate
        // as a session token, and an access token must NOT pass the
        // registration verifier.
        $regToken = $this->jwt->issueRegistrationToken('+971501234567', 'AE');
        self::assertNull($this->jwt->verifyAccessToken($regToken));
        self::assertNull($this->jwt->verifyRefreshToken($regToken));

        $pair = $this->jwt->issueTokenPair($this->makeUser());
        self::assertNull($this->jwt->verifyRegistrationToken($pair->accessToken));
        self::assertNull($this->jwt->verifyRegistrationToken($pair->refreshToken));
    }

    #[Test]
    public function registrationTokenRejectsGarbage(): void
    {
        self::assertNull($this->jwt->verifyRegistrationToken('not-a-jwt'));
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function makeUser(): User
    {
        $user = new User('alice@example.com', '+971501234567', 'fake-bcrypt-hash', 'AE');

        // Force an id via reflection (no setter, deliberately).
        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($user, 1);

        return $user;
    }
}
