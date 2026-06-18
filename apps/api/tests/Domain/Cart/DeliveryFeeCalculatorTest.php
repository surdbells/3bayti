<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Cart;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Cart\CartItem;
use Bayti\Api\Domain\Cart\DeliveryFeeCalculator;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Delivery fee = 20 (first store) + 15 × (additional stores).
 */
#[CoversClass(DeliveryFeeCalculator::class)]
final class DeliveryFeeCalculatorTest extends TestCase
{
    private DeliveryFeeCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new DeliveryFeeCalculator();
    }

    #[Test]
    public function policyByStoreCount(): void
    {
        self::assertSame('0.00', $this->calc->forStoreCount(0));
        self::assertSame('20.00', $this->calc->forStoreCount(1));
        self::assertSame('35.00', $this->calc->forStoreCount(2));
        self::assertSame('50.00', $this->calc->forStoreCount(3));
        self::assertSame('65.00', $this->calc->forStoreCount(4));
    }

    #[Test]
    public function fiveProductsAcrossThreeStoresIsFifty(): void
    {
        $cart = $this->cartWithVendorIds([1, 1, 2, 3, 3]); // 5 items, 3 stores
        self::assertSame('50.00', $this->calc->forCart($cart));
    }

    #[Test]
    public function fiveProductsAcrossTwoStoresIsThirtyFive(): void
    {
        $cart = $this->cartWithVendorIds([1, 1, 1, 2, 2]); // 2 stores
        self::assertSame('35.00', $this->calc->forCart($cart));
    }

    #[Test]
    public function fiveProductsAcrossFourStoresIsSixtyFive(): void
    {
        $cart = $this->cartWithVendorIds([1, 2, 3, 4, 4]); // 4 stores
        self::assertSame('65.00', $this->calc->forCart($cart));
    }

    #[Test]
    public function singleStoreIsTwenty(): void
    {
        $cart = $this->cartWithVendorIds([7, 7, 7]); // 1 store
        self::assertSame('20.00', $this->calc->forCart($cart));
    }

    #[Test]
    public function emptyCartIsFree(): void
    {
        $cart = $this->cartWithVendorIds([]);
        self::assertSame('0.00', $this->calc->forCart($cart));
    }

    // ===== Helpers =====

    /**
     * Build a Cart with one item per entry in $vendorIds, each item's product
     * belonging to the vendor with that id.
     *
     * @param list<int> $vendorIds
     */
    private function cartWithVendorIds(array $vendorIds): Cart
    {
        $user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $this->setId($user, 100);

        $cart = new Cart(user: $user);

        $vendors = [];
        foreach ($vendorIds as $vid) {
            if (!isset($vendors[$vid])) {
                $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
                $this->setId($vendor, $vid);
                $vendors[$vid] = $vendor;
            }

            $product = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
            $this->setProp($product, 'vendor', $vendors[$vid]);

            $item = (new \ReflectionClass(CartItem::class))->newInstanceWithoutConstructor();
            $this->setProp($item, 'product', $product);
            $cart->addItem($item);
        }

        return $cart;
    }

    private function setId(object $entity, int $id): void
    {
        $this->setProp($entity, 'id', $id);
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
