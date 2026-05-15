<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Order\OrderDispute;

/**
 * Wire shape for OrderDispute entities.
 *
 * Separate from OrderSerializer because disputes have their own
 * lifecycle + admin-facing fields (resolution_note, resolved_by_user_id)
 * that don't belong on the order shape.
 */
final class DisputeSerializer
{
    /**
     * @return array<string, mixed>
     */
    public function shape(OrderDispute $dispute): array
    {
        $order = $dispute->getOrder();
        return [
            'id' => $dispute->getId() ?? 0,
            'order_id' => $order?->getId(),
            'order_reference' => $order?->getOrderReference(),
            'provider_order_ref' => $dispute->getProviderOrderRef(),
            'provider_dispute_id' => $dispute->getProviderDisputeId(),
            'event_type' => $dispute->getEventType(),
            'status' => $dispute->getStatus(),
            'is_terminal' => $dispute->isTerminal(),
            'amount' => $dispute->getAmount(),
            'currency' => $dispute->getCurrency(),
            'reason' => $dispute->getReason(),
            'resolution_note' => $dispute->getResolutionNote(),
            'resolved_by_user_id' => $dispute->getResolvedByUserId(),
            'resolved_at' => $dispute->getResolvedAt()?->format(\DateTimeInterface::ATOM),
            'created_at' => $dispute->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updated_at' => $dispute->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
