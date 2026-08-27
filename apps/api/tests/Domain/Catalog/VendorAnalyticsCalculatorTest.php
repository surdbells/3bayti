<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\VendorAnalyticsCalculator;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(VendorAnalyticsCalculator::class)]
final class VendorAnalyticsCalculatorTest extends TestCase
{
    #[Test]
    public function returnsAllSevenTopLevelEnvelopeKeys(): void
    {
        $calc = $this->makeCalc();
        $envelope = $calc->computeForVendor(vendorId: 5);

        // The X.13-E serializer consumes exactly these keys.
        // Locking the shape early prevents later sub-phases from
        // accidentally renaming fields.
        self::assertArrayHasKey('window', $envelope);
        self::assertArrayHasKey('totals', $envelope);
        self::assertArrayHasKey('revenue_series', $envelope);
        self::assertArrayHasKey('top_products_by_units', $envelope);
        self::assertArrayHasKey('top_products_by_revenue', $envelope);
        self::assertArrayHasKey('customer_mix', $envelope);
        self::assertArrayHasKey('status_mix', $envelope);
    }

    #[Test]
    public function windowReflectsDaysParam(): void
    {
        $calc = $this->makeCalc();
        $envelope = $calc->computeForVendor(vendorId: 5, days: 60);
        self::assertSame(60, $envelope['window']['days']);
    }

    #[Test]
    public function defaultWindowIs30Days(): void
    {
        $calc = $this->makeCalc();
        $envelope = $calc->computeForVendor(vendorId: 5);
        self::assertSame(30, $envelope['window']['days']);
    }

    #[Test]
    public function windowClampedToMinimum(): void
    {
        $calc = $this->makeCalc();
        $envelope = $calc->computeForVendor(vendorId: 5, days: 0);
        self::assertSame(7, $envelope['window']['days']);  // MIN_WINDOW_DAYS
    }

    #[Test]
    public function windowClampedToMaximum(): void
    {
        $calc = $this->makeCalc();
        $envelope = $calc->computeForVendor(vendorId: 5, days: 9999);
        self::assertSame(365, $envelope['window']['days']);  // MAX_WINDOW_DAYS
    }

    #[Test]
    public function emptyVendorReturnsZeroTotals(): void
    {
        // Q-EmptyHandling = C locked: 200 with totals=0 + empty
        // arrays. Friendlier dashboard UX than X.14's null pattern.
        $calc = $this->makeCalc();
        $envelope = $calc->computeForVendor(vendorId: 5);

        self::assertSame('0.00', $envelope['totals']['revenue_aed']);
        self::assertSame(0, $envelope['totals']['orders']);
        self::assertSame(0, $envelope['totals']['items']);
        self::assertSame('0.00', $envelope['totals']['aov_aed']);
        self::assertSame(0, $envelope['totals']['unique_customers']);

        self::assertSame([], $envelope['revenue_series']);
        self::assertSame([], $envelope['top_products_by_units']);
        self::assertSame([], $envelope['top_products_by_revenue']);

        self::assertSame(0, $envelope['customer_mix']['new']);
        self::assertSame(0, $envelope['customer_mix']['returning']);
        self::assertSame(0, $envelope['customer_mix']['total']);

        self::assertSame(0, $envelope['status_mix']['delivered']);
        self::assertSame(0, $envelope['status_mix']['cancelled']);
        self::assertSame(0, $envelope['status_mix']['returned']);
        self::assertSame(0, $envelope['status_mix']['total']);
    }

    #[Test]
    public function emitsTimingLogPerCall(): void
    {
        $logger = new InMemoryLogger();
        $calc = $this->makeCalc($logger);
        $calc->computeForVendor(vendorId: 5);

        $records = $logger->findByMessage('vendor_analytics.computed');
        self::assertCount(1, $records);
        self::assertSame('debug', $records[0]['level']);
        self::assertSame(5, $records[0]['context']['vendor_id']);
        self::assertSame(30, $records[0]['context']['window_days']);
        self::assertArrayHasKey('duration_ms', $records[0]['context']);
    }

    #[Test]
    public function totalsComputedFromAggregateQuery(): void
    {
        // First query is totals: returns one row with revenue + counts.
        // Second query is revenue_series: returns rows or empty.
        // Subsequent stub queries (top-N, mixes) still return empty.
        $calc = $this->makeCalc(queryResults: [
            // totals
            [['revenue' => '1250.50', 'orders' => 8, 'items' => 12, 'unique_customers' => 6]],
            // revenue_series (skipped in this test, empty list)
            [],
            // top_units, top_revenue, customer_mix, status_mix all empty
            [], [], [], [],
        ]);

        $envelope = $calc->computeForVendor(vendorId: 5);

        self::assertSame('1250.50', $envelope['totals']['revenue_aed']);
        self::assertSame(8, $envelope['totals']['orders']);
        self::assertSame(12, $envelope['totals']['items']);
        self::assertSame(6, $envelope['totals']['unique_customers']);
        // AOV = 1250.50 / 8 = 156.3125 → 156.31 HALF_UP
        self::assertSame('156.31', $envelope['totals']['aov_aed']);
    }

