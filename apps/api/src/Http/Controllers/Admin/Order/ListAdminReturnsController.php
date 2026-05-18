<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\PaginatedEnvelope;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\ReturnRequestSerializer;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/returns
 *
 * Paginated list of all return requests across all customers. The
 * primary operator-console view for the returns review queue.
 *
 * Query parameters (all optional):
 *   - status: filter on OrderReturnRequest::ALL_STATUSES
 *   - reason: filter on OrderReturnRequest::ALL_REASONS
 *   - customer_id: filter to one customer's returns
 *   - vendor_id: filter to returns containing a specific vendor's items
 *   - order_id: filter to one order's returns
 *   - since, until: ISO8601 datetime bounds on requested_at
 *   - limit (default 20, max 100), offset (default 0)
 *
 * Authorization: AdminAuthMiddleware ensures the calling user is admin.
 *
 * Response shape: PaginatedEnvelope with admin-shape items.
 */
final class ListAdminReturnsController
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

        $params = $request->getQueryParams();
        $filters = [
            'limit' => $this->clampLimit($params['limit'] ?? null),
            'offset' => max(0, (int) ($params['offset'] ?? 0)),
        ];

        if (isset($params['status']) && in_array($params['status'], OrderReturnRequest::ALL_STATUSES, true)) {
            $filters['status'] = (string) $params['status'];
        }
        if (isset($params['reason']) && in_array($params['reason'], OrderReturnRequest::ALL_REASONS, true)) {
            $filters['reason'] = (string) $params['reason'];
        }
        if (isset($params['customer_id'])) {
            $filters['customerId'] = (int) $params['customer_id'];
        }
        if (isset($params['vendor_id'])) {
            $filters['vendorId'] = (int) $params['vendor_id'];
        }
        if (isset($params['order_id'])) {
            $filters['orderId'] = (int) $params['order_id'];
        }
        $since = $this->parseDate($params['since'] ?? null);
        if ($since !== null) {
            $filters['since'] = $since;
        }
        $until = $this->parseDate($params['until'] ?? null);
        if ($until !== null) {
            $filters['until'] = $until;
        }

        /** @var OrderReturnRequestRepository $repo */
        $repo = $this->em->getRepository(OrderReturnRequest::class);
        $page = $repo->findFilteredPaginatedForAdmin($filters);

        return $this->ok(PaginatedEnvelope::build(
            $this->serializer->adminShapeMany($page['items']),
            $page['total'],
            $filters['limit'],
            $filters['offset'],
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

    private function parseDate(mixed $raw): ?DateTimeImmutable
    {
        if (!is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
