<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Product;

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
 * GET /v3/admin/vendors/{id}/products
 *
 * Admin-scoped catalog listing for a single vendor, keyed by the v3
 * vendor id. Unlike the public storefront endpoints
 * (GET /v3/vendors/{slug}/products and the by-legacy-id shim), this
 * applies NO storefront gates: products in ALL states (draft, inactive,
 * out-of-stock) are returned, and there is NO vendor active/approved
 * gate, admins manage inactive and pending stores too. The store-products
 * page in the portal previously called the public by-legacy-id route,
 * which 404'd inactive vendors and (via ProductRepository::findActivePaginated)
 * hid every product of a non active+approved vendor, so admins saw an
 * empty list for real stores.
 *
 * Uses the ungated ProductRepository::findForVendorPaginated path (the
 * vendor's own catalog, intentionally unfiltered).
 *
 * Query: limit (1-100, default 24), offset (>=0, default 0), search, sort.
 * Returns: { data: Product[] (admin manage shape), meta: PaginationMeta }
 *
 * Authorization: admin-only (the /v3/admin route group enforces the
 * AdminAuthMiddleware stack; products.view permission added in routes.php).
 */
final class ListAdminVendorProductsController
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
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $rawId = (string) ($args['id'] ?? '');
        if ($rawId === '' || !ctype_digit($rawId)) {
            throw HttpException::notFound('Vendor not found.');
        }
        $vendorId = (int) $rawId;

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        // Admins manage inactive/pending stores, no active/approved gate.
        $vendor = $vendorRepo->find($vendorId);
        if ($vendor === null) {
            throw HttpException::notFound('Vendor not found.');
        }

        $query = $request->getQueryParams();

        $limit  = max(1, min(100, (int) ($query['limit'] ?? 24)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $result = $productRepo->findForVendorPaginated([
            'vendorId'     => $vendorId,
            'limit'        => $limit,
            'offset'       => $offset,
            'search'       => isset($query['search']) ? (string) $query['search'] : null,
            'status'       => isset($query['status']) && $query['status'] !== '' ? (string) $query['status'] : null,
            'stock_status' => isset($query['stock_status']) && $query['stock_status'] !== '' ? (string) $query['stock_status'] : null,
            'category_id'  => isset($query['category_id']) && $query['category_id'] !== '' ? (int) $query['category_id'] : null,
            'price_min'    => isset($query['price_min']) && $query['price_min'] !== '' ? (float) $query['price_min'] : null,
            'price_max'    => isset($query['price_max']) && $query['price_max'] !== '' ? (float) $query['price_max'] : null,
        ]);

        $envelope = PaginatedEnvelope::build(
            $this->serializer->vendorManageShapeMany($result['items']),
            $result['total'],
            $limit,
            $offset,
        );
        $envelope['meta']['vendor_id'] = $vendorId;

        return $this->ok($envelope);
    }
}
