<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\ProductFilterParser;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ProductFilterParser (M3.2.X.10-C).
 *
 * The parser is shared by ListProductsController + ListFacetsController.
 * Each parsing method is small, so coverage is comprehensive: every
 * branch + every edge case.
 */
#[CoversClass(ProductFilterParser::class)]
final class ProductFilterParserTest extends TestCase
{
    // =================================================================
    // Slug resolution
    // =================================================================

    #[Test]
    public function vendorSlugResolvesToId(): void
    {
        $vendor = $this->makeVendor(101, 'almas-fashion');
        $parser = $this->parserWith(vendorBySlug: ['almas-fashion' => $vendor]);

        self::assertSame(101, $parser->resolveVendorId(['vendor' => 'almas-fashion']));
    }

    #[Test]
    public function vendorLegacyIdResolvesToId(): void
    {
        $vendor = $this->makeVendor(101, 'almas-fashion');
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByLegacyId')->with(5)->willReturn($vendor);

        $em = $this->emWithRepos(vendorRepo: $vendorRepo);
        $parser = new ProductFilterParser($em);

        self::assertSame(101, $parser->resolveVendorId(['vendor_id' => '5']));
    }

    #[Test]
    public function vendorNotFoundReturnsFalse(): void
    {
        $parser = $this->parserWith(vendorBySlug: []);
        self::assertFalse($parser->resolveVendorId(['vendor' => 'does-not-exist']));
    }

    #[Test]
    public function vendorNonNumericLegacyIdReturnsFalse(): void
    {
        $parser = $this->parserWith();
        self::assertFalse($parser->resolveVendorId(['vendor_id' => 'abc']));
    }

    #[Test]
    public function vendorMissingReturnsNull(): void
    {
        $parser = $this->parserWith();
        self::assertNull($parser->resolveVendorId([]));
    }

    #[Test]
    public function slugWinsWhenBothSlugAndLegacyIdPresent(): void
    {
        $vendor = $this->makeVendor(101, 'almas-fashion');
        $parser = $this->parserWith(vendorBySlug: ['almas-fashion' => $vendor]);

        self::assertSame(101, $parser->resolveVendorId([
            'vendor' => 'almas-fashion',
            'vendor_id' => '999',  // should be ignored
        ]));
    }

    #[Test]
    public function categorySlugResolvesToId(): void
    {
        $cat = $this->makeCategory(5, 'abayas');
        $parser = $this->parserWith(categoryBySlug: ['abayas' => $cat]);

        self::assertSame(5, $parser->resolveCategoryId(['category' => 'abayas']));
    }

    #[Test]
    public function labelSlugResolvesToId(): void
    {
        $label = $this->makeLabel(7, 'eid');
        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->method('findOneBy')
            ->with(['slug' => 'eid', 'isActive' => true])
            ->willReturn($label);

        $em = $this->emWithRepos(labelRepo: $labelRepo);
        $parser = new ProductFilterParser($em);

        self::assertSame(7, $parser->resolveLabelId(['label' => 'eid']));
    }

    // =================================================================
    // Scalar parsers
    // =================================================================

    #[Test]
    public function parsePriceNormalizesToTwoDecimals(): void
    {
        $parser = $this->parserWith();
        self::assertSame('50.00', $parser->parsePrice('50'));
        self::assertSame('50.50', $parser->parsePrice('50.5'));
        self::assertSame('99.99', $parser->parsePrice('99.99'));
    }

    #[Test]
    public function parsePriceReturnsNullForBlankOrNonNumeric(): void
    {
        $parser = $this->parserWith();
        self::assertNull($parser->parsePrice(null));
        self::assertNull($parser->parsePrice(''));
        self::assertNull($parser->parsePrice('abc'));
    }

    #[Test]
    public function parseBoolAcceptsTruthyStrings(): void
    {
        $parser = $this->parserWith();
        self::assertTrue($parser->parseBool('true'));
        self::assertTrue($parser->parseBool('1'));
        self::assertTrue($parser->parseBool('yes'));
        self::assertTrue($parser->parseBool('on'));
        self::assertTrue($parser->parseBool('TRUE'));
    }

    #[Test]
    public function parseBoolFalseyStringsReturnFalse(): void
    {
        $parser = $this->parserWith();
        self::assertFalse($parser->parseBool('false'));
        self::assertFalse($parser->parseBool('0'));
        self::assertFalse($parser->parseBool('no'));
    }

