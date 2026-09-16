<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\Vision;

use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Ai\Vision\VisionEmbedding;
use Bayti\Api\Ai\Vision\VisualSearchService;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VisualSearchService::class)]
final class VisualSearchServiceTest extends TestCase
{
    #[Test]
    public function returnsEmptyWhenVisionIsDisabled(): void
    {
        $service = new VisualSearchService(
            new FakeVisionEmbedder(false, new VisionEmbedding([1.0, 0.0], 'black abaya')),
            $this->store([]),
            $this->emReturning([]),
        );

        $result = $service->search('bytes', 'image/jpeg');
        self::assertNull($result->description);
        self::assertSame([], $result->products);
    }

    #[Test]
    public function ranksByEmbeddingAndDropsUnsellablePreservingOrder(): void
    {
        $vendor = $this->makeVendor(9);
        // id 3 is nearest to the query [1,0] but is out of stock → must be dropped.
        $p1 = $this->makeProduct($vendor, 1, 'Close A');
        $p2 = $this->makeProduct($vendor, 2, 'Far');
        $p3 = $this->makeProduct($vendor, 3, 'Nearest but sold out', inStock: false);

        $service = new VisualSearchService(
            new FakeVisionEmbedder(true, new VisionEmbedding([1.0, 0.0], 'black abaya')),
            $this->store([1 => [0.99, 0.01], 2 => [0.1, 0.9], 3 => [1.0, 0.0]]),
            $this->emReturning([$p1, $p2, $p3]),
        );

        $result = $service->search('bytes', 'image/jpeg');

        // Cosine order is [3, 1, 2]; the out-of-stock nearest (3) is gated out.
        self::assertSame([1, 2], $result->productIds());
        self::assertSame('black abaya', $result->description);
    }

    #[Test]
    public function fallsBackToCosineWhenPgvectorYieldsNothing(): void
    {
        // pgvector column exists (hasPgvector true) but pgvectorRank returns [] —
        // e.g. embedding_vec not backfilled yet. Must fall back to cosine, not
        // silently return nothing.
        $vendor = $this->makeVendor(9);
        $service = new VisualSearchService(
            new FakeVisionEmbedder(true, new VisionEmbedding([1.0, 0.0], 'black abaya')),
            $this->pgvectorStore(pgIds: [], embeddings: [1 => [0.99, 0.01], 2 => [0.1, 0.9]]),
            $this->emReturning([$this->makeProduct($vendor, 1, 'Near'), $this->makeProduct($vendor, 2, 'Far')]),
        );

        $result = $service->search('bytes', 'image/jpeg');
        self::assertSame([1, 2], $result->productIds());
    }

    #[Test]
    public function keepsDescriptionButNoProductsWhenTheEmbeddingIsEmpty(): void
    {
        $service = new VisualSearchService(
            new FakeVisionEmbedder(true, new VisionEmbedding([], 'a blurry photo')),
            $this->store([1 => [1.0, 0.0]]),
            $this->emReturning([$this->makeProduct($this->makeVendor(9), 1, 'A')]),
        );

        $result = $service->search('bytes', 'image/jpeg');
        self::assertSame('a blurry photo', $result->description);
        self::assertSame([], $result->products);
    }

    // ===== fixtures =====

    /** @param array<int, list<float>> $embeddings */
    private function store(array $embeddings): ProductAiAttributesStore
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(false); // hasPgvector() → false
        $conn->method('fetchAllAssociative')->willReturnCallback(
            static fn (): array => self::embeddingRows($embeddings),
        );
        return new ProductAiAttributesStore($conn);
    }

    /**
     * A store where the pgvector column exists (hasPgvector true) but pgvectorRank
     * returns $pgIds; fetchSellableEmbeddings returns $embeddings for the fallback.
     *
     * @param list<int> $pgIds
     * @param array<int, list<float>> $embeddings
     */
    private function pgvectorStore(array $pgIds, array $embeddings): ProductAiAttributesStore
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(1); // hasPgvector() → true
        $conn->method('fetchFirstColumn')->willReturn($pgIds); // pgvectorRank()
        $conn->method('fetchAllAssociative')->willReturnCallback(
            static fn (): array => self::embeddingRows($embeddings),
        );
        return new ProductAiAttributesStore($conn);
    }

    /**
     * @param array<int, list<float>> $embeddings
     * @return list<array{product_id: int, embedding: string}>
     */
    private static function embeddingRows(array $embeddings): array
    {
        $rows = [];
        foreach ($embeddings as $id => $vec) {
            $rows[] = ['product_id' => $id, 'embedding' => (string) json_encode($vec)];
        }
        return $rows;
    }

    /** @param list<Product> $items */
    private function emReturning(array $items): EntityManagerInterface
    {
        $repo = $this->createMock(ProductRepository::class);
        $repo->method('findBy')->willReturn($items);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class) => $class === Product::class ? $repo : null,
        );
        return $em;
    }

    private function makeVendor(int $id): Vendor
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", "v{$id}@example.test");
        $v->approve();
        $this->setId($v, $id);
        return $v;
    }

    private function makeProduct(Vendor $vendor, int $id, string $name, bool $inStock = true): Product
    {
        $p = new Product(vendor: $vendor, slug: "p-{$id}", name: $name);
        $p->setStatus('active');
        if (!$inStock) {
            $p->setStockStatus(Product::STOCK_OUT);
        }
        $p->setPrice('300.00');
        $this->setId($p, $id);
        return $p;
    }

    private function setId(object $entity, int $id): void
    {
        $ref = new \ReflectionProperty($entity::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($entity, $id);
    }
}
