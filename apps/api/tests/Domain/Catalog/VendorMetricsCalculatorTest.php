<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\VendorMetricsCalculator;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for VendorMetricsCalculator (M3.2.X.14-A).
 *
 * Approach: mock Doctrine\\DBAL\\Connection::executeQuery to:
 *   - Capture each call's SQL + params + types for assertion
 *   - Return canned associative-result rows for the 4 metric queries
 *
 * The computeForVendor path uses fetchAssociative (single row per
 * metric). The computeForVendorList path uses fetchAllAssociative
 * (one row per vendor per metric).
 */
#[CoversClass(VendorMetricsCalculator::class)]
final class VendorMetricsCalculatorTest extends TestCase
{
    // =================================================================
    // Single-vendor: rate math + empty handling
    // =================================================================

    #[Test]
    public function computesAllFourRatesForVendorWithData(): void
    {
        $captured = $this->captureSingleVendor([
            'items' => ['total' => '100', 'fulfilled' => '95', 'rejected' => '3'],
            'returns' => ['approved' => '2'],
            'orders' => ['total' => '80', 'disputed' => '1'],
        ]);

        $calc = new VendorMetricsCalculator($captured['em'], new InMemoryLogger());
        $result = $calc->computeForVendor(101, 30);

        // Fulfillment: 95 / 100 = 0.95
        self::assertSame(0.95, $result['metrics']['fulfillment_rate']['value']);
        self::assertSame(95, $result['metrics']['fulfillment_rate']['fulfilled_items']);
        self::assertSame(100, $result['metrics']['fulfillment_rate']['total_items']);

        // Cancellation: 3 / 100 = 0.03
        self::assertSame(0.03, $result['metrics']['cancellation_rate']['value']);
        self::assertSame(3, $result['metrics']['cancellation_rate']['rejected_items']);

        // Return: 2 / 100 = 0.02 (denominator is items, not orders)
        self::assertSame(0.02, $result['metrics']['return_rate']['value']);
        self::assertSame(2, $result['metrics']['return_rate']['approved_returns']);
        self::assertSame(100, $result['metrics']['return_rate']['total_items']);

        // Dispute: 1 / 80 = 0.0125 → rounded to 0.0125 (4 dp)
        self::assertSame(0.0125, $result['metrics']['dispute_rate']['value']);
        self::assertSame(1, $result['metrics']['dispute_rate']['disputed_orders']);
        self::assertSame(80, $result['metrics']['dispute_rate']['total_orders']);
    }

    #[Test]
    public function emptyVendorReturnsNullRatesWithZeroCounts(): void
    {
        // Q-EmptyHandling = A: rates are null when denominator is 0
        // (not 0.0 — that would imply "perfect performance, vendor
        // had 100 orders all delivered" which is wrong).
        $captured = $this->captureSingleVendor([
            'items' => ['total' => '0', 'fulfilled' => '0', 'rejected' => '0'],
            'returns' => ['approved' => '0'],
            'orders' => ['total' => '0', 'disputed' => '0'],
        ]);

        $calc = new VendorMetricsCalculator($captured['em'], new InMemoryLogger());
        $result = $calc->computeForVendor(101, 30);

        self::assertNull($result['metrics']['fulfillment_rate']['value']);
        self::assertSame(0, $result['metrics']['fulfillment_rate']['total_items']);
        self::assertNull($result['metrics']['cancellation_rate']['value']);
        self::assertNull($result['metrics']['return_rate']['value']);
        self::assertNull($result['metrics']['dispute_rate']['value']);
        self::assertSame(0, $result['metrics']['dispute_rate']['total_orders']);
    }

