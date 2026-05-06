<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

use Doctrine\ORM\EntityRepository;

/**
 * Repository for Address entities.
 *
 * @extends EntityRepository<Address>
 */
class AddressRepository extends EntityRepository
{
    /** @return Address[] */
    public function findAllForUser(User $user): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->setParameter('user', $user)
            ->orderBy('a.isDefaultShipping', 'DESC')
            ->addOrderBy('a.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    public function findDefaultShippingForUser(User $user): ?Address
    {
        return $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->andWhere('a.isDefaultShipping = TRUE')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findDefaultBillingForUser(User $user): ?Address
    {
        return $this->createQueryBuilder('a')
            ->where('a.user = :user')
            ->andWhere('a.isDefaultBilling = TRUE')
            ->setParameter('user', $user)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findByLegacyId(int $legacyAddressId): ?Address
    {
        return $this->createQueryBuilder('a')
            ->where('a.legacyAddressId = :legacyId')
            ->setParameter('legacyId', $legacyAddressId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Set this address as the user's default shipping, unsetting
     * the previous default. Both ops happen in one transaction so
     * the user can never have two defaults.
     */
    public function setAsDefaultShipping(Address $address): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($address, $em): void {
            // Unset existing default.
            $em->createQuery(
                'UPDATE ' . Address::class . ' a SET a.isDefaultShipping = FALSE
                 WHERE a.user = :user AND a.id != :id'
            )
                ->setParameter('user', $address->getUser())
                ->setParameter('id', $address->getId())
                ->execute();

            // Set new default.
            $address->setDefaultShipping(true);
            $em->persist($address);
        });
    }

    public function setAsDefaultBilling(Address $address): void
    {
        $em = $this->getEntityManager();
        $em->wrapInTransaction(function () use ($address, $em): void {
            $em->createQuery(
                'UPDATE ' . Address::class . ' a SET a.isDefaultBilling = FALSE
                 WHERE a.user = :user AND a.id != :id'
            )
                ->setParameter('user', $address->getUser())
                ->setParameter('id', $address->getId())
                ->execute();

            $address->setDefaultBilling(true);
            $em->persist($address);
        });
    }

    public function save(Address $address, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($address);
        if ($flush) {
            $em->flush();
        }
    }

    public function remove(Address $address, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->remove($address);
        if ($flush) {
            $em->flush();
        }
    }
}
