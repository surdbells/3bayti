<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<NotificationBroadcastRecipient>
 */
class NotificationBroadcastRecipientRepository extends EntityRepository
{
    /**
     * Paginated recipient drill-down for one broadcast. Filter by status /
     * platform / token-suffix search.
     *
     * @param array{status?: ?string, platform?: ?string, search?: ?string, limit?: int, offset?: int} $filters
     * @return array{items: list<NotificationBroadcastRecipient>, total: int}
     */
    public function findForBroadcastPaginated(int $broadcastId, array $filters): array
    {
        $limit  = max(1, min(100, (int) ($filters['limit'] ?? 25)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $qb = $this->createQueryBuilder('r')
            ->where('r.broadcastId = :bid')
            ->setParameter('bid', $broadcastId);

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        $platform = $filters['platform'] ?? null;
        if (is_string($platform) && $platform !== '') {
            $qb->andWhere('r.platform = :platform')->setParameter('platform', $platform);
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $qb->andWhere('(LOWER(r.tokenSuffix) LIKE :q OR CAST(r.userId AS string) LIKE :q)')
               ->setParameter('q', '%' . strtolower(trim($search)) . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(r.id)')->getQuery()->getSingleScalarResult();

        /** @var list<NotificationBroadcastRecipient> $items */
        $items = $qb->orderBy('r.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * One keyset batch of RESEND targets for a source broadcast: the still-
     * active device tokens referenced by that broadcast's recipients
     * (optionally only the failed ones). Joined to device_tokens so a token
     * that has since gone inactive/dead is skipped. Keyed by device_token id.
     *
     * @return list<array{id:int, token:string, platform:string, user_id:int, first_name:?string, last_name:?string, email:?string}>
     */
    public function findResendTargetsBatch(
        int $sourceBroadcastId,
        bool $onlyFailed,
        int $afterId,
        int $limit,
    ): array {
        $conn = $this->getEntityManager()->getConnection();
        $statusClause = $onlyFailed ? "AND r.status = 'failed'" : '';
        $sql = "
            SELECT DISTINCT dt.id AS id, dt.token AS token, dt.platform AS platform, dt.user_id AS user_id,
                   u.first_name AS first_name, u.last_name AS last_name, u.email AS email
            FROM notification_broadcast_recipients r
            JOIN device_tokens dt ON dt.id = r.device_token_id AND dt.is_active = true
            LEFT JOIN users u ON u.id = dt.user_id
            WHERE r.broadcast_id = :src {$statusClause}
              AND dt.id > :afterId
            ORDER BY dt.id ASC
            LIMIT :lim
        ";
        $rows = $conn->fetchAllAssociative($sql, [
            'src' => $sourceBroadcastId,
            'afterId' => $afterId,
            'lim' => max(1, $limit),
        ]);

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'token' => (string) $r['token'],
            'platform' => (string) $r['platform'],
            'user_id' => (int) $r['user_id'],
            'first_name' => $r['first_name'] !== null ? (string) $r['first_name'] : null,
            'last_name' => $r['last_name'] !== null ? (string) $r['last_name'] : null,
            'email' => $r['email'] !== null ? (string) $r['email'] : null,
        ], $rows);
    }

    /**
     * Platform-split count of resend targets (active tokens) for a source
     * broadcast — used to seed the new broadcast's totals.
     *
     * @return array{total:int, android:int, ios:int}
     */
    public function countResendTargetsByPlatform(int $sourceBroadcastId, bool $onlyFailed): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $statusClause = $onlyFailed ? "AND r.status = 'failed'" : '';
        $sql = "
            SELECT dt.platform AS platform, COUNT(DISTINCT dt.id) AS cnt
            FROM notification_broadcast_recipients r
            JOIN device_tokens dt ON dt.id = r.device_token_id AND dt.is_active = true
            WHERE r.broadcast_id = :src {$statusClause}
            GROUP BY dt.platform
        ";
        $rows = $conn->fetchAllAssociative($sql, ['src' => $sourceBroadcastId]);

        $android = 0;
        $ios = 0;
        foreach ($rows as $row) {
            if ($row['platform'] === DeviceToken::PLATFORM_IOS) {
                $ios = (int) $row['cnt'];
            } elseif ($row['platform'] === DeviceToken::PLATFORM_ANDROID) {
                $android = (int) $row['cnt'];
            }
        }
        return ['total' => $android + $ios, 'android' => $android, 'ios' => $ios];
    }
}
