<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The order-gate predicates that stop an unapproved / suspended store's
 * product from being ordered:
 *   - Vendor::canSell()     = approved AND active
 *   - Product::isOrderable() = product active AND vendor->canSell()
 *
 * These back the checks added at add-to-cart, cart-merge, cart-resolve,
 * reorder, checkout, and the product-detail endpoints.
 */
#[CoversClass(Vendor::class)]
#[CoversClass(Product::class)]
final class VendorProductOrderableTest extends TestCase
{
    #[Test]
    public function vendorCanSellOnlyWhenApprovedAndActive(): void
    {
        self::assertTrue($this->vendor(Vendor::STATUS_APPROVED, true)->canSell(), 'approved + active');
        self::assertFalse($this->vendor(Vendor::STATUS_PENDING, true)->canSell(), 'pending is not sellable');
        self::assertFalse($this->vendor(Vendor::STATUS_SUSPENDED, true)->canSell(), 'suspended is not sellable');
        self::assertFalse($this->vendor(Vendor::STATUS_APPROVED, false)->canSell(), 'inactive approved is not sellable');
    }

    #[Test]
    public function productIsOrderableOnlyWhenActiveAndVendorCanSell(): void
    {
        $sellable = $this->vendor(Vendor::STATUS_APPROVED, true);

        self::assertTrue($this->product(true, $sellable)->isOrderable(), 'active product of a sellable store');
        self::assertFalse($this->product(false, $sellable)->isOrderable(), 'inactive product is never orderable');

        // The bug: an ACTIVE product of a not-yet-approved or suspended store.
        self::assertFalse(
            $this->product(true, $this->vendor(Vendor::STATUS_PENDING, true))->isOrderable(),
            'active product of a pending (incl. migrated) store is NOT orderable',
        );
        self::assertFalse(
            $this->product(true, $this->vendor(Vendor::STATUS_SUSPENDED, true))->isOrderable(),
            'active product of a suspended store is NOT orderable (the buy-again-after-suspension case)',
        );
    }

    private function vendor(string $status, bool $active): Vendor
    {
        $v = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->set($v, 'status', $status);
        $this->set($v, 'isActive', $active);
        return $v;
    }

    private function product(bool $active, Vendor $vendor): Product
    {
        $p = (new \ReflectionClass(Product::class))->newInstanceWithoutConstructor();
        $this->set($p, 'isActive', $active);
        $this->set($p, 'vendor', $vendor);
        return $p;
    }

    private function set(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
