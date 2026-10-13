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
 * GET /v3/vendors/by-legacy-id/{id}/products
 *
 * Mobile-compatibility shim during the M3.1.5 strangler-fig flip -
 * paginated active products for a single vendor, identified by the
 * legacy WordPress/CodeIgniter vendor id rather than slug.
 *
 * Behaviour matches ListVendorProductsController (the slug variant):
 *   - Page size is the shared CatalogPageSize rule, store-scoped:
 *     ?limit present = clamped to 1-100; ?limit ABSENT = the whole store
 *     catalogue, bounded by CatalogPageSize::STORE_CATALOGUE_MAX (500).
 *     Mobile bundles built before the slug storefront (2026-08-24) load
 *     their store page's "All" grid and "Latest" rail through THIS route
 *     with no limit/offset and no infinite scroll, so the old 24-row
 *     default truncated them exactly like the slug route did.
 *   - ?offset clamped to >= 0, defaults to 0; meta.has_more is accurate
 *   - Sort is hardcoded to 'newest' (matches slug controller; vendor
 *     storefront UX is consistent and doesn't expose sort controls)
 *   - 404 for unknown legacy id OR a vendor that may not sell (inactive
 *     or not approved)
 *
 * Returns: { data: Product[], meta: PaginationMeta }
 *
 * Retired with the rest of the by-legacy-id controllers once mobile
 * rebuilds against slug semantics (M3.1.10+).
 */
final class ListVendorProductsByLegacyIdController
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
        $rawId = (string) ($args['id'] ?? '');
        if ($rawId === '' || !ctype_digit($rawId)) {
            throw HttpException::notFound('Vendor not found.');
        }
        $legacyId = (int) $rawId;

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $vendor = $vendorRepo->findByLegacyId($legacyId);
        // canSell() = approved AND active — align with the slug variant
        // (ListVendorProductsController) so a pending/suspended store's
        // storefront 404s consistently across the slug and legacy-id routes.
        if ($vendor === null || !$vendor->canSell()) {
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

        $envelope = PaginatedEnvelope::build(
            $this->serializer->configureFromRequest($request)->listShapeMany($result['items']),
            $result['total'],
            $limit,
            $offset,
        );
        // Expose the resolved v3 vendor id so admin tooling (e.g. the store
        // products manager) can create products for this store without a
        // second lookup. The list itself is keyed by legacy id.
        $envelope['meta']['vendor_id'] = $vendor->getId();

        return $this->ok($envelope);
    }
}
