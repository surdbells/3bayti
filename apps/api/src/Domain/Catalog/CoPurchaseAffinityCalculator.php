<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Compute co-purchase affinity scores for the recommendations
 * engine (M3.2.X.12-C).
 *
 * Algorithm: for each product X, find products Y such that X and
 * Y appear together in the same order, then count the co-occurrence
 * frequency. Higher count = stronger affinity = better recommendation.
 *
 * Filters applied:
 *   - Only PAID orders (orders.paid_at IS NOT NULL)
 *   - Only items NOT in (rejected, refunded), same status exclusions
 *     as revenue (rejected items represent broken transactions; the
 *     customer never actually got both products together)
 *   - Window: last 365 days (Q-CoPurchaseWindow = C locked: 1 year
 *     balances recency with sample size for low-traffic products)
 *   - Minimum support: 3 co-purchase events (Q-MinSupport = B locked:
 *     prevents 1-order noise without being too strict for niche
 *     products)
 *
 * Q-VendorScope = A locked: marketplace-wide. Co-purchases can span
 * vendors, if a customer bought product X from vendor A and product
 * Y from vendor B in the same order, that's still a valid signal.
 *
 * Q-PersonalizedScope = C locked: this calculator is product-pair-
 * level, not user-level. The user-personalized "recommended for you"
 * path (X.12-F) is a separate aggregation over a user's order history.
 *
 * This service is used ONLY by the X.12-E cron command, not by
 * any HTTP read path. The hot read path queries the denormalized
 * product_recommendations table populated by this calculator.
 *
 * @internal Not final to allow PHPUnit class doubles in
 *           BuildRecommendationsCommand integration tests.
 */
class CoPurchaseAffinityCalculator
{
    /**
     * Co-purchase events from the last N days are considered
     * (Q-CoPurchaseWindow = C).
     */
    public const WINDOW_DAYS = 365;

    /**
     * Minimum co-purchase count to include a pair (Q-MinSupport = B).
     * Pairs with fewer than 3 co-occurrences are filtered out as
     * noise.
     */
    public const MIN_SUPPORT = 3;

    /**
     * Top-N pairs per source product. Set well above the X.12-G
     * read-side default limit (10) so the cron has headroom, but
     * not so high that the table grows unboundedly. With ~10k
     * products * 50 rows = 500k rows max, which fits comfortably
     * in the indexed lookup.
     */
    public const TOP_N_PER_PRODUCT = 50;

    /**
     * OrderItem statuses excluded from co-purchase counting.
     * Same exclusions as revenue (X.13-B), rejected + refunded
     * items represent broken transactions.
     */
    private const EXCLUDED_ITEM_STATUSES = ['rejected', 'refunded'];

    /**
     * Per-statement timeout. Co-purchase queries scan the full
     * order_items table for the window so this is the slowest
     * SQL in the codebase by some margin. 30 seconds gives the
     * planner room without hanging the cron indefinitely.
     */
    private const STATEMENT_TIMEOUT_MS = 30_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Compute the full co-purchase affinity graph as a map of
     * source product id → list of {target_id, score} pairs.
     *
     * The full graph is materialized in PHP memory before the
     * cron writes to the product_recommendations table. With
     * top-N=50 per source and ~10k products that's ~500k rows
     * * ~50 bytes each = ~25MB peak. Comfortable for any worker.
     *
     * @return array<int, list<array{recommended_product_id: int, score: string}>>
     */
    public function computeFullGraph(): array
    {
        $start = microtime(true);
        $this->setStatementTimeout();

        $excludedPlaceholders = implode(', ', array_fill(
            0,
            count(self::EXCLUDED_ITEM_STATUSES),
            '?',
        ));

        // Co-purchase pair count via self-JOIN on order_items.
        // For each order, every pair of distinct products in that
        // order contributes one co-occurrence count to BOTH
        // directions (X→Y and Y→X). We get symmetric pair counts
        // by joining on a.order_id = b.order_id with a.product_id
        // != b.product_id (NOT <, we want both directions
        // populated so each source product gets its own top-N list).
        //
        // ROW_NUMBER() OVER (PARTITION BY ...) ranks pairs within
        // each source product so we can take the top N per product
        // in a single query without a separate ORDER BY + LIMIT
        // per group.
        $sql = "
            WITH co_purchase_counts AS (
                SELECT
                    a.product_id AS source_product_id,
                    b.product_id AS target_product_id,
                    COUNT(DISTINCT a.order_id) AS pair_count
                FROM order_items a
                INNER JOIN order_items b
                    ON b.order_id = a.order_id
                   AND b.product_id != a.product_id
                INNER JOIN orders o
                    ON o.id = a.order_id
                WHERE o.paid_at IS NOT NULL
                  AND o.paid_at >= NOW() - INTERVAL '" . self::WINDOW_DAYS . " days'
                  AND a.item_status NOT IN ({$excludedPlaceholders})
                  AND b.item_status NOT IN ({$excludedPlaceholders})
                GROUP BY a.product_id, b.product_id
                HAVING COUNT(DISTINCT a.order_id) >= " . self::MIN_SUPPORT . "
            ),
            ranked AS (
                SELECT
                    source_product_id,
                    target_product_id,
                    pair_count,
                    ROW_NUMBER() OVER (
                        PARTITION BY source_product_id
                        ORDER BY pair_count DESC, target_product_id ASC
                    ) AS rn
                FROM co_purchase_counts
            )
            SELECT
                source_product_id,
                target_product_id,
                pair_count
            FROM ranked
            WHERE rn <= " . self::TOP_N_PER_PRODUCT . "
            ORDER BY source_product_id ASC, rn ASC
        ";

        $params = [
            ...self::EXCLUDED_ITEM_STATUSES,
            ...self::EXCLUDED_ITEM_STATUSES,
        ];
        $types = [
            ...array_fill(0, count(self::EXCLUDED_ITEM_STATUSES), ParameterType::STRING),
            ...array_fill(0, count(self::EXCLUDED_ITEM_STATUSES), ParameterType::STRING),
        ];

        $rows = $this->em->getConnection()
            ->executeQuery($sql, $params, $types)
            ->fetchAllAssociative();

        // Materialize as map: source_id → ordered list of pairs
        $graph = [];
        foreach ($rows as $row) {
            $sid = (int) $row['source_product_id'];
            $graph[$sid] ??= [];
            $graph[$sid][] = [
                'recommended_product_id' => (int) $row['target_product_id'],
                'score' => $this->formatScore((string) $row['pair_count']),
            ];
        }

        $duration = (int) ((microtime(true) - $start) * 1000);
        $this->logger->info('copurchase_affinity.computed', [
            'source_products' => count($graph),
            'total_pairs' => count($rows),
            'duration_ms' => $duration,
            'window_days' => self::WINDOW_DAYS,
            'min_support' => self::MIN_SUPPORT,
        ]);

        return $graph;
    }

    /**
     * Format a raw COUNT result as a NUMERIC(8, 4) decimal string.
     * COUNT returns an integer; the entity column is DECIMAL.
     */
    private function formatScore(string $count): string
    {
        // Pad to 4 fractional digits. bcadd with explicit scale
        // handles this cleanly.
        return bcadd($count, '0', 4);
    }

    private function setStatementTimeout(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                sprintf('SET LOCAL statement_timeout = %d', self::STATEMENT_TIMEOUT_MS),
            );
        } catch (\Throwable $e) {
            $this->logger->debug('copurchase_affinity.timeout_setup_skipped', [
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
