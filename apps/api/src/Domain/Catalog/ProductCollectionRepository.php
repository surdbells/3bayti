<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Doctrine\AdvisoryLock;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;

/** @extends EntityRepository<ProductCollection> */
class ProductCollectionRepository extends EntityRepository
{
    /** Advisory-lock key space for the collections' display order (key 2 = 0). */
    public const LOCK_NAMESPACE_ORDER = 3_100_101;

    public function save(ProductCollection $col): void
    {
        $em = $this->getEntityManager();
        $em->persist($col);
        $em->flush();
    }

    public function delete(ProductCollection $col): void
    {
        $em = $this->getEntityManager();
        $em->remove($col);
        $em->flush();
    }

    /**
     * @return array{items: list<ProductCollection>, total: int}
     */
    public function findPaginated(int $limit = 20, int $offset = 0, ?bool $activeOnly = null): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.displayOrder', 'ASC')->addOrderBy('c.id', 'DESC');
        if ($activeOnly !== null) {
            $qb->where('c.isActive = :a')->setParameter('a', $activeOnly);
        }
        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $qb->setMaxResults($limit)->setFirstResult($offset);
        /** @var list<ProductCollection> $items */
        $items = $qb->getQuery()->getResult();
        return ['items' => $items, 'total' => $total];
    }

    /**
     * EVERY collection (active or not) in display order: display_order ASC
     * NULLS LAST, then id DESC, the same order the admin list shows (Postgres
     * sorts NULLs last for ASC). Used to rewrite the order on a drag-and-drop
     * reorder; collection counts are small, so no pagination.
     *
     * $refresh: overwrite collections already in the identity map with the
     * row values just read. Without it Doctrine keeps an already-managed
     * entity's in-memory display_order, so a reorder that runs after another
     * one committed (see lockDisplayOrder) would diff against stale values
     * and skip UPDATEs, leaving duplicate positions.
     *
     * @return list<ProductCollection>
     */
    public function findAllOrdered(bool $refresh = false): array
    {
        $query = $this->orderedQueryBuilder()->getQuery();
        if ($refresh) {
            $query->setHint(Query::HINT_REFRESH, true);
        }
        /** @var list<ProductCollection> $items */
        $items = $query->getResult();
        return $items;
    }

    /**
     * Ids of EVERY collection in the admin/storefront display order (same
     * ORDER BY as findAllOrdered), as plain scalars: nothing is hydrated into
     * the identity map. Used for the reorder's unknown-id check and for a
     * collection's 1-based "position".
     *
     * @return list<int>
     */
    public function orderedIds(): array
    {
        /** @var list<array{id: int|string}> $rows */
        $rows = $this->orderedQueryBuilder()
            ->select('c.id AS id')
            ->getQuery()
            ->getArrayResult();
        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /**
     * Serialise drag-and-drop reorders until the current transaction ends (a
     * Postgres transaction-scoped advisory lock). A reorder reads every
     * collection's display_order and rewrites the ones that moved; without
     * this, two concurrent reorders interleave their UPDATEs into an order
     * neither admin asked for, with duplicate positions. Must be called
     * inside the transaction, BEFORE findAllOrdered(refresh: true).
     */
    public function lockDisplayOrder(): void
    {
        AdvisoryLock::forTransaction($this->getEntityManager()->getConnection(), self::LOCK_NAMESPACE_ORDER, 0);
    }

    private function orderedQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->orderBy('CASE WHEN c.displayOrder IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.id', 'DESC');
    }

    /**
     * Active collections in display order, for the storefront "shop by collection".
     *
     * @return list<ProductCollection>
     */
    public function findActiveForStorefront(): array
    {
        /** @var list<ProductCollection> $items */
        $items = $this->createQueryBuilder('c')
            ->where('c.isActive = TRUE')
            ->orderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getResult();
        return $items;
    }

    public function findBySlug(string $slug, bool $activeOnly = true): ?ProductCollection
    {
        $qb = $this->createQueryBuilder('c')
            ->where('c.slug = :slug')
            ->setParameter('slug', $slug)
            ->setMaxResults(1);
        if ($activeOnly) {
            $qb->andWhere('c.isActive = TRUE');
        }
        /** @var ProductCollection|null $col */
        $col = $qb->getQuery()->getOneOrNullResult();
        return $col;
    }

    public function findByLegacyId(int $legacyId): ?ProductCollection
    {
        /** @var ProductCollection|null $col */
        $col = $this->findOneBy(['legacyCollectionId' => $legacyId]);
        return $col;
    }

    /**
     * The representative image for a collection card: the primary image of the
     * FIRST curated product (by curation order) that is live on the storefront
     * (active + vendor approved + has an image). Null when the collection has
     * no such product (the caller falls back to the collection's cover image).
     */
    public function representativeImageUrl(int $collectionId): ?string
    {
        /** @var list<array{url: string}> $rows */
        $rows = $this->getEntityManager()->createQueryBuilder()
            ->select('p.primaryImageUrl AS url')
            ->from(CollectionProduct::class, 'cp')
            ->join('cp.product', 'p')
            ->join('p.vendor', 'v')
            ->where('cp.collection = :cid')
            ->andWhere('p.isActive = TRUE')
            ->andWhere('p.primaryImageUrl IS NOT NULL')
            ->andWhere('v.isActive = TRUE')
            ->andWhere('v.status = :approved')
            ->setParameter('cid', $collectionId)
            ->setParameter('approved', Vendor::STATUS_APPROVED)
            ->orderBy('cp.sortOrder', 'ASC')
            ->addOrderBy('cp.id', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();

        return $rows[0]['url'] ?? null;
    }

    /** Count of storefront-visible (active + approved vendor) products in a collection. */
    public function countActiveProducts(int $collectionId): int
    {
        return (int) $this->getEntityManager()->createQueryBuilder()
            ->select('COUNT(p.id)')
            ->from(CollectionProduct::class, 'cp')
            ->join('cp.product', 'p')
            ->join('p.vendor', 'v')
            ->where('cp.collection = :cid')
            ->andWhere('p.isActive = TRUE')
            ->andWhere('v.isActive = TRUE')
            ->andWhere('v.status = :approved')
            ->setParameter('cid', $collectionId)
            ->setParameter('approved', Vendor::STATUS_APPROVED)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
