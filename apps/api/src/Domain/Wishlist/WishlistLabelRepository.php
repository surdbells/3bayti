<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Wishlist;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for WishlistLabel entities.
 *
 * @extends EntityRepository<WishlistLabel>
 */
class WishlistLabelRepository extends EntityRepository
{
    /**
     * The user's labels, oldest first (creation order — stable for UI).
     *
     * @return list<WishlistLabel>
     */
    public function findForUser(User $user): array
    {
        /** @var list<WishlistLabel> $rows */
        $rows = $this->createQueryBuilder('l')
            ->where('l.user = :user')
            ->setParameter('user', $user)
            ->orderBy('l.createdAt', 'ASC')
            ->addOrderBy('l.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** A label owned by the user, by id, or null. */
    public function findOneForUser(User $user, int $id): ?WishlistLabel
    {
        return $this->createQueryBuilder('l')
            ->where('l.user = :user')
            ->andWhere('l.id = :id')
            ->setParameter('user', $user)
            ->setParameter('id', $id)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** A label owned by the user, by (case-sensitive) name, or null. */
    public function findOneForUserByName(User $user, string $name): ?WishlistLabel
    {
        return $this->createQueryBuilder('l')
            ->where('l.user = :user')
            ->andWhere('l.name = :name')
            ->setParameter('user', $user)
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(WishlistLabel $label, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($label);
        if ($flush) {
            $em->flush();
        }
    }

    public function remove(WishlistLabel $label, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->remove($label);
        if ($flush) {
            $em->flush();
        }
    }
}
