<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/products
 *
 * Paginated active product list. Supports filters + sorting via query string:
 *
 *   ?vendor=almas-fashion           (vendor slug)
 *   ?category=abayas                (category slug)
 *   ?min_price=50&max_price=500     (decimal AED)
 *   ?featured=true&new=true&sale=true
 *   ?sort=newest|oldest|price_asc|price_desc
 *   ?limit=24&offset=0
 *
 * Returns: { data: Product[], meta: PaginationMeta }
 *
 * Slug-based filters (vendor, category) resolve to id internally — this
 * keeps the public URL contract slug-based (SEO-friendly) while the SQL
 * uses indexed id lookups.
 *
 * Pagination caps:
 *   - limit: 1-100 (defaults to 24)
 *   - offset: >= 0 (no upper bound, but very large offsets are slow —
 *     callers should switch to category/vendor filters for deeper browsing)
 *
 * Sorting defaults to 'newest' (created_at DESC) which matches the
 * apps/web homepage's expected order.
 */
final class ListProductsController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ProductSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $query = $request->getQueryParams();

        // ---- pagination ----
        $limit = max(1, min(100, (int) ($query['limit'] ?? 24)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        // ---- resolve slug or legacy-id filters to internal ids ----
        //
        // M3.1.5 introduced the legacy-id variants for mobile compat
        // during the strangler-fig migration. Mobile passes integer
        // ids it learned from the legacy backend; web passes slugs.
        //
        // Precedence: if BOTH `vendor` (slug) and `vendor_id` (legacy)
        // are provided, the slug wins. The slug is the canonical
        // identifier going forward, so callers that send both are
        // probably mid-migration and we should reward the new form.
        // Same rule for `category` / `category_id`.
        //
        // Unknown id/slug → empty result set (not 404; "no products
        // from this vendor/category" is a valid empty list).
        $vendorId = $this->resolveVendorId($query);
        if ($vendorId === self::FILTER_NOT_FOUND) {
            return $this->ok(PaginatedEnvelope::build([], 0, $limit, $offset));
        }

        $categoryId = $this->resolveCategoryId($query);
        if ($categoryId === self::FILTER_NOT_FOUND) {
            return $this->ok(PaginatedEnvelope::build([], 0, $limit, $offset));
        }

        $labelId = $this->resolveLabelId($query);
        if ($labelId === self::FILTER_NOT_FOUND) {
            return $this->ok(PaginatedEnvelope::build([], 0, $limit, $offset));
        }

        $filters = [
            'vendorId' => $vendorId,
            'categoryId' => $categoryId,
            'labelId' => $labelId,
            'minPrice' => $this->parsePrice($query['min_price'] ?? null),
            'maxPrice' => $this->parsePrice($query['max_price'] ?? null),
            'isFeatured' => $this->parseBool($query['featured'] ?? null),
            'isNew' => $this->parseBool($query['new'] ?? null),
            'isSale' => $this->parseBool($query['sale'] ?? null),
            'sort' => $this->parseSort($query['sort'] ?? null),
            // M3.1.5.5d — fulltext search via PostgreSQL tsvector.
            // When `q` is present and non-empty, the repository adds
            // a TSMATCH WHERE clause against the products.search_tsv
            // generated column. See ProductRepository::findActivePaginated
            // docblock for details.
            //
            // Trim input and treat all-whitespace as absent so we don't
            // pollute query logs with junk searches.
            'searchQuery' => $this->parseSearchQuery($query['q'] ?? null),
            'limit' => $limit,
            'offset' => $offset,
        ];

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $result = $productRepo->findActivePaginated($filters);

        return $this->ok(PaginatedEnvelope::build(
            $this->serializer->listShapeMany($result['items']),
            $result['total'],
            $limit,
            $offset,
        ));
    }

    /**
     * Sentinel for the resolveVendorId / resolveCategoryId helpers:
     * "the caller passed an identifier but no entity matches it".
     *
     * Distinct from null, which means "no filter was passed at all".
     * Distinct from a valid int id, which means "filter matched".
     */
    private const FILTER_NOT_FOUND = -1;

    /**
     * Resolve the vendor filter from the query string.
     *
     * Returns:
     *   - int $id if the filter matched a vendor (active or not — the
     *     domain layer filters inactive separately)
     *   - null if no vendor filter was supplied
     *   - FILTER_NOT_FOUND if a filter was supplied but didn't match
     *
     * @param array<string, mixed> $query
     */
    private function resolveVendorId(array $query): int|null
    {
        // Slug wins if both forms are present.
        if (!empty($query['vendor'])) {
            /** @var VendorRepository $vendorRepo */
            $vendorRepo = $this->em->getRepository(Vendor::class);
            $vendor = $vendorRepo->findBySlug((string) $query['vendor']);
            return $vendor === null ? self::FILTER_NOT_FOUND : $vendor->getId();
        }

        if (!empty($query['vendor_id'])) {
            $rawId = (string) $query['vendor_id'];
            if (!ctype_digit($rawId)) {
                // Non-numeric vendor_id is treated as a not-found
                // filter rather than 400. Mobile only sends ints; if
                // a malformed value appears, returning empty is safer
                // than failing a whole product list call.
                return self::FILTER_NOT_FOUND;
            }
            /** @var VendorRepository $vendorRepo */
            $vendorRepo = $this->em->getRepository(Vendor::class);
            $vendor = $vendorRepo->findByLegacyId((int) $rawId);
            return $vendor === null ? self::FILTER_NOT_FOUND : $vendor->getId();
        }

        return null;
    }

    /**
     * Resolve the category filter from the query string. Same semantics
     * as resolveVendorId — slug wins over legacy id, sentinel for the
     * not-found case.
     *
     * @param array<string, mixed> $query
     */
    private function resolveCategoryId(array $query): int|null
    {
        if (!empty($query['category'])) {
            /** @var CategoryRepository $categoryRepo */
            $categoryRepo = $this->em->getRepository(Category::class);
            $category = $categoryRepo->findBySlug((string) $query['category']);
            return $category === null ? self::FILTER_NOT_FOUND : $category->getId();
        }

        if (!empty($query['category_id'])) {
            $rawId = (string) $query['category_id'];
            if (!ctype_digit($rawId)) {
                return self::FILTER_NOT_FOUND;
            }
            /** @var CategoryRepository $categoryRepo */
            $categoryRepo = $this->em->getRepository(Category::class);
            $category = $categoryRepo->findByLegacyId((int) $rawId);
            return $category === null ? self::FILTER_NOT_FOUND : $category->getId();
        }

        return null;
    }

    /**
     * Resolve the label filter from the query string (M3.1.5.5e).
     *
     * Labels are per-vendor; the URL design pairs them with a vendor
     * context (?vendor=foo&label=eid-collection). Standalone label
     * lookup (no vendor context) still works — slug is unique per
     * vendor, so the same slug across two vendors WOULD ambiguate.
     * Locked: standalone label slug uses findActiveBySlug which
     * scopes to vendor; if no vendor was provided, we fall back to
     * a global lookup (returns first match) and document the edge.
     *
     * Mobile sends `label_id: <legacy_int>` (per the vendors.page
     * rqst_param body); the legacy variant resolves to v3 via
     * VendorLabelRepository::findActiveByLegacyId.
     *
     * Same precedence as the vendor/category resolvers — slug wins
     * over legacy id when both are present.
     *
     * @param array<string, mixed> $query
     */
    private function resolveLabelId(array $query): int|null
    {
        if (!empty($query['label'])) {
            /** @var VendorLabelRepository $labelRepo */
            $labelRepo = $this->em->getRepository(VendorLabel::class);
            // findOneBy + slug+isActive (no vendor scope) — see
            // method docblock for the edge-case discussion.
            $label = $labelRepo->findOneBy([
                'slug' => (string) $query['label'],
                'isActive' => true,
            ]);
            return $label === null ? self::FILTER_NOT_FOUND : $label->getId();
        }

        if (!empty($query['label_id'])) {
            $rawId = (string) $query['label_id'];
            if (!ctype_digit($rawId)) {
                return self::FILTER_NOT_FOUND;
            }
            /** @var VendorLabelRepository $labelRepo */
            $labelRepo = $this->em->getRepository(VendorLabel::class);
            $label = $labelRepo->findActiveByLegacyId((int) $rawId);
            return $label === null ? self::FILTER_NOT_FOUND : $label->getId();
        }

        return null;
    }

    private function parsePrice(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_numeric($value)) {
            return null;
        }
        return number_format((float) $value, 2, '.', '');
    }

    private function parseBool(?string $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function parseSort(?string $value): string
    {
        // 'relevance' is only meaningful when a search query is
        // present. The repository's findActivePaginated method
        // falls back to 'newest' if 'relevance' is supplied without
        // a search query, so we accept the value here unconditionally
        // and let the domain layer enforce the dependency.
        $valid = ['newest', 'oldest', 'price_asc', 'price_desc', 'relevance'];
        return in_array($value, $valid, true) ? $value : 'newest';
    }

    /**
     * Normalise the `q` query param.
     *
     * Returns null for absent, empty, or whitespace-only input so the
     * repository's hasSearch check can skip the TSMATCH clause.
     *
     * Trims to 200 chars defensively — websearch_to_tsquery handles
     * arbitrary punctuation gracefully but very long inputs would
     * waste time tokenising. 200 chars covers any reasonable user
     * query and protects against accidental URL bombing.
     */
    private function parseSearchQuery(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (strlen($trimmed) > 200) {
            $trimmed = substr($trimmed, 0, 200);
        }
        return $trimmed;
    }
}
