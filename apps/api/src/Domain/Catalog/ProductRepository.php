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
     * Resolve a product from the integer id mobile cards carry.
     *
     * Mobile catalog cards are built by `legacyProductCardFromV3List`
     * (apps/mobile catalog-response.transforms.ts), which sets
     * `product_id = legacy_product_id ?? v3 id` — i.e. the LEGACY id for
     * any migrated product, falling back to the v3 primary key only for
     * v3-native products with no legacy row. Endpoints that take that
     * card id (wishlist add/move/remove) must therefore resolve in the
     * SAME precedence, or migrated products 404 (or, worse, a legacy id
     * collides with some other product's v3 PK and matches the wrong
     * product).
     *
     * Resolution order mirrors the card exactly: legacy id first, then
     * fall back to the v3 primary key. Returns null when neither matches.
     */
    public function findByIdOrLegacyId(int $id): ?Product
    {
        if ($id <= 0) {
            return null;
        }

        return $this->findByLegacyId($id) ?? $this->find($id);
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
     *     seed?: int|null,
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
            // ON SALE is PRICE-BASED, not a standalone flag: a product is
            // "on sale" only when it carries a sale_price that is present
            // AND strictly below its regular price. This matches the
            // operator's definition used everywhere (mobile discounted page,
            // card sale rendering) — the legacy `is_sale` boolean could be
            // set independently of the prices and so returned products that
            // weren't genuinely discounted.
            $qb->andWhere('p.salePrice IS NOT NULL AND p.salePrice < p.price');
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
            // Storefront search (SEARCH #4). Case-insensitive SUBSTRING
            // match across four columns so the search is both meaningful
            // and responsive to short / prefix queries:
            //   - product name
            //   - product description
            //   - store / vendor name      (v is already inner-joined above)
            //   - category name            (left-joined here; category is
            //     nullable, so an inner join would drop uncategorised
            //     products from EVERY listing, not just searches)
            //
            // Why substring (LOWER(col) LIKE '%term%') instead of the
            // previous TSMATCH/websearch_to_tsquery: tsquery only matches
            // whole stemmed lexemes, so 2-character queries ("ab", "si")
            // returned nothing. Mobile fires search from 2 chars, so that
            // was the common case. Substring match handles prefix, infix
            // and 2-char input. Backed by pg_trgm trigram GIN indexes
            // (Version20260622000003) on LOWER(...) of each column so the
            // planner can accelerate these for >= 3-char terms.
            //
            // LOWER(col) LIKE :term is the DQL-portable form (mirrors the
            // existing findForVendorPaginated search). The term is lower-
            // cased and LIKE wildcards escaped so user input like "50%"
            // can't act as a wildcard.
            $term = strtolower(trim((string) $searchQuery));
            $term = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term);

            // category is nullable; LEFT JOIN keeps non-search behaviour
            // identical while still letting us match on category name.
            $qb->leftJoin('p.category', 'sc');

            // Single raw-DQL OR predicate (rather than $qb->expr()->orX(),
            // whose Orx-into-Andx composition Doctrine can silently drop in
            // some builder states). A string predicate is always appended.
            // ESCAPE '\' so the wildcards we escaped in $term are treated
            // literally.
            // No explicit `ESCAPE '\'` clause: PostgreSQL's LIKE already uses
            // backslash as its default escape char, and $term has its wildcards
            // backslash-escaped above, so matching is identical. The literal
            // ESCAPE '\' inside this raw-DQL predicate corrupted the cloned
            // COUNT query's DQL->SQL parameter mapping — Postgres rejected every
            // search with HY093 "parameter was not defined". Distinct param
            // names per column are kept (one bound value per placeholder).
            $like = '%' . $term . '%';
            $qb->andWhere(
                "("
                . "LOWER(p.name) LIKE :stName OR "
                . "LOWER(p.description) LIKE :stDesc OR "
                . "LOWER(v.name) LIKE :stVendor OR "
                . "LOWER(sc.name) LIKE :stCat"
                . ")"
            )
            ->setParameter('stName', $like)
            ->setParameter('stDesc', $like)
            ->setParameter('stVendor', $like)
            ->setParameter('stCat', $like);
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

        // Seeded random order (EXPLORE feed). When a non-null `seed` is
        // supplied, the listing is presented in a deterministic per-seed
        // shuffle instead of the normal `sort` order, using
        //   ORDER BY md5(<seed> || '-' || id)
        // (SEEDED_RAND DQL function). The md5 key is a pure function of
        // (seed, id), so for a fixed seed the total order over the whole
        // result set is fixed — LIMIT/OFFSET then walk that single stable
        // order and infinite scroll never duplicates or skips a product.
        // Change the seed and the whole order reshuffles.
        //
        // This is a distinct ordering MODE: it overrides `sort` (including
        // best_seller / relevance) so it never collides with the
        // groupBy(p.id) / addSelect branches below. The controller passes
        // the seed as a normalized non-negative int; here it is bound as a
        // string so Postgres' `||` (text concat) sees a text operand.
        $seed = $filters['seed'] ?? null;
        if ($seed !== null) {
            $qb->orderBy('SEEDED_RAND(:seed, p.id)', 'ASC')
               ->setParameter('seed', (string) $seed);
            // Stable id tie-break (md5 collisions are astronomically
            // unlikely, but keep pagination fully deterministic anyway).
            $qb->addOrderBy('p.id', 'DESC');

            $qb->setMaxResults($filters['limit'] ?? 24)
               ->setFirstResult($filters['offset'] ?? 0);

            /** @var list<Product> $seededItems */
            $seededItems = $qb->getQuery()->getResult();

            return ['items' => $seededItems, 'total' => $total];
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
        } elseif ($sort === 'relevance') {
            // Relevance for the substring-based search (SEARCH #4).
            //
            // The previous implementation ranked by TSRANK against
            // products.search_tsv, but the search is no longer tsvector-
            // based and TSRANK yields 0 for short / prefix queries (the
            // exact queries this overhaul fixes), so it would not order
            // them at all. Instead we rank by WHERE the term hit:
            // a product-name match is the strongest signal, so those sort
            // first; everything else (description / store / category
            // matches) falls back to newest. :searchTerm is the same
            // bound, escaped, lower-cased '%term%' used by the WHERE
            // clause above (guaranteed present — relevance only survives
            // when $hasSearch is true).
            // Own param (:stRel) so no named parameter is ever used twice —
            // keeps every placeholder a single bound value (see HY093 note above).
            $qb->addSelect(
                "CASE WHEN LOWER(p.name) LIKE :stRel THEN 0 ELSE 1 END AS HIDDEN relevanceRank"
            )->setParameter('stRel', $like);
            $qb->orderBy('relevanceRank', 'ASC');
            $qb->addOrderBy('p.createdAt', 'DESC');
        } else {
            match ($sort) {
                'price_asc' => $qb->orderBy('p.price', 'ASC'),
                'price_desc' => $qb->orderBy('p.price', 'DESC'),
                'oldest' => $qb->orderBy('p.createdAt', 'ASC'),
                'newest' => $qb->orderBy('p.createdAt', 'DESC'),
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
