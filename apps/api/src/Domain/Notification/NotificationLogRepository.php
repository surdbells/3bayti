<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use DateTimeImmutable;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for NotificationLog entities.
 *
 * @extends EntityRepository<NotificationLog>
 */
class NotificationLogRepository extends EntityRepository
{
    /**
     * Persist a notification_log row. Fire-and-forget — callers don't
     * have to know about EntityManager mechanics.
     *
     * Why flush() per call (not batched with the request's UoW)
     * ----------------------------------------------------------
     * Same rationale as AuditLogRepository::save(). Notification log
     * rows must land independently of the parent transaction. If the
     * mailer succeeds but a later step in the controller throws and
     * rolls back the request, the notification audit trail still
     * shows the email was sent — we DID send it, regardless of what
     * the rollback does to other state.
     *
     * Failures here are SWALLOWED by the caller (OrderNotificationService::
     * safeSend wraps this in try/catch and continues on save failure).
     * The notification log is a secondary concern; persisting must
     * never block the primary action (the email send + the controller
     * response).
     */
    public function save(NotificationLog $log): void
    {
        $em = $this->getEntityManager();
        $em->persist($log);
        $em->flush();
    }

    /**
     * Filter + paginate notification logs for the admin endpoint.
     *
     * @param array{
     *   orderId?: int|null,
     *   template?: string|null,
     *   status?: string|null,
     *   recipient?: string|null,
     *   errorKind?: string|null,
     *   since?: DateTimeImmutable|null,
     *   until?: DateTimeImmutable|null,
     *   limit?: int,
     *   offset?: int,
     * } $filters
     *
     * @return array{items: list<NotificationLog>, total: int}
     */
    public function findFilteredPaginated(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('nl');

        if (!empty($filters['orderId'])) {
            $qb->andWhere('nl.orderId = :orderId')
               ->setParameter('orderId', $filters['orderId']);
        }
        if (!empty($filters['template'])) {
            $qb->andWhere('nl.template = :template')
               ->setParameter('template', $filters['template']);
        }
        if (!empty($filters['status'])) {
            $qb->andWhere('nl.status = :status')
               ->setParameter('status', $filters['status']);
        }
        if (!empty($filters['recipient'])) {
            $qb->andWhere('nl.recipient = :recipient')
               ->setParameter('recipient', $filters['recipient']);
        }
        if (!empty($filters['errorKind'])) {
            $qb->andWhere('nl.errorKind = :errorKind')
               ->setParameter('errorKind', $filters['errorKind']);
        }
        if (!empty($filters['since'])) {
            $qb->andWhere('nl.sentAt >= :since')
               ->setParameter('since', $filters['since']);
        }
        if (!empty($filters['until'])) {
            $qb->andWhere('nl.sentAt <= :until')
               ->setParameter('until', $filters['until']);
        }

        // Total count BEFORE applying limit/offset.
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(nl.id)')
            ->getQuery()
            ->getSingleScalarResult();

        // Most recent first — matches the typical triage flow
        // ("what just happened with order X").
        $qb->orderBy('nl.sentAt', 'DESC')
           ->addOrderBy('nl.id', 'DESC');

        $qb->setMaxResults($filters['limit'] ?? 20)
           ->setFirstResult($filters['offset'] ?? 0);

        /** @var list<NotificationLog> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
