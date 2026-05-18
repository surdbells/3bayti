<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\FacetAggregator;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Catalog\ListFacetsController;
use Bayti\Api\Http\Serializers\FacetsSerializer;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * HTTP-level tests for GET /v3/products/facets (M3.2.X.10-B).
 *
 * Coverage focuses on:
 *   - Query-string parsing (slugs, ids, arrays, comma-strings)
 *   - Slug-resolution sentinels (FILTER_NOT_FOUND → empty response)
 *   - Applied-filters meta block accuracy
 *   - Response envelope structure
 *   - Public endpoint (no auth required)
 *
 * FacetAggregator itself is unit-tested in FacetAggregatorTest;
 * here we mock it and just verify the controller plumbs filters
 * through correctly.
 */
#[CoversClass(ListFacetsController::class)]
#[CoversClass(FacetsSerializer::class)]
final class ListFacetsControllerTest extends HttpTestCase
{
    // =================================================================
    // Happy path + response shape
    // =================================================================

    #[Test]
    public function returnsCanonicalEnvelopeWithAllFiveFacets(): void
    {
        $this->bindStandardEm();
        $this->bindAggregator(callback: function (array $filters): array {
            return $this->cannedAggregatorResult();
        });

        $response = $this->handle($this->jsonRequest('GET', '/v3/products/facets'));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);

        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);
        // All 5 facets present
        foreach (['size', 'color', 'price', 'vendor', 'category'] as $facet) {
            self::assertArrayHasKey($facet, $body['data'], "missing facet: {$facet}");
        }
        self::assertArrayHasKey('total_products', $body['meta']);
        self::assertSame(42, $body['meta']['total_products']);
    }

    #[Test]
    public function priceFacetIncludesMinAndMaxBoundaries(): void
    {
        $this->bindStandardEm();
        $this->bindAggregator(callback: fn() => $this->cannedAggregatorResult());

        $response = $this->handle($this->jsonRequest('GET', '/v3/products/facets'));
        $body = $this->jsonBody($response);

        $priceValues = $body['data']['price']['values'];
        self::assertNotEmpty($priceValues);
        self::assertSame('0.00', $priceValues[0]['min']);
        self::assertSame('50.00', $priceValues[0]['max']);
    }

    // =================================================================
    // Filter routing
    // =================================================================

    #[Test]
    public function vendorSlugResolvedAndForwardedAsVendorId(): void
    {
        $vendor = $this->makeVendor(id: 101, slug: 'almas-fashion');
        $this->bindStandardEm(vendorBySlug: ['almas-fashion' => $vendor]);

        $captured = null;
        $this->bindAggregator(callback: function (array $filters) use (&$captured): array {
            $captured = $filters;
            return $this->cannedAggregatorResult();
        });

        $this->handle($this->jsonRequest('GET', '/v3/products/facets?vendor=almas-fashion'));
        self::assertSame(101, $captured['vendorId']);
    }

    #[Test]
    public function categorySlugResolvedAndForwardedAsCategoryId(): void
    {
        $category = $this->makeCategory(id: 5, slug: 'abayas');
        $this->bindStandardEm(categoryBySlug: ['abayas' => $category]);

        $captured = null;
        $this->bindAggregator(callback: function (array $filters) use (&$captured): array {
            $captured = $filters;
            return $this->cannedAggregatorResult();
        });

        $this->handle($this->jsonRequest('GET', '/v3/products/facets?category=abayas'));
        self::assertSame(5, $captured['categoryId']);
    }

    #[Test]
    public function unknownVendorSlugReturnsEmptyFacetsWithAppliedFilterEcho(): void
    {
        // FILTER_NOT_FOUND sentinel: empty response, not 404.
        $this->bindStandardEm(vendorBySlug: []);
        $this->bindAggregator(callback: fn() => $this->cannedAggregatorResult());

        $response = $this->handle($this->jsonRequest('GET', '/v3/products/facets?vendor=does-not-exist'));
        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // All facets empty
        self::assertSame([], $body['data']['size']['values']);
        self::assertSame([], $body['data']['color']['values']);
        self::assertSame([], $body['data']['price']['values']);
        self::assertSame(0, $body['meta']['total_products']);
        // But the applied filter is still echoed so UI can render
        // the "filtering by: does-not-exist" chip
        self::assertSame('does-not-exist', $body['meta']['applied_filters']['vendor']);
    }

    #[Test]
    public function sizesArrayQueryParsedAsList(): void
    {
        $this->bindStandardEm();
        $captured = null;
        $this->bindAggregator(callback: function (array $filters) use (&$captured): array {
            $captured = $filters;
            return $this->cannedAggregatorResult();
        });

        $this->handle($this->jsonRequest('GET', '/v3/products/facets?sizes[]=S&sizes[]=M'));
        self::assertSame(['S', 'M'], $captured['sizes']);
    }

    #[Test]
    public function sizesCommaSeparatedStringAlsoParsed(): void
    {
        $this->bindStandardEm();
        $captured = null;
        $this->bindAggregator(callback: function (array $filters) use (&$captured): array {
            $captured = $filters;
            return $this->cannedAggregatorResult();
        });

        $this->handle($this->jsonRequest('GET', '/v3/products/facets?sizes=S,M,L'));
        self::assertSame(['S', 'M', 'L'], $captured['sizes']);
    }

    #[Test]
    public function colorsArrayQueryParsedAsList(): void
    {
        $this->bindStandardEm();
        $captured = null;
        $this->bindAggregator(callback: function (array $filters) use (&$captured): array {
            $captured = $filters;
            return $this->cannedAggregatorResult();
        });

        $this->handle($this->jsonRequest('GET', '/v3/products/facets?colors[]=Black&colors[]=Red'));
        self::assertSame(['Black', 'Red'], $captured['colors']);
    }

    #[Test]
    public function priceRangeAndBoolFlagsForwarded(): void
    {
        $this->bindStandardEm();
        $captured = null;
        $this->bindAggregator(callback: function (array $filters) use (&$captured): array {
            $captured = $filters;
            return $this->cannedAggregatorResult();
        });

        $this->handle($this->jsonRequest(
            'GET',
            '/v3/products/facets?min_price=50&max_price=500&featured=true&sale=true',
        ));
        self::assertSame('50.00', $captured['minPrice']);
        self::assertSame('500.00', $captured['maxPrice']);
        self::assertTrue($captured['isFeatured']);
        self::assertTrue($captured['isSale']);
        self::assertNull($captured['isNew']);
    }

    #[Test]
    public function fulltextSearchQueryTrimmedAndForwarded(): void
    {
        $this->bindStandardEm();
        $captured = null;
        $this->bindAggregator(callback: function (array $filters) use (&$captured): array {
            $captured = $filters;
            return $this->cannedAggregatorResult();
        });

        $this->handle($this->jsonRequest('GET', '/v3/products/facets?q=%20%20evening+dress%20%20'));
        self::assertSame('evening dress', $captured['searchQuery']);
    }

    // =================================================================
    // Applied-filters meta block
    // =================================================================

    #[Test]
    public function appliedFiltersBlockEchoesAllSuppliedFilters(): void
    {
        $vendor = $this->makeVendor(id: 101, slug: 'almas-fashion');
        $category = $this->makeCategory(id: 5, slug: 'abayas');
        $this->bindStandardEm(
            vendorBySlug: ['almas-fashion' => $vendor],
            categoryBySlug: ['abayas' => $category],
        );
        $this->bindAggregator(callback: fn() => $this->cannedAggregatorResult());

        $response = $this->handle($this->jsonRequest(
            'GET',
            '/v3/products/facets?vendor=almas-fashion&category=abayas&min_price=50&sizes[]=S&sizes[]=M&colors[]=Black&q=dress&featured=true',
        ));
        $applied = $this->jsonBody($response)['meta']['applied_filters'];

        self::assertSame('almas-fashion', $applied['vendor']);
        self::assertSame('abayas', $applied['category']);
        self::assertSame('50', $applied['min_price']);
        self::assertSame(['S', 'M'], $applied['sizes']);
        self::assertSame(['Black'], $applied['colors']);
        self::assertSame('dress', $applied['q']);
        self::assertTrue($applied['featured']);
        // Unspecified filters NOT echoed
        self::assertArrayNotHasKey('max_price', $applied);
        self::assertArrayNotHasKey('new', $applied);
        self::assertArrayNotHasKey('sale', $applied);
    }

    #[Test]
    public function appliedFiltersBlockOmitsFalseAndNullFlags(): void
    {
        $this->bindStandardEm();
        $this->bindAggregator(callback: fn() => $this->cannedAggregatorResult());

        // sale=false should not appear in applied_filters (only true values do)
        $response = $this->handle($this->jsonRequest('GET', '/v3/products/facets?sale=false'));
        $applied = $this->jsonBody($response)['meta']['applied_filters'];

        self::assertArrayNotHasKey('sale', $applied);
    }

    // =================================================================
    // Auth / public access
    // =================================================================

    #[Test]
    public function endpointIsPublicNoAuthRequired(): void
    {
        $this->bindStandardEm();
        $this->bindAggregator(callback: fn() => $this->cannedAggregatorResult());

        // No Authorization header
        $response = $this->handle($this->jsonRequest('GET', '/v3/products/facets'));
        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function routeOrderingDoesNotEatTheFacetsLiteral(): void
    {
        // Regression guard: '/v3/products/facets' must NOT be matched by
        // '/v3/products/{slug}'. If it were, the GetProductController
        // would fire and try to look up product slug='facets'.
        $this->bindStandardEm();
        $this->bindAggregator(callback: fn() => $this->cannedAggregatorResult());

        $response = $this->handle($this->jsonRequest('GET', '/v3/products/facets'));
        $body = $this->jsonBody($response);

        // If the slug controller had matched, we'd see {data: Product}, not
        // {data: {size,color,price,vendor,category}}.
        self::assertArrayHasKey('size', $body['data']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param array<string, Vendor> $vendorBySlug
     * @param array<string, Category> $categoryBySlug
     */
    private function bindStandardEm(
        array $vendorBySlug = [],
        array $categoryBySlug = [],
    ): void {
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

        $em = $this->stubEm(function ($em) use ($vendorRepo, $categoryRepo, $labelRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Category::class, $categoryRepo],
                [VendorLabel::class, $labelRepo],
            ]);
        });

        $this->bind(EntityManagerInterface::class, $em);
    }

    private function bindAggregator(callable $callback): void
    {
        $aggregator = $this->createMock(FacetAggregator::class);
        $aggregator->method('compute')->willReturnCallback($callback);
        $this->bind(FacetAggregator::class, $aggregator);
    }

    /**
     * @return array<string, mixed>
     */
    private function cannedAggregatorResult(): array
    {
        return [
            'size' => [
                'values' => [
                    ['value' => 'M', 'count' => 12],
                    ['value' => 'L', 'count' => 8],
                ],
                'total_distinct' => 2,
            ],
            'color' => [
                'values' => [
                    ['value' => 'Black', 'count' => 15],
                ],
                'total_distinct' => 1,
            ],
            'price' => [
                'values' => [
                    [
                        'value' => '0-50',
                        'count' => 5,
                        'min' => '0.00',
                        'max' => '50.00',
                    ],
                ],
            ],
            'vendor' => [
                'values' => [
                    ['value' => 'almas-fashion', 'label' => 'Almas Fashion', 'count' => 20],
                ],
                'total_distinct' => 1,
            ],
            'category' => [
                'values' => [
                    ['value' => 'abayas', 'label' => 'Abayas', 'count' => 20],
                ],
                'total_distinct' => 1,
            ],
            'total_products' => 42,
        ];
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

    private function setProp(object $entity, string $prop, mixed $value): void
    {
        $ref = new \ReflectionProperty($entity::class, $prop);
        $ref->setAccessible(true);
        $ref->setValue($entity, $value);
    }
}
