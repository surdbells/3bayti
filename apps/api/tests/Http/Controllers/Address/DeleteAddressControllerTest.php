<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Address;

use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Address\DeleteAddressController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(DeleteAddressController::class)]
final class DeleteAddressControllerTest extends HttpTestCase
{
    #[Test]
    public function returns204AndCallsRemove(): void
    {
        $user = $this->makeUser(id: 70);
        $addr = $this->makeAddress($user, id: 500);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('find')->willReturn($addr);
        $addrRepo->expects(self::once())->method('remove')->with($addr);
        // Not the default — no auto-promotion path.
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
            $this->jsonRequest('DELETE', '/v3/me/addresses/500', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function autoPromotesNextAddressWhenDeletingDefault(): void
    {
        $user = $this->makeUser(id: 71);
        $defaultAddr = $this->makeAddress($user, id: 501, isDefault: true);
        $other = $this->makeAddress($user, id: 502);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('find')->willReturn($defaultAddr);
        $addrRepo->expects(self::once())->method('remove')->with($defaultAddr);
        // After delete, $other is the only remaining address.
        $addrRepo->method('findAllForUser')->willReturn([$other]);
        // Both promotion methods called since deleted addr was default for both.
        $addrRepo->expects(self::once())->method('setAsDefaultShipping')->with($other);
        $addrRepo->expects(self::once())->method('setAsDefaultBilling')->with($other);

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
            $this->jsonRequest('DELETE', '/v3/me/addresses/501', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function noPromotionWhenLastAddressDeleted(): void
    {
        $user = $this->makeUser(id: 72);
        $addr = $this->makeAddress($user, id: 503, isDefault: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('find')->willReturn($addr);
        $addrRepo->expects(self::once())->method('remove')->with($addr);
        // No remaining addresses — nothing to promote.
        $addrRepo->method('findAllForUser')->willReturn([]);
        $addrRepo->expects(self::never())->method('setAsDefaultShipping');
        $addrRepo->expects(self::never())->method('setAsDefaultBilling');

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
            $this->jsonRequest('DELETE', '/v3/me/addresses/503', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenAddressBelongsToDifferentUser(): void
    {
        $owner = $this->makeUser(id: 73);
        $intruder = $this->makeUser(id: 74);
        $addr = $this->makeAddress($owner, id: 504);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($intruder);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('find')->willReturn($addr);
        // Should NOT be deleted.
        $addrRepo->expects(self::never())->method('remove');

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
            $this->jsonRequest('DELETE', '/v3/me/addresses/504', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenNoAuth(): void
    {
        $response = $this->handle($this->jsonRequest('DELETE', '/v3/me/addresses/500'));
        self::assertSame(401, $response->getStatusCode());
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