    #[Test]
    public function aovIsZeroWhenNoOrders(): void
    {
        // Defensive: division-by-zero guard
        $calc = $this->makeCalc(queryResults: [
            [['revenue' => '0', 'orders' => 0, 'items' => 0, 'unique_customers' => 0]],
            [], [], [], [], [],
        ]);

        $envelope = $calc->computeForVendor(vendorId: 5);
        self::assertSame('0.00', $envelope['totals']['aov_aed']);
        self::assertSame(0, $envelope['totals']['orders']);
    }

    #[Test]
    public function aovHalfUpRoundingAtPenny(): void
    {
        // 100.00 / 3 = 33.3333... → 33.33 HALF_UP
        $calc = $this->makeCalc(queryResults: [
            [['revenue' => '100.00', 'orders' => 3, 'items' => 3, 'unique_customers' => 3]],
            [], [], [], [], [],
        ]);

        $envelope = $calc->computeForVendor(vendorId: 5);
        self::assertSame('33.33', $envelope['totals']['aov_aed']);
    }

    #[Test]
    public function revenueSeriesShapeMatchesDailyBuckets(): void
    {
        // Simulate 3 daily rows from the generate_series query
        $calc = $this->makeCalc(queryResults: [
            // totals
            [['revenue' => '0', 'orders' => 0, 'items' => 0, 'unique_customers' => 0]],
            // revenue_series
            [
                ['date' => '2026-04-18', 'revenue' => '320.50', 'orders' => 3],
                ['date' => '2026-04-19', 'revenue' => '0', 'orders' => 0],
                ['date' => '2026-04-20', 'revenue' => '450.00', 'orders' => 4],
            ],
            // top, mixes
            [], [], [], [],
        ]);

        $envelope = $calc->computeForVendor(vendorId: 5);
        self::assertCount(3, $envelope['revenue_series']);

        $first = $envelope['revenue_series'][0];
        self::assertSame('2026-04-18', $first['date']);
        self::assertSame('320.50', $first['revenue_aed']);
        self::assertSame(3, $first['orders']);

        // Zero-revenue day still appears with explicit 0.00 / 0
        $second = $envelope['revenue_series'][1];
        self::assertSame('2026-04-19', $second['date']);
        self::assertSame('0.00', $second['revenue_aed']);
        self::assertSame(0, $second['orders']);
    }

    #[Test]
    public function totalsQueryExcludesRejectedAndRefunded(): void
    {
        // Verify the SQL contains the exclusion clause for both
        // REVENUE_EXCLUDED_ITEM_STATUSES values. This guards Q-RevenueDef
        // = A: revenue is settled revenue, not gross.
        $captured = $this->captureQueries();
        $calc = new VendorAnalyticsCalculator($captured['em'], new InMemoryLogger());
        $calc->computeForVendor(vendorId: 5);

        $totalsSql = $captured['queries'][0]['sql'];
        self::assertStringContainsString('NOT IN (?, ?)', $totalsSql);

        // The 4th, 5th params should be the status placeholders
        $totalsParams = $captured['queries'][0]['params'];
        self::assertContains('rejected', $totalsParams);
        self::assertContains('refunded', $totalsParams);
    }

    #[Test]
    public function totalsQueryAnchorsOnPaidAt(): void
    {
        // Q-RevenueDef = A: window anchors on paid_at, not created_at.
        $captured = $this->captureQueries();
        $calc = new VendorAnalyticsCalculator($captured['em'], new InMemoryLogger());
        $calc->computeForVendor(vendorId: 5);

        $totalsSql = $captured['queries'][0]['sql'];
        self::assertStringContainsString('o.paid_at IS NOT NULL', $totalsSql);
        self::assertStringContainsString('o.paid_at >= ?', $totalsSql);
        self::assertStringContainsString('o.paid_at < ?', $totalsSql);
    }

