<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<VendorSizeChart>
 */
class VendorSizeChartRepository extends EntityRepository
{
    /**
     * A vendor's full size chart, ordered by id (insertion order).
     *
     * @return list<VendorSizeChart>
     */
    public function findForVendor(int $vendorId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.vendor = :vid')
            ->setParameter('vid', $vendorId)
            ->orderBy('c.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneForVendor(int $id, int $vendorId): ?VendorSizeChart
    {
        return $this->createQueryBuilder('c')
            ->where('c.id = :id')->andWhere('c.vendor = :vid')
            ->setParameter('id', $id)->setParameter('vid', $vendorId)
            ->getQuery()->getOneOrNullResult();
    }

    public function sizeExistsForVendor(int $vendorId, string $size, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.vendor = :vid')->andWhere('LOWER(c.size) = LOWER(:size)')
            ->setParameter('vid', $vendorId)->setParameter('size', $size);
        if ($excludeId !== null) {
            $qb->andWhere('c.id <> :ex')->setParameter('ex', $excludeId);
        }
        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }
}
