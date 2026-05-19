<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\VendorAnalyticsCalculator;
use Bayti\Api\Tests\Support\InMemoryLogger;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * X.13-A skeleton tests for VendorAnalyticsCalculator.
 *
 * Validates the envelope shape + observability before the actual
 * SQL queries land in X.13-B / X.13-C / X.13-D. Stub
 * implementations return empty data so the envelope contract is
 * locked early and downstream consumers (X.13-E serializer + tests)
 * can bind to a stable shape.
 */
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

    private function makeCalc(?InMemoryLogger $logger = null): VendorAnalyticsCalculator
    {
        $connection = $this->createMock(Connection::class);
        // Skeleton stubs don't run queries, but setStatementTimeout
        // calls executeStatement — mock it to return 0.
        $connection->method('executeStatement')->willReturn(0);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new VendorAnalyticsCalculator($em, $logger ?? new InMemoryLogger());
    }
}