    #[Test]
    public function parseBoolNullReturnsNull(): void
    {
        $parser = $this->parserWith();
        self::assertNull($parser->parseBool(null));
    }

    #[Test]
    public function parseSortReturnsValidValues(): void
    {
        $parser = $this->parserWith();
        foreach (ProductFilterParser::VALID_SORTS as $sort) {
            self::assertSame($sort, $parser->parseSort($sort));
        }
    }

    #[Test]
    public function parseSortFallsBackToNewest(): void
    {
        $parser = $this->parserWith();
        self::assertSame('newest', $parser->parseSort('bogus'));
        self::assertSame('newest', $parser->parseSort(null));
        self::assertSame('newest', $parser->parseSort(''));
    }

    #[Test]
    public function parseSearchQueryTrimsAndTruncates(): void
    {
        $parser = $this->parserWith();
        self::assertSame('hello world', $parser->parseSearchQuery('  hello world  '));
        self::assertNull($parser->parseSearchQuery('   '));
        self::assertNull($parser->parseSearchQuery(null));
        // 250-char input truncated to 200
        $long = str_repeat('a', 250);
        $truncated = $parser->parseSearchQuery($long);
        self::assertSame(200, strlen((string) $truncated));
    }

    #[Test]
    public function parseStringListAcceptsArrayForm(): void
    {
        $parser = $this->parserWith();
        self::assertSame(['S', 'M'], $parser->parseStringList(['S', 'M']));
    }

    #[Test]
    public function parseStringListAcceptsCommaSeparatedForm(): void
    {
        $parser = $this->parserWith();
        self::assertSame(['S', 'M', 'L'], $parser->parseStringList('S,M,L'));
    }

    #[Test]
    public function parseStringListTrimsAndDropsEmpties(): void
    {
        $parser = $this->parserWith();
        self::assertSame(['S', 'M'], $parser->parseStringList(' S , , M '));
        self::assertSame(['S'], $parser->parseStringList([' S ', '', null]));
    }

    #[Test]
    public function parseStringListReturnsNullForAbsentOrEmpty(): void
    {
        $parser = $this->parserWith();
        self::assertNull($parser->parseStringList(null));
        self::assertNull($parser->parseStringList(''));
        self::assertNull($parser->parseStringList([]));
        self::assertNull($parser->parseStringList(',,,'));
    }

    #[Test]
    public function parseLimitClampsRange(): void
    {
        $parser = $this->parserWith();
        self::assertSame(24, $parser->parseLimit(null));       // default
        self::assertSame(24, $parser->parseLimit(0));          // < 1 → default
        self::assertSame(24, $parser->parseLimit(-5));         // negative → default
        self::assertSame(50, $parser->parseLimit('50'));
        self::assertSame(100, $parser->parseLimit('200'));     // capped at MAX_LIMIT
    }

    #[Test]
    public function parseOffsetClampsToZero(): void
    {
        $parser = $this->parserWith();
        self::assertSame(0, $parser->parseOffset(null));
        self::assertSame(0, $parser->parseOffset(-10));
        self::assertSame(50, $parser->parseOffset('50'));
    }

    // =================================================================
    // parse() integration
    // =================================================================

    #[Test]
    public function parseAssemblesCanonicalFilterShape(): void
    {
        $vendor = $this->makeVendor(101, 'almas');
        $cat = $this->makeCategory(5, 'abayas');
        $parser = $this->parserWith(
            vendorBySlug: ['almas' => $vendor],
            categoryBySlug: ['abayas' => $cat],
        );

        $result = $parser->parse([
            'vendor' => 'almas',
            'category' => 'abayas',
            'min_price' => '50',
            'max_price' => '500',
            'featured' => 'true',
            'sale' => 'true',
            'sort' => 'price_asc',
            'q' => 'evening dress',
            'sizes' => ['S', 'M'],
            'colors' => 'Black,Red',
            'limit' => '50',
            'offset' => '20',
        ]);

        self::assertFalse($result['filterNotFound']);
        $f = $result['filters'];
        self::assertSame(101, $f['vendorId']);
        self::assertSame(5, $f['categoryId']);
        self::assertSame('50.00', $f['minPrice']);
        self::assertSame('500.00', $f['maxPrice']);
        self::assertTrue($f['isFeatured']);
        self::assertTrue($f['isSale']);
        self::assertNull($f['isNew']);
        self::assertSame('price_asc', $f['sort']);
        self::assertSame('evening dress', $f['searchQuery']);
        self::assertSame(['S', 'M'], $f['sizes']);
        self::assertSame(['Black', 'Red'], $f['colors']);
        self::assertSame(50, $f['limit']);
        self::assertSame(20, $f['offset']);
    }

