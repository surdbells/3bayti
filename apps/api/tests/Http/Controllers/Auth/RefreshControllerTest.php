<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Controllers\Auth\Dto\RefreshInput;
use Bayti\Api\Http\Controllers\Auth\RefreshController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Auth\TokenPair;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(RefreshController::class)]
#[CoversClass(RefreshInput::class)]
final class RefreshControllerTest extends HttpTestCase
{
    // -------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------

    #[Test]
    public function happyPathRotatesTokenAndReturnsNewPair(): void
    {
        $user = $this->makeUser(id: 7);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $oldPair = $jwt->issueTokenPair($user);

        // Persisted refresh row matching the issued pair.
        $row = $this->makeRefreshRow($user, $oldPair);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')
            ->with($oldPair->refreshTokenJti)
            ->willReturn($row);
        // After rotation, a new row MUST be persisted.
        $refreshRepo->expects(self::once())->method('save');

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', [
            'refresh_token' => $oldPair->refreshToken,
        ]));

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['refresh_token']);
        // Different from the old refresh — proves rotation happened.
        self::assertNotSame($oldPair->refreshToken, $body['refresh_token']);
        self::assertSame(7, $body['user']['id']);

        // Side effect: old row revoked with reason 'rotated'.
        self::assertTrue($row->isRevoked());
        self::assertSame('rotated', $row->getRevokedReason());

        // New access token verifies via real JwtService.
        $newClaims = $jwt->verifyAccessToken($body['access_token']);
        self::assertNotNull($newClaims);
        self::assertSame(7, $newClaims->userId);
    }

    // -------------------------------------------------------------------
    // Failure modes — all return 401 AUTH_REFRESH_TOKEN_INVALID
    // -------------------------------------------------------------------

    #[Test]
    public function returns401ForMalformedToken(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', [
            'refresh_token' => 'not.a.valid.jwt',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::AUTH_REFRESH_TOKEN_INVALID,
            $this->jsonBody($response)['error']['code'],
        );
    }

    #[Test]
    public function returns401WhenAccessTokenSentInsteadOfRefresh(): void
    {
        $user = $this->makeUser();
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', [
            'refresh_token' => $pair->accessToken, // wrong audience
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenJtiNotInDb(): void
    {
        $user = $this->makeUser();
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', [
            'refresh_token' => $pair->refreshToken,
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401AndRevokesAllOnReuseOfRevokedToken(): void
    {
        $user = $this->makeUser(id: 42);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $row = $this->makeRefreshRow($user, $pair);
        // Token already revoked — simulating a second presentation.
        $row->revoke('rotated');

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);
        // Defensive wholesale revocation MUST be triggered.
        $refreshRepo->expects(self::once())
            ->method('revokeAllForUser')
            ->with($user, 'reuse_detected');

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', [
            'refresh_token' => $pair->refreshToken,
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenUserDeactivated(): void
    {
        $user = $this->makeUser(active: true); // start active
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        // Now deactivate AFTER issuing the token — simulates admin
        // disabling the account between login and refresh.
        $user->deactivate();

        $row = $this->makeRefreshRow($user, $pair);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', [
            'refresh_token' => $pair->refreshToken,
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenRowExpiredInDb(): void
    {
        $user = $this->makeUser();
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        // Forge a row whose expires_at is in the past.
        $row = new RefreshToken(
            user: $user,
            jti: $pair->refreshTokenJti,
            tokenHash: $pair->refreshTokenHash(),
            expiresAt: (new DateTimeImmutable())->modify('-1 second'),
        );

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(RefreshToken::class)->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', [
            'refresh_token' => $pair->refreshToken,
        ]));

        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns422OnMissingRefreshToken(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', []));
        self::assertSame(422, $response->getStatusCode());
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    private function makeRefreshRow(User $user, TokenPair $pair): RefreshToken
    {
        return new RefreshToken(
            user: $user,
            jti: $pair->refreshTokenJti,
            tokenHash: $pair->refreshTokenHash(),
            expiresAt: $pair->refreshTokenExpiresAt,
        );
    }
}
