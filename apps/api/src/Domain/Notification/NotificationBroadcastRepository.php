<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<NotificationBroadcast>
 */
class NotificationBroadcastRepository extends EntityRepository
{
    /**
     * Atomically claim the next queued broadcast for the dispatcher and
     * flip it to 'processing' in the SAME statement, so two overlapping
     * cron runs can never grab the same row (FOR UPDATE SKIP LOCKED).
     * Returns the claimed id, or null when the queue is empty.
     */
    public function claimNextQueuedId(): ?int
    {
        $sql = <<<'SQL'
            UPDATE notification_broadcasts
            SET status = 'processing', started_at = now(), updated_at = now()
            WHERE id = (
                SELECT id FROM notification_broadcasts
                WHERE status = 'queued'
                ORDER BY id ASC
                FOR UPDATE SKIP LOCKED
                LIMIT 1
            )
            RETURNING id
        SQL;

        $id = $this->getEntityManager()->getConnection()->fetchOne($sql);
        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * Paginated broadcast history, newest first. Optional status filter +
     * title search. Counters live on the row, so the list never touches the
     * recipients table.
     *
     * @param array{status?: ?string, search?: ?string, schedule_id?: ?int, limit?: int, offset?: int} $filters
     * @return array{items: list<NotificationBroadcast>, total: int}
     */
    public function findForHistory(array $filters): array
    {
        $limit  = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $qb = $this->createQueryBuilder('b');

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $qb->andWhere('b.status = :status')->setParameter('status', $status);
        }

        // Occurrences of one schedule (recurring-notification history).
        $scheduleId = $filters['schedule_id'] ?? null;
        if ($scheduleId !== null && (int) $scheduleId > 0) {
            $qb->andWhere('b.scheduleId = :sid')->setParameter('sid', (int) $scheduleId);
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $qb->andWhere('LOWER(b.title) LIKE :q')
               ->setParameter('q', '%' . strtolower(trim($search)) . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(b.id)')->getQuery()->getSingleScalarResult();

        /** @var list<NotificationBroadcast> $items */
        $items = $qb->orderBy('b.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
