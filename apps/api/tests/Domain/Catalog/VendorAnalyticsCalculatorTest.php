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
            // revenue_series (skipped in this test — empty list)
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
