<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Compute category-affinity recommendations for the X.12-E cron
 * (M3.2.X.12-D).
 *
 * Algorithm: for each product X, find products Y in the SAME
 * category as X (different product_id, both active). Score is a
 * small constant so category-fallback rows always rank BELOW any
 * co-purchase rows for the same source product.
 *
 * Used as a fallback signal: when a product has < N co-purchase
 * recommendations (Q-Algorithm = B locked: co-purchase +
 * same-category fallback), the cron tops up from this category
 * graph. New products with no order history at all get ALL their
 * recommendations from here.
 *
 * The scoring is intentionally weak. Category similarity alone
 * isn't a strong signal — products in the same category compete
 * with each other rather than complement. We rank category rows
 * at score=1.0 (vs co-purchase scores that start at 3.0 with
 * MIN_SUPPORT=3 and go up). Higher MIN_SUPPORT co-purchase rows
 * always rank first.
 *
 * Q-VendorScope = A locked: marketplace-wide. Category fallback
 * can span vendors freely.
 *
 * Cron-only service. Not called from any HTTP read path.
 *
 * @internal Not final to allow PHPUnit class doubles.
 */
class CategoryAffinityCalculator
{
    /**
     * Constant score for category fallback rows. Below the
     * co-purchase MIN_SUPPORT floor (3) so category rows always
     * rank after any co-purchase rows for the same source.
     */
    public const CATEGORY_SCORE = '1.0000';

    /**
     * Top-N pairs per product. Matches CoPurchaseAffinityCalculator's
     * cap so the cron has consistent headroom across both sources.
     */
    public const TOP_N_PER_PRODUCT = 50;

    /**
     * Per-statement timeout. Category lookups are much cheaper
     * than co-purchase (single index scan on category_id), so
     * 10 seconds is generous headroom.
     */
    private const STATEMENT_TIMEOUT_MS = 10_000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Compute the full category-affinity graph.
     *
     * Same shape as CoPurchaseAffinityCalculator::computeFullGraph
     * so BuildRecommendationsCommand (X.12-E) can merge the two
     * sources uniformly.
     *
     * @return array<int, list<array{recommended_product_id: int, score: string}>>
     */
    public function computeFullGraph(): array
    {
        $start = microtime(true);
        $this->setStatementTimeout();

        // For each (source, target) pair in the same category,
        // we use ROW_NUMBER() OVER (PARTITION BY source ORDER BY
        // target_id ASC) to deterministically cap each source at
        // TOP_N_PER_PRODUCT. Deterministic ordering matters so
        // the cron produces stable output across runs (same source
        // gets same recommendations unless catalog changes).
        //
        // Both products must:
        //   - have category_id NOT NULL (uncategorised products
        //     don't get category fallback — they fall through to
        //     fallback_popular in X.12-E)
        //   - be active (is_active = true) so we don't recommend
        //     hidden or deleted products
        //   - be different (p1.id != p2.id) so a product doesn't
        //     recommend itself
        $sql = "
            WITH ranked_pairs AS (
                SELECT
                    p1.id AS source_product_id,
                    p2.id AS target_product_id,
                    ROW_NUMBER() OVER (
                        PARTITION BY p1.id
                        ORDER BY p2.id ASC
                    ) AS rn
                FROM products p1
                INNER JOIN products p2
                    ON p2.category_id = p1.category_id
                   AND p2.id != p1.id
                WHERE p1.category_id IS NOT NULL
                  AND p1.is_active = true
                  AND p2.is_active = true
            )
            SELECT
                source_product_id,
                target_product_id
            FROM ranked_pairs
            WHERE rn <= " . self::TOP_N_PER_PRODUCT . "
            ORDER BY source_product_id ASC, rn ASC
        ";

        $rows = $this->em->getConnection()
            ->executeQuery($sql)
            ->fetchAllAssociative();

        $graph = [];
        foreach ($rows as $row) {
            $sid = (int) $row['source_product_id'];
            $graph[$sid] ??= [];
            $graph[$sid][] = [
                'recommended_product_id' => (int) $row['target_product_id'],
                'score' => self::CATEGORY_SCORE,
            ];
        }

        $duration = (int) ((microtime(true) - $start) * 1000);
        $this->logger->info('category_affinity.computed', [
            'source_products' => count($graph),
            'total_pairs' => count($rows),
            'duration_ms' => $duration,
            'category_score' => self::CATEGORY_SCORE,
        ]);

        return $graph;
    }

    private function setStatementTimeout(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                sprintf('SET LOCAL statement_timeout = %d', self::STATEMENT_TIMEOUT_MS),
            );
        } catch (\Throwable $e) {
            $this->logger->debug('category_affinity.timeout_setup_skipped', [
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
