<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Catalog\ListVendorsController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Coverage for GET /v3/vendors (Stores H2.A), the store directory.
 *
 * Verifies:
 *   - Happy path: active vendors + embedded products + rating in
 *     directoryShape (a publicShape superset, id/logo/cover/is_verified
 *     PLUS rating + product thumbnails)
 *   - Honest pagination: has_more derived from the repo's total, not the
 *     length of the returned page
 *   - Null rating preserved (vendor with zero approved reviews)
 *   - ?q forwarded to the repo, trimmed (blank/whitespace = ignored)
 *   - ?limit clamped to 48
 */
#[CoversClass(ListVendorsController::class)]
final class ListVendorsControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsActiveVendorsWithEmbeddedProductsAndHonestPagination(): void
    {
        $vendor1 = $this->makeVendor('almas-fashion', 'Almas Fashion', 'A luxe label.');
        $vendor1->setVerified(true);
        $vendor1->setLogoUrl('https://cdn.example/almas-logo.jpg');
        $vendor1->setCoverImageUrl('https://cdn.example/almas-cover.jpg');

        $vendor2 = $this->makeVendor('beit-co', 'Beit Co', null);

        $this->setVendorId($vendor1, 1);
        $this->setVendorId($vendor2, 2);

        $product1 = new Product($vendor1, 'silk-abaya', 'Silk Abaya');
        $product1->setPrimaryImageUrl('https://cdn.example/silk.jpg');

        $product2 = new Product($vendor2, 'velvet-shawl', 'Velvet Shawl');
        $product2->setPrimaryImageUrl('https://cdn.example/velvet.jpg');

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findActiveWithStatsPaginated')
            ->with(self::equalTo(2), self::equalTo(0), self::isNull())
            ->willReturn([
                'items' => [
                    ['vendor' => $vendor1, 'rating' => 4.6, 'ratingCount' => 87],
                    ['vendor' => $vendor2, 'rating' => null, 'ratingCount' => 0],
                ],
                'total' => 5,
            ]);

        $productRepo = $this->createMock(ProductRepository::class);
        // findActivePaginated is called once per vendor on the page (N+1 by
        // design, bounded by limit, matching the Spotlight endpoint).
        $productRepo->expects(self::exactly(2))
            ->method('findActivePaginated')
            ->willReturnCallback(function (array $filters) use ($vendor1, $vendor2, $product1, $product2): array {
                if ($filters['vendorId'] === $vendor1->getId()) {
                    return ['items' => [$product1], 'total' => 1];
                }
                if ($filters['vendorId'] === $vendor2->getId()) {
                    return ['items' => [$product2], 'total' => 1];
                }
                return ['items' => [], 'total' => 0];
            });

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors?limit=2'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertArrayHasKey('data', $body);
        self::assertCount(2, $body['data']);

        // directoryShape superset, first card carries every publicShape
        // field PLUS rating + product thumbnails.
        $first = $body['data'][0];
        self::assertSame('almas-fashion', $first['slug']);
        self::assertSame('Almas Fashion', $first['name']);
        self::assertSame('A luxe label.', $first['description']);
        self::assertSame('https://cdn.example/almas-logo.jpg', $first['logo_url']);
        self::assertSame('https://cdn.example/almas-cover.jpg', $first['cover_image_url']);
        self::assertTrue($first['is_verified']);
        self::assertSame(4.6, $first['rating']);
        self::assertSame(87, $first['rating_count']);
        self::assertCount(1, $first['products']);
        self::assertSame('silk-abaya', $first['products'][0]['slug']);
        self::assertSame('https://cdn.example/silk.jpg', $first['products'][0]['image_url']);

        // Second card, null rating preserved (zero approved reviews).
        self::assertSame('beit-co', $body['data'][1]['slug']);
        self::assertNull($body['data'][1]['rating']);
        self::assertSame(0, $body['data'][1]['rating_count']);

        // Honest pagination: total 5 > offset 0 + page of 2 → has_more true.
        self::assertSame(5, $body['meta']['total']);
        self::assertSame(2, $body['meta']['limit']);
        self::assertSame(0, $body['meta']['offset']);
        self::assertTrue(
            $body['meta']['has_more'],
            'has_more must derive from the repo total, not the page length'
        );
    }

    #[Test]
    public function trimsAndForwardsSearchQueryToRepository(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        // %20 on both sides → the controller trims to 'Beit' before the repo.
        $vendorRepo->expects(self::once())
            ->method('findActiveWithStatsPaginated')
            ->with(self::equalTo(24), self::equalTo(0), self::equalTo('Beit'))
            ->willReturn(['items' => [], 'total' => 0]);

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
            $this->jsonRequest('GET', '/v3/vendors?q=%20Beit%20'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(0, $body['data']);
        self::assertSame(0, $body['meta']['total']);
        self::assertFalse($body['meta']['has_more']);
    }

    #[Test]
    public function clampsExcessiveLimitToMax(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        // ?limit=999 must be clamped to 48 BEFORE hitting the repo.
        $vendorRepo->expects(self::once())
            ->method('findActiveWithStatsPaginated')
            ->with(self::equalTo(48), self::equalTo(0), self::isNull())
            ->willReturn(['items' => [], 'total' => 0]);

        $productRepo = $this->createMock(ProductRepository::class);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/vendors?limit=999'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function makeVendor(string $slug, string $name, ?string $description): Vendor
    {
        $v = new Vendor($slug, $name, 'vendor@example.test');
        $v->setDescription($description);
        return $v;
    }

    /**
     * Assign a synthetic id to an unpersisted Vendor so the per-vendor
     * embedded-product callback can distinguish vendors by getId().
     */
    private function setVendorId(Vendor $vendor, int $id): void
    {
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, $id);
    }
}
