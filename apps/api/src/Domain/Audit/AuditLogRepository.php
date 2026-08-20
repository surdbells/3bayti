<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Audit;

use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\QueryBuilder;

/**
 * Repository for AuditLog entities.
 *
 * @extends EntityRepository<AuditLog>
 */
class AuditLogRepository extends EntityRepository
{
    /**
     * Persist an audit row. Wrapper around persist+flush so callers
     * don't have to know about EntityManager mechanics.
     *
     * Why flush() per call instead of letting it batch
     * -------------------------------------------------
     * Audit rows must land even if the parent transaction rolls back
     * later. Imagine a controller does:
     *
     *   $em->beginTransaction();
     *   $address->update(...);
     *   $audit->record(...);  // this row should persist
     *   throw new SomeException;  // rolls back the address change
     *
     * If we batch the audit insert with the address update via the
     * Doctrine UoW, both get rolled back. By flushing the audit row
     * synchronously we still get the rollback for the entity change,
     * but the audit "we tried to update X and it failed" survives.
     *
     * For the typical happy path, this is one extra round-trip per
     * audited action — acceptable. Audited actions are user-initiated
     * mutations, not hot-path reads.
     *
     * Caveat: doesn't fully isolate from outer transaction. If the
     * controller's outer transaction rolls back, the audit insert
     * also rolls back (it shares the connection). True isolation
     * would need a separate connection or savepoints. M3+ if needed.
     */
    public function save(AuditLog $log): void
    {
        $em = $this->getEntityManager();
        $em->persist($log);
        $em->flush($log);
    }

    /**
     * @return AuditLog[]
     */
    public function findForSubject(string $subjectType, int $subjectId, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.subjectType = :type')
            ->andWhere('a.subjectId = :id')
            ->setParameter('type', $subjectType)
            ->setParameter('id', $subjectId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return AuditLog[]
     */
    public function findRecentForUser(int $userId, int $limit = 50): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.userId = :user')
            ->setParameter('user', $userId)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Paginated forensic listing, newest first, with optional filters. Powers
     * the admin audit-log surface (ListAuditLogsController).
     *
     * `$filters` keys (all optional): action (string), subjectTypes (list of
     * strings → IN), userId (int), subjectId (int), dateFrom / dateTo (ISO
     * YYYY-MM-DD, inclusive UTC), search (matched against subject type / IP /
     * request id, and — when numeric — subject id / user id).
     *
     * @param array<string, mixed> $filters
     * @return array{0: AuditLog[], 1: int} [rows, totalMatching]
     */
    public function paginated(int $limit, int $offset, array $filters = []): array
    {
        $qb = $this->createQueryBuilder('a');
        $this->applyFilters($qb, $filters);

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(a.id)')->getQuery()->getSingleScalarResult();

        $rows = $qb
            ->orderBy('a.createdAt', 'DESC')
            ->addOrderBy('a.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return [$rows, $total];
    }

    /**
     * Count matching rows grouped by action — for the summary stat bar. The
     * `action` filter is intentionally ignored so the breakdown stays complete
     * (picking an action doesn't collapse the distribution to a single bar).
     *
     * @param array<string, mixed> $filters
     * @return array<string, int> action => count
     */
    public function actionCounts(array $filters = []): array
    {
        $forCounts = $filters;
        unset($forCounts['action']);

        $qb = $this->createQueryBuilder('a')
            ->select('a.action AS action, COUNT(a.id) AS cnt')
            ->groupBy('a.action');
        $this->applyFilters($qb, $forCounts);

        $out = [];
        foreach ($qb->getQuery()->getArrayResult() as $row) {
            $out[(string) $row['action']] = (int) $row['cnt'];
        }
        return $out;
    }

    /**
     * Distinct subject types present in the log — powers the subject-type
     * filter's option list. Unfiltered so the dropdown is stable.
     *
     * @return list<string>
     */
    public function distinctSubjectTypes(): array
    {
        $rows = $this->createQueryBuilder('a')
            ->select('DISTINCT a.subjectType AS subjectType')
            ->orderBy('a.subjectType', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_values(array_map(static fn (array $r): string => (string) $r['subjectType'], $rows));
    }

    /**
     * Apply the shared filter set to a query builder (aliased `a`).
     *
     * @param array<string, mixed> $f
     */
    private function applyFilters(QueryBuilder $qb, array $f): void
    {
        if (!empty($f['action'])) {
            $qb->andWhere('a.action = :action')->setParameter('action', $f['action']);
        }
        if (!empty($f['subjectTypes'])) {
            $qb->andWhere('a.subjectType IN (:subjectTypes)')
               ->setParameter('subjectTypes', (array) $f['subjectTypes']);
        }
        if (!empty($f['userId'])) {
            $qb->andWhere('a.userId = :userId')->setParameter('userId', (int) $f['userId']);
        }
        if (!empty($f['subjectId'])) {
            $qb->andWhere('a.subjectId = :subjectId')->setParameter('subjectId', (int) $f['subjectId']);
        }
        if (!empty($f['dateFrom'])) {
            $qb->andWhere('a.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTimeImmutable($f['dateFrom'] . ' 00:00:00', new \DateTimeZone('UTC')));
        }
        if (!empty($f['dateTo'])) {
            $qb->andWhere('a.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTimeImmutable($f['dateTo'] . ' 23:59:59', new \DateTimeZone('UTC')));
        }
        if (!empty($f['search'])) {
            $search = trim((string) $f['search']);
            $conds = [
                'LOWER(a.subjectType) LIKE :search',
                'LOWER(a.ipAddress) LIKE :search',
                'LOWER(a.requestId) LIKE :search',
            ];
            if (ctype_digit($search)) {
                $conds[] = 'a.subjectId = :searchNum';
                $conds[] = 'a.userId = :searchNum';
                $qb->setParameter('searchNum', (int) $search);
            }
            $qb->andWhere('(' . implode(' OR ', $conds) . ')')
               ->setParameter('search', '%' . mb_strtolower($search) . '%');
        }
    }
}
