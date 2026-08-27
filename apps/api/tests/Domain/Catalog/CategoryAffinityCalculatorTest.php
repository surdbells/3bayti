<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\CategoryAffinityCalculator;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CategoryAffinityCalculator (M3.2.X.12-D).
 *
 * Verifies the SQL contract (self-join filters, active product
 * filter, top-N cap) and the graph materialization.
 */
#[CoversClass(CategoryAffinityCalculator::class)]
final class CategoryAffinityCalculatorTest extends TestCase
{
    #[Test]
    public function computesGraphFromQueryResults(): void
    {
        $calc = $this->makeCalc(queryRows: [
            ['source_product_id' => 100, 'target_product_id' => 200],
            ['source_product_id' => 100, 'target_product_id' => 300],
            ['source_product_id' => 200, 'target_product_id' => 100],
            ['source_product_id' => 200, 'target_product_id' => 300],
        ]);

        $graph = $calc->computeFullGraph();

        self::assertCount(2, $graph);  // 2 source products
        self::assertCount(2, $graph[100]);
        self::assertSame(200, $graph[100][0]['recommended_product_id']);
        self::assertSame(300, $graph[100][1]['recommended_product_id']);

        // All rows get the constant category score
        foreach ($graph[100] as $rec) {
            self::assertSame('1.0000', $rec['score']);
        }
        foreach ($graph[200] as $rec) {
            self::assertSame('1.0000', $rec['score']);
        }
    }

    #[Test]
    public function emptyResultsReturnEmptyGraph(): void
    {
        $calc = $this->makeCalc(queryRows: []);
        $graph = $calc->computeFullGraph();
        self::assertSame([], $graph);
    }

    #[Test]
    public function sqlExcludesUncategorisedProducts(): void
    {
        // Products without a category don't get category fallback -
        // they fall through to fallback_popular in X.12-E.
        $captured = $this->captureSql();
        $calc = new CategoryAffinityCalculator($captured['em'], new InMemoryLogger());
        $calc->computeFullGraph();

        $sql = $captured['queries'][0]['sql'];
        self::assertStringContainsString('p1.category_id IS NOT NULL', $sql);
    }

    #[Test]
    public function sqlExcludesInactiveProducts(): void
    {
        // Both sides (source + target) must be active to avoid
        // recommending hidden/deleted products
        $captured = $this->captureSql();
        $calc = new CategoryAffinityCalculator($captured['em'], new InMemoryLogger());
        $calc->computeFullGraph();

        $sql = $captured['queries'][0]['sql'];
        self::assertStringContainsString('p1.is_active = true', $sql);
        self::assertStringContainsString('p2.is_active = true', $sql);
    }

    #[Test]
    public function sqlPreventsSelfRecommendation(): void
    {
        // A product cannot recommend itself even within the same
        // category, the JOIN includes p2.id != p1.id
        $captured = $this->captureSql();
        $calc = new CategoryAffinityCalculator($captured['em'], new InMemoryLogger());
        $calc->computeFullGraph();

        $sql = $captured['queries'][0]['sql'];
        self::assertStringContainsString('p2.id != p1.id', $sql);
    }

    #[Test]
    public function sqlAppliesTopNCap(): void
    {
        $captured = $this->captureSql();
        $calc = new CategoryAffinityCalculator($captured['em'], new InMemoryLogger());
        $calc->computeFullGraph();

        $sql = $captured['queries'][0]['sql'];
        self::assertStringContainsString('ROW_NUMBER() OVER', $sql);
        self::assertStringContainsString('PARTITION BY p1.id', $sql);
        self::assertStringContainsString('WHERE rn <= 50', $sql);
    }

    #[Test]
    public function sqlJoinsOnSameCategory(): void
    {
        $captured = $this->captureSql();
        $calc = new CategoryAffinityCalculator($captured['em'], new InMemoryLogger());
        $calc->computeFullGraph();

        $sql = $captured['queries'][0]['sql'];
        self::assertStringContainsString('p2.category_id = p1.category_id', $sql);
    }

    #[Test]
    public function emitsObservabilityLog(): void
    {
        $logger = new InMemoryLogger();
        $calc = $this->makeCalc(queryRows: [
            ['source_product_id' => 100, 'target_product_id' => 200],
        ], logger: $logger);

        $calc->computeFullGraph();

        $records = $logger->findByMessage('category_affinity.computed');
        self::assertCount(1, $records);
        self::assertSame('info', $records[0]['level']);
        self::assertSame(1, $records[0]['context']['source_products']);
        self::assertSame(1, $records[0]['context']['total_pairs']);
        self::assertSame('1.0000', $records[0]['context']['category_score']);
        self::assertArrayHasKey('duration_ms', $records[0]['context']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    private function makeCalc(array $queryRows, ?InMemoryLogger $logger = null): CategoryAffinityCalculator
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);

        $result = $this->createMock(Result::class);
        $result->method('fetchAllAssociative')->willReturn($queryRows);
        $connection->method('executeQuery')->willReturn($result);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new CategoryAffinityCalculator($em, $logger ?? new InMemoryLogger());
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
            function (string $sql, array $params = []) use (&$queries): Result {
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
