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
use Bayti\Api\Infrastructure\Auth\TokenPair;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * POST /v3/auth/logout: the bearer is OPTIONAL (OptionalAuthMiddleware), the
 * refresh token in the body is the credential, and the presented token's
 * whole session FAMILY is ended, never another device's.
 */
#[CoversClass(LogoutController::class)]
#[CoversClass(LogoutInput::class)]
final class LogoutControllerTest extends HttpTestCase
{
    #[Test]
    public function happyPathRevokesTheTokensFamily(): void
    {
        $user = $this->makeUser(id: 7);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        $row = $this->makeRow($user, $pair, familyId: 'fam-phone');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);
        $refreshRepo->expects(self::once())
            ->method('revokeFamily')
            ->with('fam-phone', 'logout')
            ->willReturn(3);
        // Only this device: never every session of the user.
        $refreshRepo->expects(self::never())->method('revokeAllForUser');

        $this->bindEm($userRepo, $refreshRepo);

        $response = $this->logout($pair->refreshToken, bearer: $pair->accessToken);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function worksWithoutAnyBearer(): void
    {
        // Clients log out fire-and-forget after clearing their session,
        // often after the access token expired: no Authorization header.
        $user = $this->makeUser(id: 7);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        $row = $this->makeRow($user, $pair);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);
        $refreshRepo->expects(self::once())
            ->method('revokeFamily')
            ->with($pair->refreshTokenJti, 'logout'); // a fresh sign-in's family = its jti

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->logout($pair->refreshToken);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function expiredOrInvalidBearerIsIgnoredAndTheFamilyStillEnds(): void
    {
        $user = $this->makeUser(id: 7);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        $row = $this->makeRow($user, $pair, familyId: 'fam-web');

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);
        $refreshRepo->expects(self::once())->method('revokeFamily')->with('fam-web', 'logout');

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->logout($pair->refreshToken, bearer: 'expired.or.garbage');

        self::assertSame(204, $response->getStatusCode());
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
        $refreshRepo->expects(self::never())->method('revokeFamily');

        $this->bindEm($userRepo, $refreshRepo);

        $response = $this->logout($pair->refreshToken, bearer: $pair->accessToken);

        // Idempotent: 204 even though row wasn't found.
        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function returns204IdempotentForAlreadyRevokedToken(): void
    {
        // Revoking an already-ended family again is harmless (the repository
        // leaves already-ended rows alone); the client still gets 204.
        $user = $this->makeUser(id: 7);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        $row = $this->makeRow($user, $pair);
        $row->revoke('logout');

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);
        $refreshRepo->method('revokeFamily')->willReturn(0);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        self::assertSame(204, $this->logout($pair->refreshToken)->getStatusCode());
    }

    #[Test]
    public function returns204IdempotentForGarbageWithoutBearer(): void
    {
        // No bearer, malformed token: nothing to look up, nothing revoked.
        $em = $this->stubEm(function ($em): void {
            $em->expects(self::never())->method('getRepository');
        });
        $this->bind(EntityManagerInterface::class, $em);

        self::assertSame(204, $this->logout('garbage')->getStatusCode());
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

        $response = $this->logout('garbage', bearer: $pair->accessToken);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function returns204AndRevokesNothingOnHashMismatch(): void
    {
        $user = $this->makeUser(id: 7);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        $row = new RefreshToken(
            user: $user,
            jti: $pair->refreshTokenJti,
            tokenHash: hash('sha256', 'some-other-token'),
            expiresAt: $pair->refreshTokenExpiresAt,
        );

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);
        $refreshRepo->expects(self::never())->method('revokeFamily');

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        self::assertSame(204, $this->logout($pair->refreshToken)->getStatusCode());
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
        $otherRow = $this->makeRow($otherUser, $otherPair);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($authedUser);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($otherRow);
        // Other user's session MUST NOT be ended.
        $refreshRepo->expects(self::never())->method('revokeFamily');
        $refreshRepo->expects(self::never())->method('revokeAllForUser');

        $this->bindEm($userRepo, $refreshRepo);

        $response = $this->logout($otherPair->refreshToken, bearer: $authedPair->accessToken);

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($otherRow->isRevoked());
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

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function logout(string $refreshToken, ?string $bearer = null): \Psr\Http\Message\ResponseInterface
    {
        $headers = $bearer !== null ? ['Authorization' => 'Bearer ' . $bearer] : [];
        return $this->handle($this->jsonRequest('POST', '/v3/auth/logout', [
            'refresh_token' => $refreshToken,
        ], $headers));
    }

    private function makeRow(User $user, TokenPair $pair, ?string $familyId = null): RefreshToken
    {
        return new RefreshToken(
            user: $user,
            jti: $pair->refreshTokenJti,
            tokenHash: $pair->refreshTokenHash(),
            expiresAt: $pair->refreshTokenExpiresAt,
            familyId: $familyId,
        );
    }

    private function bindEm(UserRepository $userRepo, RefreshTokenRepository $refreshRepo): void
    {
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);
    }
}
