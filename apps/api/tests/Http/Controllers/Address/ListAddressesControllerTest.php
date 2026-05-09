<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Address;

use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Address\ListAddressesController;
use Bayti\Api\Http\Serializers\AddressSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ListAddressesController::class)]
#[CoversClass(AddressSerializer::class)]
final class ListAddressesControllerTest extends HttpTestCase
{
    #[Test]
    public function returns200WithAddressList(): void
    {
        $user = $this->makeUser(id: 30);

        $addr1 = $this->makeAddress($user, id: 100, label: 'Home', isDefault: true);
        $addr2 = $this->makeAddress($user, id: 101, label: 'Office', isDefault: false);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('findAllForUser')->with($user)->willReturn([$addr1, $addr2]);

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
            $this->jsonRequest('GET', '/v3/me/addresses', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());

        $body = $this->jsonBody($response);
        self::assertCount(2, $body['addresses']);
        self::assertSame(100, $body['addresses'][0]['id']);
        self::assertSame('Home', $body['addresses'][0]['label']);
        self::assertTrue($body['addresses'][0]['is_default']);
        self::assertSame(101, $body['addresses'][1]['id']);
        self::assertFalse($body['addresses'][1]['is_default']);
    }

    #[Test]
    public function returns200WithEmptyListWhenUserHasNoAddresses(): void
    {
        $user = $this->makeUser(id: 31);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('findAllForUser')->willReturn([]);

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
            $this->jsonRequest('GET', '/v3/me/addresses', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['addresses']);
    }

    #[Test]
    public function returns401WhenNoAuthHeader(): void
    {
        $response = $this->handle($this->jsonRequest('GET', '/v3/me/addresses'));
        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * Helper: build an Address attached to a user, with a specific id.
     */
    private function makeAddress(
        User $user,
        int $id,
        ?string $label = null,
        bool $isDefault = false,
    ): Address {
        $addr = new Address(
            user: $user,
            recipientName: 'Test Recipient',
            recipientPhone: '+971501234567',
            emirate: 'Dubai',
            area: 'Jumeirah',
            label: $label,
        );
        if ($isDefault) {
            $addr->setDefaultShipping(true);
            $addr->setDefaultBilling(true);
        }
        $ref = new \ReflectionProperty(Address::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($addr, $id);
        return $addr;
    }
}
