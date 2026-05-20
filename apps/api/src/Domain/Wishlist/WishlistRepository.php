<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Wishlist;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for Wishlist entities.
 *
 * @extends EntityRepository<Wishlist>
 */
class WishlistRepository extends EntityRepository
{
    /**
     * The user's saved entries, newest first, paginated. Joins the
     * product so the serializer can read it without N+1 lazy loads.
     *
     * Optional label filter (Q-Z3=B):
     *   $labelFilter === false  → no filter (all saved products)
     *   $labelFilter === null   → only uncategorized (label_id IS NULL)
     *   $labelFilter === int    → only that label
     *
     * @return list<Wishlist>
     */
    public function findForUserPaginated(
        User $user,
        int $limit,
        int $offset,
        int|null|false $labelFilter = false,
    ): array {
        $qb = $this->createQueryBuilder('w')
            ->addSelect('p')
            ->innerJoin('w.product', 'p')
            ->where('w.user = :user')
            ->setParameter('user', $user);

        $this->applyLabelFilter($qb, $labelFilter);

        /** @var list<Wishlist> $rows */
        $rows = $qb
            ->orderBy('w.createdAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** Total saved entries for the user (for pagination meta). */
    public function countForUser(User $user, int|null|false $labelFilter = false): int
    {
        $qb = $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.user = :user')
            ->setParameter('user', $user);

        $this->applyLabelFilter($qb, $labelFilter);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Apply the optional label filter to a query builder (alias 'w').
     * See findForUserPaginated for the $labelFilter semantics.
     *
     * @param \Doctrine\ORM\QueryBuilder $qb
     */
    private function applyLabelFilter(\Doctrine\ORM\QueryBuilder $qb, int|null|false $labelFilter): void
    {
        if ($labelFilter === false) {
            return;
        }
        if ($labelFilter === null) {
            $qb->andWhere('w.label IS NULL');
            return;
        }
        $qb->andWhere('w.label = :labelId')->setParameter('labelId', $labelFilter);
    }

    /**
     * The existing entry for (user, product), or null. Used to make
     * POST idempotent: if a row already exists we no-op instead of
     * inserting a duplicate.
     */
    public function findOneForUserAndProduct(User $user, Product $product): ?Wishlist
    {
        return $this->createQueryBuilder('w')
            ->where('w.user = :user')
            ->andWhere('w.product = :product')
            ->setParameter('user', $user)
            ->setParameter('product', $product)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Per-label saved-product counts for the user, as a map of
     * labelId → count. Uncategorized rows (label IS NULL) are not
     * included (they have no label id to key on). One grouped query.
     *
     * @return array<int,int>
     */
    public function countsByLabelForUser(User $user): array
    {
        /** @var list<array{labelId:int|string|null,cnt:int|string}> $rows */
        $rows = $this->createQueryBuilder('w')
            ->select('IDENTITY(w.label) AS labelId', 'COUNT(w.id) AS cnt')
            ->where('w.user = :user')
            ->andWhere('w.label IS NOT NULL')
            ->setParameter('user', $user)
            ->groupBy('w.label')
            ->getQuery()
            ->getResult();

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row['labelId']] = (int) $row['cnt'];
        }
        return $map;
    }

    public function save(Wishlist $entry, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($entry);
        if ($flush) {
            $em->flush();
        }
    }

    public function remove(Wishlist $entry, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->remove($entry);
        if ($flush) {
            $em->flush();
        }
    }
}
