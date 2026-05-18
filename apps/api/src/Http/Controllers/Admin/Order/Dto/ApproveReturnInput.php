<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /v3/admin/returns/{id}/approve (M3.2.X.18-F).
 *
 * admin_notes is optional on approval (the customer doesn't need
 * a justification when approved); only deny requires it.
 */
final class ApproveReturnInput
{
    public function __construct(
        #[Assert\Length(
            max: 2000,
            maxMessage: 'admin_notes must be at most {{ limit }} characters.',
        )]
        public readonly ?string $admin_notes = null,
    ) {
    }
}
