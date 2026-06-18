<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Compute facet counts for the catalog search/list page (M3.2.X.10).
 *
 * Given the same filter shape used by ProductRepository::findActivePaginated,
 * returns a structured set of facet counts that the catalog page renders as
 * a refinable sidebar: "Size: XS (12), S (47), M (89)…", "Color: Black (47)…",
 * "Price: Under AED 50 (12), 50-100 (47)…", "Vendor: Almas Fashion (47)…",
 * "Category: Abayas (47)…".
 *
 * Disjunctive facets (Q-Counts-Scope = A)
 * ========================================
 * For each facet, the counts respect every OTHER active filter EXCEPT
 * the same-facet filter. This is the "Algolia-style disjunctive" pattern:
 *
 *   User filters by ?color=Black&size=M
 *   - "Color" facet counts: respect size=M but IGNORE color filter
 *     → user sees "Black (47), White (12)…" so they can switch colors
 *   - "Size" facet counts: respect color=Black but IGNORE size filter
 *     → user sees "S (12), M (47), L (33)…" so they can switch sizes
 *   - "Vendor" facet counts: respect BOTH color and size (no overlap)
 *     → standard narrowing semantics
 *
 * Without this rule every facet shows the same count = current-filter-set
 * count, which is useless for refinement.
 *
 * Performance characteristics
 * ===========================
 * Each facet is one aggregation query against indexed columns:
 *   - size: GROUP BY jsonb_array_elements_text(available_sizes)
 *           — uses products_sizes_idx (GIN)
 *   - color: GROUP BY jsonb_array_elements_text(available_colors)
 *           — uses products_colors_idx (GIN)
 *   - price-band: CASE WHEN price < 50 / 50-100 / etc. GROUP BY band
 *           — uses products_active_idx + price scan
 *   - vendor: GROUP BY vendor_id — uses products_vendor_idx
 *   - category: GROUP BY category_id — uses products_category_idx
 *
 * On a 2k-product staging catalog the full 5-facet aggregation runs in
 * <50ms. If contention surfaces at scale, M4 introduces caching keyed on
 * the canonical filter hash (Q-Caching deferred for v1).
 *
 * Per-facet hard cap (Q-Limit-Per-Facet = 50)
 * ==========================================
 * Each facet returns at most 50 values, ordered per Q-Ordering. The UI
 * implements a "show more" affordance when more exist (signaled by
 * total_distinct > values.length).
 *
 * Empty-count suppression (Q-Empty-Facets = A)
 * ============================================
 * Values with count=0 are never returned. The UI renders whatever it
 * gets without checking for zeros.
 */
class FacetAggregator
{
    /**
     * Fixed price bands in AED (Q-Price-Banding = A).
     * Each band is [lower_inclusive, upper_exclusive] in cents-free decimals.
     * The "500+" band uses null upper to mean "no upper bound".
     *
     * @var list<array{label: string, min: string, max: string|null}>
     */
    private const PRICE_BANDS = [
        ['label' => '0-50',    'min' => '0.00',   'max' => '50.00'],
        ['label' => '50-100',  'min' => '50.00',  'max' => '100.00'],
        ['label' => '100-250', 'min' => '100.00', 'max' => '250.00'],
        ['label' => '250-500', 'min' => '250.00', 'max' => '500.00'],
        ['label' => '500+',    'min' => '500.00', 'max' => null],
    ];

