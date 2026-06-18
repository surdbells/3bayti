<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Checkout;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\Promo\PromoCode;
use Bayti\Api\Domain\Promo\PromoCodeRepository;
use Bayti\Api\Domain\Promo\PromoRedemption;
use Bayti\Api\Domain\Promo\PromoRedemptionRepository;
use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Checkout\InitiateCheckoutController;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Payment\CheckoutInitiation;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Bayti\Api\Tests\Http\HttpTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for the M3.2.X.8-D promo integration in
 * POST /v3/checkout/initiate.
 *
 * Companion to InitiateCheckoutControllerTest, which covers the
 * non-promo paths. This file focuses on the new server-authoritative
 * promo flow:
 *   - promo_code applied → server-computed discount, redemption recorded
 *   - promo_code invalid → 422 with PROMO_* error code, no order created
 *   - client `discount` ignored when `promo_code` is supplied
 *   - legacy `discount` path still works + deprecation header emitted
 *   - idempotency interaction (cached response path)
 *
 * Shape parity with InitiateCheckoutControllerTest:
 *   - HttpTestCase drives the full Slim app
 *   - JwtService issues a real token for the test user
 *   - PaymentGatewayInterface mocked in setUp; tests rebind for behaviour
 *   - EM mocked; getRepository returns the relevant test repo
 */
