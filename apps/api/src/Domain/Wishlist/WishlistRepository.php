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
     * @return list<Wishlist>
     */
    public function findForUserPaginated(User $user, int $limit, int $offset): array
    {
        /** @var list<Wishlist> $rows */
        $rows = $this->createQueryBuilder('w')
            ->addSelect('p')
            ->innerJoin('w.product', 'p')
            ->where('w.user = :user')
            ->setParameter('user', $user)
            ->orderBy('w.createdAt', 'DESC')
            ->addOrderBy('w.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** Total saved entries for the user (for pagination meta). */
    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
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
