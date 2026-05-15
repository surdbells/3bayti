<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /v3/admin/orders/{id}/refund
 *
 * Body: {
 *   "amount": "29.99",   // decimal string; null/omitted = full refund
 *   "reason": "...",     // required
 * }
 *
 * Amount validation:
 *   - If amount omitted / null: refund the full remaining amount
 *     (paid - already_refunded)
 *   - If amount provided: must be > 0 and <= (paid - already_refunded);
 *     server-side validation enforces this (DTO can't see Order state)
 *   - Decimal precision: 2 dp (AED standard); validator regex enforces
 */
final class RefundOrderInput
{
    /**
     * Decimal amount as STRING. Omit or pass null for full refund.
     * Format: '\\d+(\\.\\d{1,2})?' — '0.01' to '999999.99'.
     * Server-side validates against the remaining-refundable balance.
     */
    #[Assert\Type(type: 'string', message: 'amount must be a string decimal (omit for full refund).')]
    #[Assert\Regex(
        pattern: '/^\d+(\.\d{1,2})?$/',
        message: 'amount must be a positive decimal with up to 2 decimal places.',
    )]
    public readonly ?string $amount;

    #[Assert\NotBlank(message: 'reason is required for refunds.')]
    #[Assert\Length(
        min: 1,
        max: 1000,
        maxMessage: 'reason must be 1000 characters or fewer.',
    )]
    public readonly ?string $reason;

    public function __construct(?string $amount = null, ?string $reason = null)
    {
        $this->amount = $amount;
        $this->reason = $reason;
    }
}
