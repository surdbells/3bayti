<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Vendor;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Controllers\Vendor\Product\Dto\VendorProductInput;
use Bayti\Api\Http\Controllers\Vendor\Product\ProductInputApplier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ProductInputApplier is the single field-application path shared by the
 * vendor AND admin product create/update endpoints. These lock its semantics
 * (the vendor flow's, byte for byte): non-null fields applied, absent/null kept,
 * sale_price only when the key was present, collection_id switchable (admin
 * passes false), label/category resolved by the caller, and the
 * stock_quantity -> stock_status ordering.
 */
#[CoversClass(ProductInputApplier::class)]
final class ProductInputApplierTest extends TestCase
{
    #[Test]
    public function appliesEveryDtoField(): void
    {
        $product = $this->makeProduct();
        $category = new Category('abayas', 'Abayas');

        (new ProductInputApplier())->apply(
            $product,
            $this->fullInput(),
            $category,
            31,
            salePricePresent: true,
        );

        self::assertSame('Silk Abaya', $product->getName());
        self::assertSame('Hand-finished silk.', $product->getDescription());
        self::assertSame('450.00', $product->getPrice());
        self::assertSame('399.50', $product->getSalePrice());
        self::assertSame('120.25', $product->getCostPerItem());
        self::assertSame(7, $product->getStockQuantity());
        self::assertSame(Product::STOCK_LIMITED, $product->getStockStatus());
        self::assertTrue($product->getAllowOversell());
        self::assertSame(2, $product->getMinOrderQty());
        self::assertSame(9, $product->getMaxOrderQty());
        self::assertSame('https://cdn.example.com/hero.jpg', $product->getPrimaryImageUrl());
        self::assertSame(['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'], $product->getImages());
        self::assertSame(['S', 'M'], $product->getAvailableSizes());
        self::assertSame(['black'], $product->getAvailableColors());
        self::assertTrue($product->isFeatured());
        self::assertTrue($product->isNew());
        self::assertTrue($product->isHot());
        self::assertTrue($product->isSale());
        self::assertTrue($product->requiresExtraMsmt());
        self::assertSame('Bust, waist and length.', $product->getExtraMsmt());
        self::assertSame(['time' => '3-5', 'custom_time' => null, 'note' => 'Gift wrapped'], $product->getDeliveryInfo());
        self::assertSame(Product::STATUS_ACTIVE, $product->getStatus());
        self::assertSame(7, $product->getCollectionId());
        self::assertSame(31, $product->getLabelId());
        self::assertSame($category, $product->getCategory());
    }

    #[Test]
    public function absentFieldsKeepTheStoredValues(): void
    {
        $product = $this->makePopulatedProduct();
        $category = $product->getCategory();

        (new ProductInputApplier())->apply($product, new VendorProductInput(), null, null, salePricePresent: false);

        $this->assertPopulatedProductUnchanged($product, $category);
    }

    #[Test]
    public function explicitFalseFlagsAndZeroStockAreApplied(): void
    {
        // false / 0 are real values, not "absent", they must be written.
        $product = $this->makePopulatedProduct();

        (new ProductInputApplier())->apply($product, new VendorProductInput(
            stock_quantity: 0,
            allow_oversell: false,
            is_featured: false,
            is_new: false,
            is_hot: false,
            is_sale: false,
            requires_extra_msmt: false,
        ), null, null, salePricePresent: false);

        self::assertSame(0, $product->getStockQuantity());
        self::assertFalse($product->getAllowOversell());
        self::assertFalse($product->isFeatured());
        self::assertFalse($product->isNew());
        self::assertFalse($product->isHot());
        self::assertFalse($product->isSale());
        self::assertFalse($product->requiresExtraMsmt());
    }

    #[Test]
    public function salePriceIsOnlyTouchedWhenTheKeyWasPresent(): void
    {
        $applier = new ProductInputApplier();

        $kept = $this->makePopulatedProduct();
        $applier->apply($kept, new VendorProductInput(), null, null, salePricePresent: false);
        self::assertSame('80.00', $kept->getSalePrice(), 'An omitted sale_price keeps the stored discount.');

        $cleared = $this->makePopulatedProduct();
        $applier->apply($cleared, new VendorProductInput(sale_price: null), null, null, salePricePresent: true);
        self::assertNull($cleared->getSalePrice(), 'An explicit null sale_price clears the discount.');

        $set = $this->makePopulatedProduct();
        $applier->apply($set, new VendorProductInput(sale_price: 75), null, null, salePricePresent: true);
        self::assertSame('75.00', $set->getSalePrice());
    }

    #[Test]
    public function collectionIsSkippedWhenTheCallerOptsOut(): void
    {
        $product = $this->makePopulatedProduct();

        (new ProductInputApplier())->apply(
            $product,
            new VendorProductInput(collection_id: 99, name: 'Renamed'),
            null,
            null,
            salePricePresent: false,
            applyCollection: false,
        );

        self::assertSame(3, $product->getCollectionId(), 'Admin writes never touch the storefront collection.');
        self::assertSame('Renamed', $product->getName(), 'The other fields are still applied.');
    }

    #[Test]
    public function explicitStockStatusWinsOverTheQuantityDerivedOne(): void
    {
        // setStockQuantity(0) auto-derives out_of_stock; an explicit status
        // sent alongside must be applied after it and win.
        $product = $this->makeProduct();

        (new ProductInputApplier())->apply($product, new VendorProductInput(
            stock_quantity: 0,
            stock_status: Product::STOCK_BACKORDER,
        ), null, null, salePricePresent: false);

        self::assertSame(0, $product->getStockQuantity());
        self::assertSame(Product::STOCK_BACKORDER, $product->getStockStatus());
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function fullInput(): VendorProductInput
    {
        return new VendorProductInput(
            name: 'Silk Abaya',
            description: 'Hand-finished silk.',
            price: 450,
            sale_price: 399.5,
            cost_per_item: 120.25,
            stock_status: Product::STOCK_LIMITED,
            stock_quantity: 7,
            allow_oversell: true,
            min_order_qty: 2,
            max_order_qty: 9,
            primary_image_url: 'https://cdn.example.com/hero.jpg',
            image_urls: ['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'],
            sizes: ['S', 'M'],
            colors: ['black'],
            category_id: 4,
            status: 'active',
            is_featured: true,
            is_new: true,
            is_hot: true,
            is_sale: true,
            requires_extra_msmt: true,
            extra_msmt: 'Bust, waist and length.',
            collection_id: 7,
            label_id: 31,
            delivery_info: ['time' => '3-5', 'note' => 'Gift wrapped'],
        );
    }

    private function makeProduct(): Product
    {
        $vendor = new Vendor('store-5', 'Store 5', 'store5@example.com');
        $product = new Product($vendor, 'product-100', 'Product 100');
        $product->setPrice('100.00');
        return $product;
    }

    private function makePopulatedProduct(): Product
    {
        $product = $this->makeProduct();
        $product->setDescription('Original description');
        $product->setSalePrice('80.00');
        $product->setCostPerItem('40.00');
        $product->setAllowOversell(true);
        $product->setStockQuantity(12);
        $product->setStockStatus(Product::STOCK_LIMITED);
        $product->setMinOrderQty(2);
        $product->setMaxOrderQty(6);
        $product->setPrimaryImageUrl('https://cdn.example.com/original.jpg');
        $product->setImages(['https://cdn.example.com/original-2.jpg']);
        $product->setAvailableSizes(['L']);
        $product->setAvailableColors(['ivory']);
        $product->setIsFeatured(true);
        $product->setIsNew(true);
        $product->setIsHot(true);
        $product->setIsSale(true);
        $product->setRequiresExtraMsmt(true);
        $product->setExtraMsmt('Shoulder width');
        $product->setDeliveryInfo(['time' => '1-2', 'custom_time' => null, 'note' => null]);
        $product->setStatus(Product::STATUS_ACTIVE);
        $product->setCollectionId(3);
        $product->setLabelId(31);
        $product->setCategory(new Category('dresses', 'Dresses'));
        return $product;
    }

    private function assertPopulatedProductUnchanged(Product $product, ?Category $category): void
    {
        self::assertSame('Product 100', $product->getName());
        self::assertSame('Original description', $product->getDescription());
        self::assertSame('100.00', $product->getPrice());
        self::assertSame('80.00', $product->getSalePrice());
        self::assertSame('40.00', $product->getCostPerItem());
        self::assertSame(12, $product->getStockQuantity());
        self::assertSame(Product::STOCK_LIMITED, $product->getStockStatus());
        self::assertTrue($product->getAllowOversell());
        self::assertSame(2, $product->getMinOrderQty());
        self::assertSame(6, $product->getMaxOrderQty());
        self::assertSame('https://cdn.example.com/original.jpg', $product->getPrimaryImageUrl());
        self::assertSame(['https://cdn.example.com/original-2.jpg'], $product->getImages());
        self::assertSame(['L'], $product->getAvailableSizes());
        self::assertSame(['ivory'], $product->getAvailableColors());
        self::assertTrue($product->isFeatured());
        self::assertTrue($product->isNew());
        self::assertTrue($product->isHot());
        self::assertTrue($product->isSale());
        self::assertTrue($product->requiresExtraMsmt());
        self::assertSame('Shoulder width', $product->getExtraMsmt());
        self::assertSame(['time' => '1-2', 'custom_time' => null, 'note' => null], $product->getDeliveryInfo());
        self::assertSame(Product::STATUS_ACTIVE, $product->getStatus());
        self::assertSame(3, $product->getCollectionId());
        self::assertSame(31, $product->getLabelId());
        self::assertSame($category, $product->getCategory());
    }
}
