<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Audit;

use Doctrine\ORM\EntityRepository;

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
}
