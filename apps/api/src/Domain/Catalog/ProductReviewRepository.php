<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Domain\User\User;
use Doctrine\ORM\EntityRepository;

/**
 * Repository for ProductReview entities.
 *
 * @extends EntityRepository<ProductReview>
 */
class ProductReviewRepository extends EntityRepository
{
    /**
     * The review this user has already left for this product, or null.
     * Used to make "add review" an upsert (one review per user+product)
     * rather than letting a customer spam many rows.
     */
    public function findOneForUserAndProduct(User $user, Product $product): ?ProductReview
    {
        return $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.product = :product')
            ->setParameter('user', $user)
            ->setParameter('product', $product)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * The user's own reviews (any status), newest first, paginated.
     *
     * @return array{items: list<ProductReview>, total: int}
     */
    public function findForUserPaginated(User $user, int $limit, int $offset): array
    {
        return $this->paginate(
            fn ($qb) => $qb->where('r.user = :user')->setParameter('user', $user),
            $limit,
            $offset,
        );
    }

    /**
     * Public (approved) reviews for one product, newest first, paginated.
     *
     * @return array{items: list<ProductReview>, total: int}
     */
    public function findApprovedForProductPaginated(Product $product, int $limit, int $offset): array
    {
        return $this->paginate(
            fn ($qb) => $qb
                ->where('r.product = :product')
                ->andWhere('r.status = :status')
                ->setParameter('product', $product)
                ->setParameter('status', ProductReview::STATUS_APPROVED),
            $limit,
            $offset,
        );
    }

    /**
     * Public (approved) reviews across all of a vendor's products, newest
     * first, paginated.
     *
     * @return array{items: list<ProductReview>, total: int}
     */
    public function findApprovedForVendorPaginated(Vendor $vendor, int $limit, int $offset): array
    {
        return $this->paginate(
            fn ($qb) => $qb
                ->where('r.vendor = :vendor')
                ->andWhere('r.status = :status')
                ->setParameter('vendor', $vendor)
                ->setParameter('status', ProductReview::STATUS_APPROVED),
            $limit,
            $offset,
        );
    }

    /**
     * Shared paginate helper: applies $where to a base query (alias 'r',
     * ordered newest-first) and returns items + total in two queries.
     *
     * @param callable(\Doctrine\ORM\QueryBuilder):\Doctrine\ORM\QueryBuilder $where
     * @return array{items: list<ProductReview>, total: int}
     */
    private function paginate(callable $where, int $limit, int $offset): array
    {
        $qb = $where($this->createQueryBuilder('r'))
            ->orderBy('r.id', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        /** @var list<ProductReview> $items */
        $items = $qb->getQuery()->getResult();

        $countQb = clone $qb;
        $total   = (int) $countQb
            ->select('COUNT(r.id)')
            ->resetDQLPart('orderBy')
            ->setMaxResults(null)
            ->setFirstResult(0)
            ->getQuery()
            ->getSingleScalarResult();

        return ['items' => $items, 'total' => $total];
    }

    public function save(ProductReview $review, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($review);
        if ($flush) {
            $em->flush();
        }
    }

    public function remove(ProductReview $review, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->remove($review);
        if ($flush) {
            $em->flush();
        }
    }
}
