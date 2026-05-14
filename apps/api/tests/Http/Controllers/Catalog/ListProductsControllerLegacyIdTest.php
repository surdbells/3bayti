<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Catalog\ListProductsController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Focused tests for the M3.1.5a additions to ListProductsController:
 * `vendor_id` and `category_id` query params that resolve the legacy
 * WordPress/CodeIgniter ids to internal v3 ids before filtering.
 *
 * Doesn't re-test the existing slug-resolution paths; those would
 * belong in a separate ListProductsControllerTest that pre-dates this
 * commit (and doesn't exist yet — could be added in a M4 hardening
 * pass). The legacy-id tests below add the targeted coverage M3.1.5a
 * was asked to ship.
 */
#[CoversClass(ListProductsController::class)]
final class ListProductsControllerLegacyIdTest extends HttpTestCase
{
    #[Test]
    public function resolvesVendorIdQueryParamToInternalId(): void
    {
        $vendor = $this->makeVendorWithInternalId(slug: 'almas', internalId: 42);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findByLegacyId')
            ->with(7)
            ->willReturn($vendor);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) => $f['vendorId'] === 42))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?vendor_id=7'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function resolvesCategoryIdQueryParamToInternalId(): void
    {
        $category = $this->makeCategoryWithInternalId(slug: 'abayas', internalId: 99);

        $categoryRepo = $this->createMock(CategoryRepository::class);
        $categoryRepo->expects(self::once())
            ->method('findByLegacyId')
            ->with(5)
            ->willReturn($category);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) => $f['categoryId'] === 99))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(function ($em) use ($categoryRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Category::class, $categoryRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?category_id=5'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function slugWinsOverLegacyIdWhenBothProvided(): void
    {
        $vendor = $this->makeVendorWithInternalId(slug: 'almas', internalId: 42);

        $vendorRepo = $this->createMock(VendorRepository::class);
        // We expect findBySlug to be called, NOT findByLegacyId.
        $vendorRepo->expects(self::once())
            ->method('findBySlug')
            ->with('almas')
            ->willReturn($vendor);
        $vendorRepo->expects(self::never())->method('findByLegacyId');

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?vendor=almas&vendor_id=99'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returnsEmptyEnvelopeWhenLegacyVendorIdNotFound(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByLegacyId')->willReturn(null);

        // findActivePaginated should NOT be called — the controller
        // short-circuits to an empty envelope. Matches the existing
        // unknown-slug behaviour.
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
            $this->jsonRequest('GET', '/v3/products?vendor_id=9999'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    #[Test]
    public function treatsNonNumericVendorIdAsNotFound(): void
    {
        // Per the controller's docblock, a malformed vendor_id is
        // treated as a not-found filter (empty envelope) rather than
        // 400. This guarantees catalog list responses never fail hard
        // on caller-side typos.
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::never())->method('findByLegacyId');
        $vendorRepo->expects(self::never())->method('findBySlug');

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
            $this->jsonRequest('GET', '/v3/products?vendor_id=not-a-number'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
    }

    private function makeVendorWithInternalId(string $slug, int $internalId): Vendor
    {
        $vendor = new Vendor($slug, ucfirst($slug), $slug . '@example.test');
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, $internalId);
        return $vendor;
    }

    private function makeCategoryWithInternalId(string $slug, int $internalId): Category
    {
        $category = new Category($slug, ucfirst($slug));
        $ref = new \ReflectionProperty(Category::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($category, $internalId);
        return $category;
    }
}
