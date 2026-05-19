<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Read-side service for product recommendations (M3.2.X.12-F).
 *
 * Single hot-path entry point: getRecommendationsForProduct(\$productId, \$limit)
 * which:
 *   1. Queries the denormalized product_recommendations table
 *      via the (product_id, rank) composite index — typically
 *      sub-millisecond.
 *   2. If the result is empty (product has never appeared with
 *      another product in a paid order AND has no category, OR
 *      is a brand-new product the cron hasn't seen yet) — falls
 *      back to marketplace-wide popular products.
 *   3. Hydrates the recommended Product entities so callers can
 *      shape them through ProductSerializer.
 *
 * Q-EmptyHandling = B locked: empty recommendations → popular
 * fallback. Better UX than empty array or 404. The fallback
 * popularity query is a single GROUP BY over the last 90 days
 * of order_items.
 *
 * Q-OutputSize = B locked: \$limit clamped to [3, 20].
 *
 * @internal Not final to allow PHPUnit class doubles.
 */
class RecommendationsService
{
    public const MIN_LIMIT = 3;
    public const MAX_LIMIT = 20;
    public const DEFAULT_LIMIT = 10;

    /**
     * Window for the popular-products fallback query.
     */
    private const POPULAR_WINDOW_DAYS = 90;

    /**
     * OrderItem statuses excluded from popularity counting.
     * Same exclusions as revenue (X.13-B) and copurchase (X.12-C).
     */
    private const POPULAR_EXCLUDED_STATUSES = ['rejected', 'refunded'];

    /**
     * Statement timeout for popularity-fallback query. Shorter
     * than co-purchase since this is request-time.
     */
    private const STATEMENT_TIMEOUT_MS = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch recommendations for a given product, falling back to
     * popular products if no pre-computed recommendations exist.
     *
     * Returns a list of {product, score, source} envelopes ready
     * for X.12-G serializer to shape through ProductSerializer.
     *
     * @return list<array{product: Product, score: string, source: string}>
     */
    public function getRecommendationsForProduct(int $productId, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = $this->clampLimit($limit);
        $start = microtime(true);

        /** @var ProductRecommendationRepository $repo */
        $repo = $this->em->getRepository(ProductRecommendation::class);
        $recs = $repo->findTopForProduct($productId, $limit);

        if ($recs !== []) {
            $result = array_map(
                fn (ProductRecommendation $r): array => [
                    'product' => $r->getRecommendedProduct(),
                    'score' => $r->getScore(),
                    'source' => $r->getSource(),
                ],
                $recs,
            );
        } else {
            // Q-EmptyHandling = B: fall back to popular products
            $popular = $this->getPopularFallback($productId, $limit);
            $result = $popular;
        }

        $duration = (int) ((microtime(true) - $start) * 1000);
        $this->logger->debug('recommendations.product.served', [
            'product_id' => $productId,
            'limit' => $limit,
            'returned' => count($result),
            'used_fallback' => $recs === [],
            'duration_ms' => $duration,
        ]);

        return $result;
    }

    /**
     * Compute popular fallback for a product that has no pre-
     * computed recommendations.
     *
     * Algorithm: top-N most-purchased products in the last 90
     * days, excluding the source product itself, ordered by
     * purchase count DESC.
     *
     * @return list<array{product: Product, score: string, source: string}>
     */
    private function getPopularFallback(int $excludeProductId, int $limit): array
    {
        $this->setStatementTimeout();

        $excludedPlaceholders = implode(', ', array_fill(
            0,
            count(self::POPULAR_EXCLUDED_STATUSES),
            '?',
        ));

        $sql = "
            SELECT
                oi.product_id,
                SUM(oi.quantity)::int AS units_sold
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE oi.product_id != ?
              AND o.paid_at IS NOT NULL
              AND o.paid_at >= NOW() - INTERVAL '" . self::POPULAR_WINDOW_DAYS . " days'
              AND oi.item_status NOT IN ({$excludedPlaceholders})
            GROUP BY oi.product_id
            ORDER BY units_sold DESC, oi.product_id ASC
            LIMIT ?
        ";

        $params = [
            $excludeProductId,
            ...self::POPULAR_EXCLUDED_STATUSES,
            $limit,
        ];
        $types = [
            ParameterType::INTEGER,
            ...array_fill(0, count(self::POPULAR_EXCLUDED_STATUSES), ParameterType::STRING),
            ParameterType::INTEGER,
        ];

        $rows = $this->em->getConnection()
            ->executeQuery($sql, $params, $types)
            ->fetchAllAssociative();

        if ($rows === []) {
            return [];
        }

        // Hydrate Product entities for the top-N popular ids
        $productIds = array_map(static fn ($r): int => (int) $r['product_id'], $rows);
        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $products = $productRepo->findBy(['id' => $productIds]);

        // Build id → Product map for fast lookup
        $byId = [];
        foreach ($products as $p) {
            $byId[$p->getId()] = $p;
        }

        $result = [];
        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            if (!isset($byId[$pid])) {
                continue;  // product deleted between cron and read
            }
            $result[] = [
                'product' => $byId[$pid],
                'score' => $this->formatScore((string) $row['units_sold']),
                'source' => ProductRecommendation::SOURCE_FALLBACK_POPULAR,
            ];
        }

        return $result;
    }

    private function clampLimit(int $limit): int
    {
        if ($limit < self::MIN_LIMIT) {
            return self::MIN_LIMIT;
        }
        if ($limit > self::MAX_LIMIT) {
            return self::MAX_LIMIT;
        }
        return $limit;
    }

    private function formatScore(string $count): string
    {
        return bcadd($count, '0', 4);
    }

    private function setStatementTimeout(): void
    {
        try {
            $this->em->getConnection()->executeStatement(
                sprintf('SET LOCAL statement_timeout = %d', self::STATEMENT_TIMEOUT_MS),
            );
        } catch (\Throwable $e) {
            $this->logger->debug('recommendations.timeout_setup_skipped', [
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
