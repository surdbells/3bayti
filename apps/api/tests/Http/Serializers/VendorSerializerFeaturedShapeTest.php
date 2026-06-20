<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Serializers;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Serializers\VendorSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for VendorSerializer::featuredShape (sub-phase B of M3.2.X.2).
 *
 * Locks the wire contract for apps/web's Designer Spotlight:
 *   - Exact keys: slug, name, description, rating, rating_count, products
 *   - Product keys: id, slug, image_url, name
 *   - Rounding: rating to 1dp; null preserved as null
 *   - Empty product list: serialized as `[]` not omitted
 *
 * Any change to the keys here is a breaking change for the apps/web
 * FeaturedVendor / FeaturedVendorProduct interfaces — keep them in sync.
 */
#[CoversClass(VendorSerializer::class)]
final class VendorSerializerFeaturedShapeTest extends TestCase
{
    private VendorSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new VendorSerializer();
    }

    #[Test]
    public function happyPathShapeMatchesAppsWebContract(): void
    {
        $vendor = $this->makeVendor('almas-fashion', 'Almas Fashion', 'A luxe label.');
        $products = [
            $this->makeProduct($vendor, 'silk-abaya', 'Silk Abaya', 'https://cdn.example/silk.jpg'),
            $this->makeProduct($vendor, 'velvet-shawl', 'Velvet Shawl', 'https://cdn.example/velvet.jpg'),
        ];

        $shape = $this->serializer->featuredShape($vendor, $products, rating: 4.6, ratingCount: 87);

        // Required top-level keys
        self::assertSame('almas-fashion', $shape['slug']);
        self::assertSame('Almas Fashion', $shape['name']);
        self::assertSame('A luxe label.', $shape['description']);
        self::assertSame(4.6, $shape['rating']);
        self::assertSame(87, $shape['rating_count']);
        self::assertCount(2, $shape['products']);

        // Exact key set. store_id is additive for apps/mobile (legacy
        // vendor id for by-legacy-id navigation); apps/web ignores it.
        self::assertEqualsCanonicalizing(
            ['slug', 'store_id', 'name', 'description', 'rating', 'rating_count', 'products'],
            array_keys($shape),
            'Top-level keys must match the FeaturedVendor contract.'
        );

        // Product shape. legacy_product_id + price are additive for
        // apps/mobile (tap -> single-product via by-legacy-id; thumbnail price).
        self::assertEqualsCanonicalizing(
            ['id', 'legacy_product_id', 'slug', 'image_url', 'name', 'price'],
            array_keys($shape['products'][0]),
            'Product keys must match the FeaturedVendorProduct contract.'
        );
        self::assertSame('silk-abaya', $shape['products'][0]['slug']);
        self::assertSame('Silk Abaya', $shape['products'][0]['name']);
        self::assertSame('https://cdn.example/silk.jpg', $shape['products'][0]['image_url']);
    }

    #[Test]
    public function nullRatingPreservedAsNull(): void
    {
        $vendor = $this->makeVendor('new-vendor', 'New Vendor', null);

        $shape = $this->serializer->featuredShape($vendor, [], rating: null, ratingCount: 0);

        self::assertNull(
            $shape['rating'],
            'Vendor with no approved reviews must serialize rating: null (apps/web hides the chip)'
        );
        self::assertSame(0, $shape['rating_count']);
    }

    #[Test]
    public function ratingIsRoundedToOneDecimalPlace(): void
    {
        $vendor = $this->makeVendor('precise-vendor', 'Precise Vendor', null);

        // SQL AVG can produce arbitrary precision — confirm we round
        $shape = $this->serializer->featuredShape(
            $vendor,
            [],
            rating: 4.66666666666,
            ratingCount: 12,
        );

        self::assertSame(4.7, $shape['rating']);
    }

    #[Test]
    public function ratingRoundsDownAtMidpointFloor(): void
    {
        $vendor = $this->makeVendor('half-vendor', 'Half Vendor', null);

        $shape = $this->serializer->featuredShape(
            $vendor,
            [],
            rating: 3.45,
            ratingCount: 5,
        );

        // PHP's round() defaults to PHP_ROUND_HALF_UP; 3.45 → 3.5
        self::assertSame(3.5, $shape['rating']);
    }

    #[Test]
    public function emptyDescriptionPreservedAsNull(): void
    {
        $vendor = $this->makeVendor('plain-vendor', 'Plain Vendor', null);

        $shape = $this->serializer->featuredShape($vendor, [], rating: null, ratingCount: 0);

        self::assertNull($shape['description']);
    }

    #[Test]
    public function emptyProductListSerializedAsEmptyArray(): void
    {
        $vendor = $this->makeVendor('no-products-vendor', 'No Products Vendor', 'Empty store.');

        $shape = $this->serializer->featuredShape($vendor, [], rating: null, ratingCount: 0);

        self::assertSame([], $shape['products'], 'Empty product list must serialize as [] not omitted');
    }

    #[Test]
    public function productMissingPrimaryImageGetsEmptyString(): void
    {
        $vendor = $this->makeVendor('test-vendor', 'Test Vendor', null);
        // Don't set primary_image_url; default is null
        $product = new Product($vendor, 'no-image-product', 'No Image Product');

        $shape = $this->serializer->featuredShape($vendor, [$product], rating: null, ratingCount: 0);

        self::assertSame(
            '',
            $shape['products'][0]['image_url'],
            'Null primary_image_url falls back to empty string'
        );
    }

    #[Test]
    public function preservesProductOrder(): void
    {
        $vendor = $this->makeVendor('test-vendor', 'Test Vendor', null);
        $p1 = $this->makeProduct($vendor, 'first', 'First Product', 'https://cdn.example/1.jpg');
        $p2 = $this->makeProduct($vendor, 'second', 'Second Product', 'https://cdn.example/2.jpg');
        $p3 = $this->makeProduct($vendor, 'third', 'Third Product', 'https://cdn.example/3.jpg');

        $shape = $this->serializer->featuredShape($vendor, [$p1, $p2, $p3], rating: 4.5, ratingCount: 10);

        self::assertSame('first', $shape['products'][0]['slug']);
        self::assertSame('second', $shape['products'][1]['slug']);
        self::assertSame('third', $shape['products'][2]['slug']);
    }

    #[Test]
    public function adminShapeNowIncludesIsFeatured(): void
    {
        $vendor = $this->makeVendor('test-vendor', 'Test Vendor', null);
        $vendor->setFeatured(true);

        $shape = $this->serializer->adminShape($vendor);

        self::assertArrayHasKey(
            'is_featured',
            $shape,
            'adminShape must surface is_featured for admin tools'
        );
        self::assertTrue($shape['is_featured']);
    }

    #[Test]
    public function adminShapeIsFeaturedDefaultsToFalse(): void
    {
        $vendor = $this->makeVendor('test-vendor', 'Test Vendor', null);
        // Don't set featured; verify the default surfaces correctly

        $shape = $this->serializer->adminShape($vendor);

        self::assertFalse($shape['is_featured']);
    }

    #[Test]
    public function publicShapeStillDoesNotLeakIsFeatured(): void
    {
        // publicShape is used by /v3/vendors + /v3/vendors/{slug}. Adding
        // is_featured to those endpoints would tell scrapers which
        // vendors are curation-promoted. Keep it strictly admin-only.
        $vendor = $this->makeVendor('test-vendor', 'Test Vendor', null);
        $vendor->setFeatured(true);

        $shape = $this->serializer->publicShape($vendor);

        self::assertArrayNotHasKey(
            'is_featured',
            $shape,
            'publicShape must not surface is_featured (admin curation is private)'
        );
    }

    /**
     * Helper: construct a Vendor with the minimal required fields.
     */
    private function makeVendor(string $slug, string $name, ?string $description): Vendor
    {
        $v = new Vendor($slug, $name, 'vendor@example.test');
        $v->setDescription($description);
        return $v;
    }

    /**
     * Helper: construct a Product with optional primary_image_url.
     */
    private function makeProduct(
        Vendor $vendor,
        string $slug,
        string $name,
        ?string $primaryImageUrl,
    ): Product {
        $product = new Product($vendor, $slug, $name);
        if ($primaryImageUrl !== null) {
            $product->setPrimaryImageUrl($primaryImageUrl);
        }
        return $product;
    }
}
