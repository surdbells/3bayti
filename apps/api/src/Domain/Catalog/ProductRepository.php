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
     *     labelId?: int|null,
     *     minPrice?: string|null,
     *     maxPrice?: string|null,
     *     isFeatured?: bool|null,
     *     isNew?: bool|null,
     *     isSale?: bool|null,
     *     sort?: string,
     *     searchQuery?: string|null,
     *     limit?: int,
     *     offset?: int,
     * } $filters
     *
     * Search semantics
     * ================
     * When `searchQuery` is a non-empty string, the query gains a
     * TSMATCH(p.searchTsv, :q) = TRUE clause that uses PostgreSQL's
     * `<col> @@ websearch_to_tsquery('english', :q)` operator.
     * `websearch_to_tsquery` is the user-input-friendly variant that
     * gracefully handles quoted phrases, OR/AND/NOT operators, and
     * arbitrary punctuation without throwing.
     *
     * When `sort` is 'relevance' AND a search query is supplied, the
     * primary ORDER BY becomes TSRANK(p.searchTsv, :q) DESC. The id
     * tie-break still applies.
     *
     * If 'relevance' is passed without a search query, falls back to
     * 'newest' (the no-search default) — ranking against no query
     * would produce zero values for all rows.
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
        if (!empty($filters['labelId'])) {
            // labelId is the v3 internal id; the controller resolves
            // any legacy_label_id input via VendorLabelRepository
            // before passing through.
            $qb->andWhere('p.labelId = :labelId')->setParameter('labelId', $filters['labelId']);
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

        $searchQuery = $filters['searchQuery'] ?? null;
        $hasSearch = is_string($searchQuery) && trim($searchQuery) !== '';
        if ($hasSearch) {
            $qb->andWhere('TSMATCH(p.searchTsv, :searchQuery) = TRUE')
               ->setParameter('searchQuery', trim((string) $searchQuery));
        }

        // Total count for pagination (before limit/offset).
        $countQb = clone $qb;
        $countQb->select('COUNT(p.id)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        // Sort handling
        $sort = $filters['sort'] ?? 'newest';
        // 'relevance' is only meaningful with a search query; fall
        // back to 'newest' otherwise so the result isn't a meaningless
        // "rank against nothing" sort.
        if ($sort === 'relevance' && !$hasSearch) {
            $sort = 'newest';
        }
        match ($sort) {
            'price_asc' => $qb->orderBy('p.price', 'ASC'),
            'price_desc' => $qb->orderBy('p.price', 'DESC'),
            'oldest' => $qb->orderBy('p.createdAt', 'ASC'),
            'newest' => $qb->orderBy('p.createdAt', 'DESC'),
            'relevance' => $qb->orderBy('TSRANK(p.searchTsv, :searchQuery)', 'DESC'),
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
