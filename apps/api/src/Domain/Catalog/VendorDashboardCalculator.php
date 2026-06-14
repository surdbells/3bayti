<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Aggregates an insightful, balanced vendor dashboard across ALL of a
 * user's owned stores: catalog health, sales (period + all-time), a daily
 * revenue trend, top products, an operations queue (items awaiting action,
 * low stock), and recent orders.
 *
 * Read-only aggregation over products + orders + order_items. Counts only
 * paid-and-beyond statuses (paid, fulfilling, shipped, delivered) as
 * realised sales — consistent with the per-product sales view.
 */
class VendorDashboardCalculator
{
    public const DEFAULT_WINDOW_DAYS = 30;
    public const MIN_WINDOW_DAYS = 7;
    public const MAX_WINDOW_DAYS = 365;

    /** Stock at or below this (and still in stock) is "low". */
    private const LOW_STOCK_THRESHOLD = 5;

    /** Order statuses that count as a realised sale. */
    private const SALE_STATUSES = ['paid', 'fulfilling', 'shipped', 'delivered'];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * @param list<int> $vendorIds
     * @return array<string, mixed>
     */
    public function compute(array $vendorIds, int $days = self::DEFAULT_WINDOW_DAYS): array
    {
        $days = max(self::MIN_WINDOW_DAYS, min(self::MAX_WINDOW_DAYS, $days));

        if ($vendorIds === []) {
            return $this->emptyEnvelope($days);
        }

        $conn = $this->em->getConnection();
        $saleStatuses = "'" . implode("','", self::SALE_STATUSES) . "'";

        return [
            'window'        => ['days' => $days],
            'catalog'       => $this->catalog($conn, $vendorIds),
            'sales'         => $this->sales($conn, $vendorIds, $saleStatuses, $days),
            'operations'    => $this->operations($conn, $vendorIds),
            'revenue_series' => $this->revenueSeries($conn, $vendorIds, $saleStatuses, $days),
            'top_products'  => $this->topProducts($conn, $vendorIds, $saleStatuses, $days),
            'recent_orders' => $this->recentOrders($conn, $vendorIds, $saleStatuses),
        ];
    }

    /** @param list<int> $vendorIds @return array<string,int> */
    private function catalog(Connection $conn, array $vendorIds): array
    {
        $row = $conn->fetchAssociative(
            "SELECT
                COUNT(*) FILTER (WHERE status <> 'soft_deleted')                      AS total,
                COUNT(*) FILTER (WHERE status = 'active')                             AS active,
                COUNT(*) FILTER (WHERE status = 'draft')                              AS draft,
                COUNT(*) FILTER (WHERE status <> 'soft_deleted' AND stock_status = 'out_of_stock') AS out_of_stock,
                COUNT(*) FILTER (WHERE status = 'active' AND stock_status = 'in_stock' AND stock_quantity <= :threshold) AS low_stock
             FROM products
             WHERE vendor_id IN (:ids)",
            ['ids' => $vendorIds, 'threshold' => self::LOW_STOCK_THRESHOLD],
            ['ids' => ArrayParameterType::INTEGER],
        ) ?: [];

        return [
            'total_products' => (int) ($row['total'] ?? 0),
            'active'         => (int) ($row['active'] ?? 0),
            'draft'          => (int) ($row['draft'] ?? 0),
            'out_of_stock'   => (int) ($row['out_of_stock'] ?? 0),
            'low_stock'      => (int) ($row['low_stock'] ?? 0),
        ];
    }

    /** @param list<int> $vendorIds @return array<string,mixed> */
    private function sales(Connection $conn, array $vendorIds, string $saleStatuses, int $days): array
    {
        $period = $this->salesAggregate(
            $conn,
            $vendorIds,
            $saleStatuses,
            "AND o.created_at >= (NOW() - (:days || ' days')::interval)",
            ['days' => (string) $days],
        );
        $allTime = $this->salesAggregate($conn, $vendorIds, $saleStatuses, '', []);

        $revenue = $period['revenue'];
        $orders = $period['orders'];

        return [
            'period_days'        => $days,
            'revenue'            => $revenue,
            'revenue_formatted'  => 'AED ' . number_format($revenue, 2),
            'orders'             => $orders,
            'units'              => $period['units'],
            'aov'                => $orders > 0 ? round($revenue / $orders, 2) : 0.0,
            'all_time_revenue'   => $allTime['revenue'],
            'all_time_revenue_formatted' => 'AED ' . number_format($allTime['revenue'], 2),
            'all_time_orders'    => $allTime['orders'],
            'all_time_units'     => $allTime['units'],
        ];
    }

    /**
     * @param list<int> $vendorIds
     * @param array<string,mixed> $extraParams
     * @return array{revenue: float, orders: int, units: int}
     */
    private function salesAggregate(
        Connection $conn,
        array $vendorIds,
        string $saleStatuses,
        string $dateClause,
        array $extraParams,
    ): array {
        $row = $conn->fetchAssociative(
            "SELECT
                COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS revenue,
                COUNT(DISTINCT oi.order_id)                   AS orders,
                COALESCE(SUM(oi.quantity), 0)                 AS units
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.vendor_id IN (:ids)
               AND o.status IN ({$saleStatuses})
               {$dateClause}",
            ['ids' => $vendorIds] + $extraParams,
            ['ids' => ArrayParameterType::INTEGER],
        ) ?: [];

        return [
            'revenue' => round((float) ($row['revenue'] ?? 0), 2),
            'orders'  => (int) ($row['orders'] ?? 0),
            'units'   => (int) ($row['units'] ?? 0),
        ];
    }

