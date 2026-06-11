<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;

/** @extends EntityRepository<ProductCollection> */
class ProductCollectionRepository extends EntityRepository
{
    public function save(ProductCollection $col): void
    {
        $em = $this->getEntityManager();
        $em->persist($col);
        $em->flush();
    }

    public function delete(ProductCollection $col): void
    {
        $em = $this->getEntityManager();
        $em->remove($col);
        $em->flush();
    }

    /**
     * @return array{items: list<ProductCollection>, total: int}
     */
    public function findPaginated(int $limit = 20, int $offset = 0, ?bool $activeOnly = null): array
    {
        $qb = $this->createQueryBuilder('c')->orderBy('c.displayOrder', 'ASC')->addOrderBy('c.id', 'DESC');
        if ($activeOnly !== null) {
            $qb->where('c.isActive = :a')->setParameter('a', $activeOnly);
        }
        $countQb = clone $qb;
        $total   = (int) $countQb->select('COUNT(c.id)')->resetDQLPart('orderBy')->getQuery()->getSingleScalarResult();
        $qb->setMaxResults($limit)->setFirstResult($offset);
        /** @var list<ProductCollection> $items */
        $items = $qb->getQuery()->getResult();
        return ['items' => $items, 'total' => $total];
    }
}
