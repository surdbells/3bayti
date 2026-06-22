<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
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
    /**
     * Vendor-owned product list for the seller's own catalog management.
     *
     * Unlike findActivePaginated (storefront — active products only), this
     * returns the vendor's products in ALL states (draft, inactive,
     * out-of-stock) so the vendor can see and edit everything they own.
     * Scoped strictly to the given v3 vendor id.
     *
     * @param array{vendorId:int, limit?:int, offset?:int, search?:string} $filters
     * @return array{items: list<Product>, total: int}
     */
    /**
     * A single product owned by the vendor, regardless of status (draft,
     * inactive, etc.) — for the vendor's own detail/preview view.
     */
    public function findOneByIdForVendor(int $id, int $vendorId): ?Product
    {
        return $this->createQueryBuilder('p')
            ->where('p.id = :id')->andWhere('p.vendor = :vid')
            ->setParameter('id', $id)->setParameter('vid', $vendorId)
            ->getQuery()->getOneOrNullResult();
    }

    public function findForVendorPaginated(array $filters): array
    {
        $limit  = max(1, min(100, (int) ($filters['limit'] ?? 24)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $qb = $this->createQueryBuilder('p')
            ->where('p.vendor = :vendorId')
            ->setParameter('vendorId', $filters['vendorId']);

        $search = $filters['search'] ?? null;
        if (is_string($search) && trim($search) !== '') {
            $qb->andWhere('LOWER(p.name) LIKE :q')
               ->setParameter('q', '%' . strtolower(trim($search)) . '%');
        }

        $status = $filters['status'] ?? null;
        if (is_string($status) && $status !== '') {
            $qb->andWhere('p.status = :status')->setParameter('status', $status);
        }

        $stockStatus = $filters['stock_status'] ?? null;
        if (is_string($stockStatus) && $stockStatus !== '') {
            $qb->andWhere('p.stockStatus = :stockStatus')->setParameter('stockStatus', $stockStatus);
        }

        $categoryId = $filters['category_id'] ?? null;
        if ($categoryId !== null && (int) $categoryId > 0) {
            $qb->andWhere('IDENTITY(p.category) = :categoryId')->setParameter('categoryId', (int) $categoryId);
        }

        $priceMin = $filters['price_min'] ?? null;
        if ($priceMin !== null && $priceMin !== '') {
            $qb->andWhere('p.price >= :priceMin')->setParameter('priceMin', (float) $priceMin);
        }

        $priceMax = $filters['price_max'] ?? null;
        if ($priceMax !== null && $priceMax !== '') {
            $qb->andWhere('p.price <= :priceMax')->setParameter('priceMax', (float) $priceMax);
        }

        $countQb = clone $qb;
        $countQb->select('COUNT(p.id)');
        $total = (int) $countQb->getQuery()->getSingleScalarResult();

        $items = $qb->orderBy('p.createdAt', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset)
            ->getQuery()
            ->getResult();

        return ['items' => $items, 'total' => $total];
    }

    public function findActivePaginated(array $filters = []): array
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.isActive = TRUE')
            // Storefront gating: only surface products whose vendor is
            // both active AND admin-approved. A pending/suspended vendor's
            // products must never leak onto public listings (GET /v3/products,
            // category, vendor storefront, featured embedded products).
            // The inner join is compatible with the best_seller branch's
            // groupBy(p.id) + LEFT JOIN OrderItem below.
            ->innerJoin('p.vendor', 'v')
            ->andWhere('v.isActive = TRUE')
            ->andWhere('v.status = :vendorApproved')
            ->setParameter('vendorApproved', Vendor::STATUS_APPROVED);

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

        // Refining filters: sizes, colors. JSONB "contains ANY of the
        // requested values" — same semantics FacetAggregator applies, so
        // the listing and the facet counts agree. JSONB_EXISTS_ANY is a
        // custom DQL function (config/doctrine.php) wrapping
        // `jsonb_exists_any(<col>, array[:values]::text[])`; the params are
        // bound with ArrayParameterType::STRING so Doctrine expands the
        // placeholder into the array literal. Compared to TRUE because DQL
        // has no boolean function category (mirrors the TSMATCH pattern).
        if (!empty($filters['sizes']) && is_array($filters['sizes'])) {
            $qb->andWhere('JSONB_EXISTS_ANY(p.availableSizes, :sizes) = TRUE')
               ->setParameter('sizes', $filters['sizes'], \Doctrine\DBAL\ArrayParameterType::STRING);
        }
        if (!empty($filters['colors']) && is_array($filters['colors'])) {
            $qb->andWhere('JSONB_EXISTS_ANY(p.availableColors, :colors) = TRUE')
               ->setParameter('colors', $filters['colors'], \Doctrine\DBAL\ArrayParameterType::STRING);
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

        // In-stock filter (Stores #4): mirrors the serializer's `in_stock`
        // boolean (stock_quantity > 0 OR allow_oversell) so a filtered
        // list matches exactly what the storefront renders as available.
        // Opt-in — existing callers that omit it are unaffected.
        if (!empty($filters['inStock'])) {
            $qb->andWhere('(p.stockQuantity > 0 OR p.allowOversell = TRUE)');
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
        // 'best_seller' (M3.2.X.1) needs a LEFT JOIN onto an aggregate
        // of order_items filtered by:
        //   - orders.status IN (paid, fulfilling, shipped, delivered)
        //     [excludes pending_payment / failed / cancelled / refunded —
        //     per locked Q-Order-Status = B]
        //   - orders.paid_at >= NOW() - INTERVAL '30 days'
        //     [last-30-days window per locked Q-Window = B; paid_at
        //     is the right anchor because orders may sit in
        //     pending_payment for days before they pay]
        //
        // Products with zero in-window sales still appear (LEFT JOIN +
        // COALESCE(SUM, 0)), ranked last. Tie-break: created_at DESC
        // then id DESC (already in place below).
        //
        // Performance: order_items has an index on (product_id); orders
        // is keyed on (status, paid_at) via M3.1.6 migration. EXPLAIN
        // ANALYZE on staging-sized data (~2k products, ~few-k orders)
        // shows <50ms. If contention surfaces at scale, M4 considers a
        // materialized view.
        if ($sort === 'best_seller') {
            $qb->leftJoin(
                OrderItem::class,
                'oi',
                'WITH',
                'oi.product = p AND EXISTS (
                    SELECT 1 FROM ' . Order::class . ' o
                    WHERE o = oi.order
                      AND o.status IN (:bsStatuses)
                      AND o.paidAt >= :bsWindow
                )',
            );
            $qb->setParameter('bsStatuses', [
                Order::STATUS_PAID,
                Order::STATUS_FULFILLING,
                Order::STATUS_SHIPPED,
                Order::STATUS_DELIVERED,
            ]);
            $qb->setParameter('bsWindow', new \DateTimeImmutable('-30 days'));
            $qb->addSelect('COALESCE(SUM(oi.quantity), 0) AS HIDDEN bsSales');
            $qb->groupBy('p.id');
            $qb->orderBy('bsSales', 'DESC');
            // Tie-break by createdAt then id is applied via addOrderBy
            // below.
            $qb->addOrderBy('p.createdAt', 'DESC');
        } else {
            match ($sort) {
                'price_asc' => $qb->orderBy('p.price', 'ASC'),
                'price_desc' => $qb->orderBy('p.price', 'DESC'),
                'oldest' => $qb->orderBy('p.createdAt', 'ASC'),
                'newest' => $qb->orderBy('p.createdAt', 'DESC'),
                'relevance' => $qb->orderBy('TSRANK(p.searchTsv, :searchQuery)', 'DESC'),
                default => $qb->orderBy('p.createdAt', 'DESC'),
            };
        }

        // Always have a stable tie-break by id so paginated results are deterministic.
        $qb->addOrderBy('p.id', 'DESC');

        $qb->setMaxResults($filters['limit'] ?? 24)
           ->setFirstResult($filters['offset'] ?? 0);

        /** @var list<Product> $items */
        $items = $qb->getQuery()->getResult();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Raw product count for a category — NO isActive filter, NO
     * status filter, NO vendor approval filter. Just `WHERE category = :id`.
     *
     * Used by GET /v3/categories/:slug (M3.2.X.3-C) to populate
     * the `product_count` field on the CategoryDetail response.
     *
     * Apps/web distinguishes two counts semantically:
     *   - product_count (this method):
     *     Raw join count. Informational; reflects total category
     *     associations regardless of vendor/product status.
     *   - meta.total_products (findActivePaginated total):
     *     Filtered count. What apps/web displays to users — only
     *     active products from active vendors.
     *
     * Why a separate method
     * =====================
     * findActivePaginated includes the isActive filter inherently;
     * removing it would force a `$filters['includeInactive']` flag
     * that complicates every other call site. A purpose-built
     * count-only method is cleaner.
     *
     * Performance
     * ===========
     * COUNT-only query — no row materialization. Cheap even for
     * categories with thousands of products. Single integer
     * round-trip.
     */
    public function countByCategoryRaw(int $categoryId): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.category = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
