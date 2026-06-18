<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for Cart::reactivate() — the inverse of markConverted(),
 * used to restore a cart when an order created from it could not be
 * handed to the payment provider. A failed payment initiation must
 * never strand the customer's cart.
 */
#[CoversClass(Cart::class)]
final class CartReactivateTest extends TestCase
{
    #[Test]
    public function convertedCartIsRestoredToActive(): void
    {
        $cart = $this->makeCart();
        $cart->markConverted();
        self::assertFalse($cart->isActive());

        $cart->reactivate();

        self::assertTrue($cart->isActive());
    }

    #[Test]
    public function reactivateBumpsUpdatedAtSoRetryGetsAFreshIdempotencyKey(): void
    {
        $cart = $this->makeCart();
        $cart->markConverted();
        // Pin updated_at to the distant past, then reactivate.
        $this->setUpdatedAt($cart, new \DateTimeImmutable('2020-01-01 00:00:00'));

        $cart->reactivate();

        self::assertGreaterThan(
            new \DateTimeImmutable('2020-01-01 00:00:00'),
            $cart->getUpdatedAt(),
            'reactivate() must touch updated_at',
        );
    }

    #[Test]
    public function reactivatingAnActiveCartThrows(): void
    {
        $cart = $this->makeCart(); // already active
        $this->expectException(\DomainException::class);
        $cart->reactivate();
    }

    #[Test]
    public function reactivatingAnArchivedCartThrows(): void
    {
        $cart = $this->makeCart();
        $cart->markArchived();
        $this->expectException(\DomainException::class);
        $cart->reactivate();
    }

    // ===== Helpers =====

    private function makeCart(): Cart
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $idRef = new \ReflectionProperty(User::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($user, 100);

        $cart = new Cart(user: $user);
        $item = (new \ReflectionClass(CartItem::class))->newInstanceWithoutConstructor();
        $cart->addItem($item);

        return $cart;
    }

    private function setUpdatedAt(Cart $cart, \DateTimeImmutable $when): void
    {
        $ref = new \ReflectionProperty(Cart::class, 'updatedAt');
        $ref->setAccessible(true);
        $ref->setValue($cart, $when);
    }
}
