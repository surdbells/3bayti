<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
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

        // ---- resolve slug filters to ids ----
        $vendorId = null;
        if (!empty($query['vendor'])) {
            /** @var VendorRepository $vendorRepo */
            $vendorRepo = $this->em->getRepository(Vendor::class);
            $vendor = $vendorRepo->findBySlug((string) $query['vendor']);
            if ($vendor === null) {
                // Unknown vendor slug → empty result set (not 404; "no
                // products from this vendor" is a valid empty list).
                return $this->ok(PaginatedEnvelope::build([], 0, $limit, $offset));
            }
            $vendorId = $vendor->getId();
        }

        $categoryId = null;
        if (!empty($query['category'])) {
            /** @var CategoryRepository $categoryRepo */
            $categoryRepo = $this->em->getRepository(Category::class);
            $category = $categoryRepo->findBySlug((string) $query['category']);
            if ($category === null) {
                return $this->ok(PaginatedEnvelope::build([], 0, $limit, $offset));
            }
            $categoryId = $category->getId();
        }

        $filters = [
            'vendorId' => $vendorId,
            'categoryId' => $categoryId,
            'minPrice' => $this->parsePrice($query['min_price'] ?? null),
            'maxPrice' => $this->parsePrice($query['max_price'] ?? null),
            'isFeatured' => $this->parseBool($query['featured'] ?? null),
            'isNew' => $this->parseBool($query['new'] ?? null),
            'isSale' => $this->parseBool($query['sale'] ?? null),
            'sort' => $this->parseSort($query['sort'] ?? null),
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
        $valid = ['newest', 'oldest', 'price_asc', 'price_desc'];
        return in_array($value, $valid, true) ? $value : 'newest';
    }
}
