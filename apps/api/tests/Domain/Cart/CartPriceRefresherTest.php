<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\CartPriceRefresher;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Live cart pricing: CartItem::repriceTo, Cart::priceSignature and the
 * CartPriceRefresher that keeps cart lines at the catalog's current price.
 */
#[CoversClass(CartPriceRefresher::class)]
#[CoversClass(CartItem::class)]
#[CoversClass(Cart::class)]
final class CartPriceRefresherTest extends TestCase
{
    // ----------------------------------------------------------------
    // CartItem::repriceTo
    // ----------------------------------------------------------------

    #[Test]
    public function repriceRecordsThePreviousPrice(): void
    {
        $item = new CartItem($this->product(1, '199.00'), 1, '199.00');

        self::assertTrue($item->repriceTo('249.00'));
        self::assertSame('249.00', $item->getUnitPriceSnapshot());
        self::assertSame('199.00', $item->getPreviousUnitPrice());
        self::assertTrue($item->hasPriceChanged());
    }

    #[Test]
    public function repriceToTheSamePriceIsANoOp(): void
    {
        $item = new CartItem($this->product(1, '199.00'), 1, '199.00');

        self::assertFalse($item->repriceTo('199'));
        self::assertSame('199.00', $item->getUnitPriceSnapshot());
        self::assertFalse($item->hasPriceChanged());
    }

    #[Test]
    public function successiveChangesKeepTheFirstSeenPrice(): void
    {
        $item = new CartItem($this->product(1, '199.00'), 1, '199.00');
        $item->repriceTo('249.00');
        $item->repriceTo('279.00');

        self::assertSame('279.00', $item->getUnitPriceSnapshot());
        self::assertSame('199.00', $item->getPreviousUnitPrice());
    }

    #[Test]
    public function returningToTheSeenPriceClearsTheChange(): void
    {
        $item = new CartItem($this->product(1, '199.00'), 1, '199.00');
        $item->repriceTo('249.00');

        self::assertTrue($item->repriceTo('199.00'));
        self::assertSame('199.00', $item->getUnitPriceSnapshot());
        self::assertNull($item->getPreviousUnitPrice());
        self::assertFalse($item->hasPriceChanged());
    }

    #[Test]
    public function repriceRejectsNegativePrices(): void
    {
        $item = new CartItem($this->product(1, '199.00'), 1, '199.00');

        $this->expectException(\InvalidArgumentException::class);
        $item->repriceTo('-1.00');
    }

    // ----------------------------------------------------------------
    // Cart::priceSignature / hasPriceChanges
    // ----------------------------------------------------------------

    #[Test]
    public function signatureIsOrderIndependentAndIgnoresQuantity(): void
    {
        $a = $this->line(10, $this->product(1, '100.00'), '100.00', qty: 1);
        $b = $this->line(11, $this->product(2, '50.00'), '50.00', qty: 3);

        $cart1 = $this->cart();
        $cart1->addItem($a);
        $cart1->addItem($b);

        $cart2 = $this->cart();
        $cart2->addItem($this->line(11, $this->product(2, '50.00'), '50.00', qty: 9));
        $cart2->addItem($this->line(10, $this->product(1, '100.00'), '100.00', qty: 1));

        self::assertSame($cart1->priceSignature(), $cart2->priceSignature());
        self::assertSame(32, strlen($cart1->priceSignature()));

        $b->setQuantity(5);
        self::assertSame($cart2->priceSignature(), $cart1->priceSignature());
    }

    #[Test]
    public function signatureChangesWhenAPriceChanges(): void
    {
        $item = $this->line(10, $this->product(1, '100.00'), '100.00');
        $cart = $this->cart();
        $cart->addItem($item);
        $before = $cart->priceSignature();

        $item->repriceTo('120.00');

        self::assertNotSame($before, $cart->priceSignature());
        self::assertTrue($cart->hasPriceChanges());
    }

    // ----------------------------------------------------------------
    // CartPriceRefresher
    // ----------------------------------------------------------------

