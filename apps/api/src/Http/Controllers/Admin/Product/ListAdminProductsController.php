<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Product;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/products
 *
 * Global admin product catalogue, EVERY vendor, EVERY status. Unlike the
 * public GET /v3/products (findActivePaginated, active-only), this surfaces
 * DRAFTS so admins can find and publish them. Filters: status
 * (draft/published), stock_status, vendor (slug), search, pagination.
 *
 * Authorization: admin-only (group middleware). products.view.
 */
final class ListAdminProductsController
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
        ResponseInterface $_response,
        array $args,
    ): ResponseInterface {
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(ErrorCodes::AUTH_INVALID_TOKEN, 'Authentication required.');
        }

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $limit  = max(1, min(100, (int) ($query['limit'] ?? 24)));
        $offset = max(0, (int) ($query['offset'] ?? 0));

        /** @var ProductRepository $productRepo */
        $productRepo = $this->em->getRepository(Product::class);
        $result = $productRepo->findForAdminPaginated([
            'limit'        => $limit,
            'offset'       => $offset,
            'search'       => isset($query['search']) ? (string) $query['search'] : null,
            'status'       => isset($query['status']) && $query['status'] !== '' ? (string) $query['status'] : null,
            'stock_status' => isset($query['stock_status']) && $query['stock_status'] !== '' ? (string) $query['stock_status'] : null,
            // Admin products filter passes the vendor by SLUG (the store select
            // loads options keyed on slug).
            'vendorSlug'   => isset($query['vendor']) && $query['vendor'] !== '' ? (string) $query['vendor'] : null,
        ]);

        $envelope = PaginatedEnvelope::build(
            $this->serializer->adminListShapeMany($result['items']),
            $result['total'],
            $limit,
            $offset,
        );

        return $this->ok($envelope);
    }
}