    #[Test]
    public function topProductsByUnitsReturnsSortedList(): void
    {
        // Skeleton sub-phase calls: [totals, series, top_units,
        // top_revenue, customer_mix, status_mix]. Mock top_units (3rd)
        // with 3 rows already ORDER BY units DESC from DB.
        $calc = $this->makeCalc(queryResults: [
            // totals
            [['revenue' => '0', 'orders' => 0, 'items' => 0, 'unique_customers' => 0]],
            // revenue_series
            [],
            // top_units
            [
                ['product_id' => 100, 'slug' => 'vintage-lamp', 'name' => 'Vintage Lamp', 'units' => 23, 'revenue' => '3450.00'],
                ['product_id' => 200, 'slug' => 'antique-chair', 'name' => 'Antique Chair', 'units' => 18, 'revenue' => '5400.00'],
                ['product_id' => 300, 'slug' => 'wall-art', 'name' => 'Wall Art', 'units' => 12, 'revenue' => '960.00'],
            ],
            // top_revenue, mixes
            [], [], [],
        ]);

        $envelope = $calc->computeForVendor(vendorId: 5);
        self::assertCount(3, $envelope['top_products_by_units']);

        $first = $envelope['top_products_by_units'][0];
        self::assertSame(100, $first['product_id']);
        self::assertSame('vintage-lamp', $first['slug']);
        self::assertSame('Vintage Lamp', $first['name']);
        self::assertSame(23, $first['units']);
        self::assertSame('3450.00', $first['revenue_aed']);
    }

    #[Test]
    public function topProductsByRevenueReturnsSeparateList(): void
    {
        // Q-TopN = C locked: two separate lists for units + revenue.
        // The product order may differ between them.
        $calc = $this->makeCalc(queryResults: [
            // totals
            [['revenue' => '0', 'orders' => 0, 'items' => 0, 'unique_customers' => 0]],
            // revenue_series
            [],
            // top_units (sorted by units; Antique Chair has fewer units)
            [
                ['product_id' => 100, 'slug' => 'vintage-lamp', 'name' => 'Vintage Lamp', 'units' => 23, 'revenue' => '3450.00'],
                ['product_id' => 200, 'slug' => 'antique-chair', 'name' => 'Antique Chair', 'units' => 18, 'revenue' => '5400.00'],
            ],
            // top_revenue (sorted by revenue; Antique Chair is bigger earner)
            [
                ['product_id' => 200, 'slug' => 'antique-chair', 'name' => 'Antique Chair', 'units' => 18, 'revenue' => '5400.00'],
                ['product_id' => 100, 'slug' => 'vintage-lamp', 'name' => 'Vintage Lamp', 'units' => 23, 'revenue' => '3450.00'],
            ],
            // mixes
            [], [],
        ]);

        $envelope = $calc->computeForVendor(vendorId: 5);

        // Different ordering for the two lists
        self::assertSame(100, $envelope['top_products_by_units'][0]['product_id']);
        self::assertSame(200, $envelope['top_products_by_revenue'][0]['product_id']);
    }

    #[Test]
    public function topProductsQueryGroupsByProductId(): void
    {
        $captured = $this->captureQueries();
        $calc = new VendorAnalyticsCalculator($captured['em'], new InMemoryLogger());
        $calc->computeForVendor(vendorId: 5);

        // 6 queries total: totals + series + top_units + top_revenue +
        // customer_mix + status_mix. Top-N queries are #3 and #4.
        $topUnitsSql = $captured['queries'][2]['sql'];
        self::assertStringContainsString('GROUP BY oi.product_id, p.slug, p.name', $topUnitsSql);
        self::assertStringContainsString('ORDER BY units DESC', $topUnitsSql);
        self::assertStringContainsString('LIMIT 10', $topUnitsSql);

        $topRevenueSql = $captured['queries'][3]['sql'];
        self::assertStringContainsString('ORDER BY revenue DESC', $topRevenueSql);
    }

    #[Test]
    public function topProductsHandlesEmptyVendorGracefully(): void
    {
        // No orders → empty array (not error)
        $calc = $this->makeCalc(queryResults: []);
        $envelope = $calc->computeForVendor(vendorId: 5);

        self::assertSame([], $envelope['top_products_by_units']);
        self::assertSame([], $envelope['top_products_by_revenue']);
    }

    #[Test]
    public function customerMixSeparatesNewFromReturning(): void
    {
        // 6 unique customers in window; 4 are new (no prior order),
        // 2 are returning. Mock the customer_mix query as #5.
        $calc = $this->makeCalc(queryResults: [
            // totals, series, top_units, top_revenue
            [['revenue' => '0', 'orders' => 0, 'items' => 0, 'unique_customers' => 0]],
            [], [], [],
            // customer_mix
            [['new_customers' => 4, 'returning_customers' => 2, 'total_customers' => 6]],
            // status_mix
            [],
        ]);

        $envelope = $calc->computeForVendor(vendorId: 5);
        self::assertSame(4, $envelope['customer_mix']['new']);
        self::assertSame(2, $envelope['customer_mix']['returning']);
        self::assertSame(6, $envelope['customer_mix']['total']);
    }

