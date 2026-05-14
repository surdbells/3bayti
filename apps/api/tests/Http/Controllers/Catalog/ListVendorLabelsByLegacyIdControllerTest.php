<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Catalog\ListVendorLabelsByLegacyIdController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Mirrors ListVendorLabelsControllerTest with legacy-id lookups.
 *
 * Backs the mobile store_labels endpoint flip (legacy URL:
 * customer/read_vendor_collection); same response shape as slug
 * variant.
 */
#[CoversClass(ListVendorLabelsByLegacyIdController::class)]
final class ListVendorLabelsByLegacyIdControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsLabelsForActiveVendorByLegacyId(): void
    {
        $vendor = new Vendor('almas-fashion', 'Almas Fashion', 'almas@example.test');
        $label = $this->makeLabel($vendor, 'eid-collection', 'Eid Collection');

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findByLegacyId')
            ->with(7)
            ->willReturn($vendor);

        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->expects(self::once())
            ->method('listActiveByVendor')
            ->with($vendor)
            ->willReturn([$label]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $labelRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [VendorLabel::class, $labelRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/7/labels'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(1, $body['data']);
        self::assertSame('Eid Collection', $body['data'][0]['name']);
    }

    #[Test]
    public function returns404WhenLegacyIdNotFound(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByLegacyId')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Vendor::class)->willReturn($vendorRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/9999/labels'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenVendorInactive(): void
    {
        $vendor = new Vendor('inactive', 'Inactive', 'i@example.test');
        $vendor->setActive(false);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findByLegacyId')->willReturn($vendor);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Vendor::class)->willReturn($vendorRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/8/labels'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenIdPathSegmentIsNonNumeric(): void
    {
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/abc/labels'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    private function makeLabel(Vendor $vendor, string $slug, string $name): VendorLabel
    {
        $label = new VendorLabel($vendor, $slug, $name);
        $ref = new \ReflectionProperty(VendorLabel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($label, 1);
        return $label;
    }
}
