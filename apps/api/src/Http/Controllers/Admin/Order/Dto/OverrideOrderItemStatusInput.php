<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order\Dto;

use Bayti\Api\Domain\Order\OrderItem;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PATCH /v3/admin/orders/{orderId}/items/{itemId}/status
 *
 * Body: { "status": "<one of OrderItem::ITEM_STATUS_*>", "reason": "..." }
 *
 * Admins can force any item status — full enum including
 * cancelled/returned/refunded which vendors can't set directly.
 * Transition validation is BYPASSED ($adminOverride=true).
 */
final class OverrideOrderItemStatusInput
{
    #[Assert\NotBlank(message: 'status is required.')]
    #[Assert\Choice(
        choices: [
            OrderItem::ITEM_STATUS_PENDING,
            OrderItem::ITEM_STATUS_ACCEPTED,
            OrderItem::ITEM_STATUS_REJECTED,
            OrderItem::ITEM_STATUS_PREPARING,
            OrderItem::ITEM_STATUS_SHIPPED,
            OrderItem::ITEM_STATUS_DELIVERED,
            OrderItem::ITEM_STATUS_CANCELLED,
            OrderItem::ITEM_STATUS_RETURNED,
            OrderItem::ITEM_STATUS_REFUNDED,
        ],
        message: 'status must be one of the valid OrderItem status values.',
    )]
    public readonly ?string $status;

    #[Assert\NotBlank(message: 'reason is required for admin overrides.')]
    #[Assert\Length(
        min: 1,
        max: 1000,
        maxMessage: 'reason must be 1000 characters or fewer.',
    )]
    public readonly ?string $reason;

    public function __construct(?string $status = null, ?string $reason = null)
    {
        $this->status = $status;
        $this->reason = $reason;
    }
}
