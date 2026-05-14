<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Profile;

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
        $refreshRepo->expects(self::once())
            ->method('revokeAllForUser')
            ->with($user, 'password_changed');
        $refreshRepo->expects(self::once())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $refreshRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
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

        // Fresh token pair returned — mirrors reset-confirm response shape.
        self::assertArrayHasKey('access_token', $body);
        self::assertArrayHasKey('access_token_expires_at', $body);
        self::assertArrayHasKey('refresh_token', $body);
        self::assertArrayHasKey('refresh_token_expires_at', $body);
        self::assertArrayHasKey('user', $body);
        self::assertSame(100, $body['user']['id']);

        // Password actually rotated on the entity (verified via password_verify
        // since the hash itself is opaque).
        self::assertTrue(password_verify('NewPass4567', $user->getPasswordHash()));
        self::assertFalse(password_verify('OldPass123', $user->getPasswordHash()));

        // password_changed_at bumped — see User::setPasswordHash.
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
        // Hash unchanged — no DB side effects on validation failure.
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
    // Session security: other refresh tokens are revoked
    // ---------------------------------------------------------------

    #[Test]
    public function successfulChangeRevokesAllExistingRefreshTokens(): void
    {
        // This is the key security property of the change: all existing
        // sessions (including the one on the current device) are revoked,
        // then a fresh pair is issued for the current device. The user
        // stays logged in HERE; everywhere else is kicked out.
        $user = $this->makeUser(id: 106, passwordPlain: 'OldPass123');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->expects(self::once())
            ->method('revokeAllForUser')
            ->with(
                self::identicalTo($user),
                self::identicalTo('password_changed'),
            );
        $refreshRepo->expects(self::once())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $refreshRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
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
    }
}