    #[Test]
    public function partialDataHandledCorrectly(): void
    {
        // Vendor has items but 0 dispute-eligible orders. The two
        // denominators are independent; one zero shouldn't null the other.
        // (In real data this is unlikely but defensive.)
        $captured = $this->captureSingleVendor([
            'items' => ['total' => '50', 'fulfilled' => '45', 'rejected' => '2'],
            'returns' => ['approved' => '1'],
            'orders' => ['total' => '0', 'disputed' => '0'],
        ]);

        $calc = new VendorMetricsCalculator($captured['em'], new InMemoryLogger());
        $result = $calc->computeForVendor(101, 30);

        // Item-based metrics computed
        self::assertSame(0.9, $result['metrics']['fulfillment_rate']['value']);
        self::assertSame(0.04, $result['metrics']['cancellation_rate']['value']);
        self::assertSame(0.02, $result['metrics']['return_rate']['value']);
        // Order-based metric nulled (no orders → no dispute denominator)
        self::assertNull($result['metrics']['dispute_rate']['value']);
    }

    // =================================================================
    // Single-vendor: window + SQL routing
    // =================================================================

    #[Test]
    public function windowBoundsAreCorrectlyComputed(): void
    {
        $captured = $this->captureSingleVendor([
            'items' => ['total' => '0', 'fulfilled' => '0', 'rejected' => '0'],
            'returns' => ['approved' => '0'],
            'orders' => ['total' => '0', 'disputed' => '0'],
        ]);

        $calc = new VendorMetricsCalculator($captured['em'], new InMemoryLogger());
        $beforeCall = new \DateTimeImmutable();
        $result = $calc->computeForVendor(101, 30);
        $afterCall = new \DateTimeImmutable();

        $since = new \DateTimeImmutable($result['window']['since']);
        $until = new \DateTimeImmutable($result['window']['until']);

        // until ≈ now (within a few seconds)
        self::assertLessThanOrEqual(2, abs($until->getTimestamp() - $beforeCall->getTimestamp()));
        self::assertLessThanOrEqual(2, abs($afterCall->getTimestamp() - $until->getTimestamp()));
        // since = until - 30 days
        $diffDays = ($until->getTimestamp() - $since->getTimestamp()) / 86400;
        self::assertEqualsWithDelta(30.0, $diffDays, 0.01);
        self::assertSame(30, $result['window']['days']);
    }

    #[Test]
    public function singleVendorSqlRoutesParamsCorrectly(): void
    {
        $captured = $this->captureSingleVendor([
            'items' => ['total' => '10', 'fulfilled' => '10', 'rejected' => '0'],
            'returns' => ['approved' => '0'],
            'orders' => ['total' => '8', 'disputed' => '0'],
        ]);

        $calc = new VendorMetricsCalculator($captured['em'], new InMemoryLogger());
        $calc->computeForVendor(202, 60);

        // First query = item counts; must filter on vendor_id 202
        $itemsQuery = $captured['queries'][0];
        self::assertSame(202, $itemsQuery['params']['vendorId']);
        self::assertStringContainsString('FROM order_items', $itemsQuery['sql']);
        self::assertStringContainsString('FILTER (WHERE', $itemsQuery['sql']);

        // 'fulfilled' / 'rejected' param types should be STRING arrays
        self::assertSame(ArrayParameterType::STRING, $itemsQuery['types']['fulfilled']);
        self::assertSame(ArrayParameterType::STRING, $itemsQuery['types']['rejected']);
        // Default fulfilled-list contains exactly 'delivered'
        self::assertSame(['delivered'], $itemsQuery['params']['fulfilled']);
        // Default rejected-list contains exactly 'rejected'
        self::assertSame(['rejected'], $itemsQuery['params']['rejected']);

        // Returns query joins through items
        $returnsQuery = $captured['queries'][1];
        self::assertStringContainsString('order_return_request_items', $returnsQuery['sql']);
        self::assertStringContainsString('rr.status = ANY(:approvedStatuses)', $returnsQuery['sql']);
        self::assertSame(['approved', 'picked_up', 'delivered_to_vendor', 'refunded'], $returnsQuery['params']['approvedStatuses']);

        // Orders query uses EXISTS for dispute check (no double-counting)
        $ordersQuery = $captured['queries'][2];
        self::assertStringContainsString('FROM order_disputes', $ordersQuery['sql']);
        self::assertStringContainsString('FILTER (WHERE EXISTS', $ordersQuery['sql']);
    }

