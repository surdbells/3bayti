<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Compute analytics for a vendor's dashboard (M3.2.X.13).
 *
 * Parallel to VendorMetricsCalculator (X.14) but covers the
 * vendor-self-serve dashboard metrics rather than admin-side
 * performance rates. Five distinct query shapes feeding five
 * sections of the dashboard:
 *
 *   1. Revenue + velocity (period totals: total revenue, order
 *      count, item count, AOV, unique customers)
 *   2. Daily revenue time-series (one row per day in window)
 *   3. Top-N products by units AND by revenue (Q-TopN = C: both)
 *   4. Customer mix (new vs returning, vendor-scoped definition
 *      per Q-CustomerMix = A)
 *   5. Status mix (delivered / cancelled / returned ratios from
 *      the order_items table)
 *
 * No new revenue capture — this is a pure read-side aggregation
 * over existing orders + order_items + users.
 *
 * Q-decisions locked:
 *   Q-Scope=A         : 5 metrics shipped in v1
 *   Q-Window=A        : ?days=N rolling, default 30, [7, 365]
 *   Q-RevenueDef=A    : orders.paid_at-anchored, sum of
 *                       order_items.subtotal for items NOT in
 *                       (rejected, refunded)
 *   Q-TimeSeries=A    : daily buckets (one row per day)
 *   Q-TopN=C          : top-10 by units AND top-10 by revenue
 *                       (two separate lists)
 *   Q-CustomerMix=A   : vendor-scoped (was this customer's
 *                       first order WITH THIS VENDOR in window)
 *   Q-EmptyHandling=C : zero orders → totals=0 + empty arrays
 *   Q-Caching=A       : recompute every request; cache via
 *                       Redis is operator follow-up #35
 *   Q-Performance=A   : SET LOCAL statement_timeout + slow_response
 *                       warning matches X.10/X.14/X.17 pattern
 *
 * @internal Marked non-final to allow PHPUnit class doubles in
 *           controller-level integration tests. Matches the
 *           X.14 / X.17 / X.11 locked-in pattern.
 */
class VendorAnalyticsCalculator
{
    /**
     * Per-statement timeout in milliseconds. Higher than X.14's
     * 2000ms because analytics queries scan a wider envelope
     * (full order-item set in window) than the metric ratios.
     */
    private const STATEMENT_TIMEOUT_MS = 3000;

    /**
     * Slow-response threshold per call. Set above X.14's 200ms
     * because a 30-day analytics query has heavier aggregation
     * shape than a metric ratio.
     */
    private const SLOW_THRESHOLD_MS = 500;

    public const DEFAULT_WINDOW_DAYS = 30;
    public const MIN_WINDOW_DAYS = 7;
    public const MAX_WINDOW_DAYS = 365;

    public const DEFAULT_TOP_N = 10;

    /**
     * OrderItem statuses excluded from revenue per Q-RevenueDef=A.
     * Items in these terminal-loss states never count toward the
     * vendor's revenue total. Refunded items are removed even
     * though they were originally paid — the vendor returned that
     * money so it's not earned revenue.
     *
     * Used by X.13-B revenue queries.
     */
    private const REVENUE_EXCLUDED_ITEM_STATUSES = ['rejected', 'refunded'];

    /**
     * OrderItem statuses considered "delivered" for the status_mix.
     * Mirrors VendorMetricsCalculator's FULFILLED_ITEM_STATUSES.
     *
     * Referenced in X.13-D status_mix query.
     * @phpstan-ignore-next-line classConstant.unused
     */
    private const DELIVERED_ITEM_STATUSES = ['delivered'];

    /**
     * OrderItem statuses considered "cancelled (by vendor)" for
     * status_mix. Vendor-initiated only — customer-cancelled
     * orders go through a different path (status_changed_at is
     * on the order, not the item).
     *
     * Referenced in X.13-D status_mix query.
     * @phpstan-ignore-next-line classConstant.unused
     */
    private const CANCELLED_ITEM_STATUSES = ['rejected'];

