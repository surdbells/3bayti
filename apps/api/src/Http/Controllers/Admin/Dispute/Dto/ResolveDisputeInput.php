<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Dispute\Dto;

use Bayti\Api\Domain\Order\OrderDispute;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body for PATCH /v3/admin/disputes/{id}.
 *
 * Admin advances a dispute through the lifecycle:
 *   - in_review        → triage signal (admin opened the case)
 *   - resolved_won     → we won (no money movement)
 *   - resolved_lost    → we lost (Noon issued refund)
 *   - withdrawn        → customer dropped the claim
 *
 * resolution_note is REQUIRED for resolved_* and withdrawn statuses
 * (audit accountability, every resolution has a documented rationale).
 * Optional for in_review.
 */
final class ResolveDisputeInput
{
    #[Assert\NotBlank(message: 'status is required.')]
    #[Assert\Choice(
        choices: [
            OrderDispute::STATUS_IN_REVIEW,
            OrderDispute::STATUS_RESOLVED_WON,
            OrderDispute::STATUS_RESOLVED_LOST,
            OrderDispute::STATUS_WITHDRAWN,
        ],
        message: 'status must be one of: in_review, resolved_won, resolved_lost, withdrawn.',
    )]
    public readonly ?string $status;

    #[Assert\Length(
        max: 2000,
        maxMessage: 'resolution_note must be 2000 characters or fewer.',
    )]
    public readonly ?string $resolution_note;

    public function __construct(?string $status = null, ?string $resolution_note = null)
    {
        $this->status = $status;
        $this->resolution_note = $resolution_note;
    }
}
