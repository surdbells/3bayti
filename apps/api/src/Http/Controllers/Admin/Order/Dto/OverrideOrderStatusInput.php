<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order\Dto;

use Bayti\Api\Domain\Order\Order;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PATCH /v3/admin/orders/{id}/status
 *
 * Body: { "status": "<one of Order::STATUS_*>", "reason": "..." }
 *
 * The reason is REQUIRED for admin overrides, every override
 * must justify itself in the audit log. Free-text up to 1000
 * chars; the admin UI should encourage thoughtful entries.
 */
final class OverrideOrderStatusInput
{
    #[Assert\NotBlank(message: 'status is required.')]
    #[Assert\Choice(
        choices: [
            Order::STATUS_PENDING_PAYMENT,
            Order::STATUS_PAID,
            Order::STATUS_FULFILLING,
            Order::STATUS_SHIPPED,
            Order::STATUS_DELIVERED,
            Order::STATUS_CANCELLED,
            Order::STATUS_REFUNDED,
            Order::STATUS_FAILED,
        ],
        message: 'status must be one of the valid Order status values.',
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