    #[Test]
    public function refreshRepricesStaleLinesAndFlushesPersistedCarts(): void
    {
        $stale = $this->line(10, $this->product(1, '249.00'), '199.00');
        $fresh = $this->line(11, $this->product(2, '50.00'), '50.00');
        $cart = $this->cart(id: 7);
        $cart->addItem($stale);
        $cart->addItem($fresh);
        $this->setProp($cart, 'updatedAt', new \DateTimeImmutable('2026-01-01'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $changed = (new CartPriceRefresher($em))->refresh($cart);

        self::assertSame([$stale], $changed);
        self::assertSame('249.00', $stale->getUnitPriceSnapshot());
        self::assertSame('199.00', $stale->getPreviousUnitPrice());
        self::assertFalse($fresh->hasPriceChanged());
        self::assertSame('299.00', $cart->computeSubtotal());
        self::assertGreaterThan(
            new \DateTimeImmutable('2026-01-02'),
            $cart->getUpdatedAt(),
            'cart updated_at is bumped so the checkout idempotency key moves',
        );
    }

    #[Test]
    public function refreshUsesTheSalePriceWhenOnSale(): void
    {
        $product = $this->product(1, '200.00', salePrice: '150.00');
        $item = $this->line(10, $product, '200.00');
        $cart = $this->cart(id: 7);
        $cart->addItem($item);

        (new CartPriceRefresher($this->createMock(EntityManagerInterface::class)))->refresh($cart);

        self::assertSame('150.00', $item->getUnitPriceSnapshot());
        self::assertSame('200.00', $item->getPreviousUnitPrice());
    }

    #[Test]
    public function refreshRevertsToTheRegularPriceWhenASaleEnds(): void
    {
        $product = $this->product(1, '200.00');
        $item = $this->line(10, $product, '150.00');
        $cart = $this->cart(id: 7);
        $cart->addItem($item);

        (new CartPriceRefresher($this->createMock(EntityManagerInterface::class)))->refresh($cart);

        self::assertSame('200.00', $item->getUnitPriceSnapshot());
        self::assertSame('150.00', $item->getPreviousUnitPrice());
    }

    #[Test]
    public function refreshWithNoChangesDoesNotFlush(): void
    {
        $cart = $this->cart(id: 7);
        $cart->addItem($this->line(10, $this->product(1, '100.00'), '100.00'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        self::assertSame([], (new CartPriceRefresher($em))->refresh($cart));
    }

    #[Test]
    public function refreshLeavesInactiveCartsAlone(): void
    {
        $item = $this->line(10, $this->product(1, '249.00'), '199.00');
        $cart = $this->cart(id: 7);
        $cart->addItem($item);
        $cart->markConverted();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        self::assertSame([], (new CartPriceRefresher($em))->refresh($cart));
        self::assertSame('199.00', $item->getUnitPriceSnapshot());
    }

    #[Test]
    public function refreshOfATransientCartDoesNotFlush(): void
    {
        $item = new CartItem($this->product(1, '249.00'), 1, '199.00');
        $cart = new Cart(legacyCartCode: 'PND');
        $cart->addItem($item);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        self::assertCount(1, (new CartPriceRefresher($em))->refresh($cart));
        self::assertSame('249.00', $item->getUnitPriceSnapshot());
    }

    // ===== Helpers =====

    private function cart(?int $id = null): Cart
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $cart = new Cart(user: $user);
        if ($id !== null) {
            $this->setProp($cart, 'id', $id);
        }
        return $cart;
    }

    private function line(int $id, Product $product, string $snapshot, int $qty = 1): CartItem
    {
        $item = new CartItem($product, $qty, $snapshot);
        $this->setProp($item, 'id', $id);
        return $item;
    }

    private function product(int $id, string $price, ?string $salePrice = null): Product
    {
        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->setProp($product, 'id', $id);
        $this->setProp($product, 'name', 'Product ' . $id);
        $this->setProp($product, 'price', $price);
        $this->setProp($product, 'salePrice', $salePrice);
        return $product;
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
