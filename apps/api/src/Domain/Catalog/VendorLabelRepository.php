<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;

/**
 * Repository for VendorLabel.
 *
 * Mirrors VendorRepository's structure — extends EntityRepository
 * directly rather than ServiceEntityRepository because the rest of
 * the Catalog domain uses the simple base.
 *
 * @extends EntityRepository<VendorLabel>
 */
class VendorLabelRepository extends EntityRepository
{
    /**
     * Find an active label by its v3 slug, scoped to a vendor.
     *
     * Slug is unique per-vendor; the same slug can exist for
     * different vendors. Returns null when no match (caller surfaces
     * as 404).
     */
    public function findActiveBySlug(Vendor $vendor, string $slug): ?VendorLabel
    {
        return $this->findOneBy([
            'vendor' => $vendor,
            'slug' => $slug,
            'isActive' => true,
        ]);
    }

    /**
     * Look up a label by its legacy WordPress/CodeIgniter id.
     *
     * Why this exists
     * ===============
     * Same rationale as VendorRepository::findByLegacyId (added in
     * M3.1.5a) — mobile call sites send legacy integer ids; v3 needs
     * to resolve them. Retires once mobile rebuilds against slug
     * semantics (M3.1.10+).
     *
     * Returns null if no label has that legacy id. Active filter is
     * applied — inactive labels are 404 from this lookup.
     */
    public function findActiveByLegacyId(int $legacyId): ?VendorLabel
    {
        return $this->findOneBy([
            'legacyLabelId' => $legacyId,
            'isActive' => true,
        ]);
    }

    /**
     * List all active labels for a vendor, ordered by displayOrder
     * (NULLS LAST) then name.
     *
     * Used by GET /v3/vendors/{slug}/labels and the by-legacy-id
     * variant. The NULLS LAST ordering keeps explicitly-ordered
     * labels first; unordered ones get alphabetical fallback.
     *
     * @return list<VendorLabel>
     */
    public function listActiveByVendor(Vendor $vendor): array
    {
        $qb = $this->createQueryBuilder('l')
            ->where('l.vendor = :vendor')
            ->andWhere('l.isActive = true')
            ->setParameter('vendor', $vendor)
            // Postgres NULLS LAST via ORDER BY ... NULLS LAST in DQL
            // isn't directly supported pre-DQL 2.14; use the
            // expression-based fallback. CASE returns 1 for NULL, 0
            // otherwise, so non-NULL rows sort first.
            ->addOrderBy('CASE WHEN l.displayOrder IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('l.displayOrder', 'ASC')
            ->addOrderBy('l.name', 'ASC');

        /** @var list<VendorLabel> $results */
        $results = $qb->getQuery()->getResult();
        return $results;
    }

    public function save(VendorLabel $label): void
    {
        $em = $this->getEntityManager();
        $em->persist($label);
        $em->flush();
    }

}
