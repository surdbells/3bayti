<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Product;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/products
 *
 * The authenticated vendor's own product catalog — ALL states (draft,
 * inactive, out-of-stock), not just storefront-active. Replaces the
 * portal's prior use of GET /vendors/by-legacy-id/{id}/products, which
 * resolved by legacy *vendor* id and 404'd when the session carried the
 * legacy *user* id (a different id space).
 *
 * The vendor is resolved from the authenticated user (VendorAuthMiddleware
 * guarantees an approved vendor). Multi-store users may pass ?vendor_id to
 * pick one of their stores; otherwise the first owned store is used.
 *
 * Query: limit, offset, search, vendor_id (optional).
 */
final class ListVendorOwnProductsController
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
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $ownedIds = $vendorRepo->findIdsByOwnerUser($user);
        if ($ownedIds === []) {
            throw HttpException::forbidden('No approved vendor account.');
        }

        $query = $request->getQueryParams();

        // Resolve which owned store to list.
        $vendorId = $ownedIds[0];
        if (!empty($query['vendor_id'])) {
            $requested = (int) $query['vendor_id'];
            if (!in_array($requested, $ownedIds, true)) {
                throw HttpException::notFound('Vendor not found.');
            }
            $vendorId = $requested;
        }

        $limit  = max(1, min(100, (int) ($query['limit'] ?? 24)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $result = $productRepo->findForVendorPaginated([
            'vendorId' => $vendorId,
            'limit'    => $limit,
            'offset'   => $offset,
            'search'   => isset($query['search']) ? (string) $query['search'] : null,
        ]);

        $envelope = PaginatedEnvelope::build(
            $this->serializer->configureFromRequest($request)->listShapeMany($result['items']),
            $result['total'],
            $limit,
            $offset,
        );
        $envelope['meta']['vendor_id'] = $vendorId;

        return $this->ok($envelope);
    }
}
