<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Auth;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Auth\MeController;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(MeController::class)]
#[CoversClass(UserSerializer::class)]
final class MeControllerTest extends HttpTestCase
{
    #[Test]
    public function returns200WithUserPayloadWhenAuthenticated(): void
    {
        $user = $this->makeUser(id: 7, email: 'alice@example.com');

        // AuthMiddleware will look up the user by id from the JWT.
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        // Issue a real access token for this user via the live JwtService.
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/auth/me', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertSame(7, $body['user']['id']);
        self::assertSame('alice@example.com', $body['user']['email']);
        self::assertContains('customer', $body['user']['roles']);
    }

    #[Test]
    public function returns401WhenNoAuthHeader(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/auth/me'));
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenTokenInvalid(): void
    {
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/auth/me', [], [
                'Authorization' => 'Bearer not.a.valid.jwt',
            ])
        );
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenUserDeleted(): void
    {
        $user = $this->makeUser(id: 7);

        // User row is gone — AuthMiddleware should 401.
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/auth/me', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(401, $response->getStatusCode());
    }
}
