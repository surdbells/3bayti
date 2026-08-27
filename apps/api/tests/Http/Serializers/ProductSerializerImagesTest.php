<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Serializers;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Serializers\ProductSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * listShape must carry the FULL image gallery (not only primary_image) so
 * list-driven carousels, notably the mobile explore page, can swipe/dot over
 * every image of a product. Regression guard for the explore "single image" bug.
 *
 * @see ProductSerializer::listShape()
 */
#[CoversClass(ProductSerializer::class)]
final class ProductSerializerImagesTest extends TestCase
{
    #[Test]
    public function listShapeIncludesTheFullImageGallery(): void
    {
        $serializer = new ProductSerializer();
        $product = $this->makeProduct();
        $product->setImages([
            'https://cdn.example/p100-1.jpg',
            'https://cdn.example/p100-2.jpg',
            'https://cdn.example/p100-3.jpg',
        ]);
        $product->setPrimaryImageUrl('https://cdn.example/p100-1.jpg'); // already first

        $shape = $serializer->listShape($product);

        self::assertArrayHasKey('images', $shape);
        self::assertIsArray($shape['images']);
        self::assertCount(3, $shape['images']); // primary already present, no duplicate
        self::assertSame('https://cdn.example/p100-1.jpg', $shape['images'][0]['url']);
        self::assertSame('https://cdn.example/p100-2.jpg', $shape['images'][1]['url']);
        self::assertSame('https://cdn.example/p100-3.jpg', $shape['images'][2]['url']);
        self::assertArrayHasKey('alt', $shape['images'][0]);
    }

    #[Test]
    public function listShapeImagesMatchesDetailShapeImages(): void
    {
        // listShape and detailShape must agree on the gallery contract so the
        // mobile transform behaves identically for list cards and the PDP.
        $serializer = new ProductSerializer();
        $product = $this->makeProduct();
        $product->setImages(['https://cdn.example/a.jpg', 'https://cdn.example/b.jpg']);
        $product->setPrimaryImageUrl('https://cdn.example/a.jpg');

        self::assertSame(
            $serializer->detailShape($product)['images'],
            $serializer->listShape($product)['images'],
        );
    }

    #[Test]
    public function listShapePrependsPrimaryWhenNotInTheImageList(): void
    {
        $serializer = new ProductSerializer();
        $product = $this->makeProduct();
        $product->setImages(['https://cdn.example/g1.jpg', 'https://cdn.example/g2.jpg']);
        $product->setPrimaryImageUrl('https://cdn.example/primary.jpg'); // NOT in the list

        $shape = $serializer->listShape($product);

        self::assertCount(3, $shape['images']);
        self::assertSame('https://cdn.example/primary.jpg', $shape['images'][0]['url']);
    }

    #[Test]
    public function listShapeImagesIsEmptyArrayWhenProductHasNone(): void
    {
        $serializer = new ProductSerializer();

        $shape = $serializer->listShape($this->makeProduct());

        self::assertArrayHasKey('images', $shape);
        self::assertSame([], $shape['images']);
    }

    private function makeProduct(): Product
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        foreach (['id' => 5, 'slug' => 'test-vendor', 'name' => 'Test Vendor'] as $prop => $val) {
            $ref = new \ReflectionProperty(Vendor::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue($vendor, $val);
        }

        $product = new Product($vendor, 'test-product', 'Test Product');
        $idRef = new \ReflectionProperty(Product::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($product, 100);
        $product->setPrice('365.00');

        return $product;
    }
}
