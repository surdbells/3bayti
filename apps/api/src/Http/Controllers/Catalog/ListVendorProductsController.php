<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Http\CatalogPageSize;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendors/{slug}/products
 *
 * Vendor storefront, active products for a single vendor (the vendor is
 * fixed by the URL slug). Sort is hardcoded to 'newest'; the only query
 * params are ?limit and ?offset.
 *
 * Page size (shared rule, see CatalogPageSize):
 *   - ?limit present: clamped to 1-100 (an explicit limit is a normal
 *     paginated read; the web store page always sends one).
 *   - ?limit absent: the WHOLE store catalogue, bounded by
 *     CatalogPageSize::STORE_CATALOGUE_MAX (500). The shipped mobile store
 *     page loads its "All" grid and "Latest" rail with a single request that
 *     carries no limit/offset and has no infinite scroll, so the old 24-row
 *     default hid every product past the 24th. This endpoint is always
 *     store-scoped, so an absent limit always means "the whole catalogue".
 *   - ?offset: >= 0, default 0, honoured in both cases; meta.has_more is
 *     accurate either way.
 *
 * Returns: { data: Product[], meta: PaginationMeta }
 *
 * 404 if the vendor slug is unknown OR the vendor is inactive, we
 * treat inactive vendors as nonexistent to public callers.
 */
final class ListVendorProductsController
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

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $slug = (string) ($args['slug'] ?? '');
        if ($slug === '') {
            throw HttpException::notFound('Vendor not found.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepo->findBySlug($slug);
        if ($vendor === null || !$vendor->isActive() || !$vendor->isApproved()) {
            throw HttpException::notFound('Vendor not found.');
        }

        $query = $request->getQueryParams();

        // Always store-scoped: no limit = the whole store catalogue (<= 500).
        $limit = CatalogPageSize::resolve($query, storeScoped: true);
        $offset = max(0, (int) ($query['offset'] ?? 0));

        $filters = [
            'vendorId' => $vendor->getId(),
            'sort' => 'newest',
            'limit' => $limit,
            'offset' => $offset,
        ];

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $result = $productRepo->findActivePaginated($filters);

        return $this->ok(PaginatedEnvelope::build(
            $this->serializer->configureFromRequest($request)->listShapeMany($result['items']),
            $result['total'],
            $limit,
            $offset,
        ));
    }
}
