<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile\Dto;

/**
 * Input for DELETE /v3/me, account deletion.
 *
 * Deletion is a high-stakes, irreversible action gated on re-authentication:
 * a PASSWORD account must supply its current_password (a wrong one yields 401
 * AUTH_INVALID_CREDENTIALS, mirroring ChangePasswordController). It is optional
 * here rather than NotBlank because SOCIAL-only accounts (Google/Apple sign-in,
 * password_hash IS NULL) have no password to re-enter — the controller enforces
 * the requirement conditionally on User::hasPassword().
 *
 * Like ChangePasswordInput we do NOT trim the password, legacy passwords may
 * contain significant whitespace, and login/change both compare untrimmed.
 */
final class DeleteAccountInput
{
    public readonly string $current_password;

    public function __construct(string $current_password = '')
    {
        $this->current_password = $current_password;
    }
}
