<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Http\Controllers\Catalog\ListProductsController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Focused tests for M3.1.5.5e's resolveLabelId helper in
 * ListProductsController. Mirrors the structure of
 * ListProductsControllerLegacyIdTest (which covers vendor_id +
 * category_id resolution).
 *
 * Verifies:
 *   - ?label=<slug> resolves to a VendorLabel via findOneBy
 *   - ?label_id=<legacy_int> resolves via findActiveByLegacyId
 *   - Slug wins precedence when both ?label and ?label_id supplied
 *   - Unknown slug / unknown legacy id / non-numeric label_id all
 *     yield an empty result envelope (not 404, empty list is the
 *     correct semantic for "no products under this label")
 */
#[CoversClass(ListProductsController::class)]
final class ListProductsControllerLabelTest extends HttpTestCase
{
    #[Test]
    public function resolvesLabelSlugToInternalId(): void
    {
        $label = $this->makeLabelWithInternalId('eid-collection', 55);

        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->expects(self::once())
            ->method('findOneBy')
            ->with(['slug' => 'eid-collection', 'isActive' => true])
            ->willReturn($label);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) => $f['labelId'] === 55))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(function ($em) use ($labelRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [VendorLabel::class, $labelRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?label=eid-collection'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function resolvesLabelIdQueryParamToInternalId(): void
    {
        $label = $this->makeLabelWithInternalId('eid-collection', 55);

        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->expects(self::once())
            ->method('findActiveByLegacyId')
            ->with(4)
            ->willReturn($label);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f) => $f['labelId'] === 55))
            ->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(function ($em) use ($labelRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [VendorLabel::class, $labelRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?label_id=4'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function slugWinsOverLegacyIdWhenBothProvided(): void
    {
        $label = $this->makeLabelWithInternalId('eid-collection', 55);

        $labelRepo = $this->createMock(VendorLabelRepository::class);
        // Slug wins, findOneBy fires, findActiveByLegacyId does NOT.
        $labelRepo->expects(self::once())
            ->method('findOneBy')
            ->willReturn($label);
        $labelRepo->expects(self::never())->method('findActiveByLegacyId');

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturn(['items' => [], 'total' => 0]);

        $em = $this->stubEm(function ($em) use ($labelRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [VendorLabel::class, $labelRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?label=eid-collection&label_id=99'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function returnsEmptyEnvelopeWhenLegacyLabelIdNotFound(): void
    {
        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->method('findActiveByLegacyId')->willReturn(null);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::never())->method('findActivePaginated');

        $em = $this->stubEm(function ($em) use ($labelRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [VendorLabel::class, $labelRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?label_id=9999'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    #[Test]
    public function treatsNonNumericLabelIdAsNotFound(): void
    {
        $labelRepo = $this->createMock(VendorLabelRepository::class);
        $labelRepo->expects(self::never())->method('findActiveByLegacyId');
        $labelRepo->expects(self::never())->method('findOneBy');

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::never())->method('findActivePaginated');

        $em = $this->stubEm(function ($em) use ($labelRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [VendorLabel::class, $labelRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/products?label_id=not-a-number'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
    }

    private function makeLabelWithInternalId(string $slug, int $internalId): VendorLabel
    {
        $vendor = new Vendor('vendor-' . $internalId, 'Vendor', 'v@example.test');
        $label = new VendorLabel($vendor, $slug, ucfirst($slug));
        $ref = new \ReflectionProperty(VendorLabel::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($label, $internalId);
        return $label;
    }
}
