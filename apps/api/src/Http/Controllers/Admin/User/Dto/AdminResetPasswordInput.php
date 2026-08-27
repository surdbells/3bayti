<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\User\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for PATCH /v3/admin/users/{id}/password.
 *
 * Admin-initiated password reset for another user's account. Unlike the
 * self-service change (PATCH /v3/account/password), this does NOT require
 * the target user's current password, an admin is overriding it, typically
 * to unblock a locked-out staff member or rotate a compromised credential.
 *
 * Because that is a powerful action, it is admin-tier-gated at the route and
 * audited in the controller. Password policy mirrors registration
 * (8-char NIST minimum, bcrypt 72-byte ceiling). Not trimmed.
 */
final class AdminResetPasswordInput
{
    #[Assert\NotBlank(message: 'New password is required.')]
    #[Assert\Length(
        min: 8,
        minMessage: 'Password must be at least {{ limit }} characters.',
    )]
    public readonly string $password;

    public function __construct(string $password = '')
    {
        $this->password = $password; // Do NOT trim.
    }
}