    #[Test]
    public function windowDaysParameterControlsQueryWindow(): void
    {
        $captured = $this->captureSingleVendor([
            'items' => ['total' => '0', 'fulfilled' => '0', 'rejected' => '0'],
            'returns' => ['approved' => '0'],
            'orders' => ['total' => '0', 'disputed' => '0'],
        ]);

        $calc = new VendorMetricsCalculator($captured['em'], new InMemoryLogger());
        $calc->computeForVendor(101, 90);

        $itemsQuery = $captured['queries'][0];
        $since = new \DateTimeImmutable($itemsQuery['params']['since']);
        $until = new \DateTimeImmutable($itemsQuery['params']['until']);
        $diffDays = ($until->getTimestamp() - $since->getTimestamp()) / 86400;
        self::assertEqualsWithDelta(90.0, $diffDays, 0.01);
    }

    // =================================================================
    // Multi-vendor: list shape + denormalization
    // =================================================================

    #[Test]
    public function listComputationKeyedByVendorId(): void
    {
        $captured = $this->captureListVendors([
            'items' => [
                ['vendor_id' => '101', 'total' => '50', 'fulfilled' => '48', 'rejected' => '1'],
                ['vendor_id' => '202', 'total' => '20', 'fulfilled' => '10', 'rejected' => '5'],
            ],
            'returns' => [
                ['vendor_id' => '101', 'approved' => '1'],
                // vendor 202 had no approved returns — absent from rows
            ],
            'orders' => [
                ['vendor_id' => '101', 'total' => '40', 'disputed' => '0'],
                ['vendor_id' => '202', 'total' => '15', 'disputed' => '2'],
            ],
        ]);

        $calc = new VendorMetricsCalculator($captured['em'], new InMemoryLogger());
        $result = $calc->computeForVendorList([101, 202], 30);

        self::assertArrayHasKey(101, $result);
        self::assertArrayHasKey(202, $result);

        // Vendor 101 — good performance
        self::assertSame(0.96, $result[101]['metrics']['fulfillment_rate']['value']);
        self::assertSame(0.02, $result[101]['metrics']['cancellation_rate']['value']);
        self::assertSame(0.02, $result[101]['metrics']['return_rate']['value']);
        self::assertSame(0.0, $result[101]['metrics']['dispute_rate']['value']);

        // Vendor 202 — poor performance
        self::assertSame(0.5, $result[202]['metrics']['fulfillment_rate']['value']);
        self::assertSame(0.25, $result[202]['metrics']['cancellation_rate']['value']);
        self::assertSame(0.0, $result[202]['metrics']['return_rate']['value']);
        self::assertEqualsWithDelta(0.1333, $result[202]['metrics']['dispute_rate']['value'], 0.0001);
    }

    #[Test]
    public function listIncludesEmptyVendorsAsNullRates(): void
    {
        // Vendor 999 is requested but had no orders in the window.
        // It should still appear in the result with null rates.
        $captured = $this->captureListVendors([
            'items' => [
                ['vendor_id' => '101', 'total' => '10', 'fulfilled' => '10', 'rejected' => '0'],
            ],
            'returns' => [],
            'orders' => [
                ['vendor_id' => '101', 'total' => '8', 'disputed' => '0'],
            ],
        ]);

        $calc = new VendorMetricsCalculator($captured['em'], new InMemoryLogger());
        $result = $calc->computeForVendorList([101, 999], 30);

        self::assertArrayHasKey(999, $result);
        self::assertNull($result[999]['metrics']['fulfillment_rate']['value']);
        self::assertNull($result[999]['metrics']['cancellation_rate']['value']);
        self::assertNull($result[999]['metrics']['return_rate']['value']);
        self::assertNull($result[999]['metrics']['dispute_rate']['value']);
        self::assertSame(0, $result[999]['metrics']['fulfillment_rate']['total_items']);
    }

