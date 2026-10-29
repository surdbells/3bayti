<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Profile;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Profile\ChangePasswordController;
use Bayti\Api\Http\Controllers\Profile\Dto\ChangePasswordInput;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ChangePasswordController::class)]
#[CoversClass(ChangePasswordInput::class)]
#[CoversClass(UserSerializer::class)]
final class ChangePasswordControllerTest extends HttpTestCase
{
    // ---------------------------------------------------------------
    // Happy path
    // ---------------------------------------------------------------

    #[Test]
    public function returns200WithFreshTokenPairOnSuccess(): void
    {
        $user = $this->makeUser(id: 100, passwordPlain: 'OldPass123');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        // Product rule: a password change signs NO device out.
        $refreshRepo->expects(self::never())->method('revokeAllForUser');
        $refreshRepo->expects(self::never())->method('revokeFamily');
        $refreshRepo->expects(self::once())->method('save');

        // UserSerializer::publicProfile() (in the 200 response) derives store
        // flags via getRepository(Vendor::class)->findBy(); stub it so the
        // success path doesn't 500 on an unmapped repo.
        $vendorRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $vendorRepo->method('findBy')->willReturn([]);

        $em = $this->stubEm(function ($em) use ($userRepo, $refreshRepo, $vendorRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
                [Vendor::class, $vendorRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/password', [
                'current_password' => 'OldPass123',
                'new_password' => 'NewPass4567',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // Fresh token pair returned, mirrors reset-confirm response shape.
        self::assertArrayHasKey('access_token', $body);
        self::assertArrayHasKey('access_token_expires_at', $body);
        self::assertArrayHasKey('refresh_token', $body);
        self::assertArrayHasKey('refresh_token_expires_at', $body);
        self::assertArrayHasKey('user', $body);
        self::assertSame(100, $body['user']['id']);

        // The returned access token carries the NEW pwd_changed_at, so it
        // passes AuthMiddleware on its very first use (issued before the
        // hash change it would be rejected as stale).
        $claims = $jwt->verifyAccessToken($body['access_token']);
        self::assertNotNull($claims);
        self::assertSame(
            $user->getPasswordChangedAt()?->getTimestamp(),
            $claims->passwordChangedAt?->getTimestamp(),
        );
        $me = $this->handle($this->jsonRequest('GET', '/v3/auth/me', [], [
            'Authorization' => 'Bearer ' . $body['access_token'],
        ]));
        self::assertSame(200, $me->getStatusCode());

        // ... while the token minted before the change is now stale (the
        // client recovers via /refresh; no session was ended).
        $stale = $this->handle($this->jsonRequest('GET', '/v3/auth/me', [], [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
        self::assertSame(401, $stale->getStatusCode());
        self::assertSame('AUTH_INVALID_TOKEN', $this->jsonBody($stale)['error']['code']);

        // Password actually rotated on the entity (verified via password_verify
        // since the hash itself is opaque).
        self::assertTrue(password_verify('NewPass4567', $user->getPasswordHash()));
        self::assertFalse(password_verify('OldPass123', $user->getPasswordHash()));

        // password_changed_at bumped, see User::setPasswordHash.
        self::assertNotNull($user->getPasswordChangedAt());
    }

    #[Test]
    public function passwordChangeBumpsPasswordChangedAt(): void
    {
        $user = $this->makeUser(id: 101, passwordPlain: 'OldPass123');
        self::assertNull($user->getPasswordChangedAt(), 'precondition: never changed');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);

        $em = $this->stubEm(function ($em) use ($userRepo, $refreshRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/password', [
                'current_password' => 'OldPass123',
                'new_password' => 'NewPass4567',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertNotNull(
            $user->getPasswordChangedAt(),
            'password_changed_at should be bumped — this is what invalidates old access tokens',
        );
    }

    // ---------------------------------------------------------------
    // Authentication: current_password failure
    // ---------------------------------------------------------------

    #[Test]
    public function returns401WhenCurrentPasswordWrong(): void
    {
        $user = $this->makeUser(id: 102, passwordPlain: 'RealPass123');
        $originalHash = $user->getPasswordHash();

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        // Crucially: revokeAllForUser MUST NOT be called when auth fails.
        $refreshRepo->expects(self::never())->method('revokeAllForUser');
        $refreshRepo->expects(self::never())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $refreshRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
            ]);
            // No flush on failure path.
            $em->expects(self::never())->method('wrapInTransaction');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/password', [
                'current_password' => 'WrongPass',
                'new_password' => 'NewPass4567',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(401, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('AUTH_INVALID_CREDENTIALS', $body['error']['code']);

        // Password hash UNCHANGED on the entity.
        self::assertSame($originalHash, $user->getPasswordHash());
    }

    // ---------------------------------------------------------------
    // Validation
    // ---------------------------------------------------------------

    #[Test]
    public function returns422WhenNewPasswordTooShort(): void
    {
        $user = $this->makeUser(id: 103, passwordPlain: 'RealPass123');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/password', [
                'current_password' => 'RealPass123',
                'new_password' => 'short',  // 5 chars, min is 8
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
    }

    #[Test]
    public function returns422WhenCurrentPasswordMissing(): void
    {
        $user = $this->makeUser(id: 104, passwordPlain: 'RealPass123');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/password', [
                'new_password' => 'NewPass4567',
                // current_password missing
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function returns422WhenNewPasswordSameAsCurrent(): void
    {
        $user = $this->makeUser(id: 105, passwordPlain: 'SamePass1234');
        $originalHash = $user->getPasswordHash();

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->expects(self::never())->method('revokeAllForUser');

        $em = $this->stubEm(function ($em) use ($userRepo, $refreshRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
            ]);
            $em->expects(self::never())->method('wrapInTransaction');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/password', [
                'current_password' => 'SamePass1234',
                'new_password' => 'SamePass1234',  // same
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
        // Hash unchanged, no DB side effects on validation failure.
        self::assertSame($originalHash, $user->getPasswordHash());
    }

    // ---------------------------------------------------------------
    // Auth: missing token
    // ---------------------------------------------------------------

    #[Test]
    public function returns401WhenNoAuthHeader(): void
    {
        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/password', [
                'current_password' => 'anything',
                'new_password' => 'NewPass4567',
            ])
        );

        self::assertSame(401, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('AUTH_MISSING_TOKEN', $body['error']['code']);
    }

    // ---------------------------------------------------------------
    // Sessions: no device is signed out
    // ---------------------------------------------------------------

    #[Test]
    public function successfulChangeSignsNoDeviceOut(): void
    {
        // Product rule: a customer stays signed in on every device until
        // they log out themselves. Changing the password on one device must
        // not revoke any refresh token; only a fresh row for THIS device is
        // persisted, in THIS device's session family (the access token's
        // sid), so the device still has one family to log out of.
        $user = $this->makeUser(id: 106, passwordPlain: 'OldPass123');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $saved = [];
        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->expects(self::never())->method('revokeAllForUser');
        $refreshRepo->expects(self::never())->method('revokeFamily');
        $refreshRepo->expects(self::never())->method('reassignUser');
        $refreshRepo->expects(self::once())->method('save')->willReturnCallback(
            function (RefreshToken $t) use (&$saved): void {
                $saved[] = $t;
            },
        );

        // UserSerializer::publicProfile() (in the 200 response) derives store
        // flags via getRepository(Vendor::class)->findBy(); stub it so the
        // success path doesn't 500 on an unmapped repo.
        $vendorRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $vendorRepo->method('findBy')->willReturn([]);

        $em = $this->stubEm(function ($em) use ($userRepo, $refreshRepo, $vendorRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
                [Vendor::class, $vendorRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/password', [
                'current_password' => 'OldPass123',
                'new_password' => 'NewPass4567',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(1, $saved);
        self::assertTrue($saved[0]->matchesToken($body['refresh_token']));
        self::assertFalse($saved[0]->isRevoked());
        self::assertSame($pair->familyId, $saved[0]->getFamilyId());
        self::assertNotSame($saved[0]->getJti(), $saved[0]->getFamilyId(), 'not a new family');
        self::assertSame($pair->familyId, $jwt->verifyAccessToken($body['access_token'])?->sessionFamilyId);
    }

    #[Test]
    public function accessTokenWithoutSidFallsBackToANewFamily(): void
    {
        // Access tokens minted before the sid claim existed (live for up to
        // 15 minutes after the deploy) name no family: the pair then starts
        // its own, exactly as before.
        $user = $this->makeUser(id: 107, passwordPlain: 'OldPass123');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);
        $saved = [];
        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('save')->willReturnCallback(
            function (RefreshToken $t) use (&$saved): void {
                $saved[] = $t;
            },
        );
        $vendorRepo = $this->createMock(\Doctrine\ORM\EntityRepository::class);
        $vendorRepo->method('findBy')->willReturn([]);

        $em = $this->stubEm(function ($em) use ($userRepo, $refreshRepo, $vendorRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
                [Vendor::class, $vendorRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $settings = $this->app->getContainer()->get(\Bayti\Api\Infrastructure\Auth\JwtSettings::class);
        $legacyAccess = \Firebase\JWT\JWT::encode([
            'iss' => $settings->issuer,
            'sub' => '107',
            'aud' => JwtService::AUDIENCE_ACCESS,
            'iat' => time(),
            'exp' => time() + 900,
            'jti' => 'legacy-access-jti',
            'roles' => ['customer'],
        ], $settings->signingSecret, JwtService::ALGORITHM);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/password', [
                'current_password' => 'OldPass123',
                'new_password' => 'NewPass4567',
            ], [
                'Authorization' => 'Bearer ' . $legacyAccess,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertCount(1, $saved);
        self::assertSame($saved[0]->getJti(), $saved[0]->getFamilyId());
    }
}
