<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Personalization;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Raw-DBAL reader for the behavioural signals that feed a customer's style
 * profile: wishlist adds, followed vendors, purchased products, and product
 * views. Both the offline profile builder (aggregation) and the request-time
 * rails service (seed + owned-item exclusion) read through here so there is a
 * single, consistent definition of each signal.
 *
 * Every read is defensive: any DB problem degrades to an empty result rather
 * than erroring, so the personalisation layer never breaks a page or a cron.
 *
 * Non-final so the builder/rails tests can mock the signal reads.
 */
class CustomerStyleSignals
{
    /** Order-item statuses that don't count as a genuine purchase signal. */
    private const EXCLUDED_ITEM_STATUSES = ['rejected', 'refunded'];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * User ids that have ANY personalisation signal (wishlist / follow / paid
     * order / product view), ordered + paged for the batch builder.
     *
     * @return list<int>
     */
    public function candidateUserIds(int $limit, int $offset): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);
        try {
            $rows = $this->connection->fetchFirstColumn(
                "SELECT user_id FROM (
                    SELECT user_id FROM wishlist
                    UNION
                    SELECT user_id FROM vendor_follow
                    UNION
                    SELECT user_id FROM orders WHERE paid_at IS NOT NULL AND user_id IS NOT NULL
                    UNION
                    SELECT user_id FROM ai_events WHERE event = 'product_viewed' AND user_id IS NOT NULL
                 ) s
                 ORDER BY user_id
                 LIMIT :lim OFFSET :off",
                ['lim' => $limit, 'off' => $offset],
                ['lim' => ParameterType::INTEGER, 'off' => ParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return [];
        }
        return array_map('intval', $rows);
    }

    /**
     * Product ids the user has wishlisted, most-recent first.
     *
     * @return list<int>
     */
    public function wishlistProductIds(int $userId, int $limit = 200): array
    {
        return $this->productIdColumn(
            'SELECT product_id FROM wishlist WHERE user_id = :uid ORDER BY created_at DESC LIMIT :lim',
            $userId,
            $limit,
        );
    }

    /**
     * Distinct product ids from the user's PAID orders (genuine purchases),
     * most-recent first. Rejected/refunded line items are excluded.
     *
     * @return list<int>
     */
    public function purchasedProductIds(int $userId, int $limit = 200): array
    {
        $placeholders = implode(', ', array_fill(0, count(self::EXCLUDED_ITEM_STATUSES), '?'));
        $sql = "SELECT oi.product_id
                FROM order_items oi
                INNER JOIN orders o ON o.id = oi.order_id
                WHERE o.user_id = ?
                  AND o.paid_at IS NOT NULL
                  AND oi.item_status NOT IN ({$placeholders})
                GROUP BY oi.product_id
                ORDER BY MAX(oi.id) DESC
                LIMIT ?";
        $params = [$userId, ...self::EXCLUDED_ITEM_STATUSES, $limit];
        $types = [
            ParameterType::INTEGER,
            ...array_fill(0, count(self::EXCLUDED_ITEM_STATUSES), ParameterType::STRING),
            ParameterType::INTEGER,
        ];
        try {
            $rows = $this->connection->fetchFirstColumn($sql, $params, $types);
        } catch (\Throwable) {
            return [];
        }
        return array_map('intval', $rows);
    }

    /**
     * Distinct product ids the user viewed recently (the `product_viewed`
     * client signal), most-recent first.
     *
     * @return list<int>
     */
    public function viewedProductIds(int $userId, int $days = 90, int $limit = 100): array
    {
        $days = max(1, $days);
        $sql = "SELECT product_id
                FROM ai_events
                WHERE user_id = :uid
                  AND event = 'product_viewed'
                  AND product_id IS NOT NULL
                  AND created_at >= NOW() - (:days || ' days')::interval
                GROUP BY product_id
                ORDER BY MAX(created_at) DESC
                LIMIT :lim";
        try {
            $rows = $this->connection->fetchFirstColumn(
                $sql,
                ['uid' => $userId, 'days' => $days, 'lim' => max(1, $limit)],
                ['uid' => ParameterType::INTEGER, 'days' => ParameterType::INTEGER, 'lim' => ParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return [];
        }
        return array_map('intval', $rows);
    }

    /**
     * Vendor ids the user follows, most-recent first.
     *
     * @return list<int>
     */
    public function followedVendorIds(int $userId, int $limit = 50): array
    {
        try {
            $rows = $this->connection->fetchFirstColumn(
                'SELECT vendor_id FROM vendor_follow WHERE user_id = :uid ORDER BY created_at DESC LIMIT :lim',
                ['uid' => $userId, 'lim' => max(1, $limit)],
                ['uid' => ParameterType::INTEGER, 'lim' => ParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return [];
        }
        return array_map('intval', $rows);
    }

    /**
     * @return list<int>
     */
    private function productIdColumn(string $sql, int $userId, int $limit): array
    {
        try {
            $rows = $this->connection->fetchFirstColumn(
                $sql,
                ['uid' => $userId, 'lim' => max(1, $limit)],
                ['uid' => ParameterType::INTEGER, 'lim' => ParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return [];
        }
        return array_map('intval', $rows);
    }
}
