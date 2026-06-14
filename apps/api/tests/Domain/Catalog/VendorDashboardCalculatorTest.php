<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Catalog;

use Bayti\Api\Domain\Catalog\VendorDashboardCalculator;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * VendorDashboardCalculator — assembles the dashboard envelope from the
 * aggregate queries. Connection is mocked to feed canned rows.
 */
#[CoversClass(VendorDashboardCalculator::class)]
final class VendorDashboardCalculatorTest extends TestCase
{
    #[Test]
    public function emptyVendorListReturnsZeroedEnvelopeWithoutQuerying(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->expects(self::never())->method('fetchAssociative');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $calc = new VendorDashboardCalculator($em);
        $out = $calc->compute([], 30);

        self::assertSame(0, $out['catalog']['total_products']);
        self::assertSame(0.0, $out['sales']['revenue']);
        self::assertSame('AED 0.00', $out['sales']['revenue_formatted']);
        self::assertSame([], $out['recent_orders']);
    }

    #[Test]
    public function assemblesEnvelopeFromQueryResults(): void
    {
        $conn = $this->createMock(Connection::class);

        // catalog, period sales, all-time sales, operations (4 single-row)
        $conn->method('fetchAssociative')->willReturnOnConsecutiveCalls(
            ['total' => '12', 'active' => '9', 'draft' => '3', 'out_of_stock' => '1', 'low_stock' => '2'],
            ['revenue' => '5400.00', 'orders' => '4', 'units' => '12'],
            ['revenue' => '20000.00', 'orders' => '15', 'units' => '60'],
            ['awaiting_acceptance' => '2', 'to_ship' => '1'],
        );
        // revenue_series, top_products, recent_orders (3 multi-row)
        $conn->method('fetchAllAssociative')->willReturnOnConsecutiveCalls(
            [['day' => '2026-06-01', 'revenue' => '2400.00', 'orders' => '2']],
            [['product_id' => '5', 'name' => 'Silk Abaya', 'units' => '7', 'revenue' => '3150.00']],
            [['order_reference' => 'ORD-1', 'status' => 'paid', 'created_at' => '2026-06-01T10:00:00+00', 'item_count' => '2', 'vendor_total' => '900.00']],
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $calc = new VendorDashboardCalculator($em);
        $out = $calc->compute([5, 9], 30);

        self::assertSame(12, $out['catalog']['total_products']);
        self::assertSame(2, $out['catalog']['low_stock']);
        self::assertSame(5400.0, $out['sales']['revenue']);
        self::assertSame(1350.0, $out['sales']['aov']); // 5400 / 4
        self::assertSame(20000.0, $out['sales']['all_time_revenue']);
        self::assertSame(2, $out['operations']['awaiting_acceptance']);
        self::assertCount(1, $out['revenue_series']);
        self::assertSame('Silk Abaya', $out['top_products'][0]['name']);
        self::assertSame('ORD-1', $out['recent_orders'][0]['order_reference']);
    }

    #[Test]
    public function windowDaysAreClamped(): void
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('fetchAssociative')->willReturn([]);
        $conn->method('fetchAllAssociative')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($conn);

        $calc = new VendorDashboardCalculator($em);

        self::assertSame(VendorDashboardCalculator::MAX_WINDOW_DAYS, $calc->compute([5], 9999)['window']['days']);
        self::assertSame(VendorDashboardCalculator::MIN_WINDOW_DAYS, $calc->compute([5], 1)['window']['days']);
    }
}
