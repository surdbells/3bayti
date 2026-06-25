<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<VendorApplication>
 */
class VendorApplicationRepository extends EntityRepository
{
    public function save(VendorApplication $application, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($application);
        if ($flush) {
            $em->flush();
        }
    }

    public function findById(int $id): ?VendorApplication
    {
        return $this->find($id);
    }

    /**
     * The most recent PENDING application for an email (lowercased),
     * or null. Used by the public submit to dedupe — a second submit
     * from the same email while one is still pending returns an
     * idempotent response rather than creating a duplicate row.
     */
    public function findPendingByEmail(string $email): ?VendorApplication
    {
        $email = strtolower(trim($email));
        return $this->createQueryBuilder('a')
            ->where('a.email = :email')
            ->andWhere('a.status = :pending')
            ->setParameter('email', $email)
            ->setParameter('pending', VendorApplication::STATUS_PENDING)
            ->orderBy('a.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Paginated admin listing. Optional status filter (pending|approved|
     * rejected); unknown values are ignored. Newest first. Returns the
     * page of entities plus the total count for the envelope.
     *
     * @return array{0: list<VendorApplication>, 1: int}
     */
    public function findPaginatedForAdmin(int $limit, int $offset, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('a');
        if ($status !== null && in_array($status, VendorApplication::ALL_STATUSES, true)) {
            $qb->andWhere('a.status = :status')->setParameter('status', $status);
        }

        $qb->orderBy('a.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        /** @var list<VendorApplication> $list */
        $list = $qb->getQuery()->getResult();

        $countQb = $this->createQueryBuilder('a')->select('COUNT(a.id)');
        if ($status !== null && in_array($status, VendorApplication::ALL_STATUSES, true)) {
            $countQb->andWhere('a.status = :status')->setParameter('status', $status);
        }
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        return [$list, $total];
    }
}
