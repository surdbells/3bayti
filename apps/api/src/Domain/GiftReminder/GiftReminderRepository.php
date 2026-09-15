<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\GiftReminder;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<GiftReminder>
 */
class GiftReminderRepository extends EntityRepository
{
    /** @return GiftReminder[] */
    public function findAllForUser(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->orderBy('r.remindDate', 'ASC')
            ->addOrderBy('r.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(GiftReminder $reminder, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($reminder);
        if ($flush) {
            $em->flush();
        }
    }

    public function remove(GiftReminder $reminder, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->remove($reminder);
        if ($flush) {
            $em->flush();
        }
    }
}