    #[Test]
    public function customerMixQueryUsesVendorScopedDefinition(): void
    {
        // Q-CustomerMix = A: vendor-scoped. The SQL should have
        // TWO CTEs (window_customers + prior_customers), both
        // filtered by oi.vendor_id, and a LEFT JOIN classifying
        // NULL match as new.
        $captured = $this->captureQueries();
        $calc = new VendorAnalyticsCalculator($captured['em'], new InMemoryLogger());
        $calc->computeForVendor(vendorId: 5);

        // 6 queries: totals, series, top_units, top_revenue,
        // customer_mix, status_mix. customer_mix is #5 (index 4).
        $sql = $captured['queries'][4]['sql'];
        self::assertStringContainsString('window_customers AS', $sql);
        self::assertStringContainsString('prior_customers AS', $sql);
        self::assertStringContainsString('LEFT JOIN prior_customers', $sql);
        self::assertStringContainsString('FILTER (WHERE p.user_id IS NULL)', $sql);
    }

    #[Test]
    public function statusMixReturnsItemLevelCounts(): void
    {
        // 80 delivered + 4 cancelled + 5 returned = 89 total
        $calc = $this->makeCalc(queryResults: [
            [['revenue' => '0', 'orders' => 0, 'items' => 0, 'unique_customers' => 0]],
            [], [], [],
            [['new_customers' => 0, 'returning_customers' => 0, 'total_customers' => 0]],
            [['delivered' => 80, 'cancelled' => 4, 'returned' => 5, 'total' => 89]],
        ]);

        $envelope = $calc->computeForVendor(vendorId: 5);
        self::assertSame(80, $envelope['status_mix']['delivered']);
        self::assertSame(4, $envelope['status_mix']['cancelled']);
        self::assertSame(5, $envelope['status_mix']['returned']);
        self::assertSame(89, $envelope['status_mix']['total']);
    }

    #[Test]
    public function statusMixQueryUsesFilterClauses(): void
    {
        // Single aggregate with COUNT(*) FILTER (WHERE ...) for
        // each status class, efficient single-pass over the
        // order_items table for the window.
        $captured = $this->captureQueries();
        $calc = new VendorAnalyticsCalculator($captured['em'], new InMemoryLogger());
        $calc->computeForVendor(vendorId: 5);

        // status_mix is the 6th query (index 5)
        $sql = $captured['queries'][5]['sql'];
        self::assertStringContainsString('FILTER (WHERE oi.item_status IN', $sql);
        self::assertStringContainsString('AS delivered', $sql);
        self::assertStringContainsString('AS cancelled', $sql);
        self::assertStringContainsString('AS returned', $sql);
        self::assertStringContainsString('AS total', $sql);
    }

    private function makeCalc(?InMemoryLogger $logger = null, array $queryResults = []): VendorAnalyticsCalculator
    {
        $connection = $this->createMock(Connection::class);
        // setStatementTimeout
        $connection->method('executeStatement')->willReturn(0);

        // Each successive executeQuery call returns the next preset
        // result set. If $queryResults is empty (X.13-A skeleton tests),
        // every query returns the empty list.
        $callIdx = 0;
        $connection->method('executeQuery')->willReturnCallback(
            function () use (&$callIdx, $queryResults): Result {
                $rows = $queryResults[$callIdx] ?? [];
                $callIdx++;
                $r = $this->createMock(Result::class);
                // computeTotals uses fetchAssociative (single row);
                // computeRevenueSeries uses fetchAllAssociative (list).
                $r->method('fetchAssociative')->willReturn($rows[0] ?? false);
                $r->method('fetchAllAssociative')->willReturn($rows);
                return $r;
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new VendorAnalyticsCalculator($em, $logger ?? new InMemoryLogger());
    }

    /**
     * @return array{em: EntityManagerInterface, queries: list<array{sql: string, params: array<int|string, mixed>}>}
     */
    private function captureQueries(): array
    {
        $queries = [];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willReturn(0);
        $connection->method('executeQuery')->willReturnCallback(
            function (string $sql, array $params) use (&$queries): Result {
                $queries[] = ['sql' => $sql, 'params' => $params];
                $r = $this->createMock(Result::class);
                $r->method('fetchAssociative')->willReturn(false);
                $r->method('fetchAllAssociative')->willReturn([]);
                return $r;
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        return ['em' => $em, 'queries' => &$queries];
    }
}
