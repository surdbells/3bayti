<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;

/**
 * Repository for ProductRecommendation entities (M3.2.X.12-B).
 *
 * The hot read path is findTopForProduct(), single indexed
 * lookup serving the catalog "you might also like" endpoint.
 * The write path is used by the X.12-E cron command which
 * rebuilds recommendations nightly.
 *
 * @extends EntityRepository<ProductRecommendation>
 */
class ProductRecommendationRepository extends EntityRepository
{
    public function save(ProductRecommendation $rec): void
    {
        $em = $this->getEntityManager();
        $em->persist($rec);
        $em->flush();
    }

    /**
     * Find the top-N recommendations for a given product, ordered
     * by rank ASC. The composite (product_id, rank) index makes
     * this a single seek + range scan.
     *
     * Cron-populated rows already have rank pre-computed (1..N)
     * so the read path is trivial.
     *
     * @return list<ProductRecommendation>
     */
    public function findTopForProduct(int $productId, int $limit): array
    {
        /** @var list<ProductRecommendation> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('IDENTITY(r.product) = :pid')
            ->setParameter('pid', $productId)
            ->orderBy('r.rank', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
        return $rows;
    }

    /**
     * Find all recommendations for a given product (across all
     * sources), used by the X.12-G admin "explain" endpoint to
     * break down why each recommendation was made.
     *
     * @return list<ProductRecommendation>
     */
    public function findAllForProduct(int $productId): array
    {
        /** @var list<ProductRecommendation> $rows */
        $rows = $this->createQueryBuilder('r')
            ->andWhere('IDENTITY(r.product) = :pid')
            ->setParameter('pid', $productId)
            ->orderBy('r.rank', 'ASC')
            ->getQuery()
            ->getResult();
        return $rows;
    }

    /**
     * Delete all rows where (product_id, recommended_product_id)
     * matches anything in the supplied product_id batch. Used by
     * the X.12-E cron to clear stale rows before inserting the
     * fresh batch.
     *
     * Bulk DELETE is much faster than per-row entity removal.
     *
     * @param list<int> $productIds
     */
    public function deleteForProducts(array $productIds): int
    {
        if ($productIds === []) {
            return 0;
        }
        $qb = $this->createQueryBuilder('r')
            ->delete()
            ->where('IDENTITY(r.product) IN (:pids)')
            ->setParameter('pids', $productIds);
        /** @var int $affected */
        $affected = $qb->getQuery()->execute();
        return $affected;
    }

    /**
     * Count the rows for a given product. Used by X.12-F to detect
     * whether the empty-fallback path needs to run.
     */
    public function countForProduct(int $productId): int
    {
        $count = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('IDENTITY(r.product) = :pid')
            ->setParameter('pid', $productId)
            ->getQuery()
            ->getSingleScalarResult();
        return (int) $count;
    }
}
