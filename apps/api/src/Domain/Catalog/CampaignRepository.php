<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use DateTimeImmutable;
use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<Campaign> */
class CampaignRepository extends EntityRepository
{
    public function save(Campaign $campaign): void
    {
        $em = $this->getEntityManager();
        $em->persist($campaign);
        $em->flush();
    }

    public function delete(Campaign $campaign): void
    {
        $em = $this->getEntityManager();
        $em->remove($campaign);
        $em->flush();
    }

    /**
     * The single highest-priority campaign of a type that is live right
     * now, with its items + each item's product eager-loaded.
     *
     * Two-step (id lookup → fetch-join load) avoids the Doctrine
     * setMaxResults-with-to-many-join hydration pitfall.
     */
    public function findActiveByType(string $type, DateTimeImmutable $now): ?Campaign
    {
        $id = $this->createQueryBuilder('c')
            ->select('c.id')
            ->where('c.type = :type')
            ->andWhere('c.isActive = true')
            ->andWhere('c.startsAt <= :now')
            ->andWhere('c.endsAt >= :now')
            ->setParameter('type', $type)
            ->setParameter('now', $now)
            ->orderBy('c.priority', 'ASC')
            ->addOrderBy('c.startsAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($id === null) {
            return null;
        }
        $campaignId = is_array($id) ? ($id['id'] ?? null) : $id;
        if ($campaignId === null) {
            return null;
        }

        /** @var Campaign|null $campaign */
        $campaign = $this->createQueryBuilder('c')
            ->addSelect('i', 'p')
            ->leftJoin('c.items', 'i')
            ->leftJoin('i.product', 'p')
            ->where('c.id = :id')
            ->setParameter('id', $campaignId)
            ->orderBy('i.sortOrder', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $campaign;
    }

    /**
     * Load one campaign by id with items + products eager-loaded
     * (admin detail / update).
     */
    public function findWithItems(int $id): ?Campaign
    {
        /** @var Campaign|null $campaign */
        $campaign = $this->createQueryBuilder('c')
            ->addSelect('i', 'p')
            ->leftJoin('c.items', 'i')
            ->leftJoin('i.product', 'p')
            ->where('c.id = :id')
            ->setParameter('id', $id)
            ->orderBy('i.sortOrder', 'ASC')
            ->addOrderBy('i.id', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();

        return $campaign;
    }

    /**
     * @return array{items: list<Campaign>, total: int}
     */
    public function findPaginated(int $limit = 20, int $offset = 0, ?string $type = null, ?bool $activeOnly = null): array
    {
        $qb = $this->createQueryBuilder('c')
            ->orderBy('c.priority', 'ASC')
            ->addOrderBy('c.startsAt', 'DESC')
            ->addOrderBy('c.id', 'DESC');

        if ($type !== null) {
            $qb->andWhere('c.type = :type')->setParameter('type', $type);
        }
        if ($activeOnly !== null) {
            $qb->andWhere('c.isActive = :a')->setParameter('a', $activeOnly);
        }

        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();

        $qb->setMaxResults($limit)->setFirstResult($offset);
        /** @var list<Campaign> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
