<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Order\Dto;

use Bayti\Api\Domain\Order\OrderItem;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for PATCH /v3/vendor/orders/{orderId}/items/{itemId}/status.
 *
 * Vendors advance their line items through the lifecycle.
 *
 * Body shape:
 *   { "status": "preparing", "note": "Shipping by Friday" }
 *
 * The 'note' is optional and recorded on the item for audit. Both
 * vendor and admin transitions accept a note; admins use it to
 * record override rationale (Phase D audit log requirement).
 */
final class TransitionOrderItemInput
{
    #[Assert\NotBlank(message: 'status is required.')]
    #[Assert\Choice(
        choices: [
            OrderItem::ITEM_STATUS_ACCEPTED,
            OrderItem::ITEM_STATUS_REJECTED,
            OrderItem::ITEM_STATUS_PREPARING,
            OrderItem::ITEM_STATUS_SHIPPED,
            OrderItem::ITEM_STATUS_DELIVERED,
            // CANCELLED, RETURNED, REFUNDED only via admin endpoints;
            // vendors can't put their own items into those states
            // without an admin signature.
        ],
        message: 'status must be one of: accepted, rejected, preparing, shipped, delivered.',
    )]
    public readonly ?string $status;

    #[Assert\Length(
        max: 500,
        maxMessage: 'note must be 500 characters or fewer.',
    )]
    public readonly ?string $note;

    public function __construct(?string $status = null, ?string $note = null)
    {
        $this->status = $status;
        $this->note = $note;
    }
}
