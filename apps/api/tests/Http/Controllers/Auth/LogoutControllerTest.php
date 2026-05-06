<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\LogoutInput;
use Bayti\Api\Http\Controllers\Auth\LogoutController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(LogoutController::class)]
#[CoversClass(LogoutInput::class)]
final class LogoutControllerTest extends HttpTestCase
{
    #[Test]
    public function happyPathRevokesRefreshToken(): void
    {
        $user = $this->makeUser(id: 7);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $row = new RefreshToken(
            user: $user,
            jti: $pair->refreshTokenJti,
            tokenHash: $pair->refreshTokenHash(),
            expiresAt: $pair->refreshTokenExpiresAt,
        );

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/auth/logout', [
                'refresh_token' => $pair->refreshToken,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(204, $response->getStatusCode());
        self::assertTrue($row->isRevoked());
        self::assertSame('logout', $row->getRevokedReason());
    }

    #[Test]
    public function returns204IdempotentForUnknownRefreshToken(): void
    {
        $user = $this->makeUser(id: 7);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn(null); // unknown

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/auth/logout', [
                'refresh_token' => $pair->refreshToken,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        // Idempotent: 204 even though row wasn't found.
        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function returns204IdempotentForMalformedRefreshToken(): void
    {
        $user = $this->makeUser(id: 7);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/auth/logout', [
                'refresh_token' => 'garbage',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function returns403WhenRefreshTokenBelongsToDifferentUser(): void
    {
        // Authed user is #7. Refresh token presented belongs to user #99.
        $authedUser = $this->makeUser(id: 7);
        $otherUser = $this->makeUser(id: 99, email: 'other@example.com');

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $authedPair = $jwt->issueTokenPair($authedUser);
        $otherPair = $jwt->issueTokenPair($otherUser);

        // Other user's row in DB.
        $otherRow = new RefreshToken(
            user: $otherUser,
            jti: $otherPair->refreshTokenJti,
            tokenHash: $otherPair->refreshTokenHash(),
            expiresAt: $otherPair->refreshTokenExpiresAt,
        );

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($authedUser);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($otherRow);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/auth/logout', [
                'refresh_token' => $otherPair->refreshToken,
            ], [
                'Authorization' => 'Bearer ' . $authedPair->accessToken,
            ])
        );

        self::assertSame(403, $response->getStatusCode());
        // Other user's row MUST NOT be revoked.
        self::assertFalse($otherRow->isRevoked());
    }

    #[Test]
    public function returns401WhenNoAuthHeader(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/logout', [
            'refresh_token' => 'whatever',
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns422WhenRefreshTokenMissing(): void
    {
        $user = $this->makeUser();
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/auth/logout', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }
}
