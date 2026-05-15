<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OrderSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/orders?limit=10&offset=0&status=&user_id=&vendor_id=
 *
 * Admin cross-vendor orders view. Optional filters narrow the scope.
 * Returns the full unfiltered order shape (no per-vendor item
 * filtering — admins see everything).
 *
 * Q5=A audit: emits ACTION_VIEWED with the filter set as context.
 * Does NOT log per-order subject_ids in the list view because (a)
 * it would balloon the audit table and (b) the filter set + actor
 * lets ops reconstruct what the admin saw with a query.
 *
 * Routing: under AdminAuthMiddleware → guarantees user is admin.
 */
final class ListAdminOrdersController
{
    use Responder;

    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 100;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OrderSerializer $serializer,
        private readonly AuditEmitter $audit,
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
            throw HttpException::unauthorized(
                ErrorCodes::AUTH_INVALID_TOKEN,
                'Authentication required.',
            );
        }

        $query = $request->getQueryParams();
        $limit = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);
        $status = $this->parseStatus($query['status'] ?? null);
        $userIdFilter = $this->parsePositiveInt($query['user_id'] ?? null);
        $vendorIdFilter = $this->parsePositiveInt($query['vendor_id'] ?? null);

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        [$list, $total] = $orders->paginatedForAdmin(
            $limit,
            $offset,
            $status,
            $userIdFilter,
            $vendorIdFilter,
        );

        // Audit the listing access. Subject is the admin User
        // themselves — list views don't have a single subject entity,
        // so the audit row records "admin X queried the orders list
        // with these filters." We use the actor as subject to keep
        // the audit row valid (subjectId requires int).
        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $user,
            context: [
                'context' => 'admin_orders_list',
                'filters' => array_filter([
                    'limit' => $limit,
                    'offset' => $offset,
                    'status' => $status,
                    'user_id' => $userIdFilter,
                    'vendor_id' => $vendorIdFilter,
                ], static fn ($v) => $v !== null),
                'result_count' => count($list),
                'total' => $total,
            ],
        );

        $items = array_map(
            fn (Order $o): array => $this->serializer->listShape($o),
            $list,
        );

        return $this->ok([
            'orders' => $items,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($items),
                'total' => $total,
            ],
        ]);
    }

    private function clampLimit(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return self::DEFAULT_LIMIT;
        }
        $n = (int) $raw;
        return $n < 1 ? self::DEFAULT_LIMIT : min($n, self::MAX_LIMIT);
    }

    private function clampOffset(mixed $raw): int
    {
        if ($raw === null || $raw === '') {
            return 0;
        }
        return max(0, (int) $raw);
    }

    private function parseStatus(mixed $raw): ?string
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $valid = [
            Order::STATUS_PENDING_PAYMENT,
            Order::STATUS_PAID,
            Order::STATUS_FULFILLING,
            Order::STATUS_SHIPPED,
            Order::STATUS_DELIVERED,
            Order::STATUS_CANCELLED,
            Order::STATUS_REFUNDED,
            Order::STATUS_FAILED,
        ];
        return in_array($raw, $valid, true) ? $raw : null;
    }

    private function parsePositiveInt(mixed $raw): ?int
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        $n = (int) $raw;
        return $n > 0 ? $n : null;
    }
}
