<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Catalog\GetVendorByLegacyIdController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

#[CoversClass(GetVendorByLegacyIdController::class)]
final class GetVendorByLegacyIdControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsVendorWhenLegacyIdMatchesActiveVendor(): void
    {
        $vendor = new Vendor('almas-fashion', 'Almas Fashion', 'almas@example.test');
        // Vendor defaults to isActive=true per the entity definition;
        // no explicit activation needed.

        $repo = $this->createMock(VendorRepository::class);
        $repo->expects(self::once())
            ->method('findByLegacyId')
            ->with(7)
            ->willReturn($vendor);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Vendor::class)->willReturn($repo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/7'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame('Almas Fashion', $body['data']['name']);
        self::assertSame('almas-fashion', $body['data']['slug']);
    }

    #[Test]
    public function returns404WhenLegacyIdNotFound(): void
    {
        $repo = $this->createMock(VendorRepository::class);
        $repo->method('findByLegacyId')->willReturn(null);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Vendor::class)->willReturn($repo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/9999'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenVendorIsInactive(): void
    {
        $vendor = new Vendor('inactive-vendor', 'Inactive Vendor', 'inactive@example.test');
        $vendor->setActive(false);

        $repo = $this->createMock(VendorRepository::class);
        $repo->method('findByLegacyId')->willReturn($vendor);

        $em = $this->stubEm(fn ($em) =>
            $em->method('getRepository')->with(Vendor::class)->willReturn($repo));
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/8'),
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function returns404WhenIdPathSegmentIsNonNumeric(): void
    {
        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors/by-legacy-id/foo'),
        );

        self::assertSame(404, $response->getStatusCode());
    }
}