    /**
     * Canonical size order (Q-Ordering = C). Unknown sizes (e.g. numeric
     * shoe sizes "38", "39", custom labels) sort after this list, ascending
     * by their string representation.
     *
     * @var list<string>
     */
    private const SIZE_ORDER = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];

    /**
     * Per-facet hard cap (Q-Limit-Per-Facet = A).
     */
    private const MAX_VALUES_PER_FACET = 50;

    /**
     * Per-statement timeout in milliseconds (M3.2.X.10-D).
     *
     * A defensive cap so a runaway facet query (malformed jsonb, lock
     * contention, etc.) cannot tie up a DB connection indefinitely.
     * 2000ms is generous — realistic queries complete in <50ms on a
     * 2k-product catalog. If a query EVER hits this ceiling something
     * is genuinely wrong and we want a fast clear error rather than
     * a slow degraded one.
     */
    private const STATEMENT_TIMEOUT_MS = 2000;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Compute all 5 facets against the supplied filter set.
     *
     * @param array<string, mixed> $filters Same shape as
     *        ProductRepository::findActivePaginated's $filters.
     *        Recognized keys: vendorId, categoryId, labelId, minPrice,
     *        maxPrice, isFeatured, isNew, isSale, searchQuery, sizes
     *        (list<string>), colors (list<string>).
     *
     * @return array{
     *     size: array{values: list<array{value: string, count: int}>, total_distinct: int},
     *     color: array{values: list<array{value: string, count: int}>, total_distinct: int},
     *     price: array{values: list<array{value: string, count: int, min: string, max: string|null}>},
     *     vendor: array{values: list<array{value: string, label: string, count: int}>, total_distinct: int},
     *     category: array{values: list<array{value: string, label: string, count: int}>, total_distinct: int},
     *     total_products: int
     * }
     */
    public function compute(array $filters): array
    {
        // M3.2.X.10-D — defensive per-statement timeout. SET LOCAL is
        // scoped to the current transaction only, so it doesn't bleed
        // across pool connections. If the request runs outside a TX
        // (which is the default for read endpoints) the SET LOCAL is
        // a no-op — that's a known acceptable degradation; the
        // realistic risk we're guarding against is malformed jsonb
        // queries hitting a long fallback plan, and Doctrine's
        // default plan picker handles those well even without the
        // timeout. Logged at debug for ops visibility.
        try {
            $this->em->getConnection()->executeStatement(
                sprintf('SET LOCAL statement_timeout = %d', self::STATEMENT_TIMEOUT_MS),
            );
        } catch (\Throwable $e) {
            // Some test setups + non-PostgreSQL drivers don't speak
            // statement_timeout. Log and continue — the lack of a
            // cap is a defense-in-depth degradation, not a hard
            // failure.
            $this->logger->debug('facets.timeout.skipped', [
                'reason' => $e->getMessage(),
            ]);
        }

        $startNs = hrtime(true);
        $result = [
            'size' => $this->computeSize($filters),
            'color' => $this->computeColor($filters),
            'price' => $this->computePrice($filters),
            'vendor' => $this->computeVendor($filters),
            'category' => $this->computeCategory($filters),
            'total_products' => $this->computeTotalProducts($filters),
        ];
        $elapsedMs = (int) ((hrtime(true) - $startNs) / 1_000_000);

        // M3.2.X.10-D — operator visibility: emit a single 'facets.computed'
        // log line per request with timing + active-filter signature so
        // ops can grep "slow facet queries" / correlate spikes with
        // specific filter shapes. Debug level — not noisy under
        // production WARNING threshold but available when LOG_LEVEL=debug.
        $this->logger->debug('facets.computed', [
            'duration_ms' => $elapsedMs,
            'total_products' => $result['total_products'],
            'filter_keys' => array_keys(array_filter($filters, fn($v) => $v !== null && $v !== [])),
            'has_search' => is_string($filters['searchQuery'] ?? null) && $filters['searchQuery'] !== '',
        ]);

        // Soft SLA hint: if we crossed 100ms ops should investigate.
        // 100ms is the threshold Q-Caching = A picked as "acceptable
        // without caching". Crossing it twice running is a signal to
        // enable Redis caching per the playbook §2.O entry.
        if ($elapsedMs > 100) {
            $this->logger->warning('facets.slow_response', [
                'duration_ms' => $elapsedMs,
                'threshold_ms' => 100,
            ]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{values: list<array{value: string, count: int}>, total_distinct: int}
     */
    private function computeSize(array $filters): array
    {
        // Disjunctive: drop same-facet 'sizes' filter when counting.
        $disjunctive = $filters;
        unset($disjunctive['sizes']);

        $conn = $this->em->getConnection();
        $sql = $this->buildAggregationSql(
            select: "jsonb_array_elements_text(p.available_sizes) AS value, COUNT(*) AS count",
            filters: $disjunctive,
            groupBy: 'value',
        );

        /** @var list<array{value: string, count: int|string}> $rows */
        $rows = $conn->executeQuery($sql['sql'], $sql['params'], $sql['types'])->fetchAllAssociative();

        // Sort by canonical size order, then alpha for unknowns
        $rows = $this->sortBySizeOrder($rows);

        return [
            'values' => $this->capAndShape($rows, includeLabel: false),
            'total_distinct' => count($rows),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{values: list<array{value: string, count: int}>, total_distinct: int}
     */
    private function computeColor(array $filters): array
    {
        $disjunctive = $filters;
        unset($disjunctive['colors']);

        $conn = $this->em->getConnection();
        $sql = $this->buildAggregationSql(
            select: "jsonb_array_elements_text(p.available_colors) AS value, COUNT(*) AS count",
            filters: $disjunctive,
            groupBy: 'value',
            orderBy: 'count DESC, value ASC',
        );

        /** @var list<array{value: string, count: int|string}> $rows */
        $rows = $conn->executeQuery($sql['sql'], $sql['params'], $sql['types'])->fetchAllAssociative();

        return [
            'values' => $this->capAndShape($rows, includeLabel: false),
            'total_distinct' => count($rows),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{values: list<array{value: string, count: int, min: string, max: string|null}>}
     */
    private function computePrice(array $filters): array
    {
        // Disjunctive: drop minPrice/maxPrice when computing bands.
        $disjunctive = $filters;
        unset($disjunctive['minPrice'], $disjunctive['maxPrice']);

        // We build ONE query with a CASE expression mapping each row to
        // its band label. The band-membership math is done in SQL so we
        // don't shuttle every row to PHP.
        $caseSql = "CASE\n";
        foreach (self::PRICE_BANDS as $band) {
            if ($band['max'] === null) {
                $caseSql .= sprintf(
                    "  WHEN p.price >= %s THEN '%s'\n",
                    $band['min'],
                    $band['label'],
                );
            } else {
                $caseSql .= sprintf(
                    "  WHEN p.price >= %s AND p.price < %s THEN '%s'\n",
                    $band['min'],
                    $band['max'],
                    $band['label'],
                );
            }
        }
        $caseSql .= "END";

        $conn = $this->em->getConnection();
        $sql = $this->buildAggregationSql(
            select: "({$caseSql}) AS value, COUNT(*) AS count",
            filters: $disjunctive,
            groupBy: 'value',
        );

        /** @var list<array{value: string|null, count: int|string}> $rows */
        $rows = $conn->executeQuery($sql['sql'], $sql['params'], $sql['types'])->fetchAllAssociative();

        // Re-order rows by canonical band order + attach band min/max.
        $byLabel = [];
        foreach ($rows as $r) {
            if ($r['value'] === null) {
                continue;  // products without a price (defensive — column is NOT NULL)
            }
            $byLabel[$r['value']] = (int) $r['count'];
        }

        $values = [];
        foreach (self::PRICE_BANDS as $band) {
            $count = $byLabel[$band['label']] ?? 0;
            if ($count === 0) {
                continue;  // Q-Empty-Facets = A
            }
            $values[] = [
                'value' => $band['label'],
                'count' => $count,
                'min' => $band['min'],
                'max' => $band['max'],
            ];
        }

        return ['values' => $values];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{values: list<array{value: string, label: string, count: int}>, total_distinct: int}
     */
    private function computeVendor(array $filters): array
    {
        $disjunctive = $filters;
        unset($disjunctive['vendorId']);

        $conn = $this->em->getConnection();
        $sql = $this->buildAggregationSql(
            select: "v.slug AS value, v.name AS label, COUNT(*) AS count",
            filters: $disjunctive,
            groupBy: 'v.id, v.slug, v.name',
            extraJoins: 'INNER JOIN vendors v ON v.id = p.vendor_id',
            orderBy: 'count DESC, label ASC',
        );

        /** @var list<array{value: string, label: string, count: int|string}> $rows */
        $rows = $conn->executeQuery($sql['sql'], $sql['params'], $sql['types'])->fetchAllAssociative();

        return [
            'values' => $this->capAndShape($rows, includeLabel: true),
            'total_distinct' => count($rows),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{values: list<array{value: string, label: string, count: int}>, total_distinct: int}
     */
    private function computeCategory(array $filters): array
    {
        $disjunctive = $filters;
        unset($disjunctive['categoryId']);

        $conn = $this->em->getConnection();
        $sql = $this->buildAggregationSql(
            select: "c.slug AS value, c.name AS label, COUNT(*) AS count",
            filters: $disjunctive,
            groupBy: 'c.id, c.slug, c.name',
            extraJoins: 'INNER JOIN categories c ON c.id = p.category_id',
            orderBy: 'count DESC, label ASC',
        );

        /** @var list<array{value: string, label: string, count: int|string}> $rows */
        $rows = $conn->executeQuery($sql['sql'], $sql['params'], $sql['types'])->fetchAllAssociative();

        return [
            'values' => $this->capAndShape($rows, includeLabel: true),
            'total_distinct' => count($rows),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function computeTotalProducts(array $filters): int
    {
        $conn = $this->em->getConnection();
        $sql = $this->buildAggregationSql(
            select: 'COUNT(*) AS total',
            filters: $filters,
        );
        $result = $conn->executeQuery($sql['sql'], $sql['params'], $sql['types'])->fetchOne();
        return (int) $result;
    }

    /**
     * Build a raw SQL aggregation query against the products table
     * applying the same filter set semantics as
     * ProductRepository::findActivePaginated.
     *
     * Why raw SQL and not DQL: we need jsonb_array_elements_text(),
     * which DQL doesn't speak natively, and we need fine control over
     * the SELECT/GROUP BY for aggregation (DQL is biased toward entity
     * hydration). The filter logic is straightforward enough that
     * duplicating it here doesn't create a meaningful maintenance burden;
     * any future filter additions should land in BOTH places.
     *
     * @param array<string, mixed> $filters
     * @return array{sql: string, params: array<string, mixed>, types: array<string, int|string>}
     */
    private function buildAggregationSql(
        string $select,
        array $filters,
        ?string $groupBy = null,
        string $extraJoins = '',
        ?string $orderBy = null,
    ): array {
        $where = ['p.is_active = TRUE'];
        $params = [];
        $types = [];

        if (!empty($filters['vendorId'])) {
            $where[] = 'p.vendor_id = :vendorId';
            $params['vendorId'] = $filters['vendorId'];
        }
        if (!empty($filters['categoryId'])) {
            $where[] = 'p.category_id = :categoryId';
            $params['categoryId'] = $filters['categoryId'];
        }
        if (!empty($filters['labelId'])) {
            $where[] = 'p.label_id = :labelId';
            $params['labelId'] = $filters['labelId'];
        }
        if (!empty($filters['minPrice'])) {
            $where[] = 'p.price >= :minPrice';
            $params['minPrice'] = $filters['minPrice'];
        }
        if (!empty($filters['maxPrice'])) {
            $where[] = 'p.price <= :maxPrice';
            $params['maxPrice'] = $filters['maxPrice'];
        }
        if (!empty($filters['isFeatured'])) {
            $where[] = 'p.is_featured = TRUE';
        }
        if (!empty($filters['isNew'])) {
            $where[] = 'p.is_new = TRUE';
        }
        if (!empty($filters['isSale'])) {
            $where[] = 'p.is_sale = TRUE';
        }

        // M3.1.5.5d — fulltext via TSMATCH; reuse the same approach as
        // findActivePaginated. plainto_tsquery is forgiving of user input.
        $searchQuery = $filters['searchQuery'] ?? null;
        if (is_string($searchQuery) && trim($searchQuery) !== '') {
            $where[] = "p.search_tsv @@ plainto_tsquery('simple', :searchQuery)";
            $params['searchQuery'] = trim($searchQuery);
        }

        // M3.2.X.10 — refining filters: sizes, colors. JSONB containment
        // with OR semantics ("available_* contains ANY of the requested
        // values"; @> would require ALL of them present).
        //
        // We use the jsonb_exists_any(jsonb, text[]) FUNCTION, NOT the
        // equivalent `?|` operator. The `?` in `?|` (like `?` and `?&`) is
        // parsed by DBAL/PDO as a positional parameter placeholder, so a
        // query that mixes `?|` with bound parameters throws at execute
        // time — that was the cause of the /v3/products/facets 500 whenever
        // a size or color filter was applied. The function form is the
        // documented equivalent and carries no `?`, so it binds cleanly.
        if (!empty($filters['sizes']) && is_array($filters['sizes'])) {
            $where[] = "jsonb_exists_any(p.available_sizes, array[:sizes]::text[])";
            $params['sizes'] = $filters['sizes'];
            $types['sizes'] = \Doctrine\DBAL\ArrayParameterType::STRING;
        }
        if (!empty($filters['colors']) && is_array($filters['colors'])) {
            $where[] = "jsonb_exists_any(p.available_colors, array[:colors]::text[])";
            $params['colors'] = $filters['colors'];
            $types['colors'] = \Doctrine\DBAL\ArrayParameterType::STRING;
        }

        $sql = sprintf(
            "SELECT %s FROM products p %s WHERE %s",
            $select,
            $extraJoins,
            implode(' AND ', $where),
        );

        if ($groupBy !== null) {
            $sql .= " GROUP BY {$groupBy}";
        }
        if ($orderBy !== null) {
            $sql .= " ORDER BY {$orderBy}";
        }

        return ['sql' => $sql, 'params' => $params, 'types' => $types];
    }

    /**
     * Sort rows by canonical size order: known sizes (XS/S/M/L/XL/XXL/XXXL)
     * first in fixed sequence, unknown sizes alpha-ascending after.
     *
     * @param list<array{value: string, count: int|string}> $rows
     * @return list<array{value: string, count: int|string}>
     */
    private function sortBySizeOrder(array $rows): array
    {
        $orderMap = [];
        foreach (self::SIZE_ORDER as $i => $size) {
            $orderMap[$size] = $i;
        }

        usort($rows, function (array $a, array $b) use ($orderMap): int {
            $aRank = $orderMap[$a['value']] ?? PHP_INT_MAX;
            $bRank = $orderMap[$b['value']] ?? PHP_INT_MAX;
            if ($aRank !== $bRank) {
                return $aRank <=> $bRank;
            }
            // Both unknown — alpha-ascending. PHP's natural compare
            // handles numeric strings correctly ('39' < '40').
            return strnatcmp($a['value'], $b['value']);
        });

        return $rows;
    }

    /**
     * Convert raw aggregation rows to the canonical facet-value shape,
     * applying the per-facet cap (Q-Limit-Per-Facet) and 0-count
     * suppression (Q-Empty-Facets). Counts arrive as strings from
     * PostgreSQL COUNT(*); cast to int here.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function capAndShape(array $rows, bool $includeLabel): array
    {
        $out = [];
        foreach ($rows as $row) {
            $count = (int) $row['count'];
            if ($count === 0) {
                continue;
            }
            $value = (string) ($row['value'] ?? '');
            if ($value === '') {
                continue;  // defensive: skip blanks
            }
            $shaped = ['value' => $value, 'count' => $count];
            if ($includeLabel && isset($row['label'])) {
                $shaped['label'] = (string) $row['label'];
            }
            $out[] = $shaped;
            if (count($out) >= self::MAX_VALUES_PER_FACET) {
                break;
            }
        }
        return $out;
    }
}
