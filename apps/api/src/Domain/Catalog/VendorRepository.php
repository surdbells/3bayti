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
     * Returns the Vendor entity ids owned by the given User (the
     * User must have is_vendor=true). Used by M3.1.7-C vendor
     * controllers to scope queries to the requesting user's stores.
     *
     * A vendor user MAY own multiple Vendor entities (e.g. running
     * multiple storefronts under one operator). We return all ids
     * and let the caller filter.
     *
     * @return list<int>
     */
    public function findIdsByOwnerUser(\Bayti\Api\Domain\User\User $user): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('v.id')
            ->where('v.ownerUser = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getScalarResult();

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Look up a vendor by its legacy WordPress/CodeIgniter id.
     *
     * Why this exists
     * ===============
     * v3 uses slugs as the public identifier for vendors. The mobile
     * app (M3.1.5 era) was built against the legacy backend, which
     * identified vendors by integer ids (the original DB primary key
     * from the legacy WP/CI schema). The migration script preserved
     * those ids in the `legacy_vendor_id` column on the vendors table.
     *
     * This method serves the M3.1.5 mobile flip — mobile sends
     * `store_id: 42` (a legacy id) and we resolve it to the v3 vendor.
     * Once mobile is rebuilt against v3 slug semantics (M3.1.10+), this
     * method can be removed and the by-legacy-id controllers retired.
     *
     * Returns null if no vendor has that legacy id. Inactive vendors
     * are returned by this method — the controller layer decides
     * whether to expose them.
     */
    public function findByLegacyId(int $legacyId): ?Vendor
    {
        return $this->findOneBy(['legacyVendorId' => $legacyId]);
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
