<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Serializers;

use Bayti\Api\Http\Serializers\OrderSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * OrderSerializer::scopeToVendor — a vendor's order shape must show ONLY their
 * own line items and the total of THOSE items, never the whole order's
 * subtotal / delivery fee / total, other stores' items, or the order-level
 * promo. (Vendors were seeing the whole-order total and thinking they'd sold
 * more than they're paid.)
 */
#[CoversClass(OrderSerializer::class)]
final class OrderSerializerVendorScopeTest extends TestCase
{
    #[Test]
    public function scopesItemsAndMoneyToTheVendor(): void
    {
        $serializer = new OrderSerializer();

        // A whole-order shape as listShape()/detailShape() produces for a
        // 2-store order: store 5 sells 150 + 55; store 6 sells 90. Whole order
        // subtotal 295, delivery 33, discount 10, total 328, order-level promo.
        $shape = [
            'subtotal' => '295.00',
            'delivery_fee' => '33.00',
            'discount' => '10.00',
            'total' => '328.00',
            'applied_promo' => ['code' => 'SAVE10', 'discount_amount' => '10.00'],
            'items' => [
                ['vendor_id' => 5, 'subtotal' => '150.00', 'product_name' => 'Silk Abaya'],
                ['vendor_id' => 5, 'subtotal' => '55.00', 'product_name' => 'Gold Earrings'],
                ['vendor_id' => 6, 'subtotal' => '90.00', 'product_name' => 'Leather Bag'],
            ],
        ];

        $scoped = $serializer->scopeToVendor($shape, array_flip([5]));

        // Only store 5's items survive.
        self::assertCount(2, $scoped['items']);
        self::assertSame([5, 5], array_column($scoped['items'], 'vendor_id'));

        // Money reflects ONLY store 5's items (150 + 55 = 205): no delivery,
        // no order-level discount/promo, not the whole-order subtotal/total.
        self::assertSame('205.00', $scoped['subtotal']);
        self::assertSame('205.00', $scoped['total']);
        self::assertSame('0.00', $scoped['delivery_fee']);
        self::assertSame('0.00', $scoped['discount']);
        self::assertNull($scoped['applied_promo']);
    }

    #[Test]
    public function vendorWithNoItemsInTheOrderGetsZeroTotals(): void
    {
        $serializer = new OrderSerializer();
        $shape = [
            'subtotal' => '90.00', 'delivery_fee' => '33.00', 'discount' => '0.00',
            'total' => '123.00', 'applied_promo' => null,
            'items' => [['vendor_id' => 6, 'subtotal' => '90.00']],
        ];

        $scoped = $serializer->scopeToVendor($shape, array_flip([5]));

        self::assertSame([], $scoped['items']);
        self::assertSame('0.00', $scoped['subtotal']);
        self::assertSame('0.00', $scoped['total']);
        self::assertSame('0.00', $scoped['delivery_fee']);
    }
}
