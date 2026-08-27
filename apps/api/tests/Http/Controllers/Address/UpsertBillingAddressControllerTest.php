<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Address;

use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Address\Dto\UpsertBillingAddressInput;
use Bayti\Api\Http\Controllers\Address\UpsertBillingAddressController;
use Bayti\Api\Http\Serializers\AddressSerializer;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(UpsertBillingAddressController::class)]
#[CoversClass(UpsertBillingAddressInput::class)]
#[CoversClass(AddressSerializer::class)]
final class UpsertBillingAddressControllerTest extends HttpTestCase
{
    /** Valid body fragment, complete enough to satisfy required fields. */
    private const VALID_BODY = [
        'recipient_name' => 'Alice Smith',
        'recipient_phone' => '+971501234567',
        'emirate' => 'Dubai',
        'area' => 'Jumeirah',
        'street_address' => 'Beach Road',
        'building_details' => 'Villa 12, third gate',
        'postal_code' => '12345',
        'label' => 'Office',
    ];

    // ---------------------------------------------------------------
    // UPDATE path tests
    // ---------------------------------------------------------------

    #[Test]
    public function returns200AndUpdatesExistingBillingAddress(): void
    {
        $user = $this->makeUser(id: 70);
        $existing = $this->makeBillingAddress($user, id: 700);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('findDefaultBillingForUser')->with($user)->willReturn($existing);
        // Should NOT call save/findAllForUser, UPDATE path uses
        // em->flush() directly on the existing entity.
        $addrRepo->expects(self::never())->method('save');
        $addrRepo->expects(self::never())->method('findAllForUser');

        $em = $this->stubEm(function ($em) use ($userRepo, $addrRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Address::class, $addrRepo],
            ]);
            // UPDATE path calls em->flush() once.
            $em->expects(self::atLeastOnce())->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/billing-address', self::VALID_BODY, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(700, $body['address']['id']);
        // The existing entity's fields should have been overwritten.
        self::assertSame('Alice Smith', $body['address']['recipient_name']);
        self::assertSame('Dubai', $body['address']['emirate']);
    }

    // ---------------------------------------------------------------
    // CREATE path tests
    // ---------------------------------------------------------------

    #[Test]
    public function returns200AndCreatesBillingAddressWhenNoneExists(): void
    {
        $user = $this->makeUser(id: 71);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('findDefaultBillingForUser')->with($user)->willReturn(null);
        // First address ever → both shipping AND billing default get
        // auto-set per the controller's UX safeguard.
        $addrRepo->method('findAllForUser')->with($user)->willReturn([]);
        $addrRepo->expects(self::once())->method('save')->with(
            self::callback(function (Address $a) {
                return $a->isDefaultBilling()
                    && $a->isDefaultShipping()
                    && $a->getRecipientName() === 'Alice Smith';
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
            $this->jsonRequest('PATCH', '/v3/me/billing-address', self::VALID_BODY, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        // 200 (not 201), see UpsertBillingAddressController docblock
        // for why we use 200 for both create + update.
        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('Alice Smith', $body['address']['recipient_name']);
    }

    #[Test]
    public function createPathDoesNotAutoSetShippingDefaultWhenUserAlreadyHasOtherAddresses(): void
    {
        $user = $this->makeUser(id: 72);
        $unrelatedAddr = $this->makeUnrelatedAddress($user, id: 800);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $addrRepo = $this->createMock(AddressRepository::class);
        $addrRepo->method('findDefaultBillingForUser')->with($user)->willReturn(null);
        // User has another address already (not billing-flagged).
        $addrRepo->method('findAllForUser')->with($user)->willReturn([$unrelatedAddr]);
        $addrRepo->expects(self::once())->method('save')->with(
            self::callback(function (Address $a) {
                // Billing flag set, but shipping NOT auto-set (user
                // already had addresses; their shipping default is
                // either elsewhere or intentionally unset).
                return $a->isDefaultBilling() && !$a->isDefaultShipping();
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
            $this->jsonRequest('PATCH', '/v3/me/billing-address', self::VALID_BODY, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Validation tests
    // ---------------------------------------------------------------

    #[Test]
    public function returns422WhenRequiredFieldMissing(): void
    {
        $user = $this->makeUser(id: 73);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        // Body missing recipient_name.
        $body = self::VALID_BODY;
        unset($body['recipient_name']);

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/billing-address', $body, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
        $errorBody = $this->jsonBody($response);
        self::assertSame('VALIDATION_FAILED', $errorBody['error']['code']);
    }

    #[Test]
    public function returns422WhenPhoneIsNotE164(): void
    {
        $user = $this->makeUser(id: 74);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->willReturn($user);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(User::class)->willReturn($userRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $body = self::VALID_BODY;
        $body['recipient_phone'] = '0501234567';  // local format, not E.164

        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/billing-address', $body, [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Auth tests
    // ---------------------------------------------------------------

    #[Test]
    public function returns401WhenNoAuthHeader(): void
    {
        $response = $this->handle(
            $this->jsonRequest('PATCH', '/v3/me/billing-address', self::VALID_BODY)
        );
        self::assertSame(401, $response->getStatusCode());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeBillingAddress(User $user, int $id): Address
    {
        $addr = new Address(
            user: $user,
            recipientName: 'Original Recipient',
            recipientPhone: '+971501112222',
            emirate: 'Sharjah',
            area: 'Al Majaz',
            label: 'Old Billing',
        );
        $addr->setDefaultBilling(true);
        $ref = new \ReflectionProperty(Address::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($addr, $id);
        return $addr;
    }

    /**
     * A non-billing-default address, used to simulate a user who has
     * an existing shipping address but no billing address yet.
     */
    private function makeUnrelatedAddress(User $user, int $id): Address
    {
        $addr = new Address(
            user: $user,
            recipientName: 'Some Shipping',
            recipientPhone: '+971501111111',
            emirate: 'Abu Dhabi',
            area: 'Al Reem',
        );
        // Neither default flag set.
        $ref = new \ReflectionProperty(Address::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($addr, $id);
        return $addr;
    }
}
