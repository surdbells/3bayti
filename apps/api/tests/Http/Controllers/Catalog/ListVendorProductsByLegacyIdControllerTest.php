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
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(ListVendorProductsByLegacyIdController::class)]
final class ListVendorProductsByLegacyIdControllerTest extends HttpTestCase
{
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
                // limit/offset from query string (defaults 24/0).
                return $filters['vendorId'] === 42
                    && $filters['sort'] === 'newest'
                    && $filters['limit'] === 24
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

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Vendor::class)->willReturn($vendorRepo));
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

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Vendor::class)->willReturn($vendorRepo));
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
}
