<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Ai\CompleteLook;

use Bayti\Api\Ai\CompleteLook\CompleteTheLookService;
use Bayti\Api\Ai\Enrichment\ProductAiAttributesStore;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\RecommendationsService;
use Bayti\Api\Domain\Catalog\Vendor;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompleteTheLookService::class)]
final class CompleteTheLookServiceTest extends TestCase
{
    #[Test]
    public function returnsOrderableInStockComplementsFromOtherCategories(): void
    {
        $vendor = $this->makeVendor(9);
        $abayas = $this->makeCategory(10, 'abayas');
        $bags = $this->makeCategory(20, 'bags');
        $scarves = $this->makeCategory(30, 'scarves');

        $seed = $this->makeProduct($vendor, 1, 'Seed Abaya', $abayas);
        $pool = [
            $this->makeProduct($vendor, 2, 'Another Abaya', $abayas),                 // same category → excluded
            $this->makeProduct($vendor, 3, 'Leather Bag', $bags),                     // complement
            $this->makeProduct($vendor, 4, 'Silk Scarf', $scarves),                   // complement
            $this->makeProduct($vendor, 5, 'Draft Bag', $bags, orderable: false),     // not orderable → excluded
            $this->makeProduct($vendor, 6, 'Sold Out Scarf', $scarves, inStock: false), // out of stock → excluded
        ];

        $service = $this->service($pool, $this->noEmbedStore(), $this->recs([]));
        $items = $service->forProduct($seed, 'en', 6);

        self::assertSame([3, 4], array_map(static fn ($i) => $i->product->getId(), $items));
        self::assertSame(['bags', 'scarves'], array_map(static fn ($i) => $i->complementCategorySlug, $items));
        self::assertSame('Completes the look', $items[0]->reason);
    }

    #[Test]
    public function reordersComplementsBySeedEmbeddingWhenEnriched(): void
    {
        $vendor = $this->makeVendor(9);
        $abayas = $this->makeCategory(10, 'abayas');
        $bags = $this->makeCategory(20, 'bags');
        $scarves = $this->makeCategory(30, 'scarves');

        $seed = $this->makeProduct($vendor, 1, 'Seed Abaya', $abayas);
        $pool = [
            $this->makeProduct($vendor, 2, 'Far Bag', $bags),      // best-seller order first
            $this->makeProduct($vendor, 3, 'Close Scarf', $scarves),
        ];

        // Seed [1,0]; product 3 (0.99,0.01) is closest, then product 2 (0.1,0.9).
        $embeddings = [1 => [1.0, 0.0], 2 => [0.1, 0.9], 3 => [0.99, 0.01]];
        $service = $this->service($pool, $this->embedStore($embeddings), $this->recs([]));
        $items = $service->forProduct($seed, 'en', 6);

        self::assertSame([3, 2], array_map(static fn ($i) => $i->product->getId(), $items));
    }

    #[Test]
    public function fallsBackToRecommendationsAndRevalidatesInStock(): void
    {
        $vendor = $this->makeVendor(9);
        $abayas = $this->makeCategory(10, 'abayas');
        $bags = $this->makeCategory(20, 'bags');

        $seed = $this->makeProduct($vendor, 1, 'Seed Abaya', $abayas);
        // Every catalogue product is the SAME category as the seed → no cross-category pool.
        $pool = [$this->makeProduct($vendor, 2, 'Another Abaya', $abayas)];

        // Recommendations return a valid complement + an out-of-stock one.
        $recProduct = $this->makeProduct($vendor, 7, 'Recommended Bag', $bags);
        $recOos = $this->makeProduct($vendor, 8, 'Recommended Sold Out', $bags, inStock: false);
        $service = $this->service($pool, $this->noEmbedStore(), $this->recs([
            ['product' => $recProduct, 'score' => '1.0', 'source' => 'copurchase'],
            ['product' => $recOos, 'score' => '0.9', 'source' => 'copurchase'],
        ]));

        $items = $service->forProduct($seed, 'en', 6);

        // The out-of-stock recommendation is filtered out (onlyOrderable doesn't check stock).
        self::assertSame([7], array_map(static fn ($i) => $i->product->getId(), $items));
    }

    #[Test]
    public function reasonIsLocalisedForArabic(): void
    {
        $vendor = $this->makeVendor(9);
        $seed = $this->makeProduct($vendor, 1, 'Seed Abaya', $this->makeCategory(10, 'abayas'));
        $pool = [$this->makeProduct($vendor, 3, 'Leather Bag', $this->makeCategory(20, 'bags'))];

        $service = $this->service($pool, $this->noEmbedStore(), $this->recs([]));
        $items = $service->forProduct($seed, 'ar', 6);

        self::assertCount(1, $items);
        self::assertSame('يكمل الإطلالة', $items[0]->reason);
    }

    // ===== fixtures =====

    /**
     * @param list<Product> $poolItems
     */
    private function service(array $poolItems, ProductAiAttributesStore $store, RecommendationsService $recs): CompleteTheLookService
    {
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findActivePaginated')->willReturn(['items' => $poolItems, 'total' => count($poolItems)]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            static fn (string $class) => $class === Product::class ? $productRepo : null,
        );

        return new CompleteTheLookService($em, $store, $recs);
    }

    /** @param list<array{product: Product, score: string, source: string}> $return */
    private function recs(array $return): RecommendationsService
    {
        $recs = $this->createMock(RecommendationsService::class);
        $recs->method('getRecommendationsForProduct')->willReturn($return);
        return $recs;
    }

    private function noEmbedStore(): ProductAiAttributesStore
    {
        return new ProductAiAttributesStore($this->createMock(Connection::class));
    }

    /** @param array<int, list<float>> $embeddings id => vector */
    private function embedStore(array $embeddings): ProductAiAttributesStore
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchOne')->willReturn(1); // hasAnyEmbedding() → true
        $conn->method('fetchAllAssociative')->willReturnCallback(
            static function (string $sql, array $params = []) use ($embeddings): array {
                /** @var list<int> $ids */
                $ids = $params[0] ?? [];
                $rows = [];
                foreach ($ids as $id) {
                    if (isset($embeddings[$id])) {
                        $rows[] = ['product_id' => $id, 'embedding' => (string) json_encode($embeddings[$id])];
                    }
                }
                return $rows;
            },
        );
        return new ProductAiAttributesStore($conn);
    }

    private function makeVendor(int $id): Vendor
    {
        $v = new Vendor("vendor-{$id}", "Vendor {$id}", "v{$id}@example.test");
        $v->approve();
        $this->setId($v, $id);
        return $v;
    }

    private function makeCategory(int $id, string $slug): Category
    {
        $c = new Category($slug, ucfirst($slug));
        $this->setId($c, $id);
        return $c;
    }

    private function makeProduct(Vendor $vendor, int $id, string $name, Category $category, bool $orderable = true, bool $inStock = true): Product
    {
        $p = new Product(vendor: $vendor, slug: "p-{$id}", name: $name);
        $p->setCategory($category);
        if ($orderable) {
            $p->setStatus('active');
        }
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
