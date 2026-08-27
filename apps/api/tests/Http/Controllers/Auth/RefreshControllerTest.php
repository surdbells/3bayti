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

        // NB: no ->with(RefreshToken::class) constraint, the success path
        // also serialises the user (UserSerializer looks up other
        // repositories), so pinning getRepository to a single class throws.
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', [
            'refresh_token' => $oldPair->refreshToken,
        ]));

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['refresh_token']);
        // Different from the old refresh, proves rotation happened.
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
    // Failure modes, all return 401 AUTH_REFRESH_TOKEN_INVALID
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
    public function returns401AndRevokesAllOnReuseOfDeliberatelyRevokedToken(): void
    {
        $user = $this->makeUser(id: 42);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $row = $this->makeRefreshRow($user, $pair);
        // Token deliberately revoked (logout), a replay of it is genuine
        // reuse, NOT the rotation lost-response race, so it must trip the
        // defensive wholesale revocation regardless of timing.
        $row->revoke('logout');

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
    public function graceWindowReissuesOnRetryOfJustRotatedTokenWithoutRevokingAll(): void
    {
        // The mobile lost-response race: the server rotated this token
        // moments ago, but the client never received/persisted the
        // replacement and retried with the token it still holds. Within the
        // grace window this must re-issue a fresh pair, NOT log the customer
        // out of every session.
        $user = $this->makeUser(id: 99);
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $row = $this->makeRefreshRow($user, $pair);
        $row->revoke('rotated'); // revoked just now, by rotation → within grace

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->method('findByJti')->willReturn($row);
        // Benign retry: a fresh row is persisted and the family is NOT nuked.
        $refreshRepo->expects(self::once())->method('save');
        $refreshRepo->expects(self::never())->method('revokeAllForUser');

        // No ->with(RefreshToken::class), the success path serialises the
        // user, which looks up other repositories (see happy-path note).
        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturn($refreshRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/refresh', [
            'refresh_token' => $pair->refreshToken,
        ]));

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['refresh_token']);
        self::assertNotSame($pair->refreshToken, $body['refresh_token']);
        self::assertSame(99, $body['user']['id']);
    }

    #[Test]
    public function returns401WhenUserDeactivated(): void
    {
        $user = $this->makeUser(active: true); // start active
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        // Now deactivate AFTER issuing the token, simulates admin
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
