<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Following;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for VendorFollow entities.
 *
 * @extends EntityRepository<VendorFollow>
 */
class VendorFollowRepository extends EntityRepository
{
    /**
     * The existing follow for (user, vendor), or null. Used to make
     * POST idempotent and DELETE a clean no-op: if a row already exists
     * we no-op instead of inserting a duplicate (the unique index is the
     * backstop against races).
     */
    public function findOneForUserAndVendor(User $user, Vendor $vendor): ?VendorFollow
    {
        return $this->createQueryBuilder('f')
            ->where('f.user = :user')
            ->andWhere('f.vendor = :vendor')
            ->setParameter('user', $user)
            ->setParameter('vendor', $vendor)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /** Whether the user currently follows the vendor (single existence check). */
    public function isFollowing(User $user, Vendor $vendor): bool
    {
        $count = (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.user = :user')
            ->andWhere('f.vendor = :vendor')
            ->setParameter('user', $user)
            ->setParameter('vendor', $vendor)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    /**
     * The vendors a user follows, newest first, paginated. Joins the
     * vendor so the serializer can read it without N+1 lazy loads.
     *
     * @return list<VendorFollow>
     */
    public function findForUserPaginated(User $user, int $limit, int $offset): array
    {
        /** @var list<VendorFollow> $rows */
        $rows = $this->createQueryBuilder('f')
            ->addSelect('v')
            ->innerJoin('f.vendor', 'v')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->orderBy('f.createdAt', 'DESC')
            ->addOrderBy('f.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** Total vendors the user follows (for pagination meta). */
    public function countForUser(User $user): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.user = :user')
            ->setParameter('user', $user)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * The users who follow a vendor, for fanning out a store notification
     * (new-product alert). Joins the user eagerly so the caller reads it +
     * its device tokens without an N+1. Bounded by limit/offset so a store
     * with a huge following is paged rather than loaded all at once.
     *
     * @return list<User>
     */
    public function findFollowersOfVendor(Vendor $vendor, int $limit, int $offset = 0): array
    {
        /** @var list<VendorFollow> $rows */
        $rows = $this->createQueryBuilder('f')
            ->addSelect('u')
            ->innerJoin('f.user', 'u')
            ->where('f.vendor = :vendor')
            ->setParameter('vendor', $vendor)
            ->orderBy('f.id', 'ASC')
            ->setMaxResults(max(1, $limit))
            ->setFirstResult(max(0, $offset))
            ->getQuery()
            ->getResult();

        return array_map(static fn (VendorFollow $f): User => $f->getUser(), $rows);
    }

    /** Total followers of a vendor (so the cron can log when it caps a fan-out). */
    public function countFollowersOfVendor(Vendor $vendor): int
    {
        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.vendor = :vendor')
            ->setParameter('vendor', $vendor)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(VendorFollow $entry, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($entry);
        if ($flush) {
            $em->flush();
        }
    }

    public function remove(VendorFollow $entry, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->remove($entry);
        if ($flush) {
            $em->flush();
        }
    }
}
