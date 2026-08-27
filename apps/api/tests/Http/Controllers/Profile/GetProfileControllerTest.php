<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Profile;

use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Profile\GetProfileController;
use Bayti\Api\Http\Serializers\UserSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for GET /v3/me/profile.
 *
 * Mirrors the auth/me test setup since the underlying mechanics
 * (AuthMiddleware → User attribute → serializer) are identical.
 * What's different here is the response shape, we verify the new
 * profile fields (gender, dob, locale, timezone) appear in the JSON.
 */
#[CoversClass(GetProfileController::class)]
#[CoversClass(UserSerializer::class)]
final class GetProfileControllerTest extends HttpTestCase
{
    #[Test]
    public function returns200WithFullProfileWhenAuthenticated(): void
    {
        $user = $this->makeUser(id: 7, email: 'alice@example.com');
        // Set a few profile fields so we can assert they're serialized.
        $user->setLocale('ar-AE');
        $user->setTimezone('Europe/London');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/profile', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertSame(7, $body['user']['id']);
        self::assertSame('alice@example.com', $body['user']['email']);
        // Profile fields surface even when null.
        self::assertArrayHasKey('gender', $body['user']);
        self::assertArrayHasKey('dob', $body['user']);
        self::assertSame('ar-AE', $body['user']['locale']);
        self::assertSame('Europe/London', $body['user']['timezone']);
    }

    #[Test]
    public function returnsDefaultsForNewUser(): void
    {
        // makeUser doesn't touch profile fields, so they hit the
        // entity defaults (locale='en', timezone='Asia/Dubai').
        $user = $this->makeUser(id: 8);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(8)->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/profile', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertNull($body['user']['gender']);
        self::assertNull($body['user']['dob']);
        self::assertSame('en', $body['user']['locale']);
        self::assertSame('Asia/Dubai', $body['user']['timezone']);
    }

    #[Test]
    public function returns401WhenNoAuthHeader(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/me/profile'));
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenTokenInvalid(): void
    {
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/profile', [], [
                'Authorization' => 'Bearer not.a.valid.jwt',
            ])
        );
        self::assertSame(401, $response->getStatusCode());
    }
}
