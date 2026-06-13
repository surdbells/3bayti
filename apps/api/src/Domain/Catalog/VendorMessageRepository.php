<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<VendorMessage>
 */
class VendorMessageRepository extends EntityRepository
{
    /**
     * A vendor's inbox, newest first.
     *
     * @return array{items: list<VendorMessage>, total: int}
     */
    public function findForVendorPaginated(int $vendorId, int $limit, int $offset): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.vendor = :vid')
            ->setParameter('vid', $vendorId);

        $total = (int) (clone $qb)->select('COUNT(m.id)')->getQuery()->getSingleScalarResult();

        $items = $qb->orderBy('m.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function findOneForVendor(int $id, int $vendorId): ?VendorMessage
    {
        return $this->createQueryBuilder('m')
            ->where('m.id = :id')->andWhere('m.vendor = :vid')
            ->setParameter('id', $id)->setParameter('vid', $vendorId)
            ->getQuery()->getOneOrNullResult();
    }

    public function countUnreadForVendor(int $vendorId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.vendor = :vid')->andWhere('m.isRead = false')
            ->setParameter('vid', $vendorId)
            ->getQuery()->getSingleScalarResult();
    }
}
