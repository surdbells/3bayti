<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\RefreshToken;
use Bayti\Api\Domain\User\RefreshTokenRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\Dto\LoginInput;
use Bayti\Api\Http\Controllers\Auth\LoginController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(LoginController::class)]
#[CoversClass(LoginInput::class)]
#[CoversClass(UserSerializer::class)]
final class LoginControllerTest extends HttpTestCase
{
    // -------------------------------------------------------------------
    // Happy path
    // -------------------------------------------------------------------

    #[Test]
    public function happyPathReturnsTokenPairAndUser(): void
    {
        $user = $this->makeUser(id: 42, email: 'alice@example.com', passwordPlain: 'secret123');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->with('alice@example.com')->willReturn($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);
        $refreshRepo->expects(self::once())->method('save');

        $em = $this->stubEm(function ($em) use ($userRepo, $refreshRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
            ]);
            $em->expects(self::atLeastOnce())->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]));

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertNotEmpty($body['access_token']);
        self::assertNotEmpty($body['refresh_token']);
        self::assertNotEmpty($body['access_token_expires_at']);
        self::assertNotEmpty($body['refresh_token_expires_at']);
        self::assertSame(42, $body['user']['id']);
        self::assertSame('alice@example.com', $body['user']['email']);

        // Verify the issued access token is real (round-trip via JwtService)
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $claims = $jwt->verifyAccessToken($body['access_token']);
        self::assertNotNull($claims);
        self::assertSame(42, $claims->userId);
    }

    #[Test]
    public function happyPathRecordsLoginAuditOnUser(): void
    {
        $user = $this->makeUser(passwordPlain: 'secret123');
        // Pre-condition: never logged in.
        self::assertNull($user->getLastLoginAt());

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn($user);

        $refreshRepo = $this->createMock(RefreshTokenRepository::class);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [RefreshToken::class, $refreshRepo],
            ]));
        $this->bind(EntityManagerInterface::class, $em);

        $this->handle($this->jsonRequest('POST', '/v3/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'secret123',
        ]));

        self::assertNotNull($user->getLastLoginAt(), 'Expected login audit field updated.');
    }

    // -------------------------------------------------------------------
    // Failure modes — all return 401 with AUTH_INVALID_CREDENTIALS
    // -------------------------------------------------------------------

    #[Test]
    public function returns401WhenEmailNotFound(): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'whatever',
        ]));

        self::assertSame(401, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::AUTH_INVALID_CREDENTIALS, $body['error']['code']);
    }

    #[Test]
    public function returns401WhenPasswordWrong(): void
    {
        $user = $this->makeUser(passwordPlain: 'right');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'wrong',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::AUTH_INVALID_CREDENTIALS,
            $this->jsonBody($response)['error']['code'],
        );
    }

    #[Test]
    public function returns401AccountInactiveWhenUserDeactivated(): void
    {
        $user = $this->makeUser(passwordPlain: 'right', active: false);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findByEmail')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'right',
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::AUTH_ACCOUNT_INACTIVE,
            $this->jsonBody($response)['error']['code'],
        );
    }

    // -------------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------------

    #[Test]
    public function returns422WhenBodyEmpty(): void
    {
        $response = $this->handle($this->jsonRequest('POST', '/v3/auth/login', []));
        self::assertSame(422, $response->getStatusCode());

        $fields = $this->jsonBody($response)['error']['details']['fields'];
        self::assertArrayHasKey('email', $fields);
        self::assertArrayHasKey('password', $fields);
    }
}
