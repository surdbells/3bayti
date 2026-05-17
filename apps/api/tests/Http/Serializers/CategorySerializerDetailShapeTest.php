<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Serializers;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Http\Serializers\CategorySerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for CategorySerializer::detailShape (sub-phase A of M3.2.X.3).
 *
 * Locks the wire contract for apps/web's /category/:slug route:
 *   - Adds image (object form), icon_name, product_count
 *   - Preserves publicShape fields for backwards compat
 *   - Null-safe transformations
 *
 * Any change to the keys here is a breaking change for the apps/web
 * CategoryDetail interface — keep them in sync.
 */
#[CoversClass(CategorySerializer::class)]
final class CategorySerializerDetailShapeTest extends TestCase
{
    private CategorySerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new CategorySerializer();
    }

    #[Test]
    public function happyPathIncludesAllNewFields(): void
    {
        $category = $this->makeCategory('abayas', 'Abayas', 'Classic UAE pieces.');
        $category->setImageUrl('https://cdn.example/abayas.jpg');
        $category->setIcon('@tui.sparkles');

        $shape = $this->serializer->detailShape($category, rawProductCount: 42);

        // New fields
        self::assertSame(['url' => 'https://cdn.example/abayas.jpg'], $shape['image']);
        self::assertSame('@tui.sparkles', $shape['icon_name']);
        self::assertSame(42, $shape['product_count']);

        // Backwards-compat fields preserved
        self::assertSame('abayas', $shape['slug']);
        self::assertSame('Abayas', $shape['name']);
        self::assertSame('Classic UAE pieces.', $shape['description']);
        self::assertSame('https://cdn.example/abayas.jpg', $shape['image_url']);
        self::assertArrayHasKey('display_order', $shape);
        self::assertArrayHasKey('path', $shape);
        self::assertArrayHasKey('parent_id', $shape);
    }

    #[Test]
    public function nullImageUrlSerializesAsNullImage(): void
    {
        $category = $this->makeCategory('no-image', 'No Image', null);
        // Don't set image_url; default is null

        $shape = $this->serializer->detailShape($category, rawProductCount: 0);

        self::assertNull(
            $shape['image'],
            'Null image_url must surface as image: null (not {url: null})'
        );
        // Flat image_url stays null too (backwards compat)
        self::assertNull($shape['image_url']);
    }

    #[Test]
    public function nullIconSerializesAsNullIconName(): void
    {
        $category = $this->makeCategory('no-icon', 'No Icon', null);
        // Don't set icon; default is null

        $shape = $this->serializer->detailShape($category, rawProductCount: 0);

        self::assertNull(
            $shape['icon_name'],
            'Null icon must surface as icon_name: null'
        );
    }

    #[Test]
    public function rawProductCountForwardedExactly(): void
    {
        $category = $this->makeCategory('test', 'Test', null);

        // Verify the caller-supplied count is the value emitted —
        // the serializer must not transform it (e.g. coerce to string).
        $shape = $this->serializer->detailShape($category, rawProductCount: 0);
        self::assertSame(0, $shape['product_count']);

        $shape = $this->serializer->detailShape($category, rawProductCount: 9999);
        self::assertSame(9999, $shape['product_count']);
    }

    #[Test]
    public function emptyDescriptionPreservedAsNull(): void
    {
        $category = $this->makeCategory('plain', 'Plain', null);

        $shape = $this->serializer->detailShape($category, rawProductCount: 0);

        self::assertNull($shape['description']);
    }

    #[Test]
    public function imageObjectFormHasOnlyUrlKey(): void
    {
        // Apps/web's ApiImage type allows optional alt/width/height,
        // but for category images we have only the URL. The image
        // object must NOT include unused keys (would clutter the
        // wire contract and risk future fields being misread).
        $category = $this->makeCategory('test', 'Test', null);
        $category->setImageUrl('https://cdn.example/img.jpg');

        $shape = $this->serializer->detailShape($category, rawProductCount: 0);

        self::assertSame(['url'], array_keys($shape['image']));
    }

    #[Test]
    public function detailShapeKeysLockedForAppsWebContract(): void
    {
        // Lock the exact key set so future drift surfaces in tests
        // rather than at apps/web runtime via the
        // 'as unknown as CategoryDetailEnvelope' cast.
        $category = $this->makeCategory('test', 'Test', 'Desc');
        $category->setImageUrl('https://cdn.example/img.jpg');
        $category->setIcon('@tui.gem');

        $shape = $this->serializer->detailShape($category, rawProductCount: 5);

        // Required by apps/web CategoryDetail interface (excluding
        // products, which the controller adds on top):
        $expectedKeys = [
            // From publicShape
            'id',
            'slug',
            'name',
            'description',
            'display_order',
            'image_url',
            'path',
            'parent_id',
            // Added by detailShape
            'image',
            'icon_name',
            'product_count',
        ];

        self::assertEqualsCanonicalizing(
            $expectedKeys,
            array_keys($shape),
            'detailShape key set must lock the wire contract'
        );
    }

    #[Test]
    public function publicShapeUnchanged(): void
    {
        // detailShape must add fields without affecting publicShape
        // for other consumers (admin tool, internal scripts).
        $category = $this->makeCategory('test', 'Test', 'Desc');
        $category->setImageUrl('https://cdn.example/img.jpg');
        $category->setIcon('@tui.gem');

        $public = $this->serializer->publicShape($category);

        // publicShape must NOT have detailShape's new fields
        self::assertArrayNotHasKey('image', $public);
        self::assertArrayNotHasKey('icon_name', $public);
        self::assertArrayNotHasKey('product_count', $public);
    }

    private function makeCategory(string $slug, string $name, ?string $description): Category
    {
        $c = new Category($slug, $name);
        $c->setDescription($description);
        return $c;
    }
}
