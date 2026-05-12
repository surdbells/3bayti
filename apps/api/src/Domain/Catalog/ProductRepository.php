<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<Product>
 */
class ProductRepository extends EntityRepository
{
    public function save(Product $product, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($product);
        if ($flush) {
            $em->flush();
        }
    }

    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $qb = $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.slug = :slug')
            ->setParameter('slug', $slug);

        if ($excludeId !== null) {
            $qb->andWhere('p.id != :excludeId')->setParameter('excludeId', $excludeId);
        }

        return (int) $qb->getQuery()->getSingleScalarResult() > 0;
    }

    public function findBySlug(string $slug): ?Product
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    public function findByLegacyId(int $legacyId): ?Product
    {
        return $this->findOneBy(['legacyProductId' => $legacyId]);
    }

    /**
     * Paginated active products with filters.
     *
     * @param array{
     *     vendorId?: int|null,
     *     categoryId?: int|null,
     *     minPrice?: string|null,
     *     maxPrice?: string|null,
     *     isFeatured?: bool|null,
     *     isNew?: bool|null,
     *     isSale?: bool|null,
     *     sort?: string,
     *     limit?: int,
     *     offset?: int,
     * } $filters
     *
     * @return array{items: list<Product>, total: int}
     */
    public function findActivePaginated(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.isActive = TRUE');

        if (!empty($filters['vendorId'])) {
            $qb->andWhere('p.vendor = :vendorId')->setParameter('vendorId', $filters['vendorId']);
        }
        if (!empty($filters['categoryId'])) {
            $qb->andWhere('p.category = :categoryId')->setParameter('categoryId', $filters['categoryId']);
        }
        if (!empty($filters['minPrice'])) {
            $qb->andWhere('p.price >= :minPrice')->setParameter('minPrice', $filters['minPrice']);
        }
        if (!empty($filters['maxPrice'])) {
            $qb->andWhere('p.price <= :maxPrice')->setParameter('maxPrice', $filters['maxPrice']);
        }
        if (!empty($filters['isFeatured'])) {
            $qb->andWhere('p.isFeatured = TRUE');
        }
        if (!empty($filters['isNew'])) {
            $qb->andWhere('p.isNew = TRUE');
        }
        if (!empty($filters['isSale'])) {
            $qb->andWhere('p.isSale = TRUE');
        }

        // Total count for pagination (before limit/offset).
        $countQb = clone $qb;
        $countQb->select('COUNT(p.id)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        // Sort handling
        $sort = $filters['sort'] ?? 'newest';
        match ($sort) {
            'price_asc' => $qb->orderBy('p.price', 'ASC'),
            'price_desc' => $qb->orderBy('p.price', 'DESC'),
            'oldest' => $qb->orderBy('p.createdAt', 'ASC'),
            'newest' => $qb->orderBy('p.createdAt', 'DESC'),
            default => $qb->orderBy('p.createdAt', 'DESC'),
        };

        // Always have a stable tie-break by id so paginated results are deterministic.
        $qb->addOrderBy('p.id', 'DESC');

        $qb->setMaxResults($filters['limit'] ?? 24)
           ->setFirstResult($filters['offset'] ?? 0);

        /** @var list<Product> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }
}
