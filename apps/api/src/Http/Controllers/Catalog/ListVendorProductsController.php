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
 * GET /v3/vendors/{slug}/products
 *
 * Vendor storefront, paginated active products for a single vendor.
 *
 * Same filter + sort semantics as /v3/products, but vendor is fixed
 * (not filterable away from the URL slug).
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
            $this->serializer->configureFromRequest($request)->listShapeMany($result['items']),
            $result['total'],
            $limit,
            $offset,
        ));
    }
}
