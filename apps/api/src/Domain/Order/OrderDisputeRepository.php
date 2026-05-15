<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Order;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<OrderDispute>
 */
class OrderDisputeRepository extends EntityRepository
{
    public function save(OrderDispute $dispute): void
    {
        $em = $this->getEntityManager();
        $em->persist($dispute);
        $em->flush();
    }

    /**
     * Webhook idempotency: look up by Noon's dispute id. If found,
     * the webhook handler either updates the row or no-ops (instead
     * of creating a duplicate).
     */
    public function findByProviderDisputeId(string $providerDisputeId): ?OrderDispute
    {
        if ($providerDisputeId === '') {
            return null;
        }
        return $this->findOneBy(['providerDisputeId' => $providerDisputeId]);
    }

    /**
     * Admin disputes list. Newest first; optional status filter.
     *
     * @param ?string $status Restrict to a specific OrderDispute status.
     *                         Null = all statuses.
     * @return array{0: list<OrderDispute>, 1: int} Tuple of [disputes, total].
     */
    public function paginated(int $limit, int $offset, ?string $status = null): array
    {
        $limit = max(1, min($limit, 100));
        $offset = max(0, $offset);

        $totalQb = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)');
        if ($status !== null) {
            $totalQb->where('d.status = :status')
                ->setParameter('status', $status);
        }
        $total = (int) $totalQb->getQuery()->getSingleScalarResult();

        if ($total === 0) {
            return [[], 0];
        }

        $qb = $this->createQueryBuilder('d')
            ->orderBy('d.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);
        if ($status !== null) {
            $qb->where('d.status = :status')
                ->setParameter('status', $status);
        }
        /** @var list<OrderDispute> $result */
        $result = $qb->getQuery()->getResult();

        return [$result, $total];
    }
}
