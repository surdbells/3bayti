<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Serializers;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Serializers\ProductSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * vendorDetailShape backs the vendor AND admin product editors
 * (GET /v3/vendor/products/{id}, GET /v3/admin/products/{id}). It must expose
 * every editable field under the write-DTO (VendorProductInput) name, or the
 * editor reads undefined and re-saves a default. It used to omit
 * cost_per_item / is_featured / requires_extra_msmt / extra_msmt and only had
 * the order limits as *_quantity, so every edit reset max order qty, cost,
 * featured and made-to-measure.
 *
 * @see ProductSerializer::vendorDetailShape()
 */
#[CoversClass(ProductSerializer::class)]
final class ProductSerializerVendorDetailShapeTest extends TestCase
{
    #[Test]
    public function exposesEditorFieldsUnderTheDtoNames(): void
    {
        $p = $this->makeProduct();
        $p->setCostPerItem('120.25');
        $p->setAllowOversell(true);
        $p->setStockQuantity(7);
        $p->setStockStatus(Product::STOCK_LIMITED);
        $p->setMinOrderQty(2);
        $p->setMaxOrderQty(9);
        $p->setIsFeatured(true);
        $p->setIsNew(true);
        $p->setIsHot(true);
        $p->setIsSale(true);
        $p->setRequiresExtraMsmt(true);
        $p->setExtraMsmt('Bust, waist and length.');

        $shape = (new ProductSerializer())->vendorDetailShape($p);

        self::assertSame('120.25', $shape['cost_per_item']);
        self::assertSame(7, $shape['stock_quantity']);
        self::assertSame(Product::STOCK_LIMITED, $shape['stock_status']);
        self::assertTrue($shape['allow_oversell']);
        self::assertSame(2, $shape['min_order_qty']);
        self::assertSame(9, $shape['max_order_qty']);
        self::assertTrue($shape['is_featured']);
        self::assertTrue($shape['is_new']);
        self::assertTrue($shape['is_hot']);
        self::assertTrue($shape['is_sale']);
        self::assertTrue($shape['requires_extra_msmt']);
        self::assertSame('Bust, waist and length.', $shape['extra_msmt']);
    }

    #[Test]
    public function orderLimitAliasesCarryTheSameValuesAsTheLegacyKeys(): void
    {
        $p = $this->makeProduct();
        $p->setMinOrderQty(3);
        $p->setMaxOrderQty(12);

        $shape = (new ProductSerializer())->vendorDetailShape($p);

        self::assertSame(3, $shape['min_order_quantity']);
        self::assertSame(12, $shape['max_order_quantity']);
        self::assertSame($shape['min_order_quantity'], $shape['min_order_qty']);
        self::assertSame($shape['max_order_quantity'], $shape['max_order_qty']);
    }

    #[Test]
    public function unsetFieldsSerialiseAsNullOrFalseNotMissing(): void
    {
        $shape = (new ProductSerializer())->vendorDetailShape($this->makeProduct());

        foreach (['cost_per_item', 'extra_msmt', 'min_order_qty', 'max_order_qty', 'min_order_quantity', 'max_order_quantity'] as $key) {
            self::assertArrayHasKey($key, $shape);
            self::assertNull($shape[$key], "{$key} must be null when unset");
        }
        foreach (['is_featured', 'is_new', 'is_hot', 'is_sale', 'requires_extra_msmt', 'allow_oversell'] as $key) {
            self::assertArrayHasKey($key, $shape);
            self::assertFalse($shape[$key], "{$key} must be false when unset");
        }
        self::assertSame(0, $shape['stock_quantity']);
        self::assertSame(Product::STOCK_IN, $shape['stock_status']);
    }

    #[Test]
    public function keepsEveryPreExistingKey(): void
    {
        $p = $this->makeProduct();
        $p->setCategory(new Category('abayas', 'Abayas'));

        $shape = (new ProductSerializer())->vendorDetailShape($p);

        foreach ([
            // vendorManageShape
            'id', 'slug', 'name', 'vendor_id', 'store_name', 'sku', 'image', 'category', 'category_id',
            'category_slug', 'status', 'price', 'price_formatted', 'quantity', 'stock_quantity',
            'stock_status', 'primary_image', 'sale_price', 'in_stock', 'label_id', 'collection_id', 'created_at',
            // detail additions that existed before
            'description', 'images', 'sizes', 'colors', 'delivery_info',
            'min_order_quantity', 'max_order_quantity', 'allow_oversell',
        ] as $key) {
            self::assertArrayHasKey($key, $shape, "Existing key {$key} must be kept");
        }
        self::assertSame('Abayas', $shape['category']);
        self::assertSame(100.0, $shape['price']);
    }

    private function makeProduct(): Product
    {
        $vendor = new Vendor('store-5', 'Store 5', 'store5@example.com');
        $product = new Product($vendor, 'product-100', 'Product 100');
        $product->setPrice('100.00');
        return $product;
    }
}
