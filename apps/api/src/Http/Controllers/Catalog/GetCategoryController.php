<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\CategorySerializer;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/categories/{slug}
 *
 * Category detail page for the apps/web `/category/:slug` route.
 * Returns:
 *   - data: full CategoryDetail (publicShape + image object + icon_name
 *           + product_count + children + embedded products array)
 *   - meta: { total_products, page_size }, apps/web-specific envelope
 *           (NOT the standard PaginatedEnvelope shape; this endpoint
 *           uses a custom meta to match the apps/web contract exactly)
 *
 * Locked decisions (M3.2.X.3 plan §3)
 * ====================================
 *   Q-PageSize = A: hard-coded 20 products per page (matches legacy v2;
 *                    apps/web pagination beyond page 1 deferred to W2.1b)
 *   Q-Sort     = A: newest first (sort=newest; matches legacy)
 *   Q-Children = A: keep emitting children (admin tool depends on it)
 *   Q-Empty    = A: 200 with empty products[] for categories with no
 *                    active products (not 404; empty isn't an error)
 *
 * 404 conditions
 * ==============
 * - Empty slug
 * - Unknown slug (no Category row matches)
 * - Inactive category (Category::$isActive = false; soft-deleted)
 *
 * Empty product set
 * =================
 * A category that exists, is active, but has no active products
 * returns 200 with:
 *   - data.products: []
 *   - data.product_count: <raw count, possibly > 0 if all products
 *                          are inactive>
 *   - meta.total_products: 0
 *   - meta.page_size: 20
 *
 * Apps/web handles this gracefully (renders description variants:
 * "more pieces coming soon" for 0, "one hand-picked" for 1).
 */
final class GetCategoryController
{
    use Responder;

    /**
     * Page size for the embedded products. Q-PageSize = A locked.
     * Matches legacy v2 behavior and apps/web's first-page-only
     * consumption pattern.
     */
    private const PAGE_SIZE = 20;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly CategorySerializer $serializer,
        private readonly ProductSerializer $productSerializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug = (string) ($args['slug'] ?? '');
        if ($slug === '') {
            throw HttpException::notFound('Category not found.');
        }

        /** @var CategoryRepository $categoryRepo */
        $categoryRepo = $this->em->getRepository(Category::class);
        $category = $categoryRepo->findBySlug($slug);
        if ($category === null || !$category->isActive()) {
            throw HttpException::notFound('Category not found.');
        }

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);

        // Compute raw count (no isActive filter) for the
        // CategoryDetail.product_count field. Apps/web shows this
        // alongside the filtered count when relevant.
        $rawProductCount = $productRepo->countByCategoryRaw($category->getId());

        // Fetch first page of active products (Q-Sort = A: newest first).
        // findActivePaginated returns ['items' => list<Product>, 'total' => int]
        // where 'total' is the COUNT after the isActive WHERE filter -
        // exactly what apps/web wants as meta.total_products.
        $productsResult = $productRepo->findActivePaginated([
            'categoryId' => $category->getId(),
            'sort' => 'newest',
            'limit' => self::PAGE_SIZE,
            'offset' => 0,
        ]);

        // Direct children, kept emitting per Q-Children = A
        // (admin tool depends on this field).
        $children = $categoryRepo->findChildren($category);

        // Build the data block.
        $data = $this->serializer->detailShape($category, $rawProductCount);
        $data['children'] = $this->serializer->publicShapeMany($children);
        $data['products'] = $this->productSerializer->configureFromRequest($request)->listShapeMany($productsResult['items']);

        // Build the meta block, apps/web-specific shape.
        // NOT PaginatedEnvelope::build (which emits {total, limit,
        // offset, has_more}); apps/web's CategoryDetailMeta is
        // {total_products, page_size} per category.model.ts.
        $meta = [
            'total_products' => $productsResult['total'],
            'page_size' => self::PAGE_SIZE,
        ];

        return $this->ok([
            'data' => $data,
            'meta' => $meta,
        ]);
    }
}
