<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Checkout;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\GiftCard\GiftCardRepository;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Checkout\InitiateCheckoutController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Payment\CheckoutInitiation;
use Bayti\Api\Payment\PaymentGatewayException;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(InitiateCheckoutController::class)]
final class InitiateCheckoutControllerTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The controller constructor takes PaymentGatewayInterface; DI
        // will try to build the real NoonPaymentGateway which needs
        // NOON_* env vars that aren't set in test. Bind a default mock
        // here; tests that need to verify gateway behaviour rebind
        // their own.
        $this->bind(PaymentGatewayInterface::class, $this->createMock(PaymentGatewayInterface::class));
    }
    #[Test]
    public function happyPath(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart, $product] = $this->makeCartWithOneItem($user);
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn($cart);

        $addressRepo = $this->createMock(AddressRepository::class);
        $addressRepo->method('findDefaultBillingForUser')->with($user)->willReturn($billing);
        $addressRepo->method('findDefaultShippingForUser')->with($user)->willReturn($shipping);

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $txRepo->method('findByIdempotencyKey')->willReturn(null);
        $txRepo->method('save');

        // Mock gateway returns successful initiation.
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('initiateCheckout')
            ->willReturnCallback(function (Order $order, string $returnUrl, string $channel): CheckoutInitiation {
                self::assertSame('MOBILE', $channel);
                self::assertStringStartsWith('http://localhost:8080/v3/checkout/return/V3-', $returnUrl);
                self::assertStringNotContainsString('?', $returnUrl); // no query string per Noon
                self::assertSame('299.00', $order->getTotal());
                return new CheckoutInitiation(
                    checkoutUrl: 'https://api-test.noonpayments.com/checkout/abc123',
                    providerOrderRef: '123456789012',
                    rawResponse: [
                        'resultCode' => 0,
                        'result' => [
                            'order' => ['id' => '123456789012'],
                            'checkoutData' => ['postUrl' => 'https://api-test.noonpayments.com/checkout/abc123'],
                        ],
                    ],
                );
            });

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $addressRepo, $txRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Address::class, $addressRepo],
                [PaymentTransaction::class, $txRepo],
            ]);
            // persist + flush noop in test
            $em->method('persist');
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode(), 'Body: ' . (string) $response->getBody());
        $body = $this->jsonBody($response);

        self::assertSame('https://api-test.noonpayments.com/checkout/abc123', $body['checkout_url']);
        self::assertStringStartsWith('V3-', $body['order_reference']);
        self::assertSame('123456789012', $body['provider_order_ref']);
        self::assertFalse($body['idempotent']);
    }

    #[Test]
    public function gatewayFailureRestoresCartToActive(): void
    {
        // A failed payment initiation (e.g. Noon rejects credentials) must
        // not strand the customer's cart. The cart is marked converted in
        // the order transaction; on gateway failure it must be reactivated
        // so the user can retry.
        $user = $this->makeUser(id: 7);
        [$cart, $product] = $this->makeCartWithOneItem($user);
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn($cart);

        $addressRepo = $this->createMock(AddressRepository::class);
        $addressRepo->method('findDefaultBillingForUser')->with($user)->willReturn($billing);
        $addressRepo->method('findDefaultShippingForUser')->with($user)->willReturn($shipping);

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $txRepo->method('findByIdempotencyKey')->willReturn(null);
        $txRepo->method('save');

        // Gateway rejects at INITIATE (mirrors the real Noon 401).
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('initiateCheckout')
            ->willThrowException(
                PaymentGatewayException::auth('Noon INITIATE: HTTP 401 authentication rejected')
            );

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $addressRepo, $txRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Address::class, $addressRepo],
                [PaymentTransaction::class, $txRepo],
            ]);
            $em->method('persist');
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        // Clean 502 (not a 500), and — the point of this test — the cart is
        // active again so the customer can retry once the provider is fixed.
        self::assertSame(502, $response->getStatusCode(), 'Body: ' . (string) $response->getBody());
        self::assertTrue($cart->isActive(), 'Cart must be reactivated after a gateway failure');
    }

    #[Test]
    public function rejectsEmptyCart(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn(null);

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertStringContainsString('empty', strtolower($body['error']['message']));
    }

    #[Test]
    public function rejectsWhenPhoneNotVerified(): void
    {
        // Phone verification is required before placing any order. The
        // guard fires before cart lookup, so only the user repo is needed.
        $user = $this->makeUser(id: 7, phoneVerified: false);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(403, $response->getStatusCode(), 'Body: ' . (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame('AUTH_PHONE_NOT_VERIFIED', $body['error']['code']);
    }

    #[Test]
    public function rejectsWhenNoDefaultBillingAddress(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn($cart);

        $addressRepo = $this->createMock(AddressRepository::class);
        $addressRepo->method('findDefaultBillingForUser')->with($user)->willReturn(null);

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $addressRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Address::class, $addressRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function returnsIdempotentResponseOnSecondCall(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user);
        $billing = $this->makeAddress($user, id: 50);
        $shipping = $this->makeAddress($user, id: 51);

        // Pre-existing tx for this idempotency key.
        $existingOrder = new Order(user: $user, orderReference: 'V3-001', subtotal: '299.00');
        $this->setEntityId($existingOrder, 100);

        $existingTx = new PaymentTransaction(
            order: $existingOrder,
            operation: PaymentTransaction::OPERATION_INITIATE,
            status: 'initiated',
            amount: '299.00',
            idempotencyKey: 'whatever',
            providerOrderRef: '999999999999',
            responsePayload: [
                'result' => [
                    'checkoutData' => ['postUrl' => 'https://api-test.noonpayments.com/checkout/cached'],
                ],
            ],
        );

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn($cart);

        $addressRepo = $this->createMock(AddressRepository::class);
        $addressRepo->method('findDefaultBillingForUser')->with($user)->willReturn($billing);
        $addressRepo->method('findDefaultShippingForUser')->with($user)->willReturn($shipping);

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $txRepo->method('findByIdempotencyKey')->willReturn($existingTx);

        // Gateway must NOT be called.
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('initiateCheckout');

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $addressRepo, $txRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Address::class, $addressRepo],
                [PaymentTransaction::class, $txRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('https://api-test.noonpayments.com/checkout/cached', $body['checkout_url']);
        self::assertTrue($body['idempotent']);
        self::assertSame('V3-001', $body['order_reference']);
    }

    #[Test]
    public function gatewayFailureReturns502(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user);
        $billing = $this->makeAddress($user, id: 50);
        $shipping = $this->makeAddress($user, id: 51);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn($cart);

        $addressRepo = $this->createMock(AddressRepository::class);
        $addressRepo->method('findDefaultBillingForUser')->with($user)->willReturn($billing);
        $addressRepo->method('findDefaultShippingForUser')->with($user)->willReturn($shipping);

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $txRepo->method('findByIdempotencyKey')->willReturn(null);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('initiateCheckout')->willThrowException(
            PaymentGatewayException::network('Connection refused'),
        );

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $addressRepo, $txRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Address::class, $addressRepo],
                [PaymentTransaction::class, $txRepo],
            ]);
            $em->method('persist');
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(502, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('PAYMENT_PROVIDER_ERROR', $body['error']['code']);
    }

    #[Test]
    public function rejectsInvalidChannel(): void
    {
        $user = $this->makeUser(id: 7);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $em = $this->stubEm(function ($em) use ($userRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [
                'channel' => 'TABLET',
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function requiresAuth(): void
    {
        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [])
        );

        self::assertSame(401, $response->getStatusCode());
    }

    // ----------------- gift-card purchase flow -----------------

    #[Test]
    public function giftCardPurchaseHappyPath(): void
    {
        $user = $this->makeUser(id: 7);
        $card = $this->makeGiftCard($user, id: 900, denomination: '500.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $gcRepo = $this->createMock(GiftCardRepository::class);
        $gcRepo->method('find')->with(900)->willReturn($card);

        $addressRepo = $this->createMock(AddressRepository::class);
        $addressRepo->method('findDefaultBillingForUser')->with($user)->willReturn($billing);
        $addressRepo->method('findDefaultShippingForUser')->with($user)->willReturn($shipping);

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $txRepo->method('findByIdempotencyKey')->willReturn(null);
        $txRepo->method('save');

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('initiateCheckout')
            ->willReturnCallback(function (Order $order, string $returnUrl, string $channel): CheckoutInitiation {
                // The synthetic gift-card order's total equals the denomination.
                self::assertSame('500.00', $order->getTotal());
                return new CheckoutInitiation(
                    checkoutUrl: 'https://api-test.noonpayments.com/checkout/gc123',
                    providerOrderRef: '222222222222',
                    rawResponse: [
                        'resultCode' => 0,
                        'result' => [
                            'order' => ['id' => '222222222222'],
                            'checkoutData' => ['postUrl' => 'https://api-test.noonpayments.com/checkout/gc123'],
                        ],
                    ],
                );
            });

        $em = $this->stubEm(function ($em) use ($userRepo, $gcRepo, $addressRepo, $txRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [GiftCard::class, $gcRepo],
                [Address::class, $addressRepo],
                [PaymentTransaction::class, $txRepo],
            ]);
            $em->method('persist');
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [
                'gift_card_purchase_id' => 900,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(200, $response->getStatusCode(), 'Body: ' . (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame('https://api-test.noonpayments.com/checkout/gc123', $body['checkout_url']);
        self::assertSame(900, $body['gift_card_id']);
    }

    /**
     * Regression: a gift-card purchase whose resolved billing/shipping
     * address has no street line previously threw an uncaught
     * \InvalidArgumentException from OrderAddress::__construct, which the
     * error middleware surfaced as a generic 500 INTERNAL_ERROR ("An
     * unexpected error occurred"). The buyer saw the card created but
     * checkout fail. It must instead be a clear, actionable 422 telling
     * them to complete their address.
     */
    #[Test]
    public function giftCardPurchaseWithStreetlessAddressReturns422NotInternalError(): void
    {
        $user = $this->makeUser(id: 7);
        $card = $this->makeGiftCard($user, id: 900, denomination: '500.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true, streetAddress: null);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true, streetAddress: null);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $gcRepo = $this->createMock(GiftCardRepository::class);
        $gcRepo->method('find')->with(900)->willReturn($card);

        $addressRepo = $this->createMock(AddressRepository::class);
        $addressRepo->method('findDefaultBillingForUser')->with($user)->willReturn($billing);
        $addressRepo->method('findDefaultShippingForUser')->with($user)->willReturn($shipping);

        // Mapped so the (unfixed) code reaches the order-creation/snapshot
        // step where the real OrderAddress street throw lives, rather than
        // dying earlier on an unmapped repo. After the fix, the address
        // guard short-circuits before this lookup is ever used.
        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $txRepo->method('findByIdempotencyKey')->willReturn(null);

        // The gateway must never be reached — we fail on the address guard.
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('initiateCheckout');

        $em = $this->stubEm(function ($em) use ($userRepo, $gcRepo, $addressRepo, $txRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [GiftCard::class, $gcRepo],
                [Address::class, $addressRepo],
                [PaymentTransaction::class, $txRepo],
            ]);
            $em->method('persist');
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [
                'gift_card_purchase_id' => 900,
            ], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode(), 'Body: ' . (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
        self::assertStringContainsString('address', strtolower($body['error']['message']));
    }

    /**
     * Symmetric regression for the normal cart checkout: the same
     * snapshotAddress() path is shared, so a street-less address must
     * also produce the actionable 422 here rather than a 500.
     */
    #[Test]
    public function cartCheckoutWithStreetlessAddressReturns422NotInternalError(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user);
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true, streetAddress: null);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true, streetAddress: null);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn($cart);

        $addressRepo = $this->createMock(AddressRepository::class);
        $addressRepo->method('findDefaultBillingForUser')->with($user)->willReturn($billing);
        $addressRepo->method('findDefaultShippingForUser')->with($user)->willReturn($shipping);

        // Mapped so the (unfixed) code reaches the order-creation/snapshot
        // step where the real OrderAddress street throw lives. The fix
        // short-circuits before this lookup is used.
        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $txRepo->method('findByIdempotencyKey')->willReturn(null);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('initiateCheckout');

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $addressRepo, $txRepo) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Address::class, $addressRepo],
                [PaymentTransaction::class, $txRepo],
            ]);
            $em->method('persist');
            $em->method('flush');
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [], [
                'Authorization' => 'Bearer ' . $pair->accessToken,
            ])
        );

        self::assertSame(422, $response->getStatusCode(), 'Body: ' . (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame('VALIDATION_FAILED', $body['error']['code']);
        self::assertStringContainsString('address', strtolower($body['error']['message']));
    }

    // ----------------- helpers -----------------

    /**
     * @return array{0: Cart, 1: Product}
     */
    private function makeCartWithOneItem(User $user): array
    {
        $vendor = $this->makeVendor(id: 5);
        $product = $this->makeProduct(id: 100, name: 'Silk Abaya', price: '299.00', vendor: $vendor);

        $cart = new Cart(user: $user);
        $this->setEntityId($cart, 42);

        $item = new CartItem(
            product: $product,
            quantity: 1,
            unitPriceSnapshot: '299.00',
        );
        $this->setEntityId($item, 555);
        $cart->addItem($item);

        return [$cart, $product];
    }

    private function makeAddress(
        User $user,
        int $id,
        bool $isDefaultBilling = false,
        bool $isDefaultShipping = false,
        ?string $streetAddress = '123 Main St',
    ): Address {
        $address = (new \ReflectionClass(Address::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($address, 'id', $id);
        $this->setEntityProp($address, 'user', $user);
        $this->setEntityProp($address, 'recipientName', 'Sodiq Bello');
        $this->setEntityProp($address, 'recipientPhone', '500000000');
        $this->setEntityProp($address, 'emirate', 'Dubai');
        $this->setEntityProp($address, 'area', 'Bur Dubai');
        // streetAddress is a nullable column; pass null to model an address
        // saved without a street line (the case that triggered the 500).
        $this->setEntityProp($address, 'streetAddress', $streetAddress);
        $this->setEntityProp($address, 'country', 'AE');
        $this->setEntityProp($address, 'isDefaultBilling', $isDefaultBilling);
        $this->setEntityProp($address, 'isDefaultShipping', $isDefaultShipping);
        return $address;
    }

    /**
     * Build a pending-payment gift card owned by $user, with a forced id.
     * Uses the real constructor (which generates a code + sets status to
     * pending_payment), then forces the id like the other entity factories.
     */
    private function makeGiftCard(
        User $user,
        int $id,
        string $denomination = '500.00',
        string $theme = 'birthday',
    ): GiftCard {
        $card = new GiftCard(buyerUser: $user, denomination: $denomination, theme: $theme);
        $this->setEntityId($card, $id);
        return $card;
    }

    private function setEntityId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }

    private function setEntityProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }

    private function makeProduct(int $id, string $name, string $price, Vendor $vendor): Product
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($product, 'id', $id);
        $this->setEntityProp($product, 'name', $name);
        $this->setEntityProp($product, 'price', $price);
        $this->setEntityProp($product, 'isActive', true);
        $this->setEntityProp($product, 'vendor', $vendor);
        return $product;
    }

    private function makeVendor(int $id): Vendor
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($vendor, 'id', $id);
        return $vendor;
    }
}
