<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Catalog;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
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
 * Mobile-compatibility shim during the M3.1.5 strangler-fig flip —
 * paginated active products for a single vendor, identified by the
 * legacy WordPress/CodeIgniter vendor id rather than slug.
 *
 * Behaviour matches ListVendorProductsController (the slug variant):
 *   - Pagination via ?limit + ?offset (limit clamped to 1-100, defaults
 *     to 24; offset clamped to >= 0, defaults to 0)
 *   - Sort is hardcoded to 'newest' (matches slug controller; vendor
 *     storefront UX is consistent and doesn't expose sort controls)
 *   - 404 for unknown legacy id OR inactive vendors
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
        if ($vendor === null || !$vendor->isActive()) {
            throw HttpException::notFound('Vendor not found.');
        }

        $query = $request->getQueryParams();

        $limit = max(1, min(100, (int) ($query['limit'] ?? 24)));
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
            $this->serializer->listShapeMany($result['items']),
            $result['total'],
            $limit,
            $offset,
        ));
    }
}
