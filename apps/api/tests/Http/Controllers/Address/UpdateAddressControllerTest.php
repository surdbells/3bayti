<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Address;

use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Address\Dto\UpdateAddressInput;
use Bayti\Api\Http\Controllers\Address\UpdateAddressController;
use Bayti\Api\Http\Serializers\AddressSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(UpdateAddressController::class)]
#[CoversClass(UpdateAddressInput::class)]
#[CoversClass(AddressSerializer::class)]
final class UpdateAddressControllerTest extends HttpTestCase
{
    private const VALID_BODY = [
        'recipient_name' => 'Updated Recipient',
        'recipient_phone' => '+971559999999',
        'emirate' => 'Sharjah',
        'area' => 'Al Majaz',
        'street_address' => 'New Street',
        'building_details' => 'Tower 5, Apt 808',
        'postal_code' => '54321',
        'label' => 'Office',
    ];

    #[Test]
    public function returns200WithUpdatedAddress(): void
    {
        $user = $this->makeUser(id: 60);
        $addr = $this->makeAddress($user, id: 400);

        [$em] = $this->stubFor($user, $addr);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/addresses/400', self::VALID_BODY, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('Updated Recipient', $body['address']['recipient_name']);
        self::assertSame('Sharjah', $body['address']['emirate']);
        self::assertSame('Office', $body['address']['label']);

        // Entity is mutated in place.
        self::assertSame('Updated Recipient', $addr->getRecipientName());
        self::assertSame('Sharjah', $addr->getEmirate());
    }

    #[Test]
    public function returns404WhenAddressNotFound(): void
    {
        $user = $this->makeUser(id: 61);
        [$em, $addrRepo] = $this->stubFor($user, null);
        $addrRepo->method('find')->willReturn(null);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/addresses/999', self::VALID_BODY, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenAddressBelongsToDifferentUser(): void
    {
        $owner = $this->makeUser(id: 62);
        $intruder = $this->makeUser(id: 63);
        $addr = $this->makeAddress($owner, id: 401);

        [$em] = $this->stubFor($intruder, $addr);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($intruder);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/addresses/401', self::VALID_BODY, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function rejectsMissingRequiredField(): void
    {
        $user = $this->makeUser(id: 64);
        $addr = $this->makeAddress($user, id: 402);

        [$em] = $this->stubFor($user, $addr);
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $body = self::VALID_BODY;
        unset($body['recipient_name']);

        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/addresses/402', $body, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function returns401WhenNoAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('PUT', '/v3/me/addresses/100', self::VALID_BODY)
        );
        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * @return array{0: EntityManagerInterface, 1: AddressRepository}
     */
    private function stubFor(User $authUser, ?Address $address): array
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($authUser);

        $addrRepo = $this->createMock(AddressRepository::class);
        if ($address !== null) {
            $addrRepo->method('find')->willReturn($address);
        }

        $em = $this->stubEm(function ($em) use ($userRepo, $addrRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Address::class, $addrRepo],
            ]);
        });

        return [$em, $addrRepo];
    }

    private function makeAddress(User $user, int $id): Address
    {
        $addr = new Address(
            user: $user,
            recipientName: 'Original',
            recipientPhone: '+971501112222',
            emirate: 'Dubai',
            area: 'Jumeirah',
        );
        $ref = new \ReflectionProperty(Address::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($addr, $id);
        return $addr;
    }
}
