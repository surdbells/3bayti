<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Following;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;

/**
 * Raw-DBAL eligibility finder for the store-follower new-product alert cron.
 *
 * Selects products that were newly published (published_at set) and have not
 * yet had their followers alerted (followers_notified_at null), whose store is
 * still live (active + approved). Ordered oldest-published first so a
 * back-pressured queue drains fairly.
 *
 * The migration's backfill stamps every pre-existing active product as
 * already-notified, so this only ever returns products published AFTER the
 * feature shipped — the first cron run never fans out for the legacy
 * catalogue. Idempotency + bounding live in the cron (it marks each product
 * followers_notified_at once done, and processes at most $batchSize per run).
 */
class FollowerProductAlertFinder
{
    public const DEFAULT_BATCH_SIZE = 200;
    public const MAX_BATCH_SIZE = 500;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Products due for a follower alert, each with its vendor id.
     *
     * @return list<array{product_id: int, vendor_id: int}>
     */
    public function findDue(int $batchSize = self::DEFAULT_BATCH_SIZE): array
    {
        $batch = max(1, min(self::MAX_BATCH_SIZE, $batchSize));

        try {
            $rows = $this->connection->fetchAllAssociative(
                "SELECT p.id AS product_id, p.vendor_id AS vendor_id
                 FROM products p
                 JOIN vendors v ON v.id = p.vendor_id
                 WHERE p.published_at IS NOT NULL
                   AND p.followers_notified_at IS NULL
                   AND p.status = 'active'
                   AND v.is_active = TRUE
                   AND v.status = 'approved'
                 ORDER BY p.published_at ASC, p.id ASC
                 LIMIT :batch",
                ['batch' => $batch],
                ['batch' => ParameterType::INTEGER],
            );
        } catch (\Throwable) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'product_id' => (int) $row['product_id'],
                'vendor_id' => (int) $row['vendor_id'],
            ];
        }

        return $out;
    }
}