    /** @param list<int> $vendorIds @return array<string,int> */
    private function operations(Connection $conn, array $vendorIds): array
    {
        $row = $conn->fetchAssociative(
            "SELECT
                COUNT(*) FILTER (WHERE oi.item_status = 'pending')                       AS awaiting_acceptance,
                COUNT(*) FILTER (WHERE oi.item_status IN ('accepted','preparing'))        AS to_ship
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.vendor_id IN (:ids)
               AND o.status IN ('paid','fulfilling','shipped')",
            ['ids' => $vendorIds],
            ['ids' => ArrayParameterType::INTEGER],
        ) ?: [];

        return [
            'awaiting_acceptance' => (int) ($row['awaiting_acceptance'] ?? 0),
            'to_ship'             => (int) ($row['to_ship'] ?? 0),
        ];
    }

    /** @param list<int> $vendorIds @return list<array<string,mixed>> */
    private function revenueSeries(Connection $conn, array $vendorIds, string $saleStatuses, int $days): array
    {
        $rows = $conn->fetchAllAssociative(
            "SELECT to_char(date_trunc('day', o.created_at), 'YYYY-MM-DD') AS day,
                    COALESCE(SUM(oi.quantity * oi.unit_price), 0) AS revenue,
                    COUNT(DISTINCT oi.order_id)                   AS orders
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.vendor_id IN (:ids)
               AND o.status IN ({$saleStatuses})
               AND o.created_at >= (NOW() - (:days || ' days')::interval)
             GROUP BY 1 ORDER BY 1 ASC",
            ['ids' => $vendorIds, 'days' => (string) $days],
            ['ids' => ArrayParameterType::INTEGER],
        );
        return array_map(static fn (array $r): array => [
            'day'     => $r['day'],
            'revenue' => round((float) $r['revenue'], 2),
            'orders'  => (int) $r['orders'],
        ], $rows);
    }

    /** @param list<int> $vendorIds @return list<array<string,mixed>> */
    private function topProducts(Connection $conn, array $vendorIds, string $saleStatuses, int $days): array
    {
        $rows = $conn->fetchAllAssociative(
            "SELECT oi.product_id,
                    MAX(oi.product_name_snapshot)                 AS name,
                    SUM(oi.quantity)                              AS units,
                    SUM(oi.quantity * oi.unit_price)              AS revenue
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.vendor_id IN (:ids)
               AND o.status IN ({$saleStatuses})
               AND o.created_at >= (NOW() - (:days || ' days')::interval)
             GROUP BY oi.product_id
             ORDER BY revenue DESC
             LIMIT 5",
            ['ids' => $vendorIds, 'days' => (string) $days],
            ['ids' => ArrayParameterType::INTEGER],
        );
        return array_map(static fn (array $r): array => [
            'product_id' => (int) $r['product_id'],
            'name'       => (string) $r['name'],
            'units'      => (int) $r['units'],
            'revenue'    => round((float) $r['revenue'], 2),
        ], $rows);
    }

    /** @param list<int> $vendorIds @return list<array<string,mixed>> */
    private function recentOrders(Connection $conn, array $vendorIds, string $saleStatuses): array
    {
        $rows = $conn->fetchAllAssociative(
            "SELECT o.order_reference,
                    o.status,
                    to_char(o.created_at, 'YYYY-MM-DD\"T\"HH24:MI:SSOF') AS created_at,
                    SUM(oi.quantity)                 AS item_count,
                    SUM(oi.quantity * oi.unit_price) AS vendor_total
             FROM order_items oi
             JOIN orders o ON o.id = oi.order_id
             WHERE oi.vendor_id IN (:ids)
               AND o.status IN ({$saleStatuses})
             GROUP BY o.id, o.order_reference, o.status, o.created_at
             ORDER BY o.created_at DESC
             LIMIT 8",
            ['ids' => $vendorIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
        return array_map(static fn (array $r): array => [
            'order_reference' => $r['order_reference'],
            'status'          => $r['status'],
            'created_at'      => $r['created_at'],
            'item_count'      => (int) $r['item_count'],
            'vendor_total'    => round((float) $r['vendor_total'], 2),
        ], $rows);
    }

    /** @return array<string,mixed> */
    private function emptyEnvelope(int $days): array
    {
        return [
            'window'        => ['days' => $days],
            'catalog'       => ['total_products' => 0, 'active' => 0, 'draft' => 0, 'out_of_stock' => 0, 'low_stock' => 0],
            'sales'         => [
                'period_days' => $days, 'revenue' => 0.0, 'revenue_formatted' => 'AED 0.00',
                'orders' => 0, 'units' => 0, 'aov' => 0.0,
                'all_time_revenue' => 0.0, 'all_time_revenue_formatted' => 'AED 0.00',
                'all_time_orders' => 0, 'all_time_units' => 0,
            ],
            'operations'    => ['awaiting_acceptance' => 0, 'to_ship' => 0],
            'revenue_series' => [],
            'top_products'  => [],
            'recent_orders' => [],
        ];
    }
}
