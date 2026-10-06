<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductCollection;
use Bayti\Api\Domain\Catalog\ProductCollectionRepository;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Http\Controllers\Catalog\GetCollectionController;
use Bayti\Api\Http\Controllers\Catalog\ListCollectionsController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Public storefront collection endpoints:
 *   GET /v3/collections          — shop-by-collection cards
 *   GET /v3/collections/{slug}    — detail + embedded products (mirrors category)
 */
#[CoversClass(ListCollectionsController::class)]
#[CoversClass(GetCollectionController::class)]
final class CollectionsControllerTest extends HttpTestCase
{
    #[Test]
    public function listReturnsActiveCollectionCardsWithRepresentativeImageAndCount(): void
    {
        $summer = $this->makeCollection(31, 'summer-edit', 'Summer Edit');
        $eid = $this->makeCollection(32, 'eid-picks', 'Eid Picks');

        $repo = $this->createMock(ProductCollectionRepository::class);
        $repo->method('findActiveForStorefront')->willReturn([$summer, $eid]);
        $repo->method('representativeImageUrl')->willReturnMap([
            [31, 'https://cdn.example/first-summer.jpg'],
            [32, null], // no live product image → falls back to cover (null here)
        ]);
        $repo->method('countActiveProducts')->willReturnMap([
            [31, 12],
            [32, 0],
        ]);

        $this->bindEm($repo, $this->createMock(ProductRepository::class));

        $response = $this->handle($this->jsonRequest('GET', '/v3/collections'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertCount(2, $body['data']);
        self::assertSame(2, $body['meta']['total']);

        $first = $body['data'][0];
        self::assertSame('summer-edit', $first['slug']);
        self::assertSame('Summer Edit', $first['name']);
        self::assertSame('https://cdn.example/first-summer.jpg', $first['image_url']);
        self::assertSame(12, $first['product_count']);
        // Representative image null + no cover → image_url null (card uses a placeholder).
        self::assertNull($body['data'][1]['image_url']);
    }

    #[Test]
    public function detailReturnsHeaderPlusEmbeddedProductsAndScopesTheFilterToTheCollection(): void
    {
        $col = $this->makeCollection(31, 'summer-edit', 'Summer Edit');
        $vendor = $this->makeVendor(1, 'almas');
        $p1 = new Product($vendor, 'silk-abaya', 'Silk Abaya');
        $p2 = new Product($vendor, 'linen-kaftan', 'Linen Kaftan');

        $repo = $this->createMock(ProductCollectionRepository::class);
        $repo->method('findBySlug')->with('summer-edit', true)->willReturn($col);
        $repo->method('representativeImageUrl')->willReturn('https://cdn.example/first.jpg');
        $repo->method('countActiveProducts')->willReturn(2);

        $productRepo = $this->createMock(ProductRepository::class);
        // Locks that the controller filters by collectionId (+ newest/20/0).
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::equalTo(['collectionId' => 31, 'sort' => 'newest', 'limit' => 20, 'offset' => 0]))
            ->willReturn(['items' => [$p1, $p2], 'total' => 2]);

        $this->bindEm($repo, $productRepo);

        $response = $this->handle($this->jsonRequest('GET', '/v3/collections/summer-edit'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);

        self::assertSame('summer-edit', $body['data']['slug']);
        self::assertSame('https://cdn.example/first.jpg', $body['data']['image_url']);
        self::assertSame(2, $body['data']['product_count']);
        self::assertCount(2, $body['data']['products']);
        self::assertSame('silk-abaya', $body['data']['products'][0]['slug']);
        self::assertSame(2, $body['meta']['total_products']);
        self::assertSame(20, $body['meta']['page_size']);
    }

    #[Test]
    public function detailReturns404ForUnknownOrInactiveSlug(): void
    {
        $repo = $this->createMock(ProductCollectionRepository::class);
        $repo->method('findBySlug')->willReturn(null); // unknown OR inactive (activeOnly)

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::never())->method('findActivePaginated');

        $this->bindEm($repo, $productRepo);

        $response = $this->handle($this->jsonRequest('GET', '/v3/collections/ghost'));

        self::assertSame(404, $response->getStatusCode());
    }

    // ===== Helpers =====

    private function bindEm(ProductCollectionRepository $collectionRepo, ProductRepository $productRepo): void
    {
        $em = $this->stubEm(function ($em) use ($collectionRepo, $productRepo): void {
            $em->method('getRepository')->willReturnMap([
                [ProductCollection::class, $collectionRepo],
                [Product::class, $productRepo],
            ]);
        });
        $this->bind(EntityManagerInterface::class, $em);
    }

    private function makeCollection(int $id, string $slug, string $name): ProductCollection
    {
        $c = new ProductCollection($name, $slug);
        $ref = new \ReflectionProperty(ProductCollection::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($c, $id);
        return $c;
    }

    private function makeVendor(int $id, string $slug): Vendor
    {
        $v = new Vendor($slug, ucfirst($slug), 'v@example.test');
        $ref = new \ReflectionProperty(Vendor::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($v, $id);
        return $v;
    }
}