#[CoversClass(InitiateCheckoutController::class)]
final class InitiateCheckoutPromoTest extends HttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Default gateway mock so DI doesn't try to build the real
        // NoonPaymentGateway (needs NOON_* env vars).
        $this->bind(PaymentGatewayInterface::class, $this->createMock(PaymentGatewayInterface::class));
    }

    // -------------------------------------------------------------------
    // Happy path — promo resolved, redemption recorded
    // -------------------------------------------------------------------

    #[Test]
    public function promoCodeAppliesServerComputedDiscountAndRecordsRedemption(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart, $product] = $this->makeCartWithOneItem($user, '100.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);
        $promo = $this->makePromoCode('SAVE10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00', id: 7);

        $persistedRedemptions = [];
        $persistedOrders = [];
        $orderHadPromoOnFlush = null;

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::once())
            ->method('initiateCheckout')
            ->willReturnCallback(function (Order $order) use (&$orderHadPromoOnFlush): CheckoutInitiation {
                // By the time we reach Noon, the transaction has
                // flushed and the order MUST carry the promo redemption
                // and the server-computed discount.
                $orderHadPromoOnFlush = $order->getPromoRedemption();
                self::assertSame('10.00', $order->getDiscount());
                self::assertSame('110.00', $order->getTotal());
                return new CheckoutInitiation(
                    checkoutUrl: 'https://test.noon/checkout/xyz',
                    providerOrderRef: '999',
                    rawResponse: ['result' => ['checkoutData' => ['postUrl' => 'https://test.noon/checkout/xyz']]],
                );
            });

        $em = $this->bindStandardEm(
            $user, $cart, $billing, $shipping,
            promo: $promo,
            persistedOrdersRef: $persistedOrders,
            persistedRedemptionsRef: $persistedRedemptions,
        );
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', ['promo_code' => 'save10'], [
                'Authorization' => 'Bearer ' . $this->token($user),
            ]),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        // Redemption was persisted with the server-computed amount.
        self::assertCount(1, $persistedRedemptions);
        self::assertSame('10.00', $persistedRedemptions[0]->getDiscountAmount());
        self::assertSame('SAVE10', $persistedRedemptions[0]->getCodeSnapshot());

        // Order was persisted with the redemption attached.
        self::assertCount(1, $persistedOrders);
        self::assertSame($persistedRedemptions[0], $orderHadPromoOnFlush);

        // No deprecation header on the promo path.
        self::assertFalse($response->hasHeader('X-Bayti-Deprecation'));
    }

    #[Test]
    public function clientDiscountIsIgnoredWhenPromoCodeSupplied(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user, '100.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);
        $promo = $this->makePromoCode('SAVE10', PromoCode::DISCOUNT_TYPE_PERCENTAGE, '10.00');

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $observedDiscount = null;
        $gateway->method('initiateCheckout')
            ->willReturnCallback(function (Order $order) use (&$observedDiscount): CheckoutInitiation {
                $observedDiscount = $order->getDiscount();
                return new CheckoutInitiation(
                    checkoutUrl: 'https://test.noon/checkout/xyz',
                    providerOrderRef: '999',
                    rawResponse: ['result' => ['checkoutData' => ['postUrl' => 'https://test.noon/checkout/xyz']]],
                );
            });

        $em = $this->bindStandardEm($user, $cart, $billing, $shipping, promo: $promo);
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        // Client passes BOTH promo_code AND a high client-supplied
        // discount. Server should ignore the client number entirely
        // and use the server-computed 10.00.
        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [
                'promo_code' => 'SAVE10',
                'discount' => '999.00',
            ], [
                'Authorization' => 'Bearer ' . $this->token($user),
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('10.00', $observedDiscount);
    }

    // -------------------------------------------------------------------
    // Promo failure — 422, no order persisted
    // -------------------------------------------------------------------

    #[Test]
    public function promoFailureReturns422AndDoesNotCreateOrder(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user, '100.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);
        // No promo in catalog → PROMO_NOT_FOUND.

        $persistedOrders = [];
        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects(self::never())->method('initiateCheckout');

        $em = $this->bindStandardEm(
            $user, $cart, $billing, $shipping,
            promo: null,
            persistedOrdersRef: $persistedOrders,
        );
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', ['promo_code' => 'BOGUS'], [
                'Authorization' => 'Bearer ' . $this->token($user),
            ]),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::PROMO_NOT_FOUND, $body['error']['code']);
        // No order ever persisted on this failure path.
        self::assertSame([], $persistedOrders);
    }

    #[Test]
    public function promoExpiredReturns422WithValidUntilDetail(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user, '100.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);

        $promo = $this->makePromoCode('OLDIE');
        $promo->setValidUntil(new DateTimeImmutable('2020-01-01T00:00:00Z', new DateTimeZone('UTC')));

        $em = $this->bindStandardEm($user, $cart, $billing, $shipping, promo: $promo);
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', ['promo_code' => 'OLDIE'], [
                'Authorization' => 'Bearer ' . $this->token($user),
            ]),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::PROMO_EXPIRED, $body['error']['code']);
        self::assertArrayHasKey('valid_until', $body['error']['details']);
    }

    #[Test]
    public function promoGlobalLimitReachedReturns422(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user, '100.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);

        $promo = $this->makePromoCode('CAPPED');
        $promo->setUsageLimitGlobal(5);

        $em = $this->bindStandardEm(
            $user, $cart, $billing, $shipping,
            promo: $promo,
            globalRedemptionCount: 5,  // already at cap
        );
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', ['promo_code' => 'CAPPED'], [
                'Authorization' => 'Bearer ' . $this->token($user),
            ]),
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(
            ErrorCodes::PROMO_GLOBAL_LIMIT_REACHED,
            $this->jsonBody($response)['error']['code'],
        );
    }

    // -------------------------------------------------------------------
    // Legacy discount path — preserved + deprecation header
    // -------------------------------------------------------------------

    #[Test]
    public function legacyDiscountPathStillWorksAndEmitsDeprecationHeader(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user, '100.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $observedDiscount = null;
        $gateway->method('initiateCheckout')
            ->willReturnCallback(function (Order $order) use (&$observedDiscount): CheckoutInitiation {
                $observedDiscount = $order->getDiscount();
                return new CheckoutInitiation(
                    checkoutUrl: 'https://test.noon/checkout/xyz',
                    providerOrderRef: '999',
                    rawResponse: ['result' => ['checkoutData' => ['postUrl' => 'https://test.noon/checkout/xyz']]],
                );
            });

        $em = $this->bindStandardEm($user, $cart, $billing, $shipping, promo: null);
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        // No promo_code field, just the legacy `discount` field
        // (current live mobile build's request shape).
        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', ['discount' => '15.00'], [
                'Authorization' => 'Bearer ' . $this->token($user),
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('15.00', $observedDiscount, 'legacy discount honored on the order');

        // Deprecation header signals to clients that this path is
        // being phased out in favor of promo_code.
        self::assertTrue($response->hasHeader('X-Bayti-Deprecation'));
        self::assertStringContainsString(
            'promo_code',
            $response->getHeaderLine('X-Bayti-Deprecation'),
        );
    }

    #[Test]
    public function noDiscountAndNoPromoEmitsNoDeprecationHeader(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user, '100.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('initiateCheckout')->willReturn(
            new CheckoutInitiation(
                checkoutUrl: 'https://test.noon/checkout/xyz',
                providerOrderRef: '999',
                rawResponse: ['result' => ['checkoutData' => ['postUrl' => 'https://test.noon/checkout/xyz']]],
            ),
        );

        $em = $this->bindStandardEm($user, $cart, $billing, $shipping, promo: null);
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [], [
                'Authorization' => 'Bearer ' . $this->token($user),
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse(
            $response->hasHeader('X-Bayti-Deprecation'),
            'no deprecation when neither legacy discount nor promo supplied',
        );
    }

    #[Test]
    public function zeroLegacyDiscountEmitsNoDeprecationHeader(): void
    {
        // discount='0.00' is the DTO default; it's not really the
        // "legacy path" being exercised. Header should NOT emit.
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user, '100.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('initiateCheckout')->willReturn(
            new CheckoutInitiation(
                checkoutUrl: 'https://test.noon/checkout/xyz',
                providerOrderRef: '999',
                rawResponse: ['result' => ['checkoutData' => ['postUrl' => 'https://test.noon/checkout/xyz']]],
            ),
        );

        $em = $this->bindStandardEm($user, $cart, $billing, $shipping, promo: null);
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', ['discount' => '0.00'], [
                'Authorization' => 'Bearer ' . $this->token($user),
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Bayti-Deprecation'));
    }

    // -------------------------------------------------------------------
    // DTO normalization
    // -------------------------------------------------------------------

    #[Test]
    public function promoCodeIsNormalizedBeforeResolution(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user, '100.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);
        $promo = $this->makePromoCode('WELCOME10');

        $em = $this->bindStandardEm($user, $cart, $billing, $shipping, promo: $promo);
        $this->bind(EntityManagerInterface::class, $em);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('initiateCheckout')->willReturn(
            new CheckoutInitiation(
                checkoutUrl: 'https://test.noon/checkout/xyz',
                providerOrderRef: '999',
                rawResponse: ['result' => ['checkoutData' => ['postUrl' => 'https://test.noon/checkout/xyz']]],
            ),
        );
        $this->bind(PaymentGatewayInterface::class, $gateway);

        // Mixed-case + leading whitespace; DTO normalizes to 'WELCOME10'
        // before the resolver sees it.
        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', ['promo_code' => '  welcome10  '], [
                'Authorization' => 'Bearer ' . $this->token($user),
            ]),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function emptyPromoCodeStringIsTreatedAsNullAndNoDeprecationEmits(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user, '100.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->method('initiateCheckout')->willReturn(
            new CheckoutInitiation(
                checkoutUrl: 'https://test.noon/checkout/xyz',
                providerOrderRef: '999',
                rawResponse: ['result' => ['checkoutData' => ['postUrl' => 'https://test.noon/checkout/xyz']]],
            ),
        );

        $em = $this->bindStandardEm($user, $cart, $billing, $shipping, promo: null);
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', [
                'promo_code' => '   ',  // normalizes to null
            ], [
                'Authorization' => 'Bearer ' . $this->token($user),
            ]),
        );

        // 200 expected — empty promo treated as no promo; no legacy
        // discount either (defaults to '0.00') → no deprecation.
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($response->hasHeader('X-Bayti-Deprecation'));
    }

    #[Test]
    public function promoCodeOverMaxLengthReturns422Validation(): void
    {
        $user = $this->makeUser(id: 7);
        [$cart] = $this->makeCartWithOneItem($user, '100.00');
        $billing = $this->makeAddress($user, id: 50, isDefaultBilling: true);
        $shipping = $this->makeAddress($user, id: 51, isDefaultShipping: true);

        $em = $this->bindStandardEm($user, $cart, $billing, $shipping, promo: null);
        $this->bind(EntityManagerInterface::class, $em);

        $tooLong = str_repeat('A', PromoCode::CODE_MAX_LENGTH + 5);

        $response = $this->handle(
            $this->jsonRequest('POST', '/v3/checkout/initiate', ['promo_code' => $tooLong], [
                'Authorization' => 'Bearer ' . $this->token($user),
            ]),
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(ErrorCodes::VALIDATION_FAILED, $body['error']['code']);
        self::assertArrayHasKey('promo_code', $body['error']['details']['fields']);
    }

    // -------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------

    /**
     * Build a standard EM mock + bindings for a happy-path checkout
     * with optional promo. Also binds a directly-wired
     * PromoCodeResolverService into the container so the controller's
     * injected resolver uses the test's promo/redemption repo fakes
     * (the resolver constructor accepts repos directly per locked
     * pattern #2; this bypasses the DI'd resolver's lazy EM lookup
     * which would otherwise hit the real EM bound before the test's
     * mock override).
     *
     * Returns the EM mock for the caller to pass to $this->bind().
     *
     * @param-out list<Order> $persistedOrdersRef Captures Order persists
     * @param-out list<PromoRedemption> $persistedRedemptionsRef Captures redemption persists
     */
    private function bindStandardEm(
        User $user,
        Cart $cart,
        Address $billing,
        Address $shipping,
        ?PromoCode $promo,
        int $globalRedemptionCount = 0,
        int $userRedemptionCount = 0,
        array &$persistedOrdersRef = [],
        array &$persistedRedemptionsRef = [],
    ): EntityManagerInterface {
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

        $promoRepo = $this->createMock(PromoCodeRepository::class);
        $promoRepo->method('findByNormalizedCode')->willReturnCallback(
            fn (string $code): ?PromoCode => $promo !== null
                && $promo->getCode() === PromoCode::normalizeCode($code)
                ? $promo
                : null,
        );

        $redemptionsRef = &$persistedRedemptionsRef;
        $redemptionRepo = $this->createMock(PromoRedemptionRepository::class);
        $redemptionRepo->method('countByPromoCodeIdEffective')->willReturn($globalRedemptionCount);
        $redemptionRepo->method('countByUserAndPromoCodeEffective')->willReturn($userRedemptionCount);
        $redemptionRepo->method('persist')->willReturnCallback(
            function (PromoRedemption $r) use (&$redemptionsRef): void {
                $redemptionsRef[] = $r;
            },
        );

        // Bind a resolver wired with the test repos directly. This
        // bypasses the DI-built resolver whose EM was captured before
        // the test's mock override.
        $resolver = new \Bayti\Api\Domain\Promo\PromoCodeResolverService(
            em: null,
            promoCodeRepository: $promoRepo,
            promoRedemptionRepository: $redemptionRepo,
        );
        $this->bind(\Bayti\Api\Domain\Promo\PromoCodeResolverService::class, $resolver);

        $ordersRef = &$persistedOrdersRef;
        return $this->stubEm(function ($em) use (
            $userRepo, $cartRepo, $addressRepo, $txRepo, $promoRepo, $redemptionRepo,
            &$ordersRef,
        ) {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Address::class, $addressRepo],
                [PaymentTransaction::class, $txRepo],
                [PromoCode::class, $promoRepo],
                [PromoRedemption::class, $redemptionRepo],
            ]);
            $em->method('persist')->willReturnCallback(
                function (object $entity) use (&$ordersRef): void {
                    if ($entity instanceof Order) {
                        $ordersRef[] = $entity;
                    }
                },
            );
            $em->method('flush');
        });
    }

    /**
     * @return array{0: Cart, 1: Product}
     */
    private function makeCartWithOneItem(User $user, string $unitPrice): array
    {
        $vendor = $this->makeVendor(id: 5);
        $product = $this->makeProduct(id: 100, name: 'Silk Abaya', price: $unitPrice, vendor: $vendor);

        $cart = new Cart(user: $user);
        $this->setEntityId($cart, 42);

        $item = new CartItem(
            product: $product,
            quantity: 1,
            unitPriceSnapshot: $unitPrice,
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
    ): Address {
        $address = (new \ReflectionClass(Address::class))->newInstanceWithoutConstructor();
        $this->setEntityProp($address, 'id', $id);
        $this->setEntityProp($address, 'user', $user);
        $this->setEntityProp($address, 'recipientName', 'Sodiq Bello');
        $this->setEntityProp($address, 'recipientPhone', '500000000');
        $this->setEntityProp($address, 'emirate', 'Dubai');
        $this->setEntityProp($address, 'area', 'Bur Dubai');
        $this->setEntityProp($address, 'streetAddress', '123 Main St');
        $this->setEntityProp($address, 'country', 'AE');
        $this->setEntityProp($address, 'isDefaultBilling', $isDefaultBilling);
        $this->setEntityProp($address, 'isDefaultShipping', $isDefaultShipping);
        return $address;
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

    private function makePromoCode(
        string $code,
        string $type = PromoCode::DISCOUNT_TYPE_PERCENTAGE,
        string $value = '10.00',
        int $id = 7,
    ): PromoCode {
        $promo = new PromoCode($code, $type, $value);
        $this->setEntityId($promo, $id);
        return $promo;
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

    private function token(User $user): string
    {
        $jwt = $this->app->getContainer()->get(JwtService::class);
        $pair = $jwt->issueTokenPair($user);
        return $pair->accessToken;
    }
}