    #[Test]
    public function emptyVendorListShortCircuits(): void
    {
        $captured = $this->captureListVendors([
            'items' => [], 'returns' => [], 'orders' => [],
        ]);

        $calc = new VendorMetricsCalculator($captured['em'], new InMemoryLogger());
        $result = $calc->computeForVendorList([], 30);

        self::assertSame([], $result);
        // No queries should have been issued
        self::assertEmpty($captured['queries']);
    }

    #[Test]
    public function listSqlGroupsByVendorIdAndUsesArrayBinding(): void
    {
        $captured = $this->captureListVendors([
            'items' => [
                ['vendor_id' => '101', 'total' => '10', 'fulfilled' => '10', 'rejected' => '0'],
            ],
            'returns' => [],
            'orders' => [
                ['vendor_id' => '101', 'total' => '8', 'disputed' => '0'],
            ],
        ]);

        $calc = new VendorMetricsCalculator($captured['em'], new InMemoryLogger());
        $calc->computeForVendorList([101, 202, 303], 30);

        $itemsQuery = $captured['queries'][0];
        self::assertStringContainsString('GROUP BY oi.vendor_id', $itemsQuery['sql']);
        self::assertSame([101, 202, 303], $itemsQuery['params']['vendorIds']);
        self::assertSame(ArrayParameterType::INTEGER, $itemsQuery['types']['vendorIds']);
    }

    // =================================================================
    // Observability + timeout (mirrors X.10-D pattern)
    // =================================================================

    #[Test]
    public function emitsStatementTimeoutBeforeQuerying(): void
    {
        $captured = $this->captureSingleVendor(
            [
                'items' => ['total' => '0', 'fulfilled' => '0', 'rejected' => '0'],
                'returns' => ['approved' => '0'],
                'orders' => ['total' => '0', 'disputed' => '0'],
            ],
            captureStatements: true,
        );

        $calc = new VendorMetricsCalculator($captured['em'], new InMemoryLogger());
        $calc->computeForVendor(101, 30);

        self::assertGreaterThanOrEqual(1, count($captured['statements']));
        self::assertStringContainsString('statement_timeout', $captured['statements'][0]);
        self::assertStringContainsString('2000', $captured['statements'][0]);
    }

    #[Test]
    public function timeoutFailureIsLoggedNotPropagated(): void
    {
        $logger = new InMemoryLogger();
        $connection = $this->createMock(Connection::class);
        $connection->method('executeStatement')->willThrowException(
            new \RuntimeException('driver does not support statement_timeout'),
        );
        // Provide minimal canned results so the 3 queries don't throw
        $connection->method('executeQuery')->willReturnCallback(
            function () {
                $r = $this->createMock(Result::class);
                $r->method('fetchAssociative')->willReturn(false);
                $r->method('fetchAllAssociative')->willReturn([]);
                return $r;
            },
        );
        $em = $this->emWithConnection($connection);

        $result = (new VendorMetricsCalculator($em, $logger))->computeForVendor(101, 30);

        self::assertNull($result['metrics']['fulfillment_rate']['value']);
        $skipped = $logger->findByMessage('vendor_metrics.timeout.skipped');
        self::assertCount(1, $skipped);
        self::assertSame('debug', $skipped[0]['level']);
    }

    #[Test]
    public function emitsTimingLogOnEveryCompute(): void
    {
        $logger = new InMemoryLogger();
        $captured = $this->captureSingleVendor([
            'items' => ['total' => '0', 'fulfilled' => '0', 'rejected' => '0'],
            'returns' => ['approved' => '0'],
            'orders' => ['total' => '0', 'disputed' => '0'],
        ]);

        (new VendorMetricsCalculator($captured['em'], $logger))->computeForVendor(101, 30);

        $records = $logger->findByMessage('vendor_metrics.computed');
        self::assertCount(1, $records);
        self::assertSame('debug', $records[0]['level']);
        self::assertArrayHasKey('duration_ms', $records[0]['context']);
        self::assertSame(101, $records[0]['context']['vendor_id']);
        self::assertSame(30, $records[0]['context']['window_days']);
    }

