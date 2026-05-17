<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body for vendor state-transition endpoints (M3.2.X.6-C):
 *
 *   POST /v3/admin/vendors/{id}/approve
 *   POST /v3/admin/vendors/{id}/suspend
 *   POST /v3/admin/vendors/{id}/reactivate
 *
 * Shared because all three transitions accept the same shape — an
 * optional operator-supplied reason. Reason is recommended for
 * suspend (operationally important for the vendor + audit trail)
 * and optional for approve/reactivate.
 *
 * The reason is persisted to vendors.status_reason on transition
 * and also captured in the audit log's before/after diff. The
 * audit log carries the full reason history; the entity column
 * only carries the most recent value.
 */
final class VendorTransitionInput
{
    /**
     * Free-text reason for the state transition. Stored to
     * vendors.status_reason. Max length matches the practical
     * upper bound for human-readable triage notes; longer reasons
     * should go in attached internal documentation, not on the
     * vendor record.
     */
    #[Assert\Length(max: 1000)]
    public readonly ?string $reason;

    public function __construct(?string $reason = null)
    {
        $this->reason = $reason;
    }
}
