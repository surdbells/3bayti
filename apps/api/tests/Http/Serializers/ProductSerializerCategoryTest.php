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
 * listShape must emit `category_id` (alongside `category_slug`) so the admin
 * product-edit form — which slug-loads the storefront detail shape (detailShape
 * inherits listShape) — can pre-select the category radio. Without the numeric
 * id the radio (bound by [value]="c.id") never matches and stays empty.
 *
 * @see ProductSerializer::listShape()
 */
#[CoversClass(ProductSerializer::class)]
final class ProductSerializerCategoryTest extends TestCase
{
    #[Test]
    public function listShapeEmitsCategoryId(): void
    {
        $serializer = new ProductSerializer();
        $product = $this->makeProduct();
        $product->setCategory($this->makeCategory(42, 'abayas'));

        $shape = $serializer->listShape($product);

        self::assertArrayHasKey('category_id', $shape);
        self::assertSame(42, $shape['category_id']);
        // category_slug must remain (existing consumers rely on it).
        self::assertSame('abayas', $shape['category_slug']);
    }

    #[Test]
    public function listShapeCategoryIdIsNullWhenProductHasNoCategory(): void
    {
        $serializer = new ProductSerializer();

        $shape = $serializer->listShape($this->makeProduct());

        self::assertArrayHasKey('category_id', $shape);
        self::assertNull($shape['category_id']);
    }

    #[Test]
    public function detailShapeInheritsCategoryIdFromListShape(): void
    {
        // Admin edit slug-loads detailShape, which inherits listShape — guard
        // that the inherited key flows through to the detail payload too.
        $serializer = new ProductSerializer();
        $product = $this->makeProduct();
        $product->setCategory($this->makeCategory(7, 'jewellery'));

        $shape = $serializer->detailShape($product);

        self::assertSame(7, $shape['category_id']);
    }

    private function makeCategory(int $id, string $slug): Category
    {
        $category = new Category($slug, ucfirst($slug));
        $idRef = new \ReflectionProperty(Category::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($category, $id);

        return $category;
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
