<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Compute vendor performance metrics over a rolling window
 * (M3.2.X.14-A).
 *
 * Four metrics, all derived from existing order/return/dispute data -
 * no new tables, no schema migration. Each is a rate (0.0-1.0) plus
 * the numerator and denominator so the UI can show '23 of 245 orders'
 * not just '9.4%'.
 *
 * Window semantics
 * ================
 * The window anchors on `orders.paid_at` (not created_at), an order
 * that sat in pending_payment for days before paying didn't actually
 * become work for the vendor until paid. Window length is caller-
 * supplied (X.14 Q-Window = C, defaults to 30 days, range 7-365 in
 * the controller layer).
 *
 * Metric definitions (X.14 locked decisions)
 * ===========================================
 *
 * fulfillment_rate (Q-FulfillmentDef = A):
 *   delivered_items / total_items
 *   "delivered" only, shipped-but-not-delivered is in-flight,
 *   not yet success.
 *
 * cancellation_rate (Q-CancellationDef = B):
 *   rejected_items / total_items
 *   Only vendor-initiated rejections count. Customer-cancelled or
 *   admin-cancelled orders aren't the vendor's fault and shouldn't
 *   penalize them.
 *
 * return_rate (Q-ReturnDef = B):
 *   approved_returns / total_items
 *   Source: order_return_requests WHERE status IN (approved,
 *   picked_up, delivered_to_vendor, refunded). Customer-submitted
 *   but admin-denied returns don't count, that would penalize
 *   vendors for invalid return claims.
 *
 *   Note: numerator counts return-request *items* (rows in
 *   order_return_request_items) for that vendor. A single return
 *   request covering multiple items shipped from one vendor counts
 *   each item separately, matching the denominator's item-level
 *   granularity.
 *
 * dispute_rate (Q-DisputeAttribution = A):
 *   disputed_orders / total_orders
 *   "Disputed orders" = distinct orders containing items from this
 *   vendor that ALSO have any order_disputes row attached.
 *   Multi-vendor disputes count once per vendor with items in the
 *   disputed order (let admin investigate which items triggered it).
 *
 * Empty-data handling (Q-EmptyHandling = A)
 * ==========================================
 * Vendors with 0 items in the window: all 4 rates return null in
 * the 'value' field, with the underlying counts as 0. The UI then
 * shows '-' rather than misleading '0%'.
 *
 * Performance posture
 * ===================
 * Single-vendor computation: 4 queries (one per metric). 50-100ms
 * envelope on 30-day windows.
 *
 * Multi-vendor (list-across-all): 4 queries TOTAL with GROUP BY
 * vendor_id, not 4×N. The list endpoint (X.14-D) calls
 * computeForVendorList which structures the queries this way.
 *
 * Observability mirrors M3.2.X.10-D: hrtime() per compute, PSR-3
 * 'vendor_metrics.computed' debug, 'vendor_metrics.slow_response'
 * warning > 200ms, defensive SET LOCAL statement_timeout = 2000ms
 * (try/catch fail-soft).
 */
class VendorMetricsCalculator
{
    /**
     * Per-statement timeout in milliseconds (mirrors FacetAggregator
     * X.10-D pattern). 2000ms is generous given realistic envelopes
     * of 50-200ms on a 100-vendor / 30-day query set.
     */
    private const STATEMENT_TIMEOUT_MS = 2000;

    /**
     * Slow-response threshold. Higher than X.10-D's 100ms because
     * the metric queries scan a 30-day window of orders, not just
     * indexed catalog rows.
     */
    private const SLOW_THRESHOLD_MS = 200;

    /**
     * Default window length when caller doesn't specify (Q-Window = C).
     */
    public const DEFAULT_WINDOW_DAYS = 30;
    public const MIN_WINDOW_DAYS = 7;
    public const MAX_WINDOW_DAYS = 365;

