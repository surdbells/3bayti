<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Serializers;

use Bayti\Api\Ai\TryOn\NullVirtualTryOnProvider;
use Bayti\Api\Ai\TryOn\VirtualTryOnProviderInterface;
use Bayti\Api\Ai\TryOn\VirtualTryOnResult;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\TryOn\TryOnGarmentEligibility;
use Bayti\Api\Http\Serializers\ProductSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Public try_on_enabled (detailShape, the PDP "Try it on" CTA) is the stored
 * flag AND orderable + in stock AND the live provider AND an eligible garment
 * image, so the CTA never shows when the start would be refused (TRYON_DISABLED,
 * not available) or the job would fail (GIF / external / missing image). Admin
 * shapes carry the RAW flag plus
 * try_on_available and try_on_eligibility, so products can be prepared while
 * TRYON_ENABLED is still off.
 */
#[CoversClass(ProductSerializer::class)]
#[CoversClass(TryOnGarmentEligibility::class)]
final class ProductSerializerTryOnTest extends TestCase
{
    private const BASE = 'https://cdn.test/uploads';

    private ?string $prevUploadsUrl = null;

    protected function setUp(): void
    {
        $this->prevUploadsUrl = $_ENV['UPLOADS_PUBLIC_URL'] ?? null;
        $_ENV['UPLOADS_PUBLIC_URL'] = self::BASE;
    }

    protected function tearDown(): void
    {
        if ($this->prevUploadsUrl === null) {
            unset($_ENV['UPLOADS_PUBLIC_URL']);
        } else {
            $_ENV['UPLOADS_PUBLIC_URL'] = $this->prevUploadsUrl;
        }
    }

    #[Test]
    public function publicFlagIsTrueOnlyWhenFlagProviderAndImageAreAllGood(): void
    {
        $shape = $this->serializer(true)->detailShape($this->product(true, self::BASE . '/products/v/a.jpg'));

        self::assertTrue($shape['try_on_enabled']);
    }

    #[Test]
    public function publicFlagIsFalseWhenTheProviderIsOff(): void
    {
        $product = $this->product(true, self::BASE . '/products/v/a.jpg');

        self::assertFalse($this->serializer(false)->detailShape($product)['try_on_enabled']);
        self::assertFalse(
            (new ProductSerializer(null, new NullVirtualTryOnProvider()))->detailShape($product)['try_on_enabled'],
        );
        // No provider wired at all (bare construction) = unavailable.
        self::assertFalse((new ProductSerializer())->detailShape($product)['try_on_enabled']);
    }

    #[Test]
    public function publicFlagIsFalseForAnIneligibleGarmentImageEvenWithTheProviderOn(): void
    {
        $serializer = $this->serializer(true);

        foreach ([
            'gif' => self::BASE . '/products/v/a.gif',
            'external' => 'https://images.example.com/a.jpg',
            'none' => null,
        ] as $case => $url) {
            self::assertFalse(
                $serializer->detailShape($this->product(true, $url))['try_on_enabled'],
                $case,
            );
        }
    }

    #[Test]
    public function publicFlagIsFalseWhenTheStoredFlagIsOff(): void
    {
        $shape = $this->serializer(true)->detailShape($this->product(false, self::BASE . '/products/v/a.png'));

        self::assertFalse($shape['try_on_enabled']);
    }

