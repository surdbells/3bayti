<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Address;

use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Address\Dto\SetDefaultInput;
use Bayti\Api\Http\Controllers\Address\SetDefaultAddressController;
use Bayti\Api\Http\Serializers\AddressSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(SetDefaultAddressController::class)]
#[CoversClass(SetDefaultInput::class)]
#[CoversClass(AddressSerializer::class)]
final class SetDefaultAddressControllerTest extends HttpTestCase
{
    #[Test]
    public function shippingTrueTriggersPromotion(): void
    {
        $user = $this->makeUser(id: 80);
        $addr = $this->makeAddress($user, id: 600);

        [$em, $addrRepo] = $this->stubFor($user, $addr);
        $addrRepo->expects(self::once())->method('setAsDefaultShipping')->with($addr);
        $addrRepo->expects(self::never())->method('setAsDefaultBilling');
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/addresses/600/default', [
                'shipping' => true,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function bothTrueTriggersBothPromotions(): void
    {
        $user = $this->makeUser(id: 81);
        $addr = $this->makeAddress($user, id: 601);

        [$em, $addrRepo] = $this->stubFor($user, $addr);
        $addrRepo->expects(self::once())->method('setAsDefaultShipping');
        $addrRepo->expects(self::once())->method('setAsDefaultBilling');
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/addresses/601/default', [
                'shipping' => true,
                'billing' => true,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function shippingFalseClearsFlagWithoutAutoPromotion(): void
    {
        $user = $this->makeUser(id: 82);
        $addr = $this->makeAddress($user, id: 602, isDefault: true);
        // Pre-state: this is the default. Verify clearing works.
        self::assertTrue($addr->isDefaultShipping());

        [$em, $addrRepo] = $this->stubFor($user, $addr);
        // No setAsDefault* calls — just clearing.
        $addrRepo->expects(self::never())->method('setAsDefaultShipping');
        $addrRepo->expects(self::never())->method('setAsDefaultBilling');
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/addresses/602/default', [
                'shipping' => false,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($addr->isDefaultShipping());
        // Billing flag was unchanged.
        self::assertTrue($addr->isDefaultBilling());
    }

    #[Test]
    public function emptyBodyReturns422(): void
    {
        $user = $this->makeUser(id: 83);
        $addr = $this->makeAddress($user, id: 603);

        [$em, $addrRepo] = $this->stubFor($user, $addr);
        $addrRepo->expects(self::never())->method('setAsDefaultShipping');
        $addrRepo->expects(self::never())->method('setAsDefaultBilling');
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/addresses/603/default', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenAddressBelongsToDifferentUser(): void
    {
        $owner = $this->makeUser(id: 84);
        $intruder = $this->makeUser(id: 85);
        $addr = $this->makeAddress($owner, id: 604);

        [$em, $addrRepo] = $this->stubFor($intruder, $addr);
        $addrRepo->expects(self::never())->method('setAsDefaultShipping');
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($intruder);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/addresses/604/default', [
                'shipping' => true,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenNoAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/addresses/600/default', ['shipping' => true])
        );
        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @return array{0: EntityManagerInterface, 1: AddressRepository}
     */
    private function stubFor(User $authUser, Address $address): array
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($authUser);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('find')->willReturn($address);

        $em = $this->stubEm(function ($em) use ($userRepo, $addrRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Address::class, $addrRepo],
            ]);
        });

        return [$em, $addrRepo];
    }

    private function makeAddress(
        User $user,
        int $id,
        bool $isDefault = false,
    ): Address {
        $addr = new Address(
            user: $user,
            recipientName: 'Test',
            recipientPhone: '+971500000000',
            emirate: 'Dubai',
            area: 'Jumeirah',
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