    /**
     * OrderItem statuses considered "returned" for status_mix.
     * Items that started delivered then went through a return
     * flow that completed are in 'refunded' state.
     *
     * Referenced in X.13-D status_mix query.
     * @phpstan-ignore-next-line classConstant.unused
     */
    private const RETURNED_ITEM_STATUSES = ['refunded'];

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Compute the full analytics envelope for a vendor over the
     * specified window. Returns the shape consumed by the X.13-E
     * serializer.
     *
     * The shape carries:
     *   - window: {days, since, until}
     *   - totals: {revenue_aed, orders, items, aov_aed,
     *              unique_customers}
     *   - revenue_series: list<{date, revenue_aed, orders}>
     *   - top_products_by_units: list<{product_id, slug, name,
     *                                  units, revenue_aed}>
     *   - top_products_by_revenue: list<{...}>
     *   - customer_mix: {new, returning, total}
     *   - status_mix: {delivered, cancelled, returned, total}
     *
     * @return array{
     *     window: array{days: int, since: string, until: string},
     *     totals: array{
     *         revenue_aed: string,
     *         orders: int,
     *         items: int,
     *         aov_aed: string,
     *         unique_customers: int,
     *     },
     *     revenue_series: list<array{date: string, revenue_aed: string, orders: int}>,
     *     top_products_by_units: list<array{product_id: int, slug: string, name: string, units: int, revenue_aed: string}>,
     *     top_products_by_revenue: list<array{product_id: int, slug: string, name: string, units: int, revenue_aed: string}>,
     *     customer_mix: array{new: int, returning: int, total: int},
     *     status_mix: array{delivered: int, cancelled: int, returned: int, total: int},
     * }
     */
    public function computeForVendor(int $vendorId, int $days = self::DEFAULT_WINDOW_DAYS): array
    {
        $days = $this->clampWindow($days);

        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $since = $now->modify("-{$days} days");

        $start = microtime(true);
        $this->setStatementTimeout();

        // The 5 queries are dispatched in subsequent sub-phases
        // (X.13-B revenue+velocity, X.13-C top-N, X.13-D mixes).
        // The skeleton below assembles the empty envelope shape so
        // the X.13-E serializer + tests have something to bind to.
        $envelope = [
            'window' => [
                'days' => $days,
                'since' => $since->format(\DateTimeInterface::ATOM),
                'until' => $now->format(\DateTimeInterface::ATOM),
            ],
            'totals' => $this->computeTotals($vendorId, $since, $now),
            'revenue_series' => $this->computeRevenueSeries($vendorId, $since, $now, $days),
            'top_products_by_units' => $this->computeTopProductsByUnits($vendorId, $since, $now),
            'top_products_by_revenue' => $this->computeTopProductsByRevenue($vendorId, $since, $now),
            'customer_mix' => $this->computeCustomerMix($vendorId, $since, $now),
            'status_mix' => $this->computeStatusMix($vendorId, $since, $now),
        ];

        $duration = (int) ((microtime(true) - $start) * 1000);
        $this->logger->debug('vendor_analytics.computed', [
            'vendor_id' => $vendorId,
            'window_days' => $days,
            'duration_ms' => $duration,
        ]);
        if ($duration >= self::SLOW_THRESHOLD_MS) {
            $this->logger->warning('vendor_analytics.slow_response', [
                'vendor_id' => $vendorId,
                'window_days' => $days,
                'duration_ms' => $duration,
                'threshold_ms' => self::SLOW_THRESHOLD_MS,
            ]);
        }

        return $envelope;
    }

    private function clampWindow(int $days): int
    {
        if ($days < self::MIN_WINDOW_DAYS) {
            return self::MIN_WINDOW_DAYS;
        }
        if ($days > self::MAX_WINDOW_DAYS) {
            return self::MAX_WINDOW_DAYS;
        }
        return $days;
    }

