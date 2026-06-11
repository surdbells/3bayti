<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Support;

use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<Ticket> */
class TicketRepository extends EntityRepository
{
    public function save(Ticket $ticket): void
    {
        $em = $this->getEntityManager();
        $em->persist($ticket);
        $em->flush();
    }

    /**
     * @return array{items: list<Ticket>, total: int}
     */
    public function findPaginated(
        int $limit = 20,
        int $offset = 0,
        ?string $status = null,
        ?string $priority = null,
        ?int $vendorId = null,
    ): array {
        $qb = $this->createQueryBuilder('t')
            ->orderBy('t.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if ($status !== null) {
            $qb->andWhere('t.status = :status')->setParameter('status', $status);
        }
        if ($priority !== null) {
            $qb->andWhere('t.priority = :priority')->setParameter('priority', $priority);
        }
        if ($vendorId !== null) {
            $qb->andWhere('t.vendorId = :vid')->setParameter('vid', $vendorId);
        }

        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(t.id)')->resetDQLPart('orderBy')->setMaxResults(null)->setFirstResult(0)->getQuery()->getSingleScalarResult();

        /** @var list<Ticket> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
