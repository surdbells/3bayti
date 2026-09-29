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
use Bayti\Api\Domain\User\Address;
use Bayti\Api\Domain\User\AddressRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Bayti\Api\Http\Controllers\Checkout\Dto\InitiateCheckoutInput;
use Bayti\Api\Http\Controllers\Checkout\InitiateCheckoutController;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Payment\CheckoutInitiation;
use Bayti\Api\Payment\PaymentGatewayInterface;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;

/**
 * POST /v3/checkout/initiate: the live-pricing gate.
 *
 * The cart line was added at 299.00; the vendor has since raised the product
 * to 349.00. Checkout must charge 349.00, and when the client says which
 * prices it showed (expected_price_signature) and they differ, it must stop
 * with 409 CART_PRICES_CHANGED instead of charging.
 */
#[CoversClass(InitiateCheckoutController::class)]
#[CoversClass(InitiateCheckoutInput::class)]
final class InitiateCheckoutPriceGateTest extends HttpTestCase
{
    private ?Order $chargedOrder = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chargedOrder = null;
    }

    #[Test]
    public function withoutASignatureTheOrderIsChargedAtTheCurrentPrice(): void
    {
        $user = $this->makeUser(id: 7);
        $cart = $this->makeCart($user, snapshot: '299.00', currentPrice: '349.00');
        $this->bindCheckout($user, $cart, expectGatewayCall: true);

        $response = $this->initiate($user, []);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertNotNull($this->chargedOrder);
        self::assertSame('349.00', $this->chargedOrder->getSubtotal());
        self::assertSame('369.00', $this->chargedOrder->getTotal()); // + 20.00 delivery
        self::assertSame('349.00', $this->chargedOrder->getItems()->first()->getUnitPrice());
    }

    #[Test]
    public function aStaleSignatureStopsCheckoutWith409AndTheNewPrices(): void
    {
        $user = $this->makeUser(id: 7);
        $cart = $this->makeCart($user, snapshot: '299.00', currentPrice: '349.00');
        $shownSignature = $cart->priceSignature(); // what the client displayed
        $this->bindCheckout($user, $cart, expectGatewayCall: false);

        $response = $this->initiate($user, ['expected_price_signature' => $shownSignature]);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $error = $this->jsonBody($response)['error'];
        self::assertSame('CART_PRICES_CHANGED', $error['code']);
        self::assertSame('349.00', $error['details']['subtotal']);
        self::assertSame($cart->priceSignature(), $error['details']['price_signature']);
        self::assertNotSame($shownSignature, $error['details']['price_signature']);
        self::assertSame([[
            'item_id' => 555,
            'product_id' => 100,
            'name' => 'Silk Abaya',
            'previous_unit_price' => '299.00',
            'unit_price' => '349.00',
            'quantity' => 1,
        ]], $error['details']['items']);
        self::assertNull($this->chargedOrder, 'no payment session is created');
        self::assertTrue($cart->isActive(), 'the cart is not converted');
    }

    #[Test]
    public function confirmingWithTheNewSignatureChargesTheNewPrice(): void
    {
        $user = $this->makeUser(id: 7);
        $cart = $this->makeCart($user, snapshot: '299.00', currentPrice: '349.00');
        // The signature the client receives after the 409 / cart reload.
        $confirmedSignature = $this->makeCart($user, snapshot: '349.00', currentPrice: '349.00')->priceSignature();
        $this->bindCheckout($user, $cart, expectGatewayCall: true);

        $response = $this->initiate($user, ['expected_price_signature' => $confirmedSignature]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('369.00', $this->chargedOrder?->getTotal());
    }

    #[Test]
    public function aMatchingSignatureWithUnchangedPricesProceeds(): void
    {
        $user = $this->makeUser(id: 7);
        $cart = $this->makeCart($user, snapshot: '299.00', currentPrice: '299.00');
        $this->bindCheckout($user, $cart, expectGatewayCall: true);

        $response = $this->initiate($user, ['expected_price_signature' => $cart->priceSignature()]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('319.00', $this->chargedOrder?->getTotal());
    }

    #[Test]
    public function aSaleStartingAfterAddToCartIsChargedAtTheSalePrice(): void
    {
        $user = $this->makeUser(id: 7);
        $cart = $this->makeCart($user, snapshot: '299.00', currentPrice: '299.00', salePrice: '249.00');
        $this->bindCheckout($user, $cart, expectGatewayCall: true);

        $response = $this->initiate($user, []);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame('269.00', $this->chargedOrder?->getTotal());
    }

    // ===== Helpers =====

    /** @param array<string, mixed> $body */
    private function initiate(User $user, array $body): ResponseInterface
    {
        $pair = $this->app->getContainer()->get(JwtService::class)->issueTokenPair($user);
        return $this->handle($this->jsonRequest('POST', '/v3/checkout/initiate', $body, [
            'Authorization' => 'Bearer ' . $pair->accessToken,
        ]));
    }

    private function bindCheckout(User $user, Cart $cart, bool $expectGatewayCall): void
    {
        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findById')->with(7)->willReturn($user);

        $cartRepo = $this->createMock(CartRepository::class);
        $cartRepo->method('findActiveForUser')->with($user)->willReturn($cart);

        $addressRepo = $this->createMock(AddressRepository::class);
        $addressRepo->method('findDefaultBillingForUser')->willReturn($this->makeAddress($user, 50));
        $addressRepo->method('findDefaultShippingForUser')->willReturn($this->makeAddress($user, 51));

        $txRepo = $this->createMock(PaymentTransactionRepository::class);
        $txRepo->method('findByIdempotencyKey')->willReturn(null);

        $gateway = $this->createMock(PaymentGatewayInterface::class);
        $gateway->expects($expectGatewayCall ? self::once() : self::never())
            ->method('initiateCheckout')
            ->willReturnCallback(function (Order $order): CheckoutInitiation {
                $this->chargedOrder = $order;
                return new CheckoutInitiation(
                    checkoutUrl: 'https://api-test.noonpayments.com/checkout/abc123',
                    providerOrderRef: '123456789012',
                    rawResponse: ['resultCode' => 0],
                );
            });

        $em = $this->stubEm(function ($em) use ($userRepo, $cartRepo, $addressRepo, $txRepo): void {
            $em->method('getRepository')->willReturnMap([
                [User::class, $userRepo],
                [Cart::class, $cartRepo],
                [Address::class, $addressRepo],
                [PaymentTransaction::class, $txRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
        $this->bind(PaymentGatewayInterface::class, $gateway);
    }

    private function makeCart(User $user, string $snapshot, string $currentPrice, ?string $salePrice = null): Cart
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setProp($vendor, 'id', 5);
        $this->setProp($vendor, 'status', Vendor::STATUS_APPROVED);
        $this->setProp($vendor, 'isActive', true);

        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($product, 'id', 100);
        $this->setProp($product, 'name', 'Silk Abaya');
        $this->setProp($product, 'price', $currentPrice);
        $this->setProp($product, 'salePrice', $salePrice);
        $this->setProp($product, 'isActive', true);
        $this->setProp($product, 'vendor', $vendor);

        $cart = new Cart(user: $user);
        $this->setProp($cart, 'id', 42);
        $item = new CartItem(product: $product, quantity: 1, unitPriceSnapshot: $snapshot);
        $this->setProp($item, 'id', 555);
        $cart->addItem($item);

        return $cart;
    }

    private function makeAddress(User $user, int $id): Address
    {
        $address = (new \ReflectionClass(Address::class))->newInstanceWithoutConstructor();
        $this->setProp($address, 'id', $id);
        $this->setProp($address, 'user', $user);
        $this->setProp($address, 'recipientName', 'Sodiq Bello');
        $this->setProp($address, 'recipientPhone', '500000000');
        $this->setProp($address, 'emirate', 'Dubai');
        $this->setProp($address, 'area', 'Bur Dubai');
        $this->setProp($address, 'streetAddress', '123 Main St');
        $this->setProp($address, 'country', 'AE');
        $this->setProp($address, 'isDefaultBilling', $id === 50);
        $this->setProp($address, 'isDefaultShipping', $id === 51);
        return $address;
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
