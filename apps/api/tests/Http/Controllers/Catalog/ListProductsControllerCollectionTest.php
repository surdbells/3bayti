<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductCollection;
use Bayti\Api\Domain\Catalog\ProductCollectionRepository;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Http\Controllers\Catalog\ListProductsController;
use Bayti\Api\Tests\Http\HttpTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * GET /v3/products?collection=<slug> — the admin-curated collection filter
 * that powers the web + mobile collection pages. Regression cover: the
 * controller used to build its own filter array without the collection axis,
 * so ?collection= was silently ignored and every collection page listed the
 * whole catalog (while the facets endpoint, which uses ProductFilterParser,
 * was correctly scoped).
 */
#[CoversClass(ListProductsController::class)]
final class ListProductsControllerCollectionTest extends HttpTestCase
{
    #[Test]
    public function collectionSlugIsResolvedAndPassedToTheRepository(): void
    {
        $collection = $this->makeCollection(31, 'summer-edit');

        $collectionRepo = $this->createMock(ProductCollectionRepository::class);
        $collectionRepo->expects(self::once())
            ->method('findBySlug')
            ->with('summer-edit', true)
            ->willReturn($collection);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f): bool => ($f['collectionId'] ?? null) === 31))
            ->willReturn(['items' => [], 'total' => 0]);

        $this->bindEm($collectionRepo, $productRepo);

        $response = $this->handle($this->jsonRequest('GET', '/v3/products?collection=summer-edit'));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function unknownOrInactiveCollectionReturnsAnEmptyListNotTheWholeCatalog(): void
    {
        $collectionRepo = $this->createMock(ProductCollectionRepository::class);
        $collectionRepo->method('findBySlug')->willReturn(null);

        $productRepo = $this->createMock(ProductRepository::class);
        // Must short-circuit: never query products for an unknown collection.
        $productRepo->expects(self::never())->method('findActivePaginated');

        $this->bindEm($collectionRepo, $productRepo);

        $response = $this->handle($this->jsonRequest('GET', '/v3/products?collection=ghost'));

        self::assertSame(200, $response->getStatusCode());
        $body = $this->jsonBody($response);
        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    #[Test]
    public function noCollectionParamLeavesTheFilterUnset(): void
    {
        $collectionRepo = $this->createMock(ProductCollectionRepository::class);
        $collectionRepo->expects(self::never())->method('findBySlug');

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->expects(self::once())
            ->method('findActivePaginated')
            ->with(self::callback(fn (array $f): bool => ($f['collectionId'] ?? null) === null))
            ->willReturn(['items' => [], 'total' => 0]);

        $this->bindEm($collectionRepo, $productRepo);

        $response = $this->handle($this->jsonRequest('GET', '/v3/products'));

        self::assertSame(200, $response->getStatusCode());
    }

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

    private function makeCollection(int $id, string $slug): ProductCollection
    {
        $c = new ProductCollection(ucfirst($slug), $slug);
        $ref = new \ReflectionProperty(ProductCollection::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($c, $id);
        return $c;
    }
}
