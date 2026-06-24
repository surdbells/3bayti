<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Review;

use Bayti\Api\Domain\Catalog\ProductReview;
use Bayti\Api\Domain\Catalog\ProductReviewRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Review\ListVendorPublicReviewsController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * HTTP tests for GET /v3/vendors/{vendorId}/reviews with the flexible
 * vendor resolution that fixes mobile (legacy id) + web (slug) + v3-id.
 *
 * Resolution order under test:
 *   1. numeric -> em->find(Vendor, n) (v3 PK)
 *   2. numeric, find() null -> VendorRepository::findByLegacyId(n) (mobile)
 *   3. non-numeric -> VendorRepository::findBySlug, active only (web)
 *
 * The repository's findApprovedForVendorPaginated returns vendor-scoped
 * (product_id NULL, status approved) migrated reviews — the controller just
 * needs the resolved Vendor passed to it, which these tests assert.
 */
#[CoversClass(ListVendorPublicReviewsController::class)]
final class ListVendorPublicReviewsControllerTest extends HttpTestCase
{
    #[Test]
    public function resolvesV3PrimaryKeyWhenNumericFindHits(): void
    {
        $vendor = $this->makeVendor(42, 'almas-fashion');

        $vendorRepo = $this->createMock(VendorRepository::class);
        // find() hit means we never fall through to legacy-id.
        $vendorRepo->expects(self::never())->method('findByLegacyId');

        $reviewRepo = $this->createMock(ProductReviewRepository::class);
        $reviewRepo->expects(self::once())
            ->method('findApprovedForVendorPaginated')
            ->with($vendor, 20, 0)
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(function ($em) use ($vendor, $vendorRepo, $reviewRepo) {
            $em->method('find')->with(Vendor::class, 42)->willReturn($vendor);
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [ProductReview::class, $reviewRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendors/42/reviews'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(0, $body['meta']['total']);
        self::assertSame([], $body['data']);
    }

    #[Test]
    public function fallsBackToLegacyIdWhenNumericFindMisses(): void
    {
        // Mobile passes the LEGACY store id; find(v3 PK) misses, so we
        // resolve via findByLegacyId — this is the path that was broken.
        $vendor = $this->makeVendor(900, 'legacy-store');

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findByLegacyId')
            ->with(7)
            ->willReturn($vendor);

        $reviewRepo = $this->createMock(ProductReviewRepository::class);
        $reviewRepo->expects(self::once())
            ->method('findApprovedForVendorPaginated')
            ->with($vendor, 20, 0)
            ->willReturn(['items' => [], 'total' => 3]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $reviewRepo) {
            $em->method('find')->with(Vendor::class, 7)->willReturn(null);
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [ProductReview::class, $reviewRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendors/7/reviews'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(3, $body['meta']['total']);
    }

    #[Test]
    public function resolvesBySlugWhenNonNumeric(): void
    {
        // Web passes the vendor slug.
        $vendor = $this->makeVendor(42, 'almas-fashion');

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findBySlug')
            ->with('almas-fashion')
            ->willReturn($vendor);
        $vendorRepo->expects(self::never())->method('findByLegacyId');

        $reviewRepo = $this->createMock(ProductReviewRepository::class);
        $reviewRepo->expects(self::once())
            ->method('findApprovedForVendorPaginated')
            ->with($vendor, 20, 0)
            ->willReturn(['items' => [], 'total' => 5]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $reviewRepo) {
            // Non-numeric arg never calls em->find().
            $em->expects(self::never())->method('find');
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [ProductReview::class, $reviewRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendors/almas-fashion/reviews'));

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(5, $body['meta']['total']);
    }

    #[Test]
    public function returns404WhenSlugVendorInactive(): void
    {
        $vendor = $this->makeVendor(42, 'inactive');
        $vendor->setActive(false);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findBySlug')->willReturn($vendor);

        $em = $this->stubEm(function ($em) use ($vendorRepo) {
            $em->method('getRepository')->with(Vendor::class)->willReturn($vendorRepo);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendors/inactive/reviews'));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenNumericVendorUnresolvable(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByLegacyId')->willReturn(null);

        $em = $this->stubEm(function ($em) use ($vendorRepo) {
            $em->method('find')->willReturn(null);
            $em->method('getRepository')->with(Vendor::class)->willReturn($vendorRepo);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/vendors/9999/reviews'));

        self::assertSame(404, $response->getStatusCode());
    }

    private function makeVendor(int $id, string $slug): Vendor
    {
        $vendor = new Vendor($slug, ucfirst($slug), $slug . '@example.test');
        $rp = new \ReflectionProperty($vendor, 'id');
        $rp->setAccessible(true);
        $rp->setValue($vendor, $id);
        return $vendor;
    }
}
