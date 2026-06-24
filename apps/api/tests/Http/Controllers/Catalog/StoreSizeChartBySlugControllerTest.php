<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorSizeChart;
use Bayti\Api\Domain\Catalog\VendorSizeChartRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Catalog\StoreSizeChartBySlugController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * HTTP tests for the PUBLIC slug-keyed store size guide
 * (GET /v3/vendors/{slug}/size-chart) consumed by apps/web.
 *
 * Slug-native sibling of StoreSizeChartByLegacyIdControllerTest: resolves a
 * vendor by slug (active only) and returns the same
 * { data: [ {id,size,values,created_at,updated_at} ], meta:{ total } } shape
 * as the vendor-self-scoped GET /v3/vendor/measurements. No auth required.
 */
#[CoversClass(StoreSizeChartBySlugController::class)]
final class StoreSizeChartBySlugControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsSizeChartRowsForActiveVendorBySlug(): void
    {
        $vendor = new Vendor('almas-fashion', 'Almas Fashion', 'almas@example.test');
        $this->setProp($vendor, 'id', 42);
        $chart = $this->makeChart($vendor, 1, 'M', ['bust' => 92.0, 'waist' => 76.0, 'hip' => 98.0]);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findBySlug')
            ->with('almas-fashion')
            ->willReturn($vendor);

        $chartRepo = $this->createMock(VendorSizeChartRepository::class);
        $chartRepo->expects(self::once())
            ->method('findForVendor')
            ->with(42)
            ->willReturn([$chart]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $chartRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [VendorSizeChart::class, $chartRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/almas-fashion/size-chart'),
        );

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $body = $this->jsonBody($response);
        self::assertSame(1, $body['meta']['total']);
        self::assertCount(1, $body['data']);
        self::assertSame('M', $body['data'][0]['size']);
        self::assertSame(92.0, $body['data'][0]['values']['bust']);
        // Shape parity with the legacy-id / vendor-self endpoints.
        self::assertArrayHasKey('id', $body['data'][0]);
        self::assertArrayHasKey('created_at', $body['data'][0]);
        self::assertArrayHasKey('updated_at', $body['data'][0]);
    }

    #[Test]
    public function returnsEmptyChartWhenVendorHasNoRows(): void
    {
        $vendor = new Vendor('no-chart', 'No Chart Store', 'nc@example.test');
        $this->setProp($vendor, 'id', 5);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findBySlug')->willReturn($vendor);

        $chartRepo = $this->createMock(VendorSizeChartRepository::class);
        $chartRepo->method('findForVendor')->willReturn([]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $chartRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [VendorSizeChart::class, $chartRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/no-chart/size-chart'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(0, $body['meta']['total']);
        self::assertSame([], $body['data']);
    }

    #[Test]
    public function returns404WhenSlugUnknown(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findBySlug')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Vendor::class)->willReturn($vendorRepo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/ghost-store/size-chart'),
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
            $this->jsonRequest('GET', '/v3/vendors/inactive/size-chart'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    /** @param array<string, mixed> $values */
    private function makeChart(Vendor $vendor, int $id, string $size, array $values): VendorSizeChart
    {
        $chart = new VendorSizeChart($vendor, $size, $values);
        $this->setProp($chart, 'id', $id);
        return $chart;
    }

    private function setProp(object $o, string $prop, mixed $val): void
    {
        $rp = new \ReflectionProperty($o, $prop);
        $rp->setAccessible(true);
        $rp->setValue($o, $val);
    }
}
