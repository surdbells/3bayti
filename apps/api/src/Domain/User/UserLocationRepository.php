<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Doctrine\ORM\EntityRepository;

/**
 * Repository for UserLocation entities.
 *
 * Single-row-per-user model: every query method here returns at most
 * one row per user. The DB-level UNIQUE constraint on user_id enforces
 * this invariant.
 *
 * @extends EntityRepository<UserLocation>
 */
class UserLocationRepository extends EntityRepository
{
    /**
     * Returns the user's current location row, or null if they've
     * never set one.
     */
    public function findForUser(User $user): ?UserLocation
    {
        return $this->createQueryBuilder('l')
            ->where('l.user = :user')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(UserLocation $location, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($location);
        if ($flush) {
            $em->flush();
        }
    }
}
