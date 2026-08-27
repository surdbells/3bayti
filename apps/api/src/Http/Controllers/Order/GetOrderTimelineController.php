<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order;

use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderRepository;
use Bayti\Api\Domain\Order\OrderTimelineBuilder;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Http\Errors\ErrorCodes;
use Bayti\Api\Http\Errors\HttpException;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Responder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /v3/orders/{id}/timeline?order=asc|desc&limit&offset
 *
 * Customer view of their OWN order's event history. Reuses the same
 * OrderTimelineBuilder engine as the admin (X.17-C) and vendor (X.17-D)
 * feeds, but scoped + sanitized for a shopper:
 *
 *   - Ownership: OrderRepository::findForUser → 404 on any other user's or
 *     a missing order (opaque, no existence leak, same posture as
 *     GET /v3/orders/{id}).
 *   - Event whitelist: only customer-relevant lifecycle events
 *     (order.created/paid/status_changed/item_status_changed + return.*).
 *     Disputes, the raw notification log, and internal-only audit rows are
 *     dropped.
 *   - Actor sanitization: internal identities (admin / vendor user ids +
 *     labels) are collapsed to a generic { type: 'store'|'system'|'customer' }
 *     so a shopper never sees staff identities.
 *   - `details` dropped: the per-event structured payloads can carry internal
 *     data; the customer feed exposes only id / type / occurred_at / actor /
 *     summary.
 */
final class GetOrderTimelineController
{
    use Responder;

    /** Event types a customer may see; everything else is internal. */
    private const CUSTOMER_TYPES = [
        'order.created',
        'order.paid',
        'order.status_changed',
        'order.item_status_changed',
        'return.submitted',
        'return.approved',
        'return.denied',
        'return.picked_up',
        'return.received_by_vendor',
        'return.refunded',
    ];

    public function __construct(
        protected readonly ResponseFactoryInterface $responseFactory,
        private readonly EntityManagerInterface $em,
        private readonly OrderTimelineBuilder $builder,
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
        if ($orderId < 1) {
            throw HttpException::notFound('Order not found.');
        }

        /** @var OrderRepository $orders */
        $orders = $this->em->getRepository(Order::class);
        $order = $orders->findForUser($orderId, $user);
        if ($order === null) {
            throw HttpException::notFound('Order not found.');
        }

        /** @var array<string, mixed> $query */
        $query = $request->getQueryParams();
        $orderDir = ($query['order'] ?? null) === 'asc' ? 'asc' : 'desc';
        $limit = $this->clampLimit($query['limit'] ?? null);
        $offset = $this->clampOffset($query['offset'] ?? null);

        // Build the full timeline (customer orders are small; MAX_LIMIT covers
        // them), then filter + sanitize down to the customer-safe subset before
        // paginating, so `total` reflects what the customer can actually see.
        $result = $this->builder->build(
            orderId: $orderId,
            vendorIdFilter: null,
            order: $orderDir,
            limit: OrderTimelineBuilder::MAX_LIMIT,
            offset: 0,
        );

        $safe = [];
        foreach ($result['events'] as $event) {
            $type = (string) ($event['type'] ?? '');
            if (!in_array($type, self::CUSTOMER_TYPES, true)) {
                continue;
            }
            $safe[] = [
                'id' => (string) ($event['id'] ?? ''),
                'type' => $type,
                'occurred_at' => $event['occurred_at'] ?? null,
                'actor' => $this->sanitizeActor(is_array($event['actor'] ?? null) ? $event['actor'] : []),
                'summary' => (string) ($event['summary'] ?? ''),
            ];
        }

        $total = count($safe);

        return $this->ok([
            'events' => array_slice($safe, $offset, $limit),
            'total' => $total,
        ]);
    }

    /**
     * Collapse an internal actor to a customer-safe generic, a shopper only
     * learns whether the actor was themselves, the store, or the system, never
     * a staff user id or name.
     *
     * @param array<string, mixed> $actor
     * @return array{type: string}
     */
    private function sanitizeActor(array $actor): array
    {
        $type = (string) ($actor['type'] ?? 'system');

        return [
            'type' => match ($type) {
                'customer' => 'customer',
                'vendor', 'admin' => 'store',
                default => 'system',
            },
        ];
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
