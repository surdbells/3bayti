<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Category>
 */
class CategoryRepository extends EntityRepository
{
    public function save(Category $category): void
    {
        $em = $this->getEntityManager();
        $em->persist($category);
        $em->flush();
    }

    public function findBySlug(string $slug): ?Category
    {
        // Exact match first.
        $category = $this->findOneBy(['slug' => $slug]);
        if ($category !== null) {
            return $category;
        }

        // The web app uses a "<slug>-<id>" convention (e.g. "abayas-1")
        // while migrated categories store the bare slug ("abayas"). The
        // numeric suffix is the LEGACY category id, not the v3 id, so we
        // must not blindly match it against the v3 primary key.
        // Resolution order: (a) bare slug, then (b) legacy_category_id.
        if (preg_match('/^(.*)-(\d+)$/', $slug, $m) === 1) {
            $bareSlug = $m[1];
            $legacyId = (int) $m[2];

            $byBareSlug = $this->findOneBy(['slug' => $bareSlug]);
            if ($byBareSlug !== null) {
                return $byBareSlug;
            }

            return $this->findOneBy(['legacyCategoryId' => $legacyId]);
        }

        return null;
    }

    /**
     * Look up a category by its legacy WordPress/CodeIgniter id.
     *
     * Same rationale as VendorRepository::findByLegacyId, serves the
     * M3.1.5 mobile flip where mobile sends `category: 5` (legacy id)
     * and we resolve it server-side rather than forcing a mobile-side
     * slug-cache. Removable once mobile is rebuilt against slug
     * semantics (M3.1.10+).
     *
     * Returns null if no category has that legacy id. Inactive
     * categories are returned by this method, the controller layer
     * decides whether to expose them.
     */
    public function findByLegacyId(int $legacyId): ?Category
    {
        return $this->findOneBy(['legacyCategoryId' => $legacyId]);
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.slug = :slug')
            ->setParameter('slug', $slug);

        if ($excludeId !== null) {
            $qb->andWhere('c.id != :id')->setParameter('id', $excludeId);
        }

        return ((int) $qb->getQuery()->getSingleScalarResult()) > 0;
    }

    /**
     * All root categories (parent IS NULL), ordered.
     *
     * @return Category[]
     */
    public function findRoots(): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.parent IS NULL')
            ->andWhere('c.isActive = true')
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Direct children of a category.
     *
     * @return Category[]
     */
    public function findChildren(Category $parent): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.parent = :parent')
            ->andWhere('c.isActive = true')
            ->setParameter('parent', $parent)
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * All categories under a path prefix, including the root path's
     * own category. Used for subtree queries.
     *
     * Example: $pathPrefix = '/clothing' returns clothing + all
     * descendants.
     *
     * @return Category[]
     */
    public function findUnderPath(string $pathPrefix, bool $includeInactive = false): array
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.path = :exact OR c.path LIKE :prefix')
            ->setParameter('exact', $pathPrefix)
            ->setParameter('prefix', rtrim($pathPrefix, '/') . '/%')
            ->orderBy('c.path', 'ASC');

        if (!$includeInactive) {
            $qb->andWhere('c.isActive = true');
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * All categories, active or not, for admin views.
     *
     * @return Category[]
     */
    public function findAll(): array
    {
        return $this->createQueryBuilder('c')
            ->orderBy('c.path', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Walk ancestors to check if making $candidateParent the parent
     * of $self would create a cycle. Returns true if it WOULD cycle.
     *
     * Self-parenting is the simplest cycle: a category as its own
     * parent. We catch that and the multi-step case.
     */
    public function wouldCreateCycle(Category $self, Category $candidateParent): bool
    {
        if ($self->getId() === null) {
            // Brand-new category being created; no descendants yet.
            return false;
        }

        // If candidate is self, cycle.
        if ($candidateParent->getId() === $self->getId()) {
            return true;
        }

        // Walk candidate's ancestors. If we find $self anywhere, cycle.
        $cursor = $candidateParent->getParent();
        $depth = 0;
        while ($cursor !== null) {
            if ($cursor->getId() === $self->getId()) {
                return true;
            }
            $cursor = $cursor->getParent();
            // Defensive depth cap. We never expect more than ~15
            // levels; anything beyond is a sign of corrupt data.
            if (++$depth > 50) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rebuild path for $cat and ALL descendants. Called after
     * reparent or slug change.
     *
     * This is a recursive walk down the subtree. For each descendant,
     * we refresh the path from current parent's path + slug.
     */
    public function rebuildSubtreePaths(Category $cat): void
    {
        // Refresh self first so descendants pick up the new path.
        $cat->refreshPath();

        $children = $this->createQueryBuilder('c')
            ->where('c.parent = :p')
            ->setParameter('p', $cat)
            ->getQuery()
            ->getResult();

        foreach ($children as $child) {
            $this->rebuildSubtreePaths($child);
        }

        // Flush is the caller's responsibility (typically in a
        // transaction wrapping the whole operation).
    }

    /**
     * Does any product reference this category?
     * Used to enforce "can't delete a category with products", but
     * we're soft-deleting via is_active=false anyway, so this is
     * more of a courtesy check than a hard constraint.
     *
     * Returns 0 until products table exists (M2.2); harmless.
     */
    public function productCount(Category $category): int
    {
        $em = $this->getEntityManager();
        $conn = $em->getConnection();

        // Use raw SQL because the products table doesn't exist as
        // an entity yet (M2.2). Query the table directly if it
        // exists; return 0 if not.
        $schemaManager = $conn->createSchemaManager();
        if (!$schemaManager->tablesExist(['products'])) {
            return 0;
        }

        $result = $conn->executeQuery(
            'SELECT COUNT(*) FROM products WHERE category_id = :id',
            ['id' => $category->getId()],
        );

        return (int) $result->fetchOne();
    }
}
