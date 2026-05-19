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
     * Window for the personalized "for-you" path: scan the user's
     * paid orders from this far back to find their most-purchased
     * category.
     */
    private const USER_HISTORY_WINDOW_DAYS = 180;

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

    /**
     * Fetch personalized recommendations for an authenticated user
     * (M3.2.X.12-G "for-you" endpoint).
     *
     * Q-PersonalizedScope = C locked: authenticated users get
     * personalized via category affinity from their past orders;
     * the controller short-circuits to popular fallback for
     * anonymous callers (no session_id surface in v1).
     *
     * Algorithm:
     *   1. Find the user's most-purchased category over the last
     *      USER_HISTORY_WINDOW_DAYS days (status-exclusions same as
     *      revenue + co-purchase)
     *   2. If found → return top products in that category that
     *      the user hasn't already bought, ordered by popularity
     *   3. If not found (no order history) → popular fallback
     *
     * Returns the same envelope shape as
     * getRecommendationsForProduct so the X.12-G serializer
     * handles both paths uniformly.
     *
     * @return list<array{product: Product, score: string, source: string}>
     */
    public function getRecommendationsForUser(int $userId, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = $this->clampLimit($limit);
        $start = microtime(true);
        $this->setStatementTimeout();

        // 1. Find the user's most-purchased category
        $topCategoryId = $this->findUserTopCategory($userId);

        if ($topCategoryId === null) {
            // No order history → popular fallback (no source product
            // to exclude; pass 0 which never matches a real product id)
            $result = $this->getPopularFallback(0, $limit);
            $this->logger->debug('recommendations.user.served', [
                'user_id' => $userId,
                'limit' => $limit,
                'returned' => count($result),
                'path' => 'popular_fallback_no_history',
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);
            return $result;
        }

        $result = $this->getProductsInCategoryUserHasntBought(
            $topCategoryId,
            $userId,
            $limit,
        );

        if ($result === []) {
            // User has bought everything in their top category → fall
            // back to popular products (still useful — broaden scope)
            $result = $this->getPopularFallback(0, $limit);
            $this->logger->debug('recommendations.user.served', [
                'user_id' => $userId,
                'limit' => $limit,
                'returned' => count($result),
                'path' => 'popular_fallback_exhausted_category',
                'category_id' => $topCategoryId,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
            ]);
            return $result;
        }

        $this->logger->debug('recommendations.user.served', [
            'user_id' => $userId,
            'limit' => $limit,
            'returned' => count($result),
            'path' => 'category_affinity',
            'category_id' => $topCategoryId,
            'duration_ms' => (int) ((microtime(true) - $start) * 1000),
        ]);
        return $result;
    }

    /**
     * Find a user's most-purchased category over the recent window.
     */
    private function findUserTopCategory(int $userId): ?int
    {
        $excludedPlaceholders = implode(', ', array_fill(
            0,
            count(self::POPULAR_EXCLUDED_STATUSES),
            '?',
        ));

        $sql = "
            SELECT
                p.category_id,
                SUM(oi.quantity)::int AS units
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            INNER JOIN products p ON p.id = oi.product_id
            WHERE o.user_id = ?
              AND o.paid_at IS NOT NULL
              AND o.paid_at >= NOW() - INTERVAL '" . self::USER_HISTORY_WINDOW_DAYS . " days'
              AND oi.item_status NOT IN ({$excludedPlaceholders})
              AND p.category_id IS NOT NULL
            GROUP BY p.category_id
            ORDER BY units DESC, p.category_id ASC
            LIMIT 1
        ";

        $params = [$userId, ...self::POPULAR_EXCLUDED_STATUSES];
        $types = [
            ParameterType::INTEGER,
            ...array_fill(0, count(self::POPULAR_EXCLUDED_STATUSES), ParameterType::STRING),
        ];

        $row = $this->em->getConnection()
            ->executeQuery($sql, $params, $types)
            ->fetchAssociative();

        if ($row === false) {
            return null;
        }
        return (int) $row['category_id'];
    }

    /**
     * Fetch the most popular products in a category that the user
     * has NOT already bought.
     *
     * @return list<array{product: Product, score: string, source: string}>
     */
    private function getProductsInCategoryUserHasntBought(
        int $categoryId,
        int $userId,
        int $limit,
    ): array {
        $excludedPlaceholders = implode(', ', array_fill(
            0,
            count(self::POPULAR_EXCLUDED_STATUSES),
            '?',
        ));

        // Get popular products in the category, anti-joining against
        // the user's purchase history so the user only sees products
        // they haven't already bought.
        $sql = "
            SELECT
                p.id AS product_id,
                COALESCE(SUM(oi.quantity), 0)::int AS units_sold
            FROM products p
            LEFT JOIN order_items oi ON oi.product_id = p.id
            LEFT JOIN orders o ON o.id = oi.order_id
                AND o.paid_at IS NOT NULL
                AND o.paid_at >= NOW() - INTERVAL '" . self::POPULAR_WINDOW_DAYS . " days'
                AND oi.item_status NOT IN ({$excludedPlaceholders})
            WHERE p.category_id = ?
              AND p.is_active = true
              AND NOT EXISTS (
                  SELECT 1
                  FROM order_items uoi
                  INNER JOIN orders uo ON uo.id = uoi.order_id
                  WHERE uoi.product_id = p.id
                    AND uo.user_id = ?
                    AND uo.paid_at IS NOT NULL
              )
            GROUP BY p.id
            ORDER BY units_sold DESC, p.id ASC
            LIMIT ?
        ";

        $params = [
            ...self::POPULAR_EXCLUDED_STATUSES,
            $categoryId,
            $userId,
            $limit,
        ];
        $types = [
            ...array_fill(0, count(self::POPULAR_EXCLUDED_STATUSES), ParameterType::STRING),
            ParameterType::INTEGER,
            ParameterType::INTEGER,
            ParameterType::INTEGER,
        ];

        $rows = $this->em->getConnection()
            ->executeQuery($sql, $params, $types)
            ->fetchAllAssociative();

        if ($rows === []) {
            return [];
        }

        $productIds = array_map(static fn ($r): int => (int) $r['product_id'], $rows);
        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $products = $productRepo->findBy(['id' => $productIds]);

        $byId = [];
        foreach ($products as $p) {
            $byId[$p->getId()] = $p;
        }

        $result = [];
        foreach ($rows as $row) {
            $pid = (int) $row['product_id'];
            if (!isset($byId[$pid])) {
                continue;
            }
            $result[] = [
                'product' => $byId[$pid],
                'score' => $this->formatScore((string) $row['units_sold']),
                'source' => ProductRecommendation::SOURCE_CATEGORY,
            ];
        }

        return $result;
    }


    /**
     * Fetch ALL recommendations for a product including rank, for
     * the X.12-G admin "explain" endpoint. Unlike the hot read
     * path, this returns rows from every source (copurchase +
     * category + fallback_popular if any) so admins can see the
     * full breakdown.
     *
     * @return list<array{product: Product, score: string, source: string, rank: int}>
     */
    public function getExplainForProduct(int $productId): array
    {
        /** @var ProductRecommendationRepository $repo */
        $repo = $this->em->getRepository(ProductRecommendation::class);
        $recs = $repo->findAllForProduct($productId);

        return array_map(
            fn (ProductRecommendation $r): array => [
                'product' => $r->getRecommendedProduct(),
                'score' => $r->getScore(),
                'source' => $r->getSource(),
                'rank' => $r->getRank(),
            ],
            $recs,
        );
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
