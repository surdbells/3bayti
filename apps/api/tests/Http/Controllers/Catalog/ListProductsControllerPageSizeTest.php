<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Catalog\ListProductsController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /v3/products page-size behaviour (shared CatalogPageSize rule).
 *
 *   - no limit + store-scoped (vendor filter resolves to a store) => the whole
 *     store selection (<= 500). The shipped mobile store page loads each label
 *     chip with ?vendor={slug}&label={slug} and NO limit / NO pagination.
 *   - no limit + not store-scoped => 24, unchanged (category, search, explore,
 *     best sellers, new arrivals, collections, ...).
 *   - explicit limit => the historical 1-100 clamp, whatever the scope.
 *
 * The product repository is faked to slice an in-memory result set by the
 * limit/offset the controller passes, so the response reflects exactly what
 * the database would return for that page.
 */
#[CoversClass(ListProductsController::class)]
final class ListProductsControllerPageSizeTest extends HttpTestCase
{
    private const VENDOR_ID = 42;
    private const LABEL_ID = 55;

    /** @var list<array<string, mixed>> filters each findActivePaginated call received */
    private array $repoCalls = [];

    private Vendor $vendor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = new Vendor('funun-abaya', 'Funun Abaya', 'funun@example.test');
        $this->vendor->approve();
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setValue($this->vendor, self::VENDOR_ID);
    }

    #[Test]
    public function noLimitWithoutAVendorKeepsTheDefaultOf24(): void
    {
        $this->givenResultSet(30);

        $body = $this->get('/v3/products');

        self::assertCount(24, $body['data']);
        self::assertSame(['total' => 30, 'limit' => 24, 'offset' => 0, 'has_more' => true], $body['meta']);
        self::assertNull($this->repoCalls[0]['vendorId']);
        self::assertSame(24, $this->repoCalls[0]['limit']);
    }

    /**
     * Non-vendor listings the mobile app sends without a limit today; none of
     * them may change.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function nonStoreListings(): iterable
    {
        yield 'new arrivals'  => ['/v3/products?sort=newest'];
        yield 'best sellers'  => ['/v3/products?sort=best_seller'];
        yield 'explore seed'  => ['/v3/products?seed=12345'];
        yield 'search'        => ['/v3/products?q=abaya'];
        yield 'sale'          => ['/v3/products?sale=true'];
        yield 'category'      => ['/v3/products?category=abayas'];
        yield 'empty vendor'  => ['/v3/products?vendor='];
    }

    #[Test]
    #[DataProvider('nonStoreListings')]
    public function noLimitOnNonStoreListingsKeepsTheDefaultOf24(string $uri): void
    {
        $this->givenResultSet(30);

        $body = $this->get($uri);

        self::assertCount(24, $body['data']);
        self::assertSame(24, $body['meta']['limit']);
        self::assertTrue($body['meta']['has_more']);
        self::assertNull($this->repoCalls[0]['vendorId']);
        self::assertSame(24, $this->repoCalls[0]['limit']);
    }

    #[Test]
    public function noLimitWithAVendorReturnsTheWholeStoreSelection(): void
    {
        $this->givenResultSet(30);

        $body = $this->get('/v3/products?vendor=funun-abaya');

        self::assertCount(30, $body['data']);
        self::assertSame(['total' => 30, 'limit' => 500, 'offset' => 0, 'has_more' => false], $body['meta']);
        self::assertSame(self::VENDOR_ID, $this->repoCalls[0]['vendorId']);
        self::assertSame(500, $this->repoCalls[0]['limit']);
    }

    #[Test]
    public function noLimitWithAVendorAndLabelReturnsEveryProductInThatLabel(): void
    {
        // The mobile label-chip request: 28 products in the label, which the
        // old 24-row default cut to 24.
        $this->givenResultSet(28);

        $body = $this->get('/v3/products?vendor=funun-abaya&label=latest-collection-2');

        self::assertCount(28, $body['data']);
        self::assertSame(['total' => 28, 'limit' => 500, 'offset' => 0, 'has_more' => false], $body['meta']);
        self::assertSame(self::VENDOR_ID, $this->repoCalls[0]['vendorId']);
        self::assertSame(self::LABEL_ID, $this->repoCalls[0]['labelId']);
        self::assertSame(500, $this->repoCalls[0]['limit']);
    }

    #[Test]
    public function noLimitWithAVendorResolvedByIdIsAlsoStoreScoped(): void
    {
        $this->givenResultSet(30);

        $body = $this->get('/v3/products?vendor_id=7');

        self::assertCount(30, $body['data']);
        self::assertSame(500, $body['meta']['limit']);
        self::assertSame(self::VENDOR_ID, $this->repoCalls[0]['vendorId']);
    }

    #[Test]
    public function offsetIsHonouredOnAStoreScopedRequestWithoutALimit(): void
    {
        $this->givenResultSet(30);

        $body = $this->get('/v3/products?vendor=funun-abaya&offset=25');

        self::assertCount(5, $body['data']);
        self::assertSame(['total' => 30, 'limit' => 500, 'offset' => 25, 'has_more' => false], $body['meta']);
    }

    #[Test]
    public function explicitLimitWithAVendorIsHonoured(): void
    {
        // The web store page always paginates with an explicit limit.
        $this->givenResultSet(30);

        $body = $this->get('/v3/products?vendor=funun-abaya&label=latest-collection-2&limit=12&offset=12');

        self::assertCount(12, $body['data']);
        self::assertSame(['total' => 30, 'limit' => 12, 'offset' => 12, 'has_more' => true], $body['meta']);
        self::assertSame(12, $this->repoCalls[0]['limit']);
    }

    #[Test]
    public function explicitLimitWithAVendorIsStillCappedAt100(): void
    {
        $this->givenResultSet(130);

        $body = $this->get('/v3/products?vendor=funun-abaya&limit=250');

        self::assertCount(100, $body['data']);
        self::assertSame(['total' => 130, 'limit' => 100, 'offset' => 0, 'has_more' => true], $body['meta']);
    }

    /**
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function explicitNonStoreLimits(): iterable
    {
        yield 'within range'  => ['60', 60];
        yield 'over max'      => ['250', 100];
        yield 'empty string'  => ['', 1];
        yield 'garbage'       => ['abc', 1];
        yield 'zero'          => ['0', 1];
        yield 'negative'      => ['-5', 1];
    }

    #[Test]
    #[DataProvider('explicitNonStoreLimits')]
    public function explicitLimitWithoutAVendorIsUnchanged(string $limit, int $expected): void
    {
        $this->givenResultSet(130);

        $body = $this->get('/v3/products?sort=newest&limit=' . rawurlencode($limit));

        self::assertCount($expected, $body['data']);
        self::assertSame($expected, $body['meta']['limit']);
        self::assertSame($expected, $this->repoCalls[0]['limit']);
    }

    #[Test]
    public function unknownVendorIsAnEmptyEnvelopeWithTheDefaultLimit(): void
    {
        $this->givenResultSet(30);

        $body = $this->get('/v3/products?vendor=no-such-store&label=latest-collection-2');

        self::assertSame([], $body['data']);
        self::assertSame(['total' => 0, 'limit' => 24, 'offset' => 0, 'has_more' => false], $body['meta']);
        self::assertSame([], $this->repoCalls);
    }

    #[Test]
    public function unknownLabelOnAKnownStoreReportsTheStoreCatalogueLimit(): void
    {
        $this->givenResultSet(30);

        $body = $this->get('/v3/products?vendor=funun-abaya&label=no-such-label');

        self::assertSame([], $body['data']);
        self::assertSame(['total' => 0, 'limit' => 500, 'offset' => 0, 'has_more' => false], $body['meta']);
        self::assertSame([], $this->repoCalls);
    }

    #[Test]
    public function unknownLabelOnAKnownStoreWithAnExplicitLimitEchoesIt(): void
    {
        $this->givenResultSet(30);

        $body = $this->get('/v3/products?vendor=funun-abaya&label=no-such-label&limit=12');

        self::assertSame(['total' => 0, 'limit' => 12, 'offset' => 0, 'has_more' => false], $body['meta']);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(string $uri): array
    {
        $response = $this->handle($this->jsonRequest('GET', $uri));
        self::assertSame(200, $response->getStatusCode());

        return $this->jsonBody($response);
    }

    /**
     * Install repositories for: the store funun-abaya (slug, or legacy id 7),
     * its label latest-collection-2, the category abayas, and a fake product
     * repository whose matching result set has $count products and is paged
     * by the controller's limit/offset.
     */
    private function givenResultSet(int $count): void
    {
        $resultSet = [];
        for ($i = 1; $i <= $count; $i++) {
            $product = new Product($this->vendor, 'product-' . $i, 'Product ' . $i);
            $product->setStatus(Product::STATUS_ACTIVE);
            $resultSet[] = $product;
        }

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findBySlug')->willReturnCallback(
            fn (string $slug): ?Vendor => $slug === 'funun-abaya' ? $this->vendor : null,
        );
        $vendorRepo->method('findByLegacyId')->willReturnCallback(
            fn (int $id): ?Vendor => $id === 7 ? $this->vendor : null,
        );

        $label = new VendorLabel($this->vendor, 'latest-collection-2', 'Latest Collection');
        $labelRef = new \ReflectionProperty(VendorLabel::class, 'id');
        $labelRef->setValue($label, self::LABEL_ID);

        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->method('findOneBy')->willReturnCallback(
            fn (array $criteria): ?VendorLabel => ($criteria['slug'] ?? null) === 'latest-collection-2' ? $label : null,
        );

        $category = new Category('abayas', 'Abayas');
        $categoryRef = new \ReflectionProperty(Category::class, 'id');
        $categoryRef->setValue($category, 99);

        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->method('findBySlug')->willReturnCallback(
            fn (string $slug): ?Category => $slug === 'abayas' ? $category : null,
        );

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturnCallback(
            function (array $filters) use ($resultSet): array {
                $this->repoCalls[] = $filters;
                return [
                    'items' => array_slice($resultSet, (int) $filters['offset'], (int) $filters['limit']),
                    'total' => count($resultSet),
                ];
            },
        );

        $em = $this->stubEm(function ($em) use ($vendorRepo, $labelRepo, $categoryRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [VendorLabel::class, $labelRepo],
                [Category::class, $categoryRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }
}
