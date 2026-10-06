<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;

/**
 * Membership rows for admin-curated collections (the collection_products join).
 *
 * @extends EntityRepository<CollectionProduct>
 */
class CollectionProductRepository extends EntityRepository
{
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

    /** Remove every membership row for a collection (used before a full re-set). */
    public function deleteForCollection(int $collectionId): void
    {
        $this->getEntityManager()->createQueryBuilder()
            ->delete(CollectionProduct::class, 'cp')
            ->where('cp.collection = :cid')
            ->setParameter('cid', $collectionId)
            ->getQuery()
            ->execute();
    }
}
