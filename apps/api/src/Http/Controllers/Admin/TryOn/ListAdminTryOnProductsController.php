<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\TryOn;

use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\ProductRepository;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\AdminTryOnProductSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/try-on/products
 *
 * The admin virtual try-on enablement list: every vendor's draft + active
 * products with their RAW try_on_enabled flag and garment-image eligibility
 * (AdminTryOnProductSerializer). Try-on-enabled products first, then newest.
 *
 * Query:
 *   q        optional case-insensitive name/slug substring
 *   enabled  'true' | 'false' (omitted / anything else = all)
 *   page     1-based page (default 1); `offset` is accepted instead
 *   limit    page size, default 20, max 100
 *
 * Response: the same {data, meta: {total, limit, offset, has_more}} envelope
 * as GET /v3/admin/products (PaginatedEnvelope).
 *
 * Authorization: admin-only (group middleware). products.view.
 */
final class ListAdminTryOnProductsController
{
    use Responder;

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly AdminTryOnProductSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();

        $limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        if (isset($query['page']) && is_numeric($query['page'])) {
            $offset = (max(1, (int) $query['page']) - 1) * $limit;
        } else {
            $offset = max(0, (int) (is_numeric($query['offset'] ?? null) ? $query['offset'] : 0));
        }

        $q = isset($query['q']) && is_string($query['q']) && trim($query['q']) !== '' ? trim($query['q']) : null;

        $enabledRaw = isset($query['enabled']) && is_string($query['enabled']) ? strtolower(trim($query['enabled'])) : '';
        $enabled = match ($enabledRaw) {
            'true', '1' => true,
            'false', '0' => false,
            default => null,
        };

        /** @var ProductRepository $repo */
        $repo = $this->em->getRepository(Product::class);
        $result = $repo->findForTryOnAdmin([
            'q' => $q,
            'enabled' => $enabled,
            'limit' => $limit,
            'offset' => $offset,
        ]);

        return $this->ok(PaginatedEnvelope::build(
            $this->serializer->shapeMany($result['items']),
            $result['total'],
            $limit,
            $offset,
        ));
    }
}