    private function setStatementTimeout(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                sprintf('SET LOCAL statement_timeout = %d', self::STATEMENT_TIMEOUT_MS),
            );
        } catch (\Throwable $e) {
            // Non-PostgreSQL test setups don't support SET LOCAL;
            // fail-soft and continue. Same fail-soft as X.10-D.
            $this->logger->debug('vendor_analytics.timeout_setup_skipped', [
                'reason' => $e->getMessage(),
            ]);
        }
    }

    // =================================================================
    // Per-section computations — STUBS for X.13-A.
    // Real SQL queries land in X.13-B, X.13-C, X.13-D.
    // Each stub returns the empty-window shape so X.13-E + tests
    // have something to bind against.
    // =================================================================

    /**
     * X.13-B: Period totals query.
     *
     * One aggregate SQL selecting:
     *   - revenue_aed:        SUM(oi.subtotal) for items not in
     *                          REVENUE_EXCLUDED_ITEM_STATUSES
     *   - orders:             COUNT(DISTINCT o.id) — orders that
     *                          contributed at least one revenue-
     *                          counted item
     *   - items:              SUM(oi.quantity) for same items
     *   - unique_customers:   COUNT(DISTINCT o.user_id)
     *
     * AOV is derived in PHP (revenue / orders) since the math
     * is cheap and the SQL stays clean.
     *
     * Order eligibility = orders.paid_at IS NOT NULL AND
     * paid_at >= since AND paid_at < until. The Q-RevenueDef = A
     * window anchors on paid_at, not order created_at, to match
     * what vendors think of as "earned in this period".
     *
     * @return array{revenue_aed: string, orders: int, items: int, aov_aed: string, unique_customers: int}
     */
    private function computeTotals(int $vendorId, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        $excludedPlaceholders = implode(', ', array_fill(
            0,
            count(self::REVENUE_EXCLUDED_ITEM_STATUSES),
            '?',
        ));
        $sql = "
            SELECT
                COALESCE(SUM(oi.subtotal), 0)::text AS revenue,
                COUNT(DISTINCT o.id) AS orders,
                COALESCE(SUM(oi.quantity), 0)::int AS items,
                COUNT(DISTINCT o.user_id) AS unique_customers
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE oi.vendor_id = ?
              AND o.paid_at IS NOT NULL
              AND o.paid_at >= ?
              AND o.paid_at < ?
              AND oi.item_status NOT IN ({$excludedPlaceholders})
        ";

        $params = [
            $vendorId,
            $since->format('Y-m-d H:i:sP'),
            $until->format('Y-m-d H:i:sP'),
            ...self::REVENUE_EXCLUDED_ITEM_STATUSES,
        ];
        $types = [
            ParameterType::INTEGER,
            ParameterType::STRING,
            ParameterType::STRING,
            ...array_fill(0, count(self::REVENUE_EXCLUDED_ITEM_STATUSES), ParameterType::STRING),
        ];

        $row = $this->em->getConnection()
            ->executeQuery($sql, $params, $types)
            ->fetchAssociative();

        if ($row === false) {
            // Defensive: should never happen with COALESCE aggregates,
            // but PHPStan-friendly + future-proof.
            return [
                'revenue_aed' => '0.00',
                'orders' => 0,
                'items' => 0,
                'aov_aed' => '0.00',
                'unique_customers' => 0,
            ];
        }

        $revenue = (string) ($row['revenue'] ?? '0');
        $orders = (int) ($row['orders'] ?? 0);
        $items = (int) ($row['items'] ?? 0);
        $uniqueCustomers = (int) ($row['unique_customers'] ?? 0);

        return [
            'revenue_aed' => $this->money($revenue),
            'orders' => $orders,
            'items' => $items,
            'aov_aed' => $this->computeAov($revenue, $orders),
            'unique_customers' => $uniqueCustomers,
        ];
    }

    /**
     * X.13-B: Daily revenue series query.
     *
     * Postgres generate_series fills in zero-revenue days so the
     * frontend always sees exactly `days` data points without
     * having to plug holes itself. Joining against the order_items
     * aggregate gives revenue + order count per day.
     *
     * The boundary alignment uses `date_trunc('day', paid_at)` so
     * orders paid at any time during the day land in that day's
     * bucket regardless of timezone weirdness. We compute the
     * series in UTC; client-side display can shift if needed.
     *
     * @return list<array{date: string, revenue_aed: string, orders: int}>
     */
    private function computeRevenueSeries(
        int $vendorId,
        \DateTimeImmutable $since,
        \DateTimeImmutable $until,
        int $days,
    ): array {
        $excludedPlaceholders = implode(', ', array_fill(
            0,
            count(self::REVENUE_EXCLUDED_ITEM_STATUSES),
            '?',
        ));

        // Series spans `days` rows, starting at the day-boundary
        // of `since` and stepping by 1 day. LEFT JOIN with the
        // aggregate ensures zero-revenue days appear as 0.00 / 0
        // rather than being absent from the result.
        $sql = "
            WITH series AS (
                SELECT generate_series(
                    date_trunc('day', ?::timestamptz),
                    date_trunc('day', ?::timestamptz) - INTERVAL '1 day',
                    INTERVAL '1 day'
                ) AS bucket
            ),
            daily AS (
                SELECT
                    date_trunc('day', o.paid_at) AS bucket,
                    SUM(oi.subtotal) AS revenue,
                    COUNT(DISTINCT o.id) AS orders
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                WHERE oi.vendor_id = ?
                  AND o.paid_at IS NOT NULL
                  AND o.paid_at >= ?
                  AND o.paid_at < ?
                  AND oi.item_status NOT IN ({$excludedPlaceholders})
                GROUP BY date_trunc('day', o.paid_at)
            )
            SELECT
                to_char(s.bucket, 'YYYY-MM-DD') AS date,
                COALESCE(d.revenue, 0)::text AS revenue,
                COALESCE(d.orders, 0)::int AS orders
            FROM series s
            LEFT JOIN daily d ON d.bucket = s.bucket
            ORDER BY s.bucket ASC
        ";

        $params = [
            $since->format('Y-m-d H:i:sP'),
            $until->format('Y-m-d H:i:sP'),
            $vendorId,
            $since->format('Y-m-d H:i:sP'),
            $until->format('Y-m-d H:i:sP'),
            ...self::REVENUE_EXCLUDED_ITEM_STATUSES,
        ];
        $types = [
            ParameterType::STRING,
            ParameterType::STRING,
            ParameterType::INTEGER,
            ParameterType::STRING,
            ParameterType::STRING,
            ...array_fill(0, count(self::REVENUE_EXCLUDED_ITEM_STATUSES), ParameterType::STRING),
        ];

        $rows = $this->em->getConnection()
            ->executeQuery($sql, $params, $types)
            ->fetchAllAssociative();

        return array_map(
            fn (array $r): array => [
                'date' => (string) $r['date'],
                'revenue_aed' => $this->money((string) ($r['revenue'] ?? '0')),
                'orders' => (int) ($r['orders'] ?? 0),
            ],
            $rows,
        );
    }

    /**
     * Compute AOV = revenue / orders. Returns '0.00' when no
     * orders. Uses bcdiv for decimal-safe division (no float
     * drift on the rounding boundary).
     */
    private function computeAov(string $revenue, int $orders): string
    {
        if ($orders === 0) {
            return '0.00';
        }
        return $this->money(bcdiv($revenue, (string) $orders, 4));
    }

    /**
     * Normalise decimal-string amounts to 2 decimal places.
     * Matches the AED money formatting used throughout the
     * codebase (X.15-C HALF_UP-via-bias-add-then-truncate).
     */
    private function money(string $value): string
    {
        // Use the same bias-add-then-truncate trick as
        // CurrencyConversionService::roundHalfUp. Decimal-string
        // precision; no floats.
        $cmp = bccomp($value, '0', 4);
        if ($cmp === 0) {
            return '0.00';
        }
        $halfBias = '0.005';
        $biased = $cmp > 0
            ? bcadd($value, $halfBias, 4)
            : bcsub($value, $halfBias, 4);
        $truncated = bcadd($biased, '0', 2);
        // Normalise trailing-zero padding
        if (!str_contains($truncated, '.')) {
            return $truncated . '.00';
        }
        [$whole, $frac] = explode('.', $truncated, 2);
        $frac = str_pad(substr($frac, 0, 2), 2, '0');
        return $whole . '.' . $frac;
    }

    /**
     * @return list<array{product_id: int, slug: string, name: string, units: int, revenue_aed: string}>
     */
    private function computeTopProductsByUnits(int $vendorId, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        // X.13-C implementation. Empty for X.13-A skeleton.
        return [];
    }

    /**
     * @return list<array{product_id: int, slug: string, name: string, units: int, revenue_aed: string}>
     */
    private function computeTopProductsByRevenue(int $vendorId, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        // X.13-C implementation. Empty for X.13-A skeleton.
        return [];
    }

    /**
     * @return array{new: int, returning: int, total: int}
     */
    private function computeCustomerMix(int $vendorId, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        // X.13-D implementation. Zeros for X.13-A skeleton.
        return ['new' => 0, 'returning' => 0, 'total' => 0];
    }

    /**
     * @return array{delivered: int, cancelled: int, returned: int, total: int}
     */
    private function computeStatusMix(int $vendorId, \DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        // X.13-D implementation. Zeros for X.13-A skeleton.
        return ['delivered' => 0, 'cancelled' => 0, 'returned' => 0, 'total' => 0];
    }
}
