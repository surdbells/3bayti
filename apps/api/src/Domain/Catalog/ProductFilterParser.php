<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Catalog;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Shared filter-parsing + slug-resolution helper for the catalog
 * read endpoints (M3.2.X.10-C).
 *
 * Both ListProductsController (M3.1.5.5d) and ListFacetsController
 * (M3.2.X.10-B) need to translate the public query-string vocabulary
 * (vendor slugs, category slugs, label slugs, legacy ids, decimal
 * prices, boolean flags, search query, refinement lists) into the
 * canonical filter array shape that ProductRepository::findActivePaginated
 * and FacetAggregator::compute both consume.
 *
 * Before this class existed, both controllers carried near-identical
 * private parsing helpers. Adding a new filter axis (price, locale,
 * stock-status, etc.) meant two parallel changes. This service
 * centralizes the parsing so future additions land in one place.
 *
 * Why a service (vs static helper):
 *   - parse() needs an EntityManager to resolve slugs to ids
 *   - DI-friendly: each controller injects this and calls parse()
 *   - Easier to unit-test as a unit with mocked repositories
 *
 * Filter array contract (consumed by ProductRepository + FacetAggregator):
 *   vendorId:    int|null   — resolved from slug or legacy_id
 *   categoryId:  int|null
 *   labelId:     int|null
 *   minPrice:    string|null — DECIMAL(10,2), e.g. "50.00"
 *   maxPrice:    string|null
 *   isFeatured:  bool|null
 *   isNew:       bool|null
 *   isSale:      bool|null
 *   sort:        string      — 'newest' | 'oldest' | 'price_asc' | 'price_desc' | 'relevance' | 'best_seller'
 *   searchQuery: string|null — trimmed to 200 chars
 *   sizes:       list<string>|null — refinement (M3.2.X.10-A)
 *   colors:      list<string>|null — refinement (M3.2.X.10-A)
 *   limit:       int         — clamped 1-100, default 24
 *   offset:      int         — >= 0
 *
 * Plus a `_filterNotFound` boolean sentinel set when a slug filter
 * matched no entity. Callers should short-circuit to an empty
 * result when this is true (matches existing soft-fail semantics —
 * unknown slugs don't 404, they just return empty data).
 */
class ProductFilterParser
{
    public const DEFAULT_LIMIT = 24;
    public const MAX_LIMIT = 100;
    public const MAX_SEARCH_QUERY_LENGTH = 200;
    public const VALID_SORTS = [
        'newest', 'oldest', 'price_asc', 'price_desc', 'relevance', 'best_seller',
    ];

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /**
     * Parse a raw query-params array into the canonical filter shape.
     *
     * Returns:
     *   - 'filters' — array suitable for findActivePaginated / FacetAggregator
     *   - 'filterNotFound' — true when a slug filter matched nothing
     *
     * @param array<string, mixed> $query
     * @return array{filters: array<string, mixed>, filterNotFound: bool}
     */
    public function parse(array $query): array
    {
        $vendorId = $this->resolveVendorId($query);
        if ($vendorId === false) {
            return ['filters' => [], 'filterNotFound' => true];
        }
        $categoryId = $this->resolveCategoryId($query);
        if ($categoryId === false) {
            return ['filters' => [], 'filterNotFound' => true];
        }
        $labelId = $this->resolveLabelId($query);
        if ($labelId === false) {
            return ['filters' => [], 'filterNotFound' => true];
        }

        return [
            'filters' => [
                'vendorId'    => $vendorId,
                'categoryId'  => $categoryId,
                'labelId'     => $labelId,
                'minPrice'    => $this->parsePrice($query['min_price'] ?? null),
                'maxPrice'    => $this->parsePrice($query['max_price'] ?? null),
                'isFeatured'  => $this->parseBool($query['featured'] ?? null),
                'isNew'       => $this->parseBool($query['new'] ?? null),
                'isSale'      => $this->parseBool($query['sale'] ?? null),
                'sort'        => $this->parseSort($query['sort'] ?? null),
                'searchQuery' => $this->parseSearchQuery($query['q'] ?? null),
                'sizes'       => $this->parseStringList($query['sizes'] ?? null),
                'colors'      => $this->parseStringList($query['colors'] ?? null),
                'limit'       => $this->parseLimit($query['limit'] ?? null),
                'offset'      => $this->parseOffset($query['offset'] ?? null),
            ],
            'filterNotFound' => false,
        ];
    }

    /**
     * Slug-or-legacy-id resolution for vendor.
     *
     * @param array<string, mixed> $query
     * @return int|null|false int if matched, null if no filter, false if not found
     */
    public function resolveVendorId(array $query): int|null|false
    {
        if (!empty($query['vendor'])) {
            /** @var VendorRepository $repo */
            $repo = $this->em->getRepository(Vendor::class);
            $vendor = $repo->findBySlug((string) $query['vendor']);
            return $vendor === null ? false : $vendor->getId();
        }
        if (!empty($query['vendor_id'])) {
            $rawId = (string) $query['vendor_id'];
            if (!ctype_digit($rawId)) {
                return false;
            }
            /** @var VendorRepository $repo */
            $repo = $this->em->getRepository(Vendor::class);
            $vendor = $repo->findByLegacyId((int) $rawId);
            return $vendor === null ? false : $vendor->getId();
        }
        return null;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function resolveCategoryId(array $query): int|null|false
    {
        if (!empty($query['category'])) {
            /** @var CategoryRepository $repo */
            $repo = $this->em->getRepository(Category::class);
            $cat = $repo->findBySlug((string) $query['category']);
            return $cat === null ? false : $cat->getId();
        }
        if (!empty($query['category_id'])) {
            $rawId = (string) $query['category_id'];
            if (!ctype_digit($rawId)) {
                return false;
            }
            /** @var CategoryRepository $repo */
            $repo = $this->em->getRepository(Category::class);
            $cat = $repo->findByLegacyId((int) $rawId);
            return $cat === null ? false : $cat->getId();
        }
        return null;
    }

    /**
     * @param array<string, mixed> $query
     */
    public function resolveLabelId(array $query): int|null|false
    {
        if (!empty($query['label'])) {
            /** @var VendorLabelRepository $repo */
            $repo = $this->em->getRepository(VendorLabel::class);
            $label = $repo->findOneBy([
                'slug' => (string) $query['label'],
                'isActive' => true,
            ]);
            return $label === null ? false : $label->getId();
        }
        if (!empty($query['label_id'])) {
            $rawId = (string) $query['label_id'];
            if (!ctype_digit($rawId)) {
                return false;
            }
            /** @var VendorLabelRepository $repo */
            $repo = $this->em->getRepository(VendorLabel::class);
            $label = $repo->findActiveByLegacyId((int) $rawId);
            return $label === null ? false : $label->getId();
        }
        return null;
    }

    public function parsePrice(mixed $value): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return number_format((float) $value, 2, '.', '');
    }

    public function parseBool(mixed $value): ?bool
    {
        if (!is_string($value)) {
            return null;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    public function parseSort(mixed $value): string
    {
        if (!is_string($value)) {
            return 'newest';
        }
        return in_array($value, self::VALID_SORTS, true) ? $value : 'newest';
    }

    public function parseSearchQuery(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (strlen($trimmed) > self::MAX_SEARCH_QUERY_LENGTH) {
            $trimmed = substr($trimmed, 0, self::MAX_SEARCH_QUERY_LENGTH);
        }
        return $trimmed;
    }

    /**
     * Parse a list param that may be array form (?sizes[]=S&sizes[]=M)
     * or comma-separated string form (?sizes=S,M). Returns null when
     * absent or yields no usable values.
     *
     * @return list<string>|null
     */
    public function parseStringList(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                if (is_string($v) && trim($v) !== '') {
                    $out[] = trim($v);
                }
            }
        } elseif (is_string($raw)) {
            foreach (explode(',', $raw) as $v) {
                $trimmed = trim($v);
                if ($trimmed !== '') {
                    $out[] = $trimmed;
                }
            }
        } else {
            return null;
        }
        return $out === [] ? null : $out;
    }

    public function parseLimit(mixed $value): int
    {
        if (!is_string($value) && !is_int($value)) {
            return self::DEFAULT_LIMIT;
        }
        $int = (int) $value;
        if ($int < 1) {
            return self::DEFAULT_LIMIT;
        }
        return min($int, self::MAX_LIMIT);
    }

    public function parseOffset(mixed $value): int
    {
        if (!is_string($value) && !is_int($value)) {
            return 0;
        }
        return max(0, (int) $value);
    }

    /**
     * Build the public `applied_filters` echo block — only the keys
     * the client actually supplied, in the same form they supplied
     * them (slug, not resolved id; raw string price, not formatted).
     *
     * Used by ListFacetsController; available for any future endpoint
     * that wants to surface the active filter set.
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function buildAppliedFiltersBlock(array $query): array
    {
        $applied = [];
        foreach (['vendor', 'category', 'label', 'min_price', 'max_price', 'q'] as $key) {
            if (!empty($query[$key])) {
                $applied[$key] = (string) $query[$key];
            }
        }
        foreach (['featured', 'new', 'sale'] as $key) {
            if ($this->parseBool($query[$key] ?? null) === true) {
                $applied[$key] = true;
            }
        }
        $sizes = $this->parseStringList($query['sizes'] ?? null);
        if ($sizes !== null) {
            $applied['sizes'] = $sizes;
        }
        $colors = $this->parseStringList($query['colors'] ?? null);
        if ($colors !== null) {
            $applied['colors'] = $colors;
        }
        return $applied;
    }
}