    /**
     * OrderItem statuses considered "fulfilled" (Q-FulfillmentDef = A).
     */
    private const FULFILLED_ITEM_STATUSES = ['delivered'];

    /**
     * OrderItem statuses considered "cancelled by vendor" (Q-CancellationDef = B).
     * Only the vendor's own rejection counts, not customer or admin
     * cancellations.
     */
    private const REJECTED_ITEM_STATUSES = ['rejected'];

    /**
     * OrderReturnRequest statuses that count as "approved returns"
     * (Q-ReturnDef = B). Excludes pending (admin hasn't decided) and
     * denied (rejected by admin). Includes everything from approval
     * onward in the lifecycle.
     */
    private const APPROVED_RETURN_STATUSES = [
        'approved',
        'picked_up',
        'delivered_to_vendor',
        'refunded',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Compute metrics for a single vendor over the supplied window.
     *
     * @return array{
     *     window: array{days: int, since: string, until: string},
     *     metrics: array{
     *         fulfillment_rate: array{value: float|null, fulfilled_items: int, total_items: int},
     *         cancellation_rate: array{value: float|null, rejected_items: int, total_items: int},
     *         return_rate: array{value: float|null, approved_returns: int, total_items: int},
     *         dispute_rate: array{value: float|null, disputed_orders: int, total_orders: int}
     *     }
     * }
     */
    public function computeForVendor(int $vendorId, int $windowDays): array
    {
        $this->applyStatementTimeout();
        $startNs = hrtime(true);

        [$since, $until] = $this->windowBounds($windowDays);

        $itemCounts = $this->fetchItemCounts($vendorId, $since, $until);
        $totalItems = $itemCounts['total'];
        $fulfilledItems = $itemCounts['fulfilled'];
        $rejectedItems = $itemCounts['rejected'];

        $returnCounts = $this->fetchReturnCounts($vendorId, $since, $until);
        $approvedReturns = $returnCounts['approved'];

        $orderCounts = $this->fetchOrderCounts($vendorId, $since, $until);
        $totalOrders = $orderCounts['total'];
        $disputedOrders = $orderCounts['disputed'];

        $elapsedMs = (int) ((hrtime(true) - $startNs) / 1_000_000);
        $this->emitTimingLogs($elapsedMs, ['vendor_id' => $vendorId, 'window_days' => $windowDays]);

        return [
            'window' => [
                'days' => $windowDays,
                'since' => $since->format(\DateTimeInterface::ATOM),
                'until' => $until->format(\DateTimeInterface::ATOM),
            ],
            'metrics' => [
                'fulfillment_rate' => $this->rateBlock(
                    $fulfilledItems,
                    $totalItems,
                    'fulfilled_items',
                    'total_items',
                ),
                'cancellation_rate' => $this->rateBlock(
                    $rejectedItems,
                    $totalItems,
                    'rejected_items',
                    'total_items',
                ),
                'return_rate' => $this->rateBlock(
                    $approvedReturns,
                    $totalItems,
                    'approved_returns',
                    'total_items',
                ),
                'dispute_rate' => $this->rateBlock(
                    $disputedOrders,
                    $totalOrders,
                    'disputed_orders',
                    'total_orders',
                ),
            ],
        ];
    }

    /**
     * Compute metrics for multiple vendors at once with a single
     * GROUP BY query per metric (4 queries total regardless of vendor
     * count). Used by the list-across-all endpoint (X.14-D).
     *
     * @param list<int> $vendorIds
     * @return array<int, array{
     *     window: array{days: int, since: string, until: string},
     *     metrics: array{
     *         fulfillment_rate: array{value: float|null, fulfilled_items: int, total_items: int},
     *         cancellation_rate: array{value: float|null, rejected_items: int, total_items: int},
     *         return_rate: array{value: float|null, approved_returns: int, total_items: int},
     *         dispute_rate: array{value: float|null, disputed_orders: int, total_orders: int}
     *     }
     * }>  Keyed by vendor_id.
     */
    public function computeForVendorList(array $vendorIds, int $windowDays): array
    {
        if ($vendorIds === []) {
            return [];
        }

        $this->applyStatementTimeout();
        $startNs = hrtime(true);

        [$since, $until] = $this->windowBounds($windowDays);

        $itemRows = $this->fetchItemCountsByVendor($vendorIds, $since, $until);
        $returnRows = $this->fetchReturnCountsByVendor($vendorIds, $since, $until);
        $orderRows = $this->fetchOrderCountsByVendor($vendorIds, $since, $until);

        $out = [];
        foreach ($vendorIds as $vendorId) {
            $items = $itemRows[$vendorId] ?? ['total' => 0, 'fulfilled' => 0, 'rejected' => 0];
            $returns = $returnRows[$vendorId] ?? ['approved' => 0];
            $orders = $orderRows[$vendorId] ?? ['total' => 0, 'disputed' => 0];

            $out[$vendorId] = [
                'window' => [
                    'days' => $windowDays,
                    'since' => $since->format(\DateTimeInterface::ATOM),
                    'until' => $until->format(\DateTimeInterface::ATOM),
                ],
                'metrics' => [
                    'fulfillment_rate' => $this->rateBlock(
                        $items['fulfilled'],
                        $items['total'],
                        'fulfilled_items',
                        'total_items',
                    ),
                    'cancellation_rate' => $this->rateBlock(
                        $items['rejected'],
                        $items['total'],
                        'rejected_items',
                        'total_items',
                    ),
                    'return_rate' => $this->rateBlock(
                        $returns['approved'],
                        $items['total'],
                        'approved_returns',
                        'total_items',
                    ),
                    'dispute_rate' => $this->rateBlock(
                        $orders['disputed'],
                        $orders['total'],
                        'disputed_orders',
                        'total_orders',
                    ),
                ],
            ];
        }

        $elapsedMs = (int) ((hrtime(true) - $startNs) / 1_000_000);
        $this->emitTimingLogs($elapsedMs, [
            'vendor_count' => count($vendorIds),
            'window_days' => $windowDays,
        ]);

        return $out;
    }

    /**
     * @return array{total: int, fulfilled: int, rejected: int}
     */
    private function fetchItemCounts(int $vendorId, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $sql = "
            SELECT
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE oi.item_status = ANY(:fulfilled)) AS fulfilled,
                COUNT(*) FILTER (WHERE oi.item_status = ANY(:rejected)) AS rejected
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE oi.vendor_id = :vendorId
              AND o.paid_at >= :since
              AND o.paid_at < :until
        ";

        $row = $this->em->getConnection()->executeQuery(
            $sql,
            [
                'vendorId' => $vendorId,
                'since' => $since->format('Y-m-d H:i:sP'),
                'until' => $until->format('Y-m-d H:i:sP'),
                'fulfilled' => self::FULFILLED_ITEM_STATUSES,
                'rejected' => self::REJECTED_ITEM_STATUSES,
            ],
            [
                'fulfilled' => \Doctrine\DBAL\ArrayParameterType::STRING,
                'rejected' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        if ($row === false) {
            return ['total' => 0, 'fulfilled' => 0, 'rejected' => 0];
        }

        return [
            'total' => (int) ($row['total'] ?? 0),
            'fulfilled' => (int) ($row['fulfilled'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
        ];
    }

    /**
     * @param list<int> $vendorIds
     * @return array<int, array{total: int, fulfilled: int, rejected: int}>
     */
    private function fetchItemCountsByVendor(array $vendorIds, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $sql = "
            SELECT
                oi.vendor_id AS vendor_id,
                COUNT(*) AS total,
                COUNT(*) FILTER (WHERE oi.item_status = ANY(:fulfilled)) AS fulfilled,
                COUNT(*) FILTER (WHERE oi.item_status = ANY(:rejected)) AS rejected
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE oi.vendor_id = ANY(:vendorIds)
              AND o.paid_at >= :since
              AND o.paid_at < :until
            GROUP BY oi.vendor_id
        ";

        $rows = $this->em->getConnection()->executeQuery(
            $sql,
            [
                'vendorIds' => $vendorIds,
                'since' => $since->format('Y-m-d H:i:sP'),
                'until' => $until->format('Y-m-d H:i:sP'),
                'fulfilled' => self::FULFILLED_ITEM_STATUSES,
                'rejected' => self::REJECTED_ITEM_STATUSES,
            ],
            [
                'vendorIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
                'fulfilled' => \Doctrine\DBAL\ArrayParameterType::STRING,
                'rejected' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $vid = (int) $row['vendor_id'];
            $out[$vid] = [
                'total' => (int) $row['total'],
                'fulfilled' => (int) $row['fulfilled'],
                'rejected' => (int) $row['rejected'],
            ];
        }
        return $out;
    }

    /**
     * @return array{approved: int}
     */
    private function fetchReturnCounts(int $vendorId, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        // Bridge: return_request_item → order_item → vendor
        $sql = "
            SELECT COUNT(*) AS approved
            FROM order_return_request_items rri
            INNER JOIN order_return_requests rr ON rr.id = rri.return_request_id
            INNER JOIN order_items oi ON oi.id = rri.order_item_id
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE oi.vendor_id = :vendorId
              AND o.paid_at >= :since
              AND o.paid_at < :until
              AND rr.status = ANY(:approvedStatuses)
        ";

        $row = $this->em->getConnection()->executeQuery(
            $sql,
            [
                'vendorId' => $vendorId,
                'since' => $since->format('Y-m-d H:i:sP'),
                'until' => $until->format('Y-m-d H:i:sP'),
                'approvedStatuses' => self::APPROVED_RETURN_STATUSES,
            ],
            [
                'approvedStatuses' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ],
        )->fetchAssociative();

        if ($row === false) {
            return ['approved' => 0];
        }

        return ['approved' => (int) ($row['approved'] ?? 0)];
    }

    /**
     * @param list<int> $vendorIds
     * @return array<int, array{approved: int}>
     */
    private function fetchReturnCountsByVendor(array $vendorIds, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $sql = "
            SELECT
                oi.vendor_id AS vendor_id,
                COUNT(*) AS approved
            FROM order_return_request_items rri
            INNER JOIN order_return_requests rr ON rr.id = rri.return_request_id
            INNER JOIN order_items oi ON oi.id = rri.order_item_id
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE oi.vendor_id = ANY(:vendorIds)
              AND o.paid_at >= :since
              AND o.paid_at < :until
              AND rr.status = ANY(:approvedStatuses)
            GROUP BY oi.vendor_id
        ";

        $rows = $this->em->getConnection()->executeQuery(
            $sql,
            [
                'vendorIds' => $vendorIds,
                'since' => $since->format('Y-m-d H:i:sP'),
                'until' => $until->format('Y-m-d H:i:sP'),
                'approvedStatuses' => self::APPROVED_RETURN_STATUSES,
            ],
            [
                'vendorIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
                'approvedStatuses' => \Doctrine\DBAL\ArrayParameterType::STRING,
            ],
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['vendor_id']] = ['approved' => (int) $row['approved']];
        }
        return $out;
    }

    /**
     * Order-level counts: total distinct orders the vendor has items in,
     * and how many of those had ANY order_disputes row attached
     * (Q-DisputeAttribution = A: a multi-vendor disputed order counts
     * once per vendor with items in it).
     *
     * @return array{total: int, disputed: int}
     */
    private function fetchOrderCounts(int $vendorId, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $sql = "
            SELECT
                COUNT(DISTINCT o.id) AS total,
                COUNT(DISTINCT o.id) FILTER (WHERE EXISTS (
                    SELECT 1 FROM order_disputes od WHERE od.order_id = o.id
                )) AS disputed
            FROM orders o
            INNER JOIN order_items oi ON oi.order_id = o.id
            WHERE oi.vendor_id = :vendorId
              AND o.paid_at >= :since
              AND o.paid_at < :until
        ";

        $row = $this->em->getConnection()->executeQuery(
            $sql,
            [
                'vendorId' => $vendorId,
                'since' => $since->format('Y-m-d H:i:sP'),
                'until' => $until->format('Y-m-d H:i:sP'),
            ],
        )->fetchAssociative();

        if ($row === false) {
            return ['total' => 0, 'disputed' => 0];
        }

        return [
            'total' => (int) ($row['total'] ?? 0),
            'disputed' => (int) ($row['disputed'] ?? 0),
        ];
    }

    /**
     * @param list<int> $vendorIds
     * @return array<int, array{total: int, disputed: int}>
     */
    private function fetchOrderCountsByVendor(array $vendorIds, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $sql = "
            SELECT
                oi.vendor_id AS vendor_id,
                COUNT(DISTINCT o.id) AS total,
                COUNT(DISTINCT o.id) FILTER (WHERE EXISTS (
                    SELECT 1 FROM order_disputes od WHERE od.order_id = o.id
                )) AS disputed
            FROM orders o
            INNER JOIN order_items oi ON oi.order_id = o.id
            WHERE oi.vendor_id = ANY(:vendorIds)
              AND o.paid_at >= :since
              AND o.paid_at < :until
            GROUP BY oi.vendor_id
        ";

        $rows = $this->em->getConnection()->executeQuery(
            $sql,
            [
                'vendorIds' => $vendorIds,
                'since' => $since->format('Y-m-d H:i:sP'),
                'until' => $until->format('Y-m-d H:i:sP'),
            ],
            [
                'vendorIds' => \Doctrine\DBAL\ArrayParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        $out = [];
        foreach ($rows as $row) {
            $out[(int) $row['vendor_id']] = [
                'total' => (int) $row['total'],
                'disputed' => (int) $row['disputed'],
            ];
        }
        return $out;
    }

    /**
     * Build the rate-block return shape. Per Q-EmptyHandling = A,
     * 'value' is null (not 0.0) when denominator is 0.
     *
     * @return array{value: float|null, fulfilled_items?: int, total_items?: int, rejected_items?: int, approved_returns?: int, disputed_orders?: int, total_orders?: int}
     */
    private function rateBlock(int $numerator, int $denominator, string $numKey, string $denomKey): array
    {
        if ($denominator === 0) {
            return [
                'value' => null,
                $numKey => $numerator,
                $denomKey => $denominator,
            ];
        }
        return [
            'value' => round($numerator / $denominator, 4),
            $numKey => $numerator,
            $denomKey => $denominator,
        ];
    }

    /**
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}
     */
    private function windowBounds(int $windowDays): array
    {
        $until = new \DateTimeImmutable();
        $since = $until->modify("-{$windowDays} days");
        return [$since, $until];
    }

    private function applyStatementTimeout(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                sprintf('SET LOCAL statement_timeout = %d', self::STATEMENT_TIMEOUT_MS),
            );
        } catch (\Throwable $e) {
            $this->logger->debug('vendor_metrics.timeout.skipped', [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function emitTimingLogs(int $elapsedMs, array $context): void
    {
        $this->logger->debug('vendor_metrics.computed', array_merge(
            ['duration_ms' => $elapsedMs],
            $context,
        ));

        if ($elapsedMs > self::SLOW_THRESHOLD_MS) {
            $this->logger->warning('vendor_metrics.slow_response', array_merge(
                ['duration_ms' => $elapsedMs, 'threshold_ms' => self::SLOW_THRESHOLD_MS],
                $context,
            ));
        }
    }
}
