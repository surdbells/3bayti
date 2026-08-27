<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\CoPurchaseAffinityCalculator;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CoPurchaseAffinityCalculator (M3.2.X.12-C).
 *
 * Verifies the SQL contract (window, min_support, top-N, status
 * exclusions) and the in-memory graph materialization.
 */
#[CoversClass(CoPurchaseAffinityCalculator::class)]
final class CoPurchaseAffinityCalculatorTest extends TestCase
{
    #[Test]
    public function computesGraphFromQueryResults(): void
    {
        // Mock 3 source products with co-purchase pairs:
        //   product 100 → 200 (count 23) + 300 (count 15)
        //   product 200 → 100 (count 23)
        //   product 300 → 100 (count 15)
        $calc = $this->makeCalc(queryRows: [
            ['source_product_id' => 100, 'target_product_id' => 200, 'pair_count' => 23],
            ['source_product_id' => 100, 'target_product_id' => 300, 'pair_count' => 15],
            ['source_product_id' => 200, 'target_product_id' => 100, 'pair_count' => 23],
            ['source_product_id' => 300, 'target_product_id' => 100, 'pair_count' => 15],
        ]);

        $graph = $calc->computeFullGraph();

        self::assertCount(3, $graph);  // 3 source products
        self::assertCount(2, $graph[100]);  // 2 recommendations for product 100
        self::assertSame(200, $graph[100][0]['recommended_product_id']);
        self::assertSame('23.0000', $graph[100][0]['score']);
        self::assertSame(300, $graph[100][1]['recommended_product_id']);
        self::assertSame('15.0000', $graph[100][1]['score']);

        self::assertCount(1, $graph[200]);
        self::assertSame(100, $graph[200][0]['recommended_product_id']);
    }

    #[Test]
    public function emptyResultsReturnEmptyGraph(): void
    {
        $calc = $this->makeCalc(queryRows: []);
        $graph = $calc->computeFullGraph();
        self::assertSame([], $graph);
    }

    #[Test]
    public function sqlExcludesRejectedAndRefundedTwice(): void
    {
        // The SQL self-joins order_items so BOTH sides (a + b) must
        // filter out rejected + refunded, that's 2 NOT IN clauses
        // with the same status list.
        $captured = $this->captureSql();
        $calc = new CoPurchaseAffinityCalculator($captured['em'], new InMemoryLogger());
        $calc->computeFullGraph();

        $sql = $captured['queries'][0]['sql'];
        self::assertStringContainsString('a.item_status NOT IN', $sql);
        self::assertStringContainsString('b.item_status NOT IN', $sql);

        // Each side has 2 placeholders for ('rejected', 'refunded')
        // = 4 total params
        $params = $captured['queries'][0]['params'];
        self::assertCount(4, $params);
        self::assertSame(['rejected', 'refunded', 'rejected', 'refunded'], $params);
    }

    #[Test]
    public function sqlAppliesMinSupportFilter(): void
    {
        // Q-MinSupport = B locked: pairs with < 3 co-occurrences
        // are filtered out at SQL level (HAVING clause), not in PHP.
        $captured = $this->captureSql();
        $calc = new CoPurchaseAffinityCalculator($captured['em'], new InMemoryLogger());
        $calc->computeFullGraph();

        $sql = $captured['queries'][0]['sql'];
        self::assertStringContainsString('HAVING COUNT(DISTINCT a.order_id) >= 3', $sql);
    }

    #[Test]
    public function sqlAppliesWindowFilter(): void
    {
        // Q-CoPurchaseWindow = C locked: last 365 days.
        $captured = $this->captureSql();
        $calc = new CoPurchaseAffinityCalculator($captured['em'], new InMemoryLogger());
        $calc->computeFullGraph();

        $sql = $captured['queries'][0]['sql'];
        self::assertStringContainsString("NOW() - INTERVAL '365 days'", $sql);
        self::assertStringContainsString('o.paid_at IS NOT NULL', $sql);
    }

    #[Test]
    public function sqlAppliesTopNCap(): void
    {
        // ROW_NUMBER OVER (PARTITION BY ...) + WHERE rn <= 50 caps
        // each source product's recommendations to TOP_N_PER_PRODUCT.
        $captured = $this->captureSql();
        $calc = new CoPurchaseAffinityCalculator($captured['em'], new InMemoryLogger());
        $calc->computeFullGraph();

        $sql = $captured['queries'][0]['sql'];
        self::assertStringContainsString('ROW_NUMBER() OVER', $sql);
        self::assertStringContainsString('PARTITION BY source_product_id', $sql);
        self::assertStringContainsString('WHERE rn <= 50', $sql);
    }

    #[Test]
    public function sqlOrdersDeterministically(): void
    {
        // Within a source product, pairs ordered by count DESC
        // with target_product_id ASC tiebreaker, deterministic
        // ordering across runs.
        $captured = $this->captureSql();
        $calc = new CoPurchaseAffinityCalculator($captured['em'], new InMemoryLogger());
        $calc->computeFullGraph();

        $sql = $captured['queries'][0]['sql'];
        self::assertStringContainsString(
            'ORDER BY pair_count DESC, target_product_id ASC',
            $sql,
        );
    }

    #[Test]
    public function emitsObservabilityLog(): void
    {
        $logger = new InMemoryLogger();
        $calc = $this->makeCalc(queryRows: [
            ['source_product_id' => 100, 'target_product_id' => 200, 'pair_count' => 23],
            ['source_product_id' => 200, 'target_product_id' => 100, 'pair_count' => 23],
        ], logger: $logger);

        $calc->computeFullGraph();

        $records = $logger->findByMessage('copurchase_affinity.computed');
        self::assertCount(1, $records);
        self::assertSame('info', $records[0]['level']);
        self::assertSame(2, $records[0]['context']['source_products']);
        self::assertSame(2, $records[0]['context']['total_pairs']);
        self::assertSame(365, $records[0]['context']['window_days']);
        self::assertSame(3, $records[0]['context']['min_support']);
        self::assertArrayHasKey('duration_ms', $records[0]['context']);
    }

    #[Test]
    public function scoreFormattedAsNumeric84Decimal(): void
    {
        // COUNT returns integer; entity column is NUMERIC(8, 4).
        // Calculator pads to 4 fractional digits via bcadd.
        $calc = $this->makeCalc(queryRows: [
            ['source_product_id' => 100, 'target_product_id' => 200, 'pair_count' => 5],
        ]);

        $graph = $calc->computeFullGraph();
        self::assertSame('5.0000', $graph[100][0]['score']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeCalc(array $queryRows, ?InMemoryLogger $logger = null): CoPurchaseAffinityCalculator
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($queryRows);
        $connection->method('executeQuery')->willReturn($result);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new CoPurchaseAffinityCalculator($em, $logger ?? new InMemoryLogger());
    }

    /**
     * @return array{em: EntityManagerInterface, queries: list<array{sql: string, params: array<int|string, mixed>}>}
     */
    private function captureSql(): array
    {
        $queries = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);
        $connection->method('executeQuery')->willReturnCallback(
            function (string $sql, array $params) use (&$queries): Result {
                $queries[] = ['sql' => $sql, 'params' => $params];
                $r = $this->createMock(Result::class);
                $r->method('fetchAllAssociative')->willReturn([]);
                return $r;
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        return ['em' => $em, 'queries' => &$queries];
    }
}
