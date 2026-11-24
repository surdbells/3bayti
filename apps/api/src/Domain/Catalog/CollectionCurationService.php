<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Transactional writes for admin collection curation that touch MANY rows:
 *
 *   - reorderCollections(): the drag-and-drop display order of the collections
 *     themselves (which IS the storefront "Shop by collection" order).
 *   - setCollectionProducts(): a collection's full curated product list
 *     (collection side, PUT /admin/collections/{id}/products).
 *   - setProductCollections(): a single product's complete membership set
 *     across collections (product side, PUT /admin/products/{id}/collections;
 *     a product can sit in any number of collections). The vendor product
 *     page uses it too, scoped to active collections ($activeOnly).
 *   - setVendorProductsInCollection(): a VENDOR's own products in one
 *     collection (PUT /vendor/collections/{id}/products), appended at the
 *     end, never touching other stores' rows.
 *
 * Callers validate ids first (unknown ids are a 422 at the HTTP edge); this
 * service only applies an already-valid plan. Every change goes through the
 * UnitOfWork (setters / persist / remove, never bulk DQL) so the Doctrine
 * EntityAuditListener records each ProductCollection update and each
 * CollectionProduct create/update/delete, whichever endpoint made it.
 *
 * Concurrency: each write takes Postgres transaction-scoped advisory locks
 * BEFORE reading the rows it diffs against (see the repositories' lock*()
 * methods), so concurrent admins are serialised instead of interleaving:
 *   - reorders share one lock;
 *   - membership writers lock every collection whose rows they write
 *     (ascending id), and the product side locks the product first.
 * Both membership writers also accept an optional precondition (the state the
 * client loaded); a mismatch writes nothing and throws
 * StaleCollectionMembershipException, so neither writer can silently undo
 * the other's change.
 */
