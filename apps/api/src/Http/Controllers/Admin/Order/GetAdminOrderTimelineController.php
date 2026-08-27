<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order;

use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderTimelineBuilder;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Bayti\Api\Http\Serializers\OrderTimelineSerializer;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/admin/orders/{id}/timeline?order=desc&limit=50&offset=0
 *
 * Admin view of an order's full event history (M3.2.X.17-C).
 * Returns every event from 5 sources (audit_log, notification_logs,
 * order_return_requests, order_disputes, entity-derived) in a single
 * chronological feed.
 *
 * Query parameters:
 *   order , 'desc' (default, newest first) | 'asc' (oldest first)
 *   limit , 1-200, default 50
 *   offset, >=0, default 0
 *
 * Authorization: admin-only (group middleware). Audit ACTION_VIEWED
 * emitted with the order as subject so forensic 'who looked at this
 * order's timeline' queries work.
 *
 * Empty handling (Q-EmptyHandling = A): if the order exists, the
 * timeline always has at least one event (order.created). A truly
 * empty timeline implies the order itself doesn't exist; controller
 * returns 404 upstream of the builder call.
 */
final class GetAdminOrderTimelineController
{
    use Responder;

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OrderTimelineBuilder $builder,
        private readonly OrderTimelineSerializer $serializer,
        private readonly AuditEmitter $audit,
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

        $orderId = (int) ($args['id'] ?? 0);
        if ($orderId <= 0) {
            throw HttpException::notFound('Order not found.');
        }

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        $order = $orders->findByIdForAdmin($orderId);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $orderDir = $this->parseOrder($query['order'] ?? null);
        $limit = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);

        $result = $this->builder->build(
            orderId: $orderId,
            vendorIdFilter: null,
            order: $orderDir,
            limit: $limit,
            offset: $offset,
        );

        $this->audit->recordView(
            request: $request,
            actor: $user,
            subject: $order,
            context: [
                'context' => 'admin_order_timeline',
                'filters' => [
                    'order' => $orderDir,
                    'limit' => $limit,
                    'offset' => $offset,
                ],
                'result_count' => count($result['events']),
                'total' => $result['total'],
            ],
        );

        return $this->ok($this->serializer->shape(
            $order,
            $result['events'],
            $result['total'],
            $limit,
            $offset,
        ));
    }

    private function parseOrder(mixed $raw): string
    {
        if (!is_string($raw)) {
            return 'desc';
        }
        return $raw === 'asc' ? 'asc' : 'desc';
    }

    private function clampLimit(mixed $raw): int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return OrderTimelineBuilder::DEFAULT_LIMIT;
        }
        $n = (int) $raw;
        if ($n < 1) {
            return OrderTimelineBuilder::DEFAULT_LIMIT;
        }
        return min($n, OrderTimelineBuilder::MAX_LIMIT);
    }

    private function clampOffset(mixed $raw): int
    {
        if (!is_string($raw) && !is_int($raw)) {
            return 0;
        }
        return max(0, (int) $raw);
    }
}
