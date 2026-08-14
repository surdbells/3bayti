<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use DateTimeImmutable;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<NotificationSchedule>
 */
class NotificationScheduleRepository extends EntityRepository
{
    /**
     * Id of the next due schedule, locked FOR UPDATE SKIP LOCKED so
     * overlapping dispatcher runs never claim the same one. The caller runs
     * inside a transaction and advances the schedule before committing, which
     * releases the lock and moves next_run_at forward. Returns null when
     * nothing is due.
     */
    public function claimDueId(DateTimeImmutable $now): ?int
    {
        $sql = <<<'SQL'
            SELECT id FROM notification_schedules
            WHERE status = 'scheduled'
              AND next_run_at IS NOT NULL
              AND next_run_at <= :now
            ORDER BY next_run_at ASC
            FOR UPDATE SKIP LOCKED
            LIMIT 1
        SQL;
        $id = $this->getEntityManager()->getConnection()->fetchOne($sql, [
            'now' => $now->format('Y-m-d H:i:sP'),
        ]);
        return $id === false || $id === null ? null : (int) $id;
    }

    /**
     * Paginated schedule list. Optional status filter + name/title search.
     *
     * @param array{status?: ?string, search?: ?string, limit?: int, offset?: int} $filters
     * @return array{items: list<NotificationSchedule>, total: int}
     */
    public function findForList(array $filters): array
    {
        $limit  = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $qb = $this->createQueryBuilder('s');

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $qb->andWhere('s.status = :status')->setParameter('status', $status);
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $qb->andWhere('(LOWER(s.name) LIKE :q OR LOWER(s.title) LIKE :q)')
               ->setParameter('q', '%' . strtolower(trim($search)) . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(s.id)')->getQuery()->getSingleScalarResult();

        /** @var list<NotificationSchedule> $items */
        $items = $qb
            // Live schedules first (by next run), then the rest by recency.
            ->orderBy('s.nextRunAt', 'ASC')
            ->addOrderBy('s.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
