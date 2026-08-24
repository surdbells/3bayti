<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Catalog\ListVendorLabelsController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Coverage for GET /v3/vendors/{slug}/labels.
 *
 * Verifies:
 *   - Slug resolves to vendor + repo returns ordered labels
 *   - 404 for unknown slug / inactive vendor
 *   - Empty result returns 200 with empty data array (not 404)
 *   - Response shape matches VendorLabelSerializer.publicShape
 */
#[CoversClass(ListVendorLabelsController::class)]
final class ListVendorLabelsControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsLabelsForActiveVendor(): void
    {
        $vendor = new Vendor('almas-fashion', 'Almas Fashion', 'almas@example.test');
        $label = $this->makeLabel($vendor, 'eid-collection', 'Eid Collection', 1);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findBySlug')
            ->with('almas-fashion')
            ->willReturn($vendor);

        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->expects(self::once())
            ->method('listActiveByVendor')
            ->with($vendor)
            ->willReturn([$label]);

        // Active-product count per label for the chip badge (label id 1 -> 3).
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('countActiveByLabelForVendor')->willReturn([1 => 3]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $labelRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [VendorLabel::class, $labelRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/almas-fashion/labels'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(1, $body['data']);
        self::assertSame('eid-collection', $body['data'][0]['slug']);
        self::assertSame('Eid Collection', $body['data'][0]['name']);
        self::assertSame(1, $body['data'][0]['display_order']);
        self::assertSame(3, $body['data'][0]['count']);
        // Total equals count when there's no pagination cap.
        self::assertSame(1, $body['meta']['total']);
    }

    #[Test]
    public function returnsEmptyListForVendorWithNoLabels(): void
    {
        $vendor = new Vendor('no-labels', 'No Labels Inc', 'nl@example.test');

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findBySlug')->willReturn($vendor);

        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->method('listActiveByVendor')->willReturn([]);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('countActiveByLabelForVendor')->willReturn([]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $labelRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [VendorLabel::class, $labelRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/no-labels/labels'),
        );

        // Empty list != not found — 200 with empty data array is the
        // right semantic for "this vendor has no labels yet".
        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    #[Test]
    public function returns404WhenVendorSlugNotFound(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findBySlug')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Vendor::class)->willReturn($vendorRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/nope/labels'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenVendorInactive(): void
    {
        $vendor = new Vendor('inactive', 'Inactive', 'i@example.test');
        $vendor->setActive(false);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findBySlug')->willReturn($vendor);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Vendor::class)->willReturn($vendorRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/inactive/labels'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    private function makeLabel(Vendor $vendor, string $slug, string $name, ?int $displayOrder): VendorLabel
    {
        $label = new VendorLabel($vendor, $slug, $name);
        if ($displayOrder !== null) {
            $label->setDisplayOrder($displayOrder);
        }
        // Force a v3 internal id via reflection for serializer output
        // (Doctrine assigns id on persist; tests don't persist).
        $ref = new \ReflectionProperty(VendorLabel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($label, 1);
        return $label;
    }
}