final class CollectionCurationService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /**
     * Rewrite display_order 0..n-1 for ALL collections in one transaction.
     * $orderedIds come first in the given order; collections not listed keep
     * their current relative order and follow them. Ids that no longer exist
     * are skipped.
     *
     * @param list<int> $orderedIds
     * @return list<ProductCollection> every collection, in the new order
     */
    public function reorderCollections(array $orderedIds): array
    {
        /** @var list<ProductCollection> $ordered */
        $ordered = $this->em->wrapInTransaction(function () use ($orderedIds): array {
            /** @var ProductCollectionRepository $repo */
            $repo = $this->em->getRepository(ProductCollection::class);

            // Serialise with any concurrent reorder, THEN read: the read sees
            // the previous reorder's committed order, and refresh overwrites
            // any collection already in the identity map so the diff below is
            // against the real stored display_order (never stale values).
            $repo->lockDisplayOrder();

            $byId = [];
            foreach ($repo->findAllOrdered(refresh: true) as $c) {
                $byId[(int) $c->getId()] = $c;
            }

            $ordered = [];
            foreach ($orderedIds as $id) {
                if (isset($byId[$id])) {
                    $ordered[] = $byId[$id];
                    unset($byId[$id]);
                }
            }
            // Unlisted collections: $byId still holds them in their current
            // display order (insertion order of findAllOrdered).
            foreach ($byId as $c) {
                $ordered[] = $c;
            }

            foreach ($ordered as $position => $c) {
                if ($c->getDisplayOrder() !== $position) {
                    $c->setDisplayOrder($position);
                }
            }
            $this->em->flush();

            return $ordered;
        });

        return $ordered;
    }

    /**
     * Make $products the collection's curated list, in that order (index 0 =
     * the "first product" whose image fronts the collection card), in one
     * transaction. Diffed through the UnitOfWork: products no longer listed
     * are removed, kept ones are re-positioned only when their position
     * changed, new ones are inserted. Memberships in OTHER collections are
     * never touched.
     *
     * @param list<Product>  $products            distinct products, in curation order
     * @param list<int>|null $expectedProductIds  optional precondition: the
     *        curated product ids (in order) the client loaded; when the stored
     *        list differs nothing is written
     *
     * @throws StaleCollectionMembershipException precondition failed (carries
     *         the stored ids) or a concurrent writer won a unique-key race
     */
    public function setCollectionProducts(ProductCollection $collection, array $products, ?array $expectedProductIds = null): void
    {
        $collectionId = (int) $collection->getId();

        $stale = $this->guardUniqueRace(fn (): ?array => $this->em->wrapInTransaction(
            function () use ($collection, $collectionId, $products, $expectedProductIds): ?array {
                $joinRepo = $this->joinRepo();
                $joinRepo->lockCollections([$collectionId]);

                // Authoritative read, under the lock.
                $rows = $joinRepo->findForCollection($collectionId);
                $currentIds = array_map(
                    static fn (CollectionProduct $r): int => (int) $r->getProduct()->getId(),
                    $rows,
                );
                if ($expectedProductIds !== null && $currentIds !== $expectedProductIds) {
                    return $currentIds;
                }

                $position = [];
                foreach ($products as $i => $p) {
                    $position[(int) $p->getId()] = $i;
                }

                $kept = [];
                foreach ($rows as $row) {
                    $pid = (int) $row->getProduct()->getId();
                    if (!isset($position[$pid])) {
                        $this->em->remove($row);
                        continue;
                    }
                    $kept[$pid] = true;
                    if ($row->getSortOrder() !== $position[$pid]) {
                        $row->setSortOrder($position[$pid]);
                    }
                }
                foreach ($products as $i => $p) {
                    if (!isset($kept[(int) $p->getId()])) {
                        $this->em->persist(new CollectionProduct($collection, $p, $i));
                    }
                }

                $this->em->flush();
                return null;
            },
        ));

        if ($stale !== null) {
            throw new StaleCollectionMembershipException($stale);
        }
    }

    /**
     * Make $collections the product's COMPLETE membership set, in one
     * transaction:
     *   - removed from every collection not in the set;
     *   - added to newly listed collections at the END (sort_order = that
     *     collection's current max + 1, or 0 when it is empty), so adding a
     *     product never displaces the collection's "first product" card image;
     *   - collections it stays in keep its existing sort_order.
     * An empty set removes the product from every collection.
     *
     * $activeOnly (the VENDOR product page, which only sees active
     * collections): the write is scoped to ACTIVE collections. Memberships in
     * inactive collections are neither removed nor part of the precondition,
     * so a vendor saving its product never drops it from a collection an
     * admin has temporarily switched off. Callers then pass active
     * collections only. Activity is judged on the rows read under the locks.
     *
     * @param list<ProductCollection> $collections
     * @param list<int>|null $expectedCollectionIds optional precondition: the
     *        collection ids (any order) the client loaded for this product;
     *        when the stored set differs nothing is written
     *
     * @throws StaleCollectionMembershipException precondition failed (carries
     *         the stored ids, ascending) or a concurrent writer won a
     *         unique-key race
     */
    public function setProductCollections(
        Product $product,
        array $collections,
        ?array $expectedCollectionIds = null,
        bool $activeOnly = false,
    ): void {
        $productId = (int) $product->getId();

        $stale = $this->guardUniqueRace(fn (): ?array => $this->em->wrapInTransaction(
            function () use ($product, $productId, $collections, $expectedCollectionIds, $activeOnly): ?array {
                $joinRepo = $this->joinRepo();

                $wanted = [];
                foreach ($collections as $c) {
                    $wanted[(int) $c->getId()] = $c;
                }

                // Global lock order: the product, then every collection whose
                // rows this write may touch (wanted + current), ascending.
                $joinRepo->lockProduct($productId);
                $joinRepo->lockCollections(array_merge(
                    array_keys($wanted),
                    $joinRepo->collectionIdsForProduct($productId),
                ));

                // Authoritative read, under the locks.
                $current = [];
                foreach ($joinRepo->findForProduct($productId) as $row) {
                    if ($activeOnly && !$row->getCollection()->isActive()) {
                        continue; // out of scope: kept as is, not compared
                    }
                    $current[(int) $row->getCollection()->getId()] = $row;
                }

                if ($expectedCollectionIds !== null) {
                    $currentIds = array_keys($current);
                    $expected = array_values(array_unique($expectedCollectionIds));
                    sort($currentIds);
                    sort($expected);
                    if ($currentIds !== $expected) {
                        return $currentIds;
                    }
                }

                foreach ($current as $collectionId => $row) {
                    if (!isset($wanted[$collectionId])) {
                        $this->em->remove($row);
                    }
                }

                $toAdd = array_diff_key($wanted, $current);
                if ($toAdd !== []) {
                    $maxSort = $joinRepo->maxSortOrders(array_keys($toAdd));
                    foreach ($toAdd as $collectionId => $collection) {
                        $sortOrder = isset($maxSort[$collectionId]) ? $maxSort[$collectionId] + 1 : 0;
                        $this->em->persist(new CollectionProduct($collection, $product, $sortOrder));
                    }
                }

                $this->em->flush();
                return null;
            },
        ));

        if ($stale !== null) {
            throw new StaleCollectionMembershipException($stale);
        }
    }

    /**
     * A VENDOR's write to one collection: make $products (the vendor's own,
     * live products) exactly the vendor's members of $collection, without
     * touching anyone else's rows. In one locked transaction:
     *   - "own" rows = members whose product belongs to one of $vendorIds and
     *     is not soft-deleted (the set the vendor can see);
     *   - own rows not in $products are removed; other rows are never
     *     removed, re-positioned or renumbered (gaps left by a removal are
     *     harmless: every reader orders by (sort_order, id), and the next
     *     admin save rewrites 0..n-1);
     *   - $products not yet members are APPENDED after every current row
     *     (max sort_order over ALL rows + 1, incrementing, in request order;
     *     0 for an empty collection), so a vendor never displaces the admin's
     *     curation or the card image (the first product);
     *   - own rows that stay keep their position (an idempotent PUT writes
     *     nothing).
     * Same lock discipline as the admin collection side (lock, then read).
     *
     * @param list<int>      $vendorIds      the caller's store ids
     * @param list<Product>  $products       distinct products, all owned by
     *        $vendorIds and not soft-deleted (validated by the caller)
     * @param list<int>|null $expectedOwnIds optional precondition: the own
     *        product ids (any order) the client loaded; when the stored own
     *        set differs nothing is written
     *
     * @throws StaleCollectionMembershipException precondition failed (carries
     *         the stored own ids in curation order) or a unique-key race
     */
    public function setVendorProductsInCollection(
        ProductCollection $collection,
        array $vendorIds,
        array $products,
        ?array $expectedOwnIds = null,
    ): void {
        $collectionId = (int) $collection->getId();
        $vendorSet = array_fill_keys($vendorIds, true);

        $stale = $this->guardUniqueRace(fn (): ?array => $this->em->wrapInTransaction(
            function () use ($collection, $collectionId, $vendorSet, $products, $expectedOwnIds): ?array {
                $joinRepo = $this->joinRepo();
                $joinRepo->lockCollections([$collectionId]);

                // Authoritative read, under the lock.
                $rows = $joinRepo->findForCollection($collectionId);

                $maxSort = null;
                $own = [];
                $memberIds = [];
                foreach ($rows as $row) {
                    $maxSort = $maxSort === null ? $row->getSortOrder() : max($maxSort, $row->getSortOrder());
                    $product = $row->getProduct();
                    $pid = (int) $product->getId();
                    $memberIds[$pid] = true;
                    if (isset($vendorSet[(int) $product->getVendor()->getId()])
                        && $product->getStatus() !== Product::STATUS_SOFT_DELETED) {
                        $own[$pid] = $row;
                    }
                }

                if ($expectedOwnIds !== null) {
                    $currentIds = array_keys($own);
                    $sortedCurrent = $currentIds;
                    $expected = array_values(array_unique($expectedOwnIds));
                    sort($sortedCurrent);
                    sort($expected);
                    if ($sortedCurrent !== $expected) {
                        return $currentIds;
                    }
                }

                $wanted = [];
                foreach ($products as $p) {
                    $wanted[(int) $p->getId()] = $p;
                }

                foreach ($own as $pid => $row) {
                    if (!isset($wanted[$pid])) {
                        $this->em->remove($row);
                    }
                }

                $next = $maxSort === null ? 0 : $maxSort + 1;
                foreach ($wanted as $pid => $p) {
                    if (!isset($memberIds[$pid])) {
                        $this->em->persist(new CollectionProduct($collection, $p, $next++));
                    }
                }

                $this->em->flush();
                return null;
            },
        ));

        if ($stale !== null) {
            throw new StaleCollectionMembershipException($stale);
        }
    }

    /**
     * Backstop for a writer that does not honour the membership locks: a
     * duplicate (collection, product) INSERT becomes a clean "reload and try
     * again" conflict instead of a 500. (The failed flush has already rolled
     * the transaction back and closed the EntityManager, so no retry here.)
     *
     * @param callable(): (list<int>|null) $write
     * @return list<int>|null
     */
    private function guardUniqueRace(callable $write): ?array
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException $e) {
            throw new StaleCollectionMembershipException(null, $e);
        }
    }

    private function joinRepo(): CollectionProductRepository
    {
        /** @var CollectionProductRepository $repo */
        $repo = $this->em->getRepository(CollectionProduct::class);
        return $repo;
    }
}
