<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Order\Order;

/**
 * Shape OrderTimelineBuilder output for the HTTP response envelope
 * (M3.2.X.17-C/D).
 *
 * Response:
 *   {
 *     data: [ { id, type, occurred_at, actor, summary, details }, ... ],
 *     meta: {
 *       total, limit, offset, order_id, order_reference
 *     }
 *   }
 *
 * The builder already shapes the per-event payload to the canonical
 * Q-EventShape; the serializer just attaches the order identity and
 * pagination metadata in the meta block.
 */
final class OrderTimelineSerializer
{
    /**
     * @param list<array<string, mixed>> $events
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{
     *         total: int,
     *         limit: int,
     *         offset: int,
     *         order_id: int,
     *         order_reference: string
     *     }
     * }
     */
    public function shape(
        Order $order,
        array $events,
        int $total,
        int $limit,
        int $offset,
    ): array {
        return [
            'data' => $events,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'order_id' => $order->getId() ?? 0,
                'order_reference' => $order->getOrderReference(),
            ],
        ];
    }
}
