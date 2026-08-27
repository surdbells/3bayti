<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Order\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /v3/orders/{id}/cancel
 *
 * Body: { "reason": "..." (optional) }
 *
 * Customer-self-serve cancel. Only allowed for pending_payment
 * orders (they may have abandoned checkout). Reason is optional
 * for the customer path, if missing, we record an empty string.
 *
 * Admin's CancelOrderInput requires reason; this DTO doesn't, since
 * customers shouldn't need to justify abandoning a checkout.
 */
final class CustomerCancelOrderInput
{
    #[Assert\Length(
        max: 500,
        maxMessage: 'reason must be 500 characters or fewer.',
    )]
    public readonly ?string $reason;

    public function __construct(?string $reason = null)
    {
        $this->reason = $reason;
    }
}
