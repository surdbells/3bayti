<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRecommendation;
use Bayti\Api\Domain\Catalog\ProductRecommendationRepository;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\RecommendationsService;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RecommendationsService (M3.2.X.12-F).
 *
 * Verifies:
 *   - Pre-computed recommendations returned when available
 *   - Empty results trigger popular-fallback path
 *   - Popular fallback excludes the source product
 *   - Limit clamping [3, 20]
 *   - Observability (used_fallback flag in log)
 */
#[CoversClass(RecommendationsService::class)]
final class RecommendationsServiceTest extends TestCase
{
    #[Test]
    public function returnsPreComputedRecommendationsWhenAvailable(): void
    {
        $source = $this->makeProduct(100);
        $rec1 = $this->makeRec($source, $this->makeProduct(200), '23.0000', 'copurchase', 1);
        $rec2 = $this->makeRec($source, $this->makeProduct(300), '15.0000', 'copurchase', 2);

        $service = $this->makeService(
            preComputedRecs: [$rec1, $rec2],
        );

        $result = $service->getRecommendationsForProduct(100, limit: 10);

        self::assertCount(2, $result);
        self::assertSame(200, $result[0]['product']->getId());
        self::assertSame('23.0000', $result[0]['score']);
        self::assertSame('copurchase', $result[0]['source']);
    }

    #[Test]
    public function fallsBackToPopularWhenNoPreComputed(): void
    {
        // Pre-computed table is empty for product 100
        $service = $this->makeService(
            preComputedRecs: [],
            popularRows: [
                ['product_id' => 200, 'units_sold' => 50],
                ['product_id' => 300, 'units_sold' => 30],
            ],
            popularProducts: [
                200 => $this->makeProduct(200),
                300 => $this->makeProduct(300),
            ],
        );

        $result = $service->getRecommendationsForProduct(100, limit: 10);

        self::assertCount(2, $result);
        self::assertSame(200, $result[0]['product']->getId());
        self::assertSame('50.0000', $result[0]['score']);
        self::assertSame('fallback_popular', $result[0]['source']);

        self::assertSame(300, $result[1]['product']->getId());
        self::assertSame('30.0000', $result[1]['score']);
    }

    #[Test]
    public function popularFallbackExcludesSourceProduct(): void
    {
        // Verify the SQL excludes the source product id from the
        // popular query — a product cannot be its own fallback rec
        $captured = $this->captureSql();
        $service = new RecommendationsService($captured['em'], new InMemoryLogger());
        $service->getRecommendationsForProduct(100);

        // The fallback SQL is the only executeQuery (pre-computed
        // path uses ORM findTopForProduct which doesn't show here)
        $sql = $captured['queries'][0]['sql'];
        self::assertStringContainsString('oi.product_id != ?', $sql);

        // First param is the excluded product id
        self::assertSame(100, $captured['queries'][0]['params'][0]);
    }

    #[Test]
    public function popularFallbackHandlesDeletedProductsGracefully(): void
    {
        // SQL returns product_id=200 + 300 from order_items, but
        // findBy only returns product 200 (product 300 was deleted
        // between the cron and now). Service should skip 300
        // silently rather than error.
        $service = $this->makeService(
            preComputedRecs: [],
            popularRows: [
                ['product_id' => 200, 'units_sold' => 50],
                ['product_id' => 300, 'units_sold' => 30],
            ],
            popularProducts: [
                200 => $this->makeProduct(200),
                // 300 missing — simulates deletion
            ],
        );

        $result = $service->getRecommendationsForProduct(100);
        self::assertCount(1, $result);
        self::assertSame(200, $result[0]['product']->getId());
    }

