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
    public function emptyFeaturedSetReturnsValidEmptyEnvelope(): void
    {
        $vendorRepo = $this->createMock(VendorRepository::class);
        $vendorRepo->expects(self::once())
            ->method('findFeaturedWithStats')
            ->willReturn([]);

        $productRepo = $this->createMock(ProductRepository::class);
        // Must NOT be called when there are no featured vendors —
        // early return after the empty check prevents wasted queries.
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

    private function makeVendor(string $slug, string $name, ?string $description): Vendor
    {
        $v = new Vendor($slug, $name, 'vendor@example.test');
        $v->setDescription($description);
        return $v;
    }
}
