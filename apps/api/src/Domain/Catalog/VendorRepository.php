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
     * All Vendor entities owned by the given user, regardless of status.
     *
     * Used by:
     *   - Self-serve onboarding (M3.2.X.6-D) to check whether the user
     *     already has stores before allowing a new submission
     *   - Vendor self-serve status endpoint (M3.2.X.6-D) to surface
     *     pending vendors that the user has submitted but admin
     *     hasn't approved yet
     *
     * NOT used by VendorAuthMiddleware — that one needs only approved
     * vendors via existsApprovedForOwnerUser below.
     *
     * @return list<Vendor>
     */
    public function findByOwnerUser(\Bayti\Api\Domain\User\User $user): array
    {
        /** @var list<Vendor> $items */
        $items = $this->createQueryBuilder('v')
            ->where('v.ownerUser = :user')
            ->setParameter('user', $user)
            ->orderBy('v.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
        return $items;
    }

    /**
     * All approved Vendor entities owned by the given user.
     *
     * Used by vendor controllers (M3.1.7 lineage) to restrict the
     * orders/items view to stores in the 'approved' lifecycle state.
     * A user with mixed approved + suspended stores sees only the
     * approved ones via this method.
     *
     * Q-MiddlewareGate = A: VendorAuthMiddleware uses
     * existsApprovedForOwnerUser to gate access at the middleware
     * layer (more efficient than fetching full entities). This method
     * is for controllers that need the full Vendor list.
     *
     * @return list<Vendor>
     */
    public function findApprovedByOwnerUser(\Bayti\Api\Domain\User\User $user): array
    {
        /** @var list<Vendor> $items */
        $items = $this->createQueryBuilder('v')
            ->where('v.ownerUser = :user')
            ->andWhere('v.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', Vendor::STATUS_APPROVED)
            ->orderBy('v.name', 'ASC')
            ->getQuery()
            ->getResult();
        return $items;
    }

    /**
     * Cheap existence check — does the user own AT LEAST ONE approved
     * vendor? Used by VendorAuthMiddleware as the lifecycle gate.
     *
     * Uses COUNT-only query rather than fetching entities; the
     * composite index (status, owner_user_id) added by
     * Version20260517000001 covers this perfectly.
     */
    public function existsApprovedForOwnerUser(\Bayti\Api\Domain\User\User $user): bool
    {
        $count = (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.ownerUser = :user')
            ->andWhere('v.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', Vendor::STATUS_APPROVED)
            ->getQuery()
            ->getSingleScalarResult();
        return $count > 0;
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

    /**
     * Paginated admin-side vendor list for the M3.2.X.14-D metrics
     * endpoint. Returns vendors plus a total count, allowing the
     * controller to ship a paginated response envelope.
     *
     * Filters:
     *   - status: 'pending' | 'approved' | 'suspended' | null
     *     (null = no status filter)
     *
     * Sort axes ('name_asc' default):
     *   - 'name_asc' / 'name_desc' — alpha
     *   - 'created_at_desc' / 'created_at_asc' — by Vendor.createdAt
     *
     * NOTE: metric-based sorts (?sort=fulfillment_rate_desc) are NOT
     * handled here — the controller paginates the full vendor list,
     * computes metrics for the whole set, then sorts + slices in PHP.
     * Acceptable for current 3bayti scale (≤200 vendors); flagged as
     * an operator follow-up for cache-warming when scale demands.
     *
     * @return array{0: list<Vendor>, 1: int}
     */
    public function findPaginatedForAdmin(
        int $limit,
        int $offset,
        ?string $status = null,
        string $sort = 'name_asc',
    ): array {
        $qb = $this->createQueryBuilder('v');
        if ($status !== null) {
            $qb->andWhere('v.status = :status')->setParameter('status', $status);
        }

        // Sort routing — name + created_at only here; metric-based
        // sorts are handled in PHP by the caller (see method docblock).
        switch ($sort) {
            case 'name_desc':
                $qb->orderBy('v.name', 'DESC');
                break;
            case 'created_at_desc':
                $qb->orderBy('v.createdAt', 'DESC');
                break;
            case 'created_at_asc':
                $qb->orderBy('v.createdAt', 'ASC');
                break;
            case 'name_asc':
            default:
                $qb->orderBy('v.name', 'ASC');
                break;
        }

        $qb->setMaxResults($limit)->setFirstResult($offset);

        /** @var list<Vendor> $list */
        $list = $qb->getQuery()->getResult();

        // Re-run for total count
        $countQb = $this->createQueryBuilder('v')->select('COUNT(v.id)');
        if ($status !== null) {
            $countQb->andWhere('v.status = :status')->setParameter('status', $status);
        }
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        return [$list, $total];
    }

    /**
     * Companion to findPaginatedForAdmin: returns ALL vendor IDs
     * matching the supplied status filter (no pagination). Used by
     * the metrics list endpoint when a metric-based sort is requested
     * — the controller computes metrics for every vendor, sorts in
     * PHP, then slices.
     *
     * @return list<int>
     */
    public function findAllIdsForAdmin(?string $status = null): array
    {
        $qb = $this->createQueryBuilder('v')->select('v.id');
        if ($status !== null) {
            $qb->andWhere('v.status = :status')->setParameter('status', $status);
        }
        $rows = $qb->getQuery()->getScalarResult();
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Active + featured vendors, ordered alphabetically by name.
     *
     * Powers the apps/web home-page Designer Spotlight surface.
     * Strictly filters by BOTH is_active = true AND is_featured = true
     * — a soft-deleted vendor that was previously flagged featured
     * must NOT leak onto the public Spotlight.
     *
     * Q-Sort = A (locked in M3.2.X.2 plan): alphabetical name ASC.
     * Deterministic, stable, reflects no implicit preference.
     * Switching to featured_at-based ordering is a future-phase
     * change requiring a `featured_at` column (cheap migration).
     *
     * Q-LimitClamp = A (locked): caller is expected to clamp the
     * limit to [1..12] before invoking. This repository does not
     * re-clamp — that's the controller's responsibility per layered
     * input-validation discipline.
     *
     * @return Vendor[]
     */
    public function findFeatured(int $limit = 4, int $offset = 0): array
    {
        return $this->createQueryBuilder('v')
            ->where('v.isActive = true')
            ->andWhere('v.isFeatured = true')
            ->orderBy('v.name', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();
    }

    /**
     * Total count of active + featured vendors. Used for the
     * PaginatedEnvelope `total` field; cheap aggregate query that
     * doesn't materialize the row data.
     */
    public function countFeatured(): int
    {
        return (int) $this->createQueryBuilder('v')
            ->select('COUNT(v.id)')
            ->where('v.isActive = true')
            ->andWhere('v.isFeatured = true')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Active + featured vendors with their aggregated rating stats.
     *
     * Returns a list of {vendor, rating, ratingCount} dicts where
     *   - rating: float|null — average star of approved reviews
     *     scoped to the vendor (NULL if vendor has zero approved
     *     reviews; matches the apps/web `FeaturedVendor.rating`
     *     contract semantics)
     *   - ratingCount: int — number of approved reviews counted
     *     in the aggregate
     *
     * Why a separate method (vs decorating findFeatured)
     * ===================================================
     * findFeatured() returns pure Vendor entities. Many call sites
     * (admin listings, sitemap, etc.) don't care about rating data,
     * and forcing a JOIN on every query would be wasteful.
     *
     * findFeaturedWithStats is the variant explicitly for the
     * Designer Spotlight surface. Single query with LEFT JOIN +
     * GROUP BY (no N+1) — at Spotlight scale (≤4 vendors per
     * request) this is the right shape.
     *
     * SQL shape
     * =========
     * SELECT v, AVG(pr.star) AS rating, COUNT(pr.id) AS ratingCount
     *   FROM Vendor v
     *   LEFT JOIN ProductReview pr WITH pr.vendor = v
     *       AND pr.status = 'approved'
     *  WHERE v.isActive = true AND v.isFeatured = true
     *  GROUP BY v.id
     *  ORDER BY v.name ASC
     *  LIMIT :limit
     *
     * `LEFT JOIN` so vendors with zero reviews still appear with
     * NULL rating (matches apps/web's `rating: number | null` typing).
     * `WITH` clause filters joined rows to approved reviews only;
     * pending/rejected/spam reviews don't pollute the average.
     *
     * Q-Rating = A (locked in M3.2.X.2 plan): real-time aggregate.
     * Spotlight is at most 4 vendors; cost is negligible.
     * Denormalized columns deferred to M3.2.X.13 (vendor analytics).
     *
     * @return list<array{vendor: Vendor, rating: float|null, ratingCount: int}>
     */
    public function findFeaturedWithStats(int $limit = 4, int $offset = 0): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('v', 'AVG(pr.star) AS rating', 'COUNT(pr.id) AS ratingCount')
            ->leftJoin(
                \Bayti\Api\Domain\Catalog\ProductReview::class,
                'pr',
                'WITH',
                "pr.vendor = v AND pr.status = 'approved'"
            )
            ->where('v.isActive = true')
            ->andWhere('v.isFeatured = true')
            ->groupBy('v.id')
            ->orderBy('v.name', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        // Doctrine returns mixed-array shape when SELECT mixes
        // entities + scalars. Normalize into typed dicts so callers
        // don't deal with the raw column-name conventions.
        $out = [];
        foreach ($rows as $row) {
            // Row shape: [0 => Vendor entity, 'rating' => string|null, 'ratingCount' => string|int]
            $vendor = $row[0];
            $rating = $row['rating'] !== null ? (float) $row['rating'] : null;
            $ratingCount = (int) $row['ratingCount'];

            $out[] = [
                'vendor' => $vendor,
                'rating' => $rating,
                'ratingCount' => $ratingCount,
            ];
        }

        return $out;
    }
}