    #[Test]
    public function limitClampedToMinimum(): void
    {
        $service = $this->makeService(preComputedRecs: []);
        // Limit 1 should clamp to MIN_LIMIT = 3
        $captured = $this->captureSql();
        $service = new RecommendationsService($captured['em'], new InMemoryLogger());
        $service->getRecommendationsForProduct(100, limit: 1);

        // The LIMIT placeholder is the last param of the popular SQL
        $params = $captured['queries'][0]['params'];
        self::assertSame(3, $params[count($params) - 1]);  // MIN_LIMIT
    }

    #[Test]
    public function limitClampedToMaximum(): void
    {
        $captured = $this->captureSql();
        $service = new RecommendationsService($captured['em'], new InMemoryLogger());
        $service->getRecommendationsForProduct(100, limit: 999);

        $params = $captured['queries'][0]['params'];
        self::assertSame(20, $params[count($params) - 1]);  // MAX_LIMIT
    }

    #[Test]
    public function emitsObservabilityLogWithUsedFallbackFlag(): void
    {
        $logger = new InMemoryLogger();

        // Pre-computed path
        $source = $this->makeProduct(100);
        $rec = $this->makeRec($source, $this->makeProduct(200), '10.0000', 'copurchase', 1);
        $service = $this->makeService(preComputedRecs: [$rec], logger: $logger);
        $service->getRecommendationsForProduct(100);

        $records = $logger->findByMessage('recommendations.product.served');
        self::assertCount(1, $records);
        self::assertFalse($records[0]['context']['used_fallback']);
        self::assertSame(100, $records[0]['context']['product_id']);
        self::assertSame(1, $records[0]['context']['returned']);
    }

    #[Test]
    public function fallbackPathLogsUsedFallbackTrue(): void
    {
        $logger = new InMemoryLogger();
        $service = $this->makeService(
            preComputedRecs: [],
            popularRows: [['product_id' => 200, 'units_sold' => 50]],
            popularProducts: [200 => $this->makeProduct(200)],
            logger: $logger,
        );

        $service->getRecommendationsForProduct(100);

        $records = $logger->findByMessage('recommendations.product.served');
        self::assertCount(1, $records);
        self::assertTrue($records[0]['context']['used_fallback']);
    }

    // =================================================================
    // getRecommendationsForUser (M3.2.X.12-G personalized path)
    // =================================================================

    #[Test]
    public function userPathReturnsPopularFallbackWhenNoHistory(): void
    {
        // First query (findUserTopCategory) returns no row → null
        // category → service falls through to popular fallback.
        $service = $this->makeUserService(
            topCategoryRow: false,
            categoryProducts: [],
            categoryProductEntities: [],
            popularRows: [['product_id' => 200, 'units_sold' => 50]],
            popularProductEntities: [200 => $this->makeProduct(200)],
        );

        $result = $service->getRecommendationsForUser(userId: 42);

        self::assertCount(1, $result);
        self::assertSame(200, $result[0]['product']->getId());
        self::assertSame('fallback_popular', $result[0]['source']);
    }

    #[Test]
    public function userPathReturnsCategoryAffinityWhenHistoryExists(): void
    {
        // Top category lookup returns category_id=7 → service runs
        // category lookup → returns products user hasn't bought
        $service = $this->makeUserService(
            topCategoryRow: ['category_id' => 7, 'units' => 12],
            categoryProducts: [
                ['product_id' => 200, 'units_sold' => 50],
                ['product_id' => 300, 'units_sold' => 30],
            ],
            categoryProductEntities: [
                200 => $this->makeProduct(200),
                300 => $this->makeProduct(300),
            ],
            popularRows: [],
            popularProductEntities: [],
        );

        $result = $service->getRecommendationsForUser(userId: 42);

        self::assertCount(2, $result);
        self::assertSame(200, $result[0]['product']->getId());
        self::assertSame('category', $result[0]['source']);
        self::assertSame('50.0000', $result[0]['score']);
    }

