<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Controllers\Catalog\ListFeaturedVendorsController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Coverage for GET /v3/featured-vendors (M3.2.X.2-C).
 *
 * Verifies:
 *   - Happy path: featured vendors + embedded products + rating
 *   - Empty case: no featured vendors → valid empty envelope
 *   - Limit clamping: ?limit=999 → clamped to 12
 *   - Invalid limit: negative / non-numeric → falls back to default 4
 *   - Response shape matches the apps/web FeaturedVendor contract
 *   - Null rating preserved (vendor with zero approved reviews)
 *   - has_more = false always (Spotlight isn't paginated)
 */
#[CoversClass(ListFeaturedVendorsController::class)]
final class ListFeaturedVendorsControllerTest extends HttpTestCase
{
    #[Test]
    public function returnsFeaturedVendorsWithEmbeddedProducts(): void
    {
        $vendor1 = $this->makeVendor('almas-fashion', 'Almas Fashion', 'A luxe label.');
        $vendor1->setFeatured(true);

        $vendor2 = $this->makeVendor('beit-co', 'Beit Co', null);
        $vendor2->setFeatured(true);

        $product1 = new Product($vendor1, 'silk-abaya', 'Silk Abaya');
        $product1->setPrimaryImageUrl('https://cdn.example/silk.jpg');

        $product2 = new Product($vendor2, 'velvet-shawl', 'Velvet Shawl');
        $product2->setPrimaryImageUrl('https://cdn.example/velvet.jpg');

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findFeaturedWithStats')
            ->with(self::equalTo(4), self::equalTo(0))
            ->willReturn([
                ['vendor' => $vendor1, 'rating' => 4.6, 'ratingCount' => 87],
                ['vendor' => $vendor2, 'rating' => null, 'ratingCount' => 0],
            ]);

        $productRepo = $this->createMock(ProductRepository::class);
        // findActivePaginated is called once per featured vendor (N+1 by design).
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
            $this->jsonRequest('GET', '/v3/featured-vendors'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // Envelope structure
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('meta', $body);

        // 2 featured vendors returned
        self::assertCount(2, $body['data']);

        // First vendor — with rating
        self::assertSame('almas-fashion', $body['data'][0]['slug']);
        self::assertSame('Almas Fashion', $body['data'][0]['name']);
        self::assertSame('A luxe label.', $body['data'][0]['description']);
        self::assertSame(4.6, $body['data'][0]['rating']);
        self::assertSame(87, $body['data'][0]['rating_count']);
        self::assertCount(1, $body['data'][0]['products']);
        self::assertSame('silk-abaya', $body['data'][0]['products'][0]['slug']);
        self::assertSame('https://cdn.example/silk.jpg', $body['data'][0]['products'][0]['image_url']);

        // Second vendor — no rating
        self::assertSame('beit-co', $body['data'][1]['slug']);
        self::assertNull($body['data'][1]['description']);
        self::assertNull(
            $body['data'][1]['rating'],
            'Vendor with zero approved reviews must serialize rating: null'
        );
        self::assertSame(0, $body['data'][1]['rating_count']);

        // Meta
        self::assertSame(2, $body['meta']['total']);
        self::assertSame(4, $body['meta']['limit']);
        self::assertSame(0, $body['meta']['offset']);
        self::assertFalse(
            $body['meta']['has_more'],
            'Spotlight is curated; has_more must always be false'
        );
    }

    #[Test]
    public function emptyFeaturedAndEmptyFallbackReturnsValidEmptyEnvelope(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findFeaturedWithStats')
            ->willReturn([]);
        // Stores H0.1: an empty featured set triggers the verified-vendor
        // fallback. With the fallback ALSO empty (no verified vendors at
        // all), the envelope stays empty.
        $vendorRepo->expects(self::once())
            ->method('findTopVerifiedWithStats')
            ->willReturn([]);

        $productRepo = $this->createMock(ProductRepository::class);
        // Must NOT be called when neither featured nor fallback yields
        // vendors — the early return prevents wasted product queries.
        $productRepo->expects(self::never())->method('findActivePaginated');

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/featured-vendors'),
        );

        // 200 not 404 — empty curation is a legitimate state
        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
        self::assertFalse($body['meta']['has_more']);
    }

    #[Test]
    public function emptyFeaturedFallsBackToTopVerifiedVendors(): void
    {
        // No vendor is admin-flagged featured...
        $verified1 = $this->makeVendor('noor-atelier', 'Noor Atelier', 'A verified label.');
        $verified2 = $this->makeVendor('rana-couture', 'Rana Couture', null);
        $this->setVendorId($verified1, 801);
        $this->setVendorId($verified2, 802);

        $p1 = new Product($verified1, 'linen-kaftan', 'Linen Kaftan');
        $p1->setPrimaryImageUrl('https://cdn.example/linen.jpg');
        $p2 = new Product($verified2, 'pearl-gown', 'Pearl Gown');
        $p2->setPrimaryImageUrl('https://cdn.example/pearl.jpg');

        $vendorRepo = $this->createMock(VendorRepository::class);
        // Empty featured → triggers the fallback.
        $vendorRepo->expects(self::once())
            ->method('findFeaturedWithStats')
            ->willReturn([]);
        // ...so we fall back to top verified vendors (with stats).
        $vendorRepo->expects(self::once())
            ->method('findTopVerifiedWithStats')
            ->willReturn([
                ['vendor' => $verified1, 'rating' => 4.2, 'ratingCount' => 30],
                ['vendor' => $verified2, 'rating' => null, 'ratingCount' => 0],
            ]);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')
            ->willReturnCallback(function (array $filters) use ($verified1, $verified2, $p1, $p2): array {
                if ($filters['vendorId'] === $verified1->getId()) {
                    return ['items' => [$p1], 'total' => 1];
                }
                if ($filters['vendorId'] === $verified2->getId()) {
                    return ['items' => [$p2], 'total' => 1];
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
            $this->jsonRequest('GET', '/v3/featured-vendors'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // Both verified vendors surface with their embedded products,
        // exactly as featured vendors would — same wire contract.
        self::assertCount(2, $body['data']);
        self::assertSame('noor-atelier', $body['data'][0]['slug']);
        self::assertSame(4.2, $body['data'][0]['rating']);
        self::assertSame('linen-kaftan', $body['data'][0]['products'][0]['slug']);
        self::assertSame('rana-couture', $body['data'][1]['slug']);
        self::assertNull($body['data'][1]['rating']);
        self::assertSame('pearl-gown', $body['data'][1]['products'][0]['slug']);
        self::assertSame(2, $body['meta']['total']);
    }

    #[Test]
    public function fallbackSkipsVerifiedVendorsWithoutInStockProducts(): void
    {
        // In the fallback path, a verified vendor with no in-stock
        // products must NOT produce an empty Spotlight card — it's
        // skipped so the surface always looks populated. (Contrast with
        // vendorWithNoProductsStillAppears: a *curated* featured vendor
        // is kept even when empty, because an admin chose to feature it.)
        $hasStock = $this->makeVendor('with-stock', 'With Stock', null);
        $noStock = $this->makeVendor('no-stock', 'No Stock', null);
        $this->setVendorId($hasStock, 902);
        $this->setVendorId($noStock, 901);
        $p = new Product($hasStock, 'item', 'Item');

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findFeaturedWithStats')->willReturn([]);
        $vendorRepo->method('findTopVerifiedWithStats')->willReturn([
            ['vendor' => $noStock, 'rating' => null, 'ratingCount' => 0],
            ['vendor' => $hasStock, 'rating' => null, 'ratingCount' => 0],
        ]);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')
            ->willReturnCallback(function (array $filters) use ($hasStock, $p): array {
                if ($filters['vendorId'] === $hasStock->getId()) {
                    return ['items' => [$p], 'total' => 1];
                }
                return ['items' => [], 'total' => 0]; // noStock → skipped
            });

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/featured-vendors'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        // Only the vendor that actually has in-stock products appears.
        self::assertCount(1, $body['data']);
        self::assertSame('with-stock', $body['data'][0]['slug']);
    }

    #[Test]
    public function limitClampedAtTwelve(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        // Verify the controller clamps ?limit=999 to 12 BEFORE
        // calling the repository.
        $vendorRepo->expects(self::once())
            ->method('findFeaturedWithStats')
            ->with(self::equalTo(12), self::equalTo(0))
            ->willReturn([]);

        $productRepo = $this->createMock(ProductRepository::class);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/featured-vendors?limit=999'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(12, $body['meta']['limit'], 'limit echoed back to caller');
    }

    #[Test]
    public function customValidLimitForwardedToRepository(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findFeaturedWithStats')
            ->with(self::equalTo(2), self::equalTo(0))
            ->willReturn([]);

        $productRepo = $this->createMock(ProductRepository::class);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/featured-vendors?limit=2'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame(2, $body['meta']['limit']);
    }

    #[Test]
    public function negativeLimitFallsBackToDefault(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        // -1 is not ctype_digit, so falls back to default 4
        $vendorRepo->expects(self::once())
            ->method('findFeaturedWithStats')
            ->with(self::equalTo(4), self::equalTo(0))
            ->willReturn([]);

        $productRepo = $this->createMock(ProductRepository::class);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/featured-vendors?limit=-5'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function nonNumericLimitFallsBackToDefault(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findFeaturedWithStats')
            ->with(self::equalTo(4), self::equalTo(0))
            ->willReturn([]);

        $productRepo = $this->createMock(ProductRepository::class);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/featured-vendors?limit=abc'),
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function vendorWithNoProductsStillAppears(): void
    {
        // Edge case: vendor flagged featured but with zero active
        // products. They should still appear in the response with
        // an empty products array — admin chose to feature them.
        $vendor = $this->makeVendor('empty-vendor', 'Empty Vendor', null);
        $vendor->setFeatured(true);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findFeaturedWithStats')->willReturn([
            ['vendor' => $vendor, 'rating' => null, 'ratingCount' => 0],
        ]);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturn([
            'items' => [],
            'total' => 0,
        ]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/featured-vendors'),
        );

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertCount(1, $body['data']);
        self::assertSame([], $body['data'][0]['products']);
    }

    #[Test]
    public function topLevelKeysMatchAppsWebContract(): void
    {
        // Lock the wire contract: exactly these keys appear in each
        // FeaturedVendor object. Any divergence breaks apps/web's
        // strict TypeScript interface.
        $vendor = $this->makeVendor('test-vendor', 'Test Vendor', 'Desc');
        $vendor->setFeatured(true);

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findFeaturedWithStats')->willReturn([
            ['vendor' => $vendor, 'rating' => 4.5, 'ratingCount' => 10],
        ]);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturn([
            'items' => [],
            'total' => 0,
        ]);

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle(
            $this->jsonRequest('GET', '/v3/featured-vendors'),
        );

        $body = $this->jsonBody($response);
        self::assertEqualsCanonicalizing(
            ['slug', 'name', 'description', 'rating', 'rating_count', 'products'],
            array_keys($body['data'][0]),
            'Exact FeaturedVendor key set must be preserved'
        );
    }

    #[Test]
    public function embedsUpToFiveNewestInStockProductsPerVendor(): void
    {
        $vendor = $this->makeVendor('almas-fashion', 'Almas Fashion', 'A luxe label.');
        $vendor->setFeatured(true);

        $product = new Product($vendor, 'silk-abaya', 'Silk Abaya');
        $product->setPrimaryImageUrl('https://cdn.example/silk.jpg');

        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->method('findFeaturedWithStats')->willReturn([
            ['vendor' => $vendor, 'rating' => null, 'ratingCount' => 0],
        ]);

        $capturedFilters = null;
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->willReturnCallback(function (array $filters) use (&$capturedFilters, $product): array {
                $capturedFilters = $filters;
                return ['items' => [$product], 'total' => 1];
            });

        $em = $this->stubEm(function ($em) use ($vendorRepo, $productRepo) {
            $em->method('getRepository')->willReturnMap([
                [Vendor::class, $vendorRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);

        $response = $this->handle($this->jsonRequest('GET', '/v3/featured-vendors'));
        self::assertSame(200, $response->getStatusCode());

        // Stores #4: the spotlight embed asks for the 5 newest IN-STOCK
        // products per vendor, in the same request.
        self::assertIsArray($capturedFilters);
        self::assertSame(5, $capturedFilters['limit'], 'embed must request up to 5 products');
        self::assertTrue($capturedFilters['inStock'] ?? false, 'embed must filter to in-stock products');
        self::assertSame('newest', $capturedFilters['sort'], 'embed must be newest-first');
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
     * Without this, unpersisted entities all report id=null and a mock
     * keyed on vendorId can't tell them apart.
     */
    private function setVendorId(Vendor $vendor, int $id): void
    {
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($vendor, $id);
    }
}
