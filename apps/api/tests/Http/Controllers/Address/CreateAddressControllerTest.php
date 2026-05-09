<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Address;

use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Address\CreateAddressController;
use Bayti\Api\Http\Controllers\Address\Dto\CreateAddressInput;
use Bayti\Api\Http\Serializers\AddressSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(CreateAddressController::class)]
#[CoversClass(CreateAddressInput::class)]
#[CoversClass(AddressSerializer::class)]
final class CreateAddressControllerTest extends HttpTestCase
{
    /** Valid body fragment, missing only what each test wants to test. */
    private const VALID_BODY = [
        'recipient_name' => 'Alice Smith',
        'recipient_phone' => '+971501234567',
        'emirate' => 'Dubai',
        'area' => 'Jumeirah',
        'street_address' => 'Beach Road',
        'building_details' => 'Villa 12, third gate',
        'postal_code' => '12345',
        'label' => 'Home',
    ];

    #[Test]
    public function returns201AndCreatesFirstAddressAsDefault(): void
    {
        $user = $this->makeUser(id: 50);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        // No existing addresses
        $addrRepo->method('findAllForUser')->with($user)->willReturn([]);
        // Save called once with flush=true (default)
        $addrRepo->expects(self::once())->method('save')->with(
            self::callback(function (Address $a) {
                // Auto-default applied since this is first address.
                return $a->isDefaultShipping() && $a->isDefaultBilling();
            })
        );

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
            $this->jsonRequest('POST', '/v3/me/addresses', self::VALID_BODY, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(201, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('Alice Smith', $body['address']['recipient_name']);
        self::assertSame('Dubai', $body['address']['emirate']);
        self::assertTrue($body['address']['is_default']);
    }

    #[Test]
    public function nonFirstAddressIsNotDefaultByDefault(): void
    {
        $user = $this->makeUser(id: 51);
        $existing = $this->makeAddress($user, id: 999, isDefault: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('findAllForUser')->willReturn([$existing]);
        $addrRepo->expects(self::once())->method('save')->with(
            self::callback(fn (Address $a) =>
                !$a->isDefaultShipping() && !$a->isDefaultBilling())
        );
        // setAsDefault* should NOT be called since is_default not in body
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
            $this->jsonRequest('POST', '/v3/me/addresses', self::VALID_BODY, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function isDefaultTrueOnNonFirstAddressTriggersPromotion(): void
    {
        $user = $this->makeUser(id: 52);
        $existing = $this->makeAddress($user, id: 998, isDefault: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('findAllForUser')->willReturn([$existing]);
        // First save with flush=false
        $addrRepo->expects(self::once())->method('save');
        // Then setAsDefault* gets called for both shipping + billing
        $addrRepo->expects(self::once())->method('setAsDefaultShipping');
        $addrRepo->expects(self::once())->method('setAsDefaultBilling');

        $em = $this->stubEm(function ($em) use ($userRepo, $addrRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Address::class, $addrRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $body = array_merge(self::VALID_BODY, ['is_default' => true]);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/me/addresses', $body, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(201, $response->getStatusCode());
    }

    #[Test]
    public function rejectsMissingRecipientName(): void
    {
        $user = $this->makeUser(id: 53);
        [$em, $addrRepo] = $this->stubEmWithEmptyAddresses($user);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $body = self::VALID_BODY;
        unset($body['recipient_name']);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/me/addresses', $body, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsMissingEmirate(): void
    {
        $user = $this->makeUser(id: 54);
        [$em, $addrRepo] = $this->stubEmWithEmptyAddresses($user);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $body = self::VALID_BODY;
        unset($body['emirate']);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/me/addresses', $body, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function rejectsInvalidPhoneFormat(): void
    {
        $user = $this->makeUser(id: 55);
        [$em, $addrRepo] = $this->stubEmWithEmptyAddresses($user);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $body = array_merge(self::VALID_BODY, ['recipient_phone' => '0501234567']);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/me/addresses', $body, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function enforcesAddressCountLimit(): void
    {
        $user = $this->makeUser(id: 56);

        // Create 50 fake existing addresses to hit the cap.
        $existing = [];
        for ($i = 1; $i <= 50; $i++) {
            $existing[] = $this->makeAddress($user, id: 1000 + $i);
        }

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('findAllForUser')->willReturn($existing);
        // Save should NOT happen.
        $addrRepo->expects(self::never())->method('save');

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
            $this->jsonRequest('POST', '/v3/me/addresses', self::VALID_BODY, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenNoAuthHeader(): void
    {
        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/me/addresses', self::VALID_BODY)
        );
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function trimsAndStoresOptionalFields(): void
    {
        $user = $this->makeUser(id: 57);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('findAllForUser')->willReturn([]);

        // Capture what gets saved.
        $captured = null;
        $addrRepo->method('save')->willReturnCallback(
            function (Address $a) use (&$captured) {
                $captured = $a;
            }
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $addrRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Address::class, $addrRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $body = array_merge(self::VALID_BODY, [
            'recipient_name' => '   Alice   ',
            'label' => '   Home   ',
        ]);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/me/addresses', $body, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(201, $response->getStatusCode());
        self::assertNotNull($captured);
        self::assertSame('Alice', $captured->getRecipientName());
        self::assertSame('Home', $captured->getLabel());
    }

    /**
     * Helper to wire up the EM with a UserRepo + an empty AddressRepo.
     * @return array{0: EntityManagerInterface, 1: AddressRepository}
     */
    private function stubEmWithEmptyAddresses(User $user): array
    {
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
        return [$em, $addrRepo];
    }

    private function makeAddress(
        User $user,
        int $id,
        bool $isDefault = false,
    ): Address {
        $addr = new Address(
            user: $user,
            recipientName: 'Existing',
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