    #[Test]
    public function userPathFallsBackToPopularWhenCategoryExhausted(): void
    {
        // Has history (top category found) but category lookup
        // returns empty (user has bought everything in category)
        $service = $this->makeUserService(
            topCategoryRow: ['category_id' => 7, 'units' => 12],
            categoryProducts: [],  // empty — user bought it all
            categoryProductEntities: [],
            popularRows: [['product_id' => 999, 'units_sold' => 100]],
            popularProductEntities: [999 => $this->makeProduct(999)],
        );

        $result = $service->getRecommendationsForUser(userId: 42);

        self::assertCount(1, $result);
        self::assertSame(999, $result[0]['product']->getId());
        self::assertSame('fallback_popular', $result[0]['source']);
    }

    #[Test]
    public function getExplainForProductReturnsRanksAndSources(): void
    {
        $source = $this->makeProduct(100);
        $rec1 = $this->makeRec($source, $this->makeProduct(200), '23.0000', 'copurchase', 1);
        $rec2 = $this->makeRec($source, $this->makeProduct(300), '1.0000', 'category', 2);

        $service = $this->makeService(preComputedAllRecs: [$rec1, $rec2]);

        $result = $service->getExplainForProduct(100);

        self::assertCount(2, $result);
        self::assertSame(200, $result[0]['product']->getId());
        self::assertSame('copurchase', $result[0]['source']);
        self::assertSame(1, $result[0]['rank']);
        self::assertSame('category', $result[1]['source']);
        self::assertSame(2, $result[1]['rank']);
    }

    // =================================================================
    // Helpers (additions for user path)
    // =================================================================

    /**
     * Helper for getRecommendationsForUser tests. Two SQL queries
     * are involved: findUserTopCategory (first), then either
     * getProductsInCategoryUserHasntBought OR getPopularFallback
     * (second).
     *
     * @param array{category_id: int, units: int}|false $topCategoryRow
     * @param list<array{product_id: int, units_sold: int}> $categoryProducts
     * @param array<int, Product> $categoryProductEntities
     * @param list<array{product_id: int, units_sold: int}> $popularRows
     * @param array<int, Product> $popularProductEntities
     */
    private function makeUserService(
        array|false $topCategoryRow,
        array $categoryProducts,
        array $categoryProductEntities,
        array $popularRows,
        array $popularProductEntities,
    ): RecommendationsService {
        $recRepo = $this->createMock(ProductRecommendationRepository::class);

        // Build the Product entity map for findBy.
        // findBy returns combined results — but each test only
        // exercises one path, so this is safe.
        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findBy')->willReturnCallback(
            function (array $criteria) use ($categoryProductEntities, $popularProductEntities): array {
                $ids = $criteria['id'] ?? [];
                $all = $categoryProductEntities + $popularProductEntities;
                $result = [];
                foreach ($ids as $id) {
                    if (isset($all[$id])) {
                        $result[] = $all[$id];
                    }
                }
                return $result;
            },
        );

