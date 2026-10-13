<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Catalog\ListVendorProductsByLegacyIdController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ListVendorProductsByLegacyIdController::class)]
final class ListVendorProductsByLegacyIdControllerTest extends HttpTestCase
{
    /** @var list<array<string, mixed>> filters each findActivePaginated call received */
    private array $repoCalls = [];

    #[Test]
    public function returnsPaginatedProductsForActiveVendor(): void
    {
        $vendor = $this->makeVendorWithInternalId(slug: 'almas-fashion', internalId: 42);
        $product = $this->makeProduct($vendor, 'silk-abaya', 'Silk Abaya');

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findByLegacyId')
            ->with(7)
            ->willReturn($vendor);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(function (array $filters) {
                // The controller hardcodes sort=newest and forwards
                // limit/offset from query string. No limit on this
                // store-scoped route = the whole catalogue (bound 500).
                return $filters['vendorId'] === 42
                    && $filters['sort'] === 'newest'
                    && $filters['limit'] === 500
                    && $filters['offset'] === 0;
            }))
            ->willReturn(['items' => [$product], 'total' => 1]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/7/products'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(1, $body['data']);
        self::assertSame('Silk Abaya', $body['data'][0]['name']);
        self::assertSame(1, $body['meta']['total']);
        self::assertSame(500, $body['meta']['limit']);
    }

    /**
     * Mobile bundles older than the slug storefront load the store page's
     * "All" grid and "Latest" rail through this route with NO limit/offset
     * and no infinite scroll, so an absent limit must return every product
     * (bounded at 500), not the first 24.
     */
    #[Test]
    public function noLimitReturnsTheWholeStoreCatalogue(): void
    {
        $this->givenLegacyStoreWithProducts(30);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/7/products'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(30, $body['data']);
        self::assertSame('Product 30', $body['data'][29]['name']);
        self::assertSame(30, $body['meta']['total']);
        self::assertSame(500, $body['meta']['limit']);
        self::assertSame(0, $body['meta']['offset']);
        self::assertFalse($body['meta']['has_more']);
        self::assertSame(42, $body['meta']['vendor_id']);
        self::assertSame(500, $this->repoCalls[0]['limit']);
    }

    #[Test]
    public function offsetIsHonouredWithoutALimit(): void
    {
        $this->givenLegacyStoreWithProducts(30);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/7/products?offset=20'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(10, $body['data']);
        self::assertSame('Product 21', $body['data'][0]['name']);
        self::assertSame(500, $body['meta']['limit']);
        self::assertSame(20, $body['meta']['offset']);
        self::assertFalse($body['meta']['has_more']);
        self::assertSame(20, $this->repoCalls[0]['offset']);
    }

    #[Test]
    public function explicitLimitIsHonouredAndReportsMore(): void
    {
        $this->givenLegacyStoreWithProducts(30);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/7/products?limit=10'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(10, $body['data']);
        self::assertSame(10, $body['meta']['limit']);
        self::assertTrue($body['meta']['has_more']);
        self::assertSame(10, $this->repoCalls[0]['limit']);
    }

    /**
     * Present-but-odd limit values keep the historical clamp
     * (max(1, min(100, (int) $limit))): they are NOT treated as absent.
     *
     * @return iterable<string, array{0: string, 1: int}>
     */
    public static function oddExplicitLimits(): iterable
    {
        yield 'empty string' => ['', 1];
        yield 'garbage'      => ['abc', 1];
        yield 'zero'         => ['0', 1];
        yield 'negative'     => ['-5', 1];
    }

    #[Test]
    #[DataProvider('oddExplicitLimits')]
    public function oddExplicitLimitsKeepTheHistoricalClamp(string $limit, int $expected): void
    {
        $this->givenLegacyStoreWithProducts(30);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/7/products?limit=' . rawurlencode($limit)),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount($expected, $body['data']);
        self::assertSame($expected, $body['meta']['limit']);
        self::assertSame($expected, $this->repoCalls[0]['limit']);
    }

    #[Test]
    public function honorsLimitAndOffsetQueryParams(): void
    {
        $vendor = $this->makeVendorWithInternalId(slug: 'vendor-a', internalId: 11);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByLegacyId')->willReturn($vendor);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) =>
                $f['limit'] === 50 && $f['offset'] === 100))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/3/products?limit=50&offset=100'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function clampsLimitTo100UpperBound(): void
    {
        $vendor = $this->makeVendorWithInternalId(slug: 'vendor-a', internalId: 11);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByLegacyId')->willReturn($vendor);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) => $f['limit'] === 100))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/3/products?limit=500'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenVendorIdNotFound(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByLegacyId')->willReturn(null);

        $em = $this->stubEm(function ($em) use ($vendorRepo): void {
            $em->method('getRepository')->with(Vendor::class)->willReturn($vendorRepo);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/9999/products'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenVendorIsInactive(): void
    {
        $vendor = new Vendor('inactive', 'Inactive', 'i@example.test');
        $vendor->setActive(false);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByLegacyId')->willReturn($vendor);

        $em = $this->stubEm(function ($em) use ($vendorRepo): void {
            $em->method('getRepository')->with(Vendor::class)->willReturn($vendorRepo);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/8/products'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenVendorIsActiveButNotApproved(): void
    {
        // Active but still pending review: canSell() is false.
        $vendor = new Vendor('pending-store', 'Pending Store', 'p@example.test');

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByLegacyId')->willReturn($vendor);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::never())->method('findActivePaginated');

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/8/products'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenIdPathSegmentIsNonNumeric(): void
    {
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/abc/products'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    /**
     * Vendor with a forced internal id (the Doctrine PK). Required because
     * the controller passes $vendor->getId() into the product filter, and
     * an unpersisted Vendor's id is null. We use reflection, same pattern
     * as HttpTestCase::makeUser uses for User.
     */
    private function makeVendorWithInternalId(string $slug, int $internalId): Vendor
    {
        $vendor = new Vendor($slug, ucfirst($slug), $slug . '@example.test');
        // The route now 404s unless the vendor may sell (approved + active);
        // approve() moves it from the default pending → approved.
        $vendor->approve();
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, $internalId);
        return $vendor;
    }

    private function makeProduct(Vendor $vendor, string $slug, string $name): Product
    {
        $product = new Product($vendor, $slug, $name);
        $product->setStatus(Product::STATUS_ACTIVE);
        return $product;
    }

    /**
     * Install an approved, active store (legacy id 7, internal id 42) with
     * $count active products ("Product 1" is the newest) and a fake
     * repository that pages through them by the controller's limit/offset,
     * recording each call's filters in $this->repoCalls.
     */
    private function givenLegacyStoreWithProducts(int $count): void
    {
        $vendor = $this->makeVendorWithInternalId(slug: 'almas-fashion', internalId: 42);

        $catalogue = [];
        for ($i = 1; $i <= $count; $i++) {
            $catalogue[] = $this->makeProduct($vendor, 'product-' . $i, 'Product ' . $i);
        }

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByLegacyId')->with(7)->willReturn($vendor);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturnCallback(
            function (array $filters) use ($catalogue): array {
                $this->repoCalls[] = $filters;
                return [
                    'items' => array_slice($catalogue, (int) $filters['offset'], (int) $filters['limit']),
                    'total' => count($catalogue),
                ];
            },
        );

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }
}