    #[Test]
    public function emitsSlowResponseWarningWhenOver200Ms(): void
    {
        $logger = new InMemoryLogger();
        // Sleep 220ms on first executeQuery; others return immediately
        $sleeps = [220_000, 0, 0];
        $callIdx = 0;
        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(
            function () use (&$callIdx, $sleeps) {
                $delay = $sleeps[$callIdx] ?? 0;
                $callIdx++;
                if ($delay > 0) {
                    usleep($delay);
                }
                $r = $this->createMock(Result::class);
                $r->method('fetchAssociative')->willReturn(false);
                $r->method('fetchAllAssociative')->willReturn([]);
                return $r;
            },
        );
        $em = $this->emWithConnection($connection);

        (new VendorMetricsCalculator($em, $logger))->computeForVendor(101, 30);

        $slow = $logger->findByMessage('vendor_metrics.slow_response');
        self::assertCount(1, $slow);
        self::assertSame('warning', $slow[0]['level']);
        self::assertGreaterThan(200, $slow[0]['context']['duration_ms']);
        self::assertSame(200, $slow[0]['context']['threshold_ms']);
    }

    // =================================================================
    // Helpers
    // =================================================================

    /**
     * @param array{
     *     items: array{total: string, fulfilled: string, rejected: string},
     *     returns: array{approved: string},
     *     orders: array{total: string, disputed: string}
     * } $rows
     * @return array{em: EntityManagerInterface, queries: list<array{sql: string, params: array<string, mixed>, types: array<string, int|string>}>, statements: list<string>}
     */
    private function captureSingleVendor(array $rows, bool $captureStatements = false): array
    {
        $queries = [];
        $statements = [];
        $callIndex = 0;
        $orderedRows = [$rows['items'], $rows['returns'], $rows['orders']];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(
            function (string $sql, array $params, array $types) use (&$queries, &$callIndex, $orderedRows): Result {
                $queries[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
                $row = $orderedRows[$callIndex] ?? false;
                $callIndex++;

                $result = $this->createMock(Result::class);
                $result->method('fetchAssociative')->willReturn($row !== [] ? $row : false);
                $result->method('fetchAllAssociative')->willReturn([]);
                return $result;
            },
        );

        if ($captureStatements) {
            $connection->method('executeStatement')->willReturnCallback(
                function (string $sql) use (&$statements): int {
                    $statements[] = $sql;
                    return 0;
                },
            );
        }

        $em = $this->emWithConnection($connection);
        return ['em' => $em, 'queries' => &$queries, 'statements' => &$statements];
    }

    /**
     * @param array{
     *     items: list<array<string, string>>,
     *     returns: list<array<string, string>>,
     *     orders: list<array<string, string>>
     * } $rows
     * @return array{em: EntityManagerInterface, queries: list<array{sql: string, params: array<string, mixed>, types: array<string, int|string>}>}
     */
    private function captureListVendors(array $rows): array
    {
        $queries = [];
        $callIndex = 0;
        $orderedRows = [$rows['items'], $rows['returns'], $rows['orders']];

        $connection = $this->createMock(Connection::class);
        $connection->method('executeQuery')->willReturnCallback(
            function (string $sql, array $params, array $types) use (&$queries, &$callIndex, $orderedRows): Result {
                $queries[] = ['sql' => $sql, 'params' => $params, 'types' => $types];
                $rowSet = $orderedRows[$callIndex] ?? [];
                $callIndex++;

                $result = $this->createMock(Result::class);
                $result->method('fetchAllAssociative')->willReturn($rowSet);
                $result->method('fetchAssociative')->willReturn(false);
                return $result;
            },
        );

        $em = $this->emWithConnection($connection);
        return ['em' => $em, 'queries' => &$queries];
    }

    private function emWithConnection(Connection $connection): EntityManagerInterface
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        return $em;
    }
}
