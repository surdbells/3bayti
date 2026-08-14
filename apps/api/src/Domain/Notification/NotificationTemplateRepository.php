<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<NotificationTemplate>
 */
class NotificationTemplateRepository extends EntityRepository
{
    /**
     * Paginated template list. Optional status filter + name/title search.
     *
     * @param array{status?: ?string, search?: ?string, limit?: int, offset?: int} $filters
     * @return array{items: list<NotificationTemplate>, total: int}
     */
    public function findForList(array $filters): array
    {
        $limit  = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $qb = $this->createQueryBuilder('t');

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $qb->andWhere('t.status = :status')->setParameter('status', $status);
        }

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $qb->andWhere('(LOWER(t.name) LIKE :q OR LOWER(t.title) LIKE :q)')
               ->setParameter('q', '%' . strtolower(trim($search)) . '%');
        }

        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(t.id)')->getQuery()->getSingleScalarResult();

        /** @var list<NotificationTemplate> $items */
        $items = $qb->orderBy('t.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Active templates for the compose picker (newest first, capped).
     *
     * @return list<NotificationTemplate>
     */
    public function findActiveForPicker(int $limit = 200): array
    {
        /** @var list<NotificationTemplate> $rows */
        $rows = $this->createQueryBuilder('t')
            ->where('t.status = :active')
            ->setParameter('active', NotificationTemplate::STATUS_ACTIVE)
            ->orderBy('t.updatedAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
        return $rows;
    }
}