        // Sequence the executeQuery responses:
        //   1st call: findUserTopCategory
        //   2nd call: getProductsInCategoryUserHasntBought OR getPopularFallback
        //   3rd call (if user exhausted category): popular fallback
        $callIdx = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);
        $connection->method('executeQuery')->willReturnCallback(
            function () use (
                &$callIdx,
                $topCategoryRow,
                $categoryProducts,
                $popularRows,
            ): Result {
                $callIdx++;
                $r = $this->createMock(Result::class);
                if ($callIdx === 1) {
                    // findUserTopCategory uses fetchAssociative
                    $r->method('fetchAssociative')->willReturn($topCategoryRow);
                    return $r;
                }
                if ($callIdx === 2) {
                    // Either category lookup or popular fallback
                    if ($topCategoryRow === false) {
                        $r->method('fetchAllAssociative')->willReturn($popularRows);
                    } else {
                        $r->method('fetchAllAssociative')->willReturn($categoryProducts);
                    }
                    return $r;
                }
                // 3rd call = exhausted category fallback to popular
                $r->method('fetchAllAssociative')->willReturn($popularRows);
                return $r;
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class) => match ($class) {
                ProductRecommendation::class => $recRepo,
                Product::class => $productRepo,
                default => throw new \LogicException("Unexpected repo: {$class}"),
            },
        );
        $em->method('getConnection')->willReturn($connection);

        return new RecommendationsService($em, new InMemoryLogger());
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param list<ProductRecommendation> $preComputedRecs
     * @param list<array{product_id: int, units_sold: int}> $popularRows
     * @param array<int, Product> $popularProducts
     * @param list<ProductRecommendation> $preComputedAllRecs Used by getExplainForProduct tests
     */
    private function makeService(
        array $preComputedRecs = [],
        array $popularRows = [],
        array $popularProducts = [],
        ?InMemoryLogger $logger = null,
        array $preComputedAllRecs = [],
    ): RecommendationsService {
        $recRepo = $this->createMock(ProductRecommendationRepository::class);
        $recRepo->method('findTopForProduct')->willReturn($preComputedRecs);
        $recRepo->method('findAllForProduct')->willReturn($preComputedAllRecs);

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findBy')->willReturn(array_values($popularProducts));

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($popularRows);
        $connection->method('executeQuery')->willReturn($result);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class) => match ($class) {
                ProductRecommendation::class => $recRepo,
                Product::class => $productRepo,
                default => throw new \LogicException("Unexpected repo: {$class}"),
            },
        );
        $em->method('getConnection')->willReturn($connection);

        return new RecommendationsService($em, $logger ?? new InMemoryLogger());
    }

    /**
     * @return array{em: EntityManagerInterface, queries: list<array{sql: string, params: array<int|string, mixed>}>}
     */
    private function captureSql(): array
    {
        $queries = [];

        $recRepo = $this->createMock(ProductRecommendationRepository::class);
        $recRepo->method('findTopForProduct')->willReturn([]);  // force fallback

        $productRepo = $this->createMock(ProductRepository::class);
        $productRepo->method('findBy')->willReturn([]);

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);
        $connection->method('executeQuery')->willReturnCallback(
            function (string $sql, array $params = []) use (&$queries): Result {
                $queries[] = ['sql' => $sql, 'params' => $params];
                $r = $this->createMock(Result::class);
                $r->method('fetchAllAssociative')->willReturn([]);
                return $r;
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(
            fn (string $class) => match ($class) {
                ProductRecommendation::class => $recRepo,
                Product::class => $productRepo,
                default => throw new \LogicException("Unexpected repo: {$class}"),
            },
        );
        $em->method('getConnection')->willReturn($connection);
        return ['em' => $em, 'queries' => &$queries];
    }

    private function makeProduct(int $id): Product
    {
        $vendor = (new \ReflectionClass(Vendor::class))->newInstanceWithoutConstructor();
        $vIdRef = new \ReflectionProperty(Vendor::class, 'id');
        $vIdRef->setAccessible(true);
        $vIdRef->setValue($vendor, 5);
        $vSlugRef = new \ReflectionProperty(Vendor::class, 'slug');
        $vSlugRef->setAccessible(true);
        $vSlugRef->setValue($vendor, 'v');
        $vNameRef = new \ReflectionProperty(Vendor::class, 'name');
        $vNameRef->setAccessible(true);
        $vNameRef->setValue($vendor, 'V');

        $product = new Product($vendor, "slug-{$id}", "Product {$id}");
        $idRef = new \ReflectionProperty(Product::class, 'id');
        $idRef->setAccessible(true);
        $idRef->setValue($product, $id);
        $product->setPrice('100.00');
        return $product;
    }

    private function makeRec(Product $source, Product $target, string $score, string $sourceTag, int $rank): ProductRecommendation
    {
        return new ProductRecommendation(
            product: $source,
            recommendedProduct: $target,
            score: $score,
            source: $sourceTag,
            rank: $rank,
        );
    }
}
