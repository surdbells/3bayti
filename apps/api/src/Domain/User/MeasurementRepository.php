<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Doctrine\ORM\EntityRepository;

/**
 * Repository for Measurement entities.
 *
 * @extends EntityRepository<Measurement>
 */
class MeasurementRepository extends EntityRepository
{
    /**
     * Get all measurement rows for a user. Most users have 0 or 1;
     * some have multiple (one per category).
     *
     * @return Measurement[]
     */
    public function findAllForUser(User $user): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.user = :user')
            ->setParameter('user', $user)
            ->orderBy('m.categoryId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get the user's measurement for a specific category, or the
     * default (categoryId IS NULL) if no category-specific row
     * exists. Used by checkout to populate size pickers.
     */
    public function findForUserAndCategory(User $user, ?int $categoryId): ?Measurement
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.user = :user')
            ->setParameter('user', $user);

        if ($categoryId === null) {
            $qb->andWhere('m.categoryId IS NULL');
        } else {
            $qb->andWhere('m.categoryId = :catId')
               ->setParameter('catId', $categoryId);
        }

        return $qb->setMaxResults(1)->getQuery()->getOneOrNullResult();
    }

    /**
     * Get the default (categoryId IS NULL) measurement row for a user.
     * This is what most callers want, the catch-all measurements
     * that apply when no category-specific row exists.
     */
    public function findDefaultForUser(User $user): ?Measurement
    {
        return $this->findForUserAndCategory($user, null);
    }

    public function save(Measurement $measurement, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($measurement);
        if ($flush) {
            $em->flush();
        }
    }

    public function remove(Measurement $measurement, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->remove($measurement);
        if ($flush) {
            $em->flush();
        }
    }
}
