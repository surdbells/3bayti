<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Cart::isAbandoned() (M3.2.X.11-B).
 *
 * The helper centralises the abandonment-eligibility policy for
 * use in CartAbandonmentFinder. Three conditions must hold:
 *   1. status = 'active'
 *   2. items is non-empty
 *   3. updated_at < (now - threshold)
 */
#[CoversClass(Cart::class)]
final class CartIsAbandonedTest extends TestCase
{
    #[Test]
    public function activeCartWithItemsAndStaleUpdatedAtIsAbandoned(): void
    {
        $cart = $this->makeCart();
        $this->setUpdatedAt($cart, new \DateTimeImmutable('2026-05-17 08:00:00'));
        // Set updated_at to 25h ago at the time of the check (24h threshold)
        $now = new \DateTimeImmutable('2026-05-18 09:00:00');

        self::assertTrue($cart->isAbandoned($now, new \DateInterval('PT24H')));
    }

    #[Test]
    public function recentlyUpdatedActiveCartIsNotAbandoned(): void
    {
        $cart = $this->makeCart();
        // updated_at 1 hour ago, threshold 24h → NOT abandoned
        $this->setUpdatedAt($cart, new \DateTimeImmutable('2026-05-18 08:00:00'));
        $now = new \DateTimeImmutable('2026-05-18 09:00:00');

        self::assertFalse($cart->isAbandoned($now, new \DateInterval('PT24H')));
    }

    #[Test]
    public function convertedCartIsNeverAbandoned(): void
    {
        $cart = $this->makeCart();
        $this->setUpdatedAt($cart, new \DateTimeImmutable('2020-01-01 00:00:00'));
        $cart->markConverted();
        // Even with very stale updated_at, converted carts are out
        $now = new \DateTimeImmutable('2026-05-18 09:00:00');

        self::assertFalse($cart->isAbandoned($now, new \DateInterval('PT24H')));
    }

    #[Test]
    public function archivedCartIsNeverAbandoned(): void
    {
        $cart = $this->makeCart();
        $this->setUpdatedAt($cart, new \DateTimeImmutable('2020-01-01 00:00:00'));
        $cart->markArchived();
        $now = new \DateTimeImmutable('2026-05-18 09:00:00');

        self::assertFalse($cart->isAbandoned($now, new \DateInterval('PT24H')));
    }

    #[Test]
    public function emptyCartIsNotAbandonedEvenIfStale(): void
    {
        $cart = $this->makeEmptyCart();
        $this->setUpdatedAt($cart, new \DateTimeImmutable('2020-01-01 00:00:00'));
        $now = new \DateTimeImmutable('2026-05-18 09:00:00');

        self::assertFalse($cart->isAbandoned($now, new \DateInterval('PT24H')));
    }

    #[Test]
    public function exactBoundaryNotAbandoned(): void
    {
        // updated_at exactly at the cutoff → cutoff is strict less-than
        $cart = $this->makeCart();
        $now = new \DateTimeImmutable('2026-05-18 09:00:00');
        $this->setUpdatedAt($cart, $now->sub(new \DateInterval('PT24H')));

        self::assertFalse($cart->isAbandoned($now, new \DateInterval('PT24H')));
    }

    // ===== Helpers =====

    private function makeCart(): Cart
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $idRef = new \ReflectionProperty(User::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($user, 100);

        $cart = new Cart(user: $user);

        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $vIdRef = new \ReflectionProperty(Vendor::class, 'id');
        $vIdRef->setAccessible(true);
        $vIdRef->setValue($vendor, 5);

        $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $pIdRef = new \ReflectionProperty(Product::class, 'id');
        $pIdRef->setAccessible(true);
        $pIdRef->setValue($product, 200);

        $item = (new \ReflectionClass(CartItem::class))->newInstanceWithoutConstructor();
        $cart->addItem($item);

        return $cart;
    }

    private function makeEmptyCart(): Cart
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $idRef = new \ReflectionProperty(User::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($user, 100);
        return new Cart(user: $user);
    }

    private function setUpdatedAt(Cart $cart, \DateTimeImmutable $when): void
    {
        $ref = new \ReflectionProperty(Cart::class, 'updatedAt');
        $ref->setAccessible(true);
        $ref->setValue($cart, $when);
    }
}
