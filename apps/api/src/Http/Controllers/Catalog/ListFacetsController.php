<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\FacetAggregator;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorLabel;
use Bayti\Api\Domain\Catalog\VendorLabelRepository;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\FacetsSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/products/facets
 *
 * Returns facet counts for the catalog list page (M3.2.X.10-B).
 *
 * Same query-string contract as GET /v3/products PLUS two new keys
 * for the refinement axes that products endpoint doesn't itself
 * filter on yet (but FacetAggregator can use to compute disjunctive
 * counts):
 *
 *   ?vendor=almas-fashion            (vendor slug, or vendor_id legacy int)
 *   ?category=abayas                 (category slug, or category_id legacy int)
 *   ?label=eid-collection            (label slug, or label_id legacy int)
 *   ?min_price=50&max_price=500      (decimal AED)
 *   ?featured=true&new=true&sale=true
 *   ?q=evening+dress                 (fulltext search)
 *   ?sizes[]=S&sizes[]=M             (NEW — refine by sizes)
 *   ?colors[]=Black&colors[]=Red     (NEW — refine by colors)
 *
 * The endpoint returns 200 with an empty result set rather than 404
 * when filters reference non-existent vendors/categories/labels —
 * matches ListProductsController's existing soft-fail semantics.
 *
 * Authorization: public endpoint (no auth required), same as
 * GET /v3/products.
 */
final class ListFacetsController
{
    use Responder;

    /**
     * Sentinel return from the slug resolvers meaning "a filter was
     * supplied but no entity matched". Identical to ListProductsController's
     * approach — the controller short-circuits with an empty facet
     * response in this case.
     */
    private const FILTER_NOT_FOUND = -1;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly FacetAggregator $aggregator,
        private readonly FacetsSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();

        // Slug → id resolution. Same precedence rule as ListProductsController:
        // slug wins over legacy id, FILTER_NOT_FOUND short-circuits.
        $vendorId = $this->resolveVendorId($query);
        if ($vendorId === self::FILTER_NOT_FOUND) {
            return $this->emptyResponse($query);
        }
        $categoryId = $this->resolveCategoryId($query);
        if ($categoryId === self::FILTER_NOT_FOUND) {
            return $this->emptyResponse($query);
        }
        $labelId = $this->resolveLabelId($query);
        if ($labelId === self::FILTER_NOT_FOUND) {
            return $this->emptyResponse($query);
        }

        $filters = [
            'vendorId'    => $vendorId,
            'categoryId'  => $categoryId,
            'labelId'     => $labelId,
            'minPrice'    => $this->parsePrice($query['min_price'] ?? null),
            'maxPrice'    => $this->parsePrice($query['max_price'] ?? null),
            'isFeatured'  => $this->parseBool($query['featured'] ?? null),
            'isNew'       => $this->parseBool($query['new'] ?? null),
            'isSale'      => $this->parseBool($query['sale'] ?? null),
            'searchQuery' => $this->parseSearchQuery($query['q'] ?? null),
            'sizes'       => $this->parseStringList($query['sizes'] ?? null),
            'colors'      => $this->parseStringList($query['colors'] ?? null),
        ];

        $result = $this->aggregator->compute($filters);

        return $this->ok($this->serializer->shape(
            $result,
            $this->buildAppliedFiltersBlock($query),
        ));
    }

    /**
     * Empty response when a slug filter doesn't match anything in
     * the DB. Returns the canonical envelope with all-empty facets +
     * total_products=0 so the UI can render "0 results" cleanly.
     *
     * @param array<string, mixed> $query
     */
    private function emptyResponse(array $query): ResponseInterface
    {
        return $this->ok($this->serializer->shape(
            [
                'size'           => ['values' => [], 'total_distinct' => 0],
                'color'          => ['values' => [], 'total_distinct' => 0],
                'price'          => ['values' => []],
                'vendor'         => ['values' => [], 'total_distinct' => 0],
                'category'       => ['values' => [], 'total_distinct' => 0],
                'total_products' => 0,
            ],
            $this->buildAppliedFiltersBlock($query),
        ));
    }

    /**
     * Build the `applied_filters` block surfaced in the response meta.
     * Includes only the filters the client actually supplied (not the
     * resolved ids or unspecified booleans).
     *
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function buildAppliedFiltersBlock(array $query): array
    {
        $applied = [];
        foreach (['vendor', 'category', 'label', 'min_price', 'max_price', 'q'] as $key) {
            if (!empty($query[$key])) {
                $applied[$key] = (string) $query[$key];
            }
        }
        foreach (['featured', 'new', 'sale'] as $key) {
            $parsed = $this->parseBool($query[$key] ?? null);
            if ($parsed === true) {
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

    // -----------------------------------------------------------------
    // Filter parsing — mirrors ListProductsController (X.10-D candidate
    // for extraction into a shared ProductFilterParser).
    // -----------------------------------------------------------------

    /**
     * @param array<string, mixed> $query
     */
    private function resolveVendorId(array $query): int|null
    {
        if (!empty($query['vendor'])) {
            /** @var VendorRepository $vendorRepo */
            $vendorRepo = $this->em->getRepository(Vendor::class);
            $vendor = $vendorRepo->findBySlug((string) $query['vendor']);
            return $vendor === null ? self::FILTER_NOT_FOUND : $vendor->getId();
        }
        if (!empty($query['vendor_id'])) {
            $rawId = (string) $query['vendor_id'];
            if (!ctype_digit($rawId)) {
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
     * @param array<string, mixed> $query
     */
    private function resolveLabelId(array $query): int|null
    {
        if (!empty($query['label'])) {
            /** @var VendorLabelRepository $labelRepo */
            $labelRepo = $this->em->getRepository(VendorLabel::class);
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

    private function parsePrice(mixed $value): ?string
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return number_format((float) $value, 2, '.', '');
    }

    private function parseBool(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)) {
            return null;
        }
        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function parseSearchQuery(mixed $value): ?string
    {
        if (!is_string($value)) {
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

    /**
     * Parse a query string param that may be an array (?sizes[]=S&sizes[]=M)
     * or a comma-separated string (?sizes=S,M).
     *
     * Returns null when the param is absent or yields no values.
     *
     * @return list<string>|null
     */
    private function parseStringList(mixed $raw): ?array
    {
        if ($raw === null) {
            return null;
        }
        $values = [];
        if (is_array($raw)) {
            foreach ($raw as $v) {
                if (is_string($v) && trim($v) !== '') {
                    $values[] = trim($v);
                }
            }
        } elseif (is_string($raw)) {
            foreach (explode(',', $raw) as $v) {
                $trimmed = trim($v);
                if ($trimmed !== '') {
                    $values[] = $trimmed;
                }
            }
        } else {
            return null;
        }
        return $values === [] ? null : $values;
    }
}
