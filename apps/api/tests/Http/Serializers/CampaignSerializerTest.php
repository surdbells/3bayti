<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Serializers;

use Bayti\Api\Domain\Catalog\Campaign;
use Bayti\Api\Domain\Catalog\CampaignItem;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Serializers\CampaignSerializer;
use Bayti\Api\Http\Serializers\ProductSerializer;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CampaignSerializer (no DB):
 *   - the effective discount (item override vs campaign default)
 *   - campaign_price = product price with the discount applied
 *   - 0% discount → null campaign_price
 *   - stock fields pass through
 *   - campaign meta is present in the shape
 *
 * Pricing is derived from ProductSerializer::listShape() (AED here), so
 * these also confirm the discount is applied to the shaped price.
 */
#[CoversClass(CampaignSerializer::class)]
final class CampaignSerializerTest extends TestCase
{
    private function makeProduct(string $price, ?string $salePrice = null, int $stock = 10): Product
    {
        $vendor = new Vendor('test-vendor', 'Test Vendor', 'vendor@test.com');
        $vIdRef = new \ReflectionProperty(Vendor::class, 'id');
        $vIdRef->setAccessible(true);
        $vIdRef->setValue($vendor, 5);

        $product = new Product($vendor, 'test-product', 'Test Product');
        $idRef = new \ReflectionProperty(Product::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($product, 100);
        $product->setPrice($price);
        if ($salePrice !== null) {
            $product->setSalePrice($salePrice);
        }
        $product->setStockQuantity($stock);
        return $product;
    }

    private function makeCampaign(int $defaultDiscount): Campaign
    {
        $now = new DateTimeImmutable('2026-06-15 12:00:00');
        $c   = new Campaign('anniv', 'anniversary', 'Anniversary Sale', $now->modify('-1 hour'), $now->modify('+1 day'));
        $c->setDiscountPercent($defaultDiscount);
        $c->setSubtitle('Up to half off');
        return $c;
    }

    private function addItem(Campaign $c, Product $p, ?int $discount = null, ?int $stockTotal = null, ?int $stockRemaining = null): CampaignItem
    {
        $item = new CampaignItem($c, $p);
        $item->setDiscountPercent($discount);
        $item->setStockTotal($stockTotal);
        $item->setStockRemaining($stockRemaining);
        $c->addItem($item);
        return $item;
    }

    #[Test]
    public function itemAppliesCampaignDefaultDiscount(): void
    {
        $c = $this->makeCampaign(20);
        $this->addItem($c, $this->makeProduct('365.00'));

        $shape = (new CampaignSerializer())->shape($c, new ProductSerializer());

        self::assertCount(1, $shape['items']);
        $item = $shape['items'][0];
        self::assertSame(20, $item['discount_percent']);
        self::assertSame(292.00, $item['campaign_price']['amount']); // 365 * 0.8
        self::assertSame('AED', $item['campaign_price']['currency']);
        self::assertSame(365.00, $item['product']['price']['amount']);
    }

    #[Test]
    public function itemOverrideBeatsCampaignDefault(): void
    {
        $c = $this->makeCampaign(20);
        $this->addItem($c, $this->makeProduct('365.00'), discount: 50);

        $shape = (new CampaignSerializer())->shape($c, new ProductSerializer());
        $item  = $shape['items'][0];

        self::assertSame(50, $item['discount_percent']);
        self::assertSame(182.50, $item['campaign_price']['amount']); // 365 * 0.5
    }

    #[Test]
    public function zeroDiscountYieldsNullCampaignPrice(): void
    {
        $c = $this->makeCampaign(0);
        $this->addItem($c, $this->makeProduct('365.00'));

        $shape = (new CampaignSerializer())->shape($c, new ProductSerializer());
        $item  = $shape['items'][0];

        self::assertSame(0, $item['discount_percent']);
        self::assertNull($item['campaign_price']);
    }

    #[Test]
    public function stockFieldsPassThrough(): void
    {
        $c = $this->makeCampaign(10);
        $this->addItem($c, $this->makeProduct('100.00'), stockTotal: 50, stockRemaining: 12);

        $shape = (new CampaignSerializer())->shape($c, new ProductSerializer());
        $item  = $shape['items'][0];

        self::assertSame(50, $item['stock_total']);
        self::assertSame(12, $item['stock_remaining']);
        self::assertSame(90.00, $item['campaign_price']['amount']); // 100 * 0.9
    }

    #[Test]
    public function shapeIncludesCampaignMeta(): void
    {
        $c = $this->makeCampaign(15);
        $shape = (new CampaignSerializer())->shape($c, new ProductSerializer());

        self::assertSame('anniv', $shape['slug']);
        self::assertSame('anniversary', $shape['type']);
        self::assertSame('Anniversary Sale', $shape['title']);
        self::assertSame('Up to half off', $shape['subtitle']);
        self::assertSame(15, $shape['discount_percent']);
        self::assertArrayHasKey('starts_at', $shape);
        self::assertArrayHasKey('ends_at', $shape);
        self::assertSame([], $shape['items']);
    }
}