    /**
     * StartTryOnController refuses (and the worker fails) a product that is not
     * orderable or out of stock, so the public CTA must not show for one either,
     * even with the flag, a live provider and a good image.
     */
    #[Test]
    public function publicFlagIsFalseWhenTheProductCannotBeOrderedOrIsOutOfStock(): void
    {
        $serializer = $this->serializer(true);
        $url = self::BASE . '/products/v/a.jpg';

        $outOfStock = $this->product(true, $url);
        $outOfStock->setStockStatus(Product::STOCK_OUT);

        $draft = $this->product(true, $url);
        $draft->setStatus(Product::STATUS_DRAFT);

        $suspendedStore = $this->product(true, $url);
        $suspendedStore->getVendor()->suspend();

        $inactiveStore = $this->product(true, $url);
        $inactiveStore->getVendor()->setActive(false);

        $pendingStore = new Product(new Vendor('pending', 'Pending', 'pending@example.test'), 'p', 'P');
        $pendingStore->setStatus(Product::STATUS_ACTIVE);
        $pendingStore->setPrice('300.00');
        $pendingStore->setTryOnEnabled(true);
        $pendingStore->setPrimaryImageUrl($url);

        foreach ([
            'out of stock' => $outOfStock,
            'draft product' => $draft,
            'suspended store' => $suspendedStore,
            'inactive store' => $inactiveStore,
            'unapproved store' => $pendingStore,
        ] as $case => $product) {
            self::assertFalse($serializer->detailShape($product)['try_on_enabled'], $case);
            // Admins still see the stored flag and that the image is fine.
            self::assertTrue($serializer->adminDetailShape($product)['try_on_enabled'], $case);
            self::assertTrue($serializer->adminDetailShape($product)['try_on_eligibility']['eligible'], $case);
        }

        // Oversell counts as in stock (Product::isInStock), so the CTA shows.
        $oversold = $this->product(true, $url);
        $oversold->setStockStatus(Product::STOCK_OUT);
        $oversold->setAllowOversell(true);
        self::assertTrue($serializer->detailShape($oversold)['try_on_enabled']);
    }

    #[Test]
    public function adminDetailShapeCarriesTheRawFlagWhileTheProviderIsOff(): void
    {
        $product = $this->product(true, self::BASE . '/products/v/a.gif');

        $shape = $this->serializer(false)->adminDetailShape($product);

        self::assertTrue($shape['try_on_enabled'], 'admin sees the stored flag, not the gated one');
        self::assertFalse($shape['try_on_available']);
        self::assertSame(
            ['eligible' => false, 'reason' => TryOnGarmentEligibility::REASON_UNSUPPORTED_FORMAT],
            $shape['try_on_eligibility'],
        );
        // Still the full editor shape underneath.
        self::assertArrayHasKey('cost_per_item', $shape);
        self::assertArrayHasKey('max_order_qty', $shape);
    }

    #[Test]
    public function adminTryOnFieldsReportAvailabilityAndEligibility(): void
    {
        $fields = $this->serializer(true)->adminTryOnFields($this->product(false, self::BASE . '/products/v/a.webp'));

        self::assertSame([
            'try_on_enabled' => false,
            'try_on_available' => true,
            'try_on_eligibility' => ['eligible' => true, 'reason' => null],
        ], $fields);
    }

    #[Test]
    public function vendorShapesDoNotExposeTryOnFields(): void
    {
        $product = $this->product(true, self::BASE . '/products/v/a.jpg');
        $serializer = $this->serializer(true);

        self::assertArrayNotHasKey('try_on_enabled', $serializer->vendorDetailShape($product));
        self::assertArrayNotHasKey('try_on_available', $serializer->vendorDetailShape($product));
        self::assertArrayNotHasKey('try_on_enabled', $serializer->listShape($product));
    }

    private function serializer(bool $providerEnabled): ProductSerializer
    {
        return new ProductSerializer(null, $this->provider($providerEnabled));
    }

    private function provider(bool $enabled): VirtualTryOnProviderInterface
    {
        return new class ($enabled) implements VirtualTryOnProviderInterface {
            public function __construct(private readonly bool $enabled)
            {
            }

            public function isEnabled(): bool
            {
                return $this->enabled;
            }

            public function generate(
                string $personBytes,
                string $personMime,
                string $garmentBytes,
                string $garmentMime,
                string $garmentPrompt,
            ): VirtualTryOnResult {
                return VirtualTryOnResult::empty();
            }
        };
    }

    /** An orderable (active product, approved store), in-stock product. */
    private function product(bool $tryOnEnabled, ?string $primaryImageUrl): Product
    {
        $vendor = new Vendor('store', 'Store', 'store@example.test');
        $vendor->approve();
        $product = new Product($vendor, 'rose-abaya', 'Rose Abaya');
        $product->setStatus(Product::STATUS_ACTIVE);
        $product->setPrice('300.00');
        $product->setTryOnEnabled($tryOnEnabled);
        $product->setPrimaryImageUrl($primaryImageUrl);

        return $product;
    }
}
