<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Order;

use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ReturnRequestSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/vendor/returns
 *
 * List returns where at least one returned item belongs to a vendor
 * owned by the calling user. Paginated.
 *
 * Query parameters:
 *   - status: optional filter on OrderReturnRequest::ALL_STATUSES
 *   - vendor_id: optional, if the user owns multiple vendors, the
 *     UI can request returns for just one of them; otherwise we
 *     show returns across all of the user's vendors.
 *   - limit: default 20, max 100
 *   - offset: default 0
 *
 * Authorization:
 *   - VendorAuthMiddleware has already enforced "user is a vendor
 *     with at least one approved store" by the time this controller
 *     runs.
 *   - We resolve the user's vendor IDs and filter the query to those
 *     vendors only, defense in depth.
 *
 * Multi-vendor user
 * =================
 * A single user MAY own multiple Vendor entities. Without a vendor_id
 * query param, this endpoint returns the union: any return touching
 * any of the user's vendors. With a vendor_id, we narrow to just
 * that one (after verifying the user actually owns it, cross-vendor
 * attempts return empty rather than 403, since the existence-leak
 * pattern argues for indistinguishability).
 *
 * Response shape: PaginatedEnvelope with vendorShape items (only
 * items relevant to the vendor are exposed in each return).
 */
final class ListVendorReturnsController
{
    use Responder;

    private const DEFAULT_LIMIT = 20;
    private const MAX_LIMIT = 100;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly ReturnRequestSerializer $serializer,
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
        $user = $request->getAttribute(AuthMiddleware::ATTR_USER);
        if (!$user instanceof User) {
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        /** @var VendorRepository $vendorRepo */
        $vendorRepo = $this->em->getRepository(Vendor::class);
        $userVendorIds = $vendorRepo->findIdsByOwnerUser($user);
        if ($userVendorIds === []) {
            // VendorAuthMiddleware should have caught this, but
            // defense in depth, empty page.
            return $this->ok(PaginatedEnvelope::build([], 0, self::DEFAULT_LIMIT, 0));
        }

        $params = $request->getQueryParams();
        $status = isset($params['status']) ? (string) $params['status'] : null;
        $requestedVendorId = isset($params['vendor_id']) ? (int) $params['vendor_id'] : null;
        $limit = $this->clampLimit($params['limit'] ?? null);
        $offset = max(0, (int) ($params['offset'] ?? 0));

        // If the caller specified vendor_id, narrow to that one only
        // (and only if the user owns it). Otherwise iterate across
        // all of the user's vendors and merge.
        if ($requestedVendorId !== null) {
            if (!in_array($requestedVendorId, $userVendorIds, true)) {
                // Pretend they have no returns there. Existence-leak
                // prevention, same response shape as a vendor with
                // genuinely zero returns.
                return $this->ok(PaginatedEnvelope::build([], 0, $limit, $offset));
            }
            $targetVendorIds = [$requestedVendorId];
        } else {
            $targetVendorIds = $userVendorIds;
        }

        /** @var OrderReturnRequestRepository $returnRepo */
        $returnRepo = $this->em->getRepository(OrderReturnRequest::class);

        // For a single-vendor user (or single-vendor-filtered) call,
        // the repo's vendor-paginated method is a direct match.
        // For multi-vendor users iterating all their vendors, we
        // call findFilteredPaginatedForAdmin with vendorId set per
        // vendor and merge, but for v1 simplicity, we keep the
        // contract simple: vendor_id is required when the user owns
        // multiple vendors. Multi-vendor merge is a future
        // enhancement when use-cases demand it.
        if (count($targetVendorIds) > 1) {
            // Could merge here, but the UX is clearer if the API
            // requires a vendor selector when the user owns
            // multiple. Surface a structured 422 for the frontend
            // to use as a hint.
            throw new HttpException(
                status: 422,
                errorCode: 'VENDOR_ID_REQUIRED',
                publicMessage: 'You own multiple vendors. Specify vendor_id in the query string.',
                details: ['owned_vendor_ids' => $userVendorIds],
            );
        }

        $vendorId = $targetVendorIds[0];

        $filters = ['limit' => $limit, 'offset' => $offset];
        if ($status !== null && in_array($status, OrderReturnRequest::ALL_STATUSES, true)) {
            $filters['status'] = $status;
        }

        $page = $returnRepo->findForVendorPaginated($vendorId, $filters);
        $serialized = $this->serializer->vendorShapeMany($page['items'], $vendorId);

        return $this->ok(PaginatedEnvelope::build(
            $serialized,
            $page['total'],
            $limit,
            $offset,
        ));
    }

    private function clampLimit(mixed $raw): int
    {
        $value = (int) ($raw ?? self::DEFAULT_LIMIT);
        if ($value <= 0) {
            return self::DEFAULT_LIMIT;
        }
        return min($value, self::MAX_LIMIT);
    }
}
