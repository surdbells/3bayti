<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Address;

use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Address\GetBillingAddressController;
use Bayti\Api\Http\Serializers\AddressSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GetBillingAddressController::class)]
#[CoversClass(AddressSerializer::class)]
final class GetBillingAddressControllerTest extends HttpTestCase
{
    #[Test]
    public function returns200WithAddressWhenBillingIsSet(): void
    {
        $user = $this->makeUser(id: 60);
        $addr = $this->makeBillingAddress($user, id: 500, label: 'Billing');

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('findDefaultBillingForUser')->with($user)->willReturn($addr);

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
            $this->jsonRequest('GET', '/v3/me/billing-address', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertIsArray($body['address']);
        self::assertSame(500, $body['address']['id']);
        self::assertSame('Billing', $body['address']['label']);
    }

    #[Test]
    public function returns200WithNullWhenNoBillingAddressIsSet(): void
    {
        $user = $this->makeUser(id: 61);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        // No billing set for this user.
        $addrRepo->method('findDefaultBillingForUser')->with($user)->willReturn(null);

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
            $this->jsonRequest('GET', '/v3/me/billing-address', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        // 200, not 404 — see GetBillingAddressController docblock.
        // "Not set" is a valid state; null in the response signals it.
        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertArrayHasKey('address', $body);
        self::assertNull($body['address']);
    }

    #[Test]
    public function returns401WhenNoAuthHeader(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/me/billing-address'));
        self::assertSame(401, $response->getStatusCode());
    }

    private function makeBillingAddress(
        User $user,
        int $id,
        ?string $label = null,
    ): Address {
        $addr = new Address(
            user: $user,
            recipientName: 'Billing Recipient',
            recipientPhone: '+971501234567',
            emirate: 'Dubai',
            area: 'Jumeirah',
            label: $label,
        );
        $addr->setDefaultBilling(true);
        $ref = new \ReflectionProperty(Address::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($addr, $id);
        return $addr;
    }
}
