<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\VendorApplication\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body shape for POST /v3/admin/vendor-applications/{id}/reject.
 *
 * reason is optional free text surfaced back to the applicant + stored
 * on the application for the audit trail.
 */
final class RejectVendorApplicationInput
{
    #[Assert\Length(max: 2000)]
    public readonly ?string $reason;

    public function __construct(?string $reason = null)
    {
        $reason = $reason !== null ? trim($reason) : null;
        $this->reason = ($reason === '' || $reason === null) ? null : $reason;
    }
}
