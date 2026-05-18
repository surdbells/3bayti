<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Order\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /v3/admin/returns/{id}/deny (M3.2.X.18-F).
 *
 * Per the X.18-A aggregate root invariant: deny REQUIRES non-empty
 * admin_notes (the customer deserves to know why). The DTO enforces
 * this at the HTTP boundary; the entity re-enforces at construction
 * time (defense in depth).
 */
final class DenyReturnInput
{
    public function __construct(
        #[Assert\NotBlank(message: 'admin_notes is required when denying a return.')]
        #[Assert\Length(
            min: 5,
            max: 2000,
            minMessage: 'admin_notes must be at least {{ limit }} characters.',
            maxMessage: 'admin_notes must be at most {{ limit }} characters.',
        )]
        public readonly string $admin_notes = '',
    ) {
    }
}
