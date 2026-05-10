<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Vendor>
 */
class VendorRepository extends EntityRepository
{
    public function save(Vendor $vendor): void
    {
        $em = $this->getEntityManager();
        $em->persist($vendor);
        $em->flush();
    }

    public function findBySlug(string $slug): ?Vendor
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Slug is taken if a vendor (including soft-deleted) holds it.
     * Soft-deleted vendors still own their slug so we don't recycle
     * URLs unpredictably.
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.slug = :slug')
            ->setParameter('slug', $slug);

        if ($excludeId !== null) {
            $qb->andWhere('v.id != :id')->setParameter('id', $excludeId);
        }

        return ((int) $qb->getQuery()->getSingleScalarResult()) > 0;
    }

    /**
     * Active vendors, ordered by name.
     * Public-facing list endpoint backs onto this.
     *
     * @return Vendor[]
     */
    public function findActive(int $limit = 100, int $offset = 0): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.isActive = true')
            ->orderBy('v.name', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * All vendors (active or not), for admin views.
     *
     * @return Vendor[]
     */
    public function findAll(): array
    {
        return $this->createQueryBuilder('v')
            ->orderBy('v.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}
