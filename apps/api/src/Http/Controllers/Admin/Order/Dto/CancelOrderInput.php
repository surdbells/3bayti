<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /v3/admin/orders/{id}/cancel
 *
 * Body: { "reason": "..." }
 *
 * Admin cancels an order. Behavior depends on order state:
 *   - pending_payment: cancel locally; no Noon call (money never moved)
 *   - paid/fulfilling: cancel + AUTO-REFUND via Noon
 *   - shipped+: rejected (must use returns flow, not cancellation)
 */
final class CancelOrderInput
{
    #[Assert\NotBlank(message: 'reason is required for cancellation.')]
    #[Assert\Length(
        min: 1,
        max: 1000,
        maxMessage: 'reason must be 1000 characters or fewer.',
    )]
    public readonly ?string $reason;

    public function __construct(?string $reason = null)
    {
        $this->reason = $reason;
    }
}
