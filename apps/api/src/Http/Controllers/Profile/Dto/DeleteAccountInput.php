<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for DELETE /v3/me — account deletion.
 *
 * Deletion is a high-stakes, irreversible-feeling action, so it is
 * gated on re-authentication: the caller must supply their current
 * password (Q6.2). This mirrors ChangePasswordController's re-auth:
 * a wrong password yields 401 AUTH_INVALID_CREDENTIALS.
 *
 * Like ChangePasswordInput we do NOT trim the password — legacy
 * passwords may contain significant whitespace, and login/change
 * both compare untrimmed.
 */
final class DeleteAccountInput
{
    #[Assert\NotBlank(message: 'current_password is required.')]
    public readonly string $current_password;

    public function __construct(string $current_password = '')
    {
        $this->current_password = $current_password;
    }
}