    #[Test]
    public function parseShortCircuitsOnUnknownVendor(): void
    {
        $parser = $this->parserWith(vendorBySlug: []);
        $result = $parser->parse(['vendor' => 'does-not-exist']);

        self::assertTrue($result['filterNotFound']);
        self::assertSame([], $result['filters']);
    }

    #[Test]
    public function parseShortCircuitsOnUnknownCategory(): void
    {
        $parser = $this->parserWith(categoryBySlug: []);
        $result = $parser->parse(['category' => 'does-not-exist']);

        self::assertTrue($result['filterNotFound']);
    }

    #[Test]
    public function parseShortCircuitsOnUnknownLabel(): void
    {
        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->method('findOneBy')->willReturn(null);
        $em = $this->emWithRepos(labelRepo: $labelRepo);
        $parser = new ProductFilterParser($em);

        $result = $parser->parse(['label' => 'unknown']);
        self::assertTrue($result['filterNotFound']);
    }

    // =================================================================
    // buildAppliedFiltersBlock
    // =================================================================

    #[Test]
    public function appliedFiltersBlockEchoesSuppliedKeysOnly(): void
    {
        $parser = $this->parserWith();
        $applied = $parser->buildAppliedFiltersBlock([
            'vendor' => 'almas',
            'min_price' => '50',
            'sizes' => 'S,M',
            'featured' => 'true',
            'sale' => 'false',     // false flag, not echoed
            // 'category' absent, not echoed
        ]);

        self::assertSame('almas', $applied['vendor']);
        self::assertSame('50', $applied['min_price']);
        self::assertSame(['S', 'M'], $applied['sizes']);
        self::assertTrue($applied['featured']);
        self::assertArrayNotHasKey('sale', $applied);
        self::assertArrayNotHasKey('category', $applied);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param array<string, Vendor> $vendorBySlug
     * @param array<string, Category> $categoryBySlug
     */
    private function parserWith(
        array $vendorBySlug = [],
        array $categoryBySlug = [],
    ): ProductFilterParser {
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findBySlug')->willReturnCallback(
            fn(string $slug) => $vendorBySlug[$slug] ?? null,
        );

        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->method('findBySlug')->willReturnCallback(
            fn(string $slug) => $categoryBySlug[$slug] ?? null,
        );

        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->method('findOneBy')->willReturn(null);

        $em = $this->emWithRepos($vendorRepo, $categoryRepo, $labelRepo);
        return new ProductFilterParser($em);
    }

    private function emWithRepos(
        ?VendorRepository $vendorRepo = null,
        ?CategoryRepository $categoryRepo = null,
        ?VendorLabelRepository $labelRepo = null,
    ): EntityManagerInterface {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(function (string $class) use (
            $vendorRepo, $categoryRepo, $labelRepo,
        ) {
            return match ($class) {
                Vendor::class      => $vendorRepo ?? $this->createMock(VendorRepository::class),
                Category::class    => $categoryRepo ?? $this->createMock(CategoryRepository::class),
                VendorLabel::class => $labelRepo ?? $this->createMock(VendorLabelRepository::class),
                default => $this->fail("Unexpected getRepository call for {$class}"),
            };
        });
        return $em;
    }

    private function makeVendor(int $id, string $slug): Vendor
    {
        $v = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $this->setProp($v, 'id', $id);
        $this->setProp($v, 'slug', $slug);
        $this->setProp($v, 'name', ucfirst(str_replace('-', ' ', $slug)));
        $this->setProp($v, 'contactEmail', "{$slug}@example.com");
        return $v;
    }

    private function makeCategory(int $id, string $slug): Category
    {
        $c = (new \ReflectionClass(Category::class))->newInstanceWithoutConstructor();
        $this->setProp($c, 'id', $id);
        $this->setProp($c, 'slug', $slug);
        $this->setProp($c, 'name', ucfirst($slug));
        return $c;
    }

    private function makeLabel(int $id, string $slug): VendorLabel
    {
        $l = (new \ReflectionClass(VendorLabel::class))->newInstanceWithoutConstructor();
        $this->setProp($l, 'id', $id);
        $this->setProp($l, 'slug', $slug);
        return $l;
    }

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
