<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\VendorApplication;

use Bayti\Api\Domain\Catalog\VendorApplication;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\VendorApplicationSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/vendor-applications?status=&limit=&offset=
 *   (gated vendors.view)
 *
 * Paginated admin listing of seller applications. Optional status
 * filter (pending|approved|rejected). Emits the standard
 * PaginatedEnvelope ({ data: [...], meta: { total, limit, offset,
 * has_more } }).
 */
final class ListVendorApplicationsController
{
    use Responder;

    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT = 200;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly VendorApplicationSerializer $serializer,
    ) {
    }

    protected function getResponseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory;
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();

        $limit = (int) ($q['limit'] ?? self::DEFAULT_LIMIT);
        if ($limit <= 0) {
            $limit = self::DEFAULT_LIMIT;
        }
        $limit = min($limit, self::MAX_LIMIT);
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $status = isset($q['status']) ? trim((string) $q['status']) : '';
        $status = $status !== '' ? $status : null;

        /** @var \Bayti\Api\Domain\Catalog\VendorApplicationRepository $repo */
        $repo = $this->em->getRepository(VendorApplication::class);
        [$items, $total] = $repo->findPaginatedForAdmin($limit, $offset, $status);

        return $this->ok(PaginatedEnvelope::build(
            $this->serializer->adminShapeMany($items),
            $total,
            $limit,
            $offset,
        ));
    }
}
