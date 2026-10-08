<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Doctrine\AdvisoryLock;
use Doctrine\ORM\EntityRepository;

/**
 * Membership rows for admin-curated collections (the collection_products join).
 *
 * @extends EntityRepository<CollectionProduct>
 */
class CollectionProductRepository extends EntityRepository
{
    /** Advisory-lock key space for one collection's membership rows (key 2 = collection id). */
    public const LOCK_NAMESPACE_COLLECTION = 3_100_102;
    /** Advisory-lock key space for one product's membership set (key 2 = product id). */
    public const LOCK_NAMESPACE_PRODUCT = 3_100_103;

    /**
     * Curated product IDs for a collection, in curation order. Includes every
     * curated product regardless of active/stock state (the admin curation UI
     * wants to see exactly what it set); the storefront applies its own
     * active/approved gating downstream.
     *
     * @return list<int>
     */
    public function productIdsForCollection(int $collectionId): array
    {
        /** @var list<array{pid: int|string}> $rows */
        $rows = $this->createQueryBuilder('cp')
            ->select('IDENTITY(cp.product) AS pid')
            ->where('cp.collection = :cid')
            ->setParameter('cid', $collectionId)
            ->orderBy('cp.sortOrder', 'ASC')
            ->addOrderBy('cp.id', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): int => (int) $r['pid'], $rows);
    }

    /**
     * A collection's membership rows in curation order (sort_order ASC, then
     * id ASC, the same order as productIdsForCollection). Used to DIFF a
     * full re-set through the UnitOfWork, so every removal, re-position and
     * addition is an audited entity change (no bulk DQL delete, which would
     * bypass the EntityAuditListener).
     *
     * @return list<CollectionProduct>
     */
    public function findForCollection(int $collectionId): array
    {
        /** @var list<CollectionProduct> $rows */
        $rows = $this->createQueryBuilder('cp')
            ->where('cp.collection = :cid')
            ->setParameter('cid', $collectionId)
            ->orderBy('cp.sortOrder', 'ASC')
            ->addOrderBy('cp.id', 'ASC')
            ->getQuery()
            ->getResult();
        return $rows;
    }

    /**
     * The ids of the collections a product is curated into (unordered; a
     * cheap scalar read used to decide which collections to lock).
     *
     * @return list<int>
     */
    public function collectionIdsForProduct(int $productId): array
    {
        /** @var list<array{cid: int|string}> $rows */
        $rows = $this->createQueryBuilder('cp')
            ->select('IDENTITY(cp.collection) AS cid')
            ->where('cp.product = :pid')
            ->setParameter('pid', $productId)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): int => (int) $r['cid'], $rows);
    }

    /**
     * Serialise writers of these collections' membership rows until the
     * current transaction ends (Postgres transaction-scoped advisory locks,
     * one per collection, always taken in ASCENDING id order so two writers
     * locking overlapping sets can never deadlock).
     *
     * Every code path that inserts/updates/deletes collection_products rows
     * takes the lock of each collection it writes BEFORE reading the rows it
     * diffs against. Under READ COMMITTED every later statement then sees the
     * previous holder's committed result, so two admins adding the same
     * product to the same collection at once cannot both INSERT the pair
     * (UNIQUE(collection_id, product_id) violation → 500), and two appends to
     * one collection cannot both pick the same "max + 1" sort_order.
     *
     * @param list<int> $collectionIds
     */
    public function lockCollections(array $collectionIds): void
    {
        $ids = array_values(array_unique($collectionIds));
        sort($ids);
        foreach ($ids as $id) {
            AdvisoryLock::forTransaction($this->getEntityManager()->getConnection(), self::LOCK_NAMESPACE_COLLECTION, $id);
        }
    }

    /**
     * Serialise product-side membership writes (PUT /admin/products/{id}/
     * collections) for one product until the transaction ends. Always taken
     * BEFORE any lockCollections() call (product first, then collections
     * ascending) so lock order is global and deadlock-free.
     */
    public function lockProduct(int $productId): void
    {
        AdvisoryLock::forTransaction($this->getEntityManager()->getConnection(), self::LOCK_NAMESPACE_PRODUCT, $productId);
    }

    /**
     * Which collections each of the given products is curated into, in ONE
     * query (the admin product builder asks for a whole page of cards at once,
     * so no per-product N+1).
     *
     * Rows come back ordered by the collection's display_order ASC NULLS LAST,
     * then collection id DESC, the same order as the admin collections list
     * (ProductCollectionRepository::findPaginated on Postgres), so grouping the
     * rows by product_id keeps every product's list in that order.
     *
     * @param list<int> $productIds
     * @return list<array{product_id: int, id: int, name: string, slug: string, is_active: bool, sort_order: int}>
     */
    public function membershipsForProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** @var list<array{pid: int|string, cid: int|string, cname: string, cslug: string, cactive: bool, cpsort: int|string}> $rows */
        $rows = $this->createQueryBuilder('cp')
            ->select(
                'IDENTITY(cp.product) AS pid',
                'c.id AS cid',
                'c.name AS cname',
                'c.slug AS cslug',
                'c.isActive AS cactive',
                'cp.sortOrder AS cpsort',
            )
            ->join('cp.collection', 'c')
            ->where('cp.product IN (:pids)')
            ->setParameter('pids', $productIds)
            // NULLS LAST via the same CASE trick as VendorLabelRepository.
            ->orderBy('CASE WHEN c.displayOrder IS NULL THEN 1 ELSE 0 END', 'ASC')
            ->addOrderBy('c.displayOrder', 'ASC')
            ->addOrderBy('c.id', 'DESC')
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $r): array => [
            'product_id' => (int) $r['pid'],
            'id'         => (int) $r['cid'],
            'name'       => (string) $r['cname'],
            'slug'       => (string) $r['cslug'],
            'is_active'  => (bool) $r['cactive'],
            'sort_order' => (int) $r['cpsort'],
        ], $rows);
    }

    /**
     * A product's membership rows, with each collection fetch-joined (used to
     * diff the product's membership set on a write).
     *
     * @return list<CollectionProduct>
     */
    public function findForProduct(int $productId): array
    {
        /** @var list<CollectionProduct> $rows */
        $rows = $this->createQueryBuilder('cp')
            ->addSelect('c')
            ->join('cp.collection', 'c')
            ->where('cp.product = :pid')
            ->setParameter('pid', $productId)
            ->getQuery()
            ->getResult();
        return $rows;
    }

    /**
     * Member count per collection, one grouped query. Collections with no
     * members are absent from the map (callers default them to 0).
     *
     * @param list<int> $collectionIds
     * @return array<int, int> collection id => member count
     */
    public function countsByCollection(array $collectionIds): array
    {
        return $this->aggregateByCollection('COUNT(cp.id)', $collectionIds);
    }

    /**
     * Highest sort_order per collection, one grouped query (so a product added
     * to a collection can be appended at its end). Empty collections are
     * absent from the map.
     *
     * @param list<int> $collectionIds
     * @return array<int, int> collection id => MAX(sort_order)
     */
    public function maxSortOrders(array $collectionIds): array
    {
        return $this->aggregateByCollection('MAX(cp.sortOrder)', $collectionIds);
    }

    /**
     * @param list<int> $collectionIds
     * @return array<int, int>
     */
    private function aggregateByCollection(string $aggregate, array $collectionIds): array
    {
        if ($collectionIds === []) {
            return [];
        }

        /** @var list<array{cid: int|string, agg: int|string}> $rows */
        $rows = $this->createQueryBuilder('cp')
            ->select('IDENTITY(cp.collection) AS cid', $aggregate . ' AS agg')
            ->where('cp.collection IN (:cids)')
            ->setParameter('cids', $collectionIds)
            ->groupBy('cp.collection')
            ->getQuery()
            ->getArrayResult();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['cid']] = (int) $r['agg'];
        }
        return $out;
    }
}
