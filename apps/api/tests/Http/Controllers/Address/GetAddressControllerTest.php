<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Address;

use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Address\GetAddressController;
use Bayti\Api\Http\Serializers\AddressSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GetAddressController::class)]
#[CoversClass(AddressSerializer::class)]
final class GetAddressControllerTest extends HttpTestCase
{
    #[Test]
    public function returns200WhenAddressBelongsToUser(): void
    {
        $user = $this->makeUser(id: 40);
        $addr = $this->makeAddress($user, id: 200, label: 'Home');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('find')->with(200)->willReturn($addr);

        $em = $this->stubEm(function ($em) use ($userRepo, $addrRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Address::class, $addrRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/addresses/200', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(200, $body['address']['id']);
        self::assertSame('Home', $body['address']['label']);
    }

    #[Test]
    public function returns404WhenAddressDoesNotExist(): void
    {
        $user = $this->makeUser(id: 41);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('find')->willReturn(null);

        $em = $this->stubEm(function ($em) use ($userRepo, $addrRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Address::class, $addrRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/addresses/999', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenAddressBelongsToDifferentUser(): void
    {
        $owner = $this->makeUser(id: 42);
        $intruder = $this->makeUser(id: 43);
        $addr = $this->makeAddress($owner, id: 300);

        $userRepo = $this->createMock(UserRepository::class);
        // Intruder logs in...
        $userRepo->method('findById')->willReturn($intruder);

        $addrRepo = $this->createMock(AddressRepository::class);
        // ...and tries to read someone else's address
        $addrRepo->method('find')->willReturn($addr);

        $em = $this->stubEm(function ($em) use ($userRepo, $addrRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Address::class, $addrRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($intruder);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/addresses/300', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        // 404, NOT 403 — see GetAddressController docblock for IDOR rationale.
        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404ForNonNumericId(): void
    {
        $user = $this->makeUser(id: 44);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/me/addresses/not-a-number', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenNoAuthHeader(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/me/addresses/100'));
        self::assertSame(401, $response->getStatusCode());
    }

    private function makeAddress(
        User $user,
        int $id,
        ?string $label = null,
    ): Address {
        $addr = new Address(
            user: $user,
            recipientName: 'Test Recipient',
            recipientPhone: '+971501234567',
            emirate: 'Dubai',
            area: 'Jumeirah',
            label: $label,
        );
        $ref = new \ReflectionProperty(Address::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($addr, $id);
        return $addr;
    }
}
