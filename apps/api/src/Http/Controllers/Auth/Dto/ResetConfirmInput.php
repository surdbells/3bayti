<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/reset/confirm.
 *
 * Completes the password reset. Three fields:
 *   - verification_id: the opaque token from /reset
 *   - code:            the 6-digit OTP code from the SMS
 *   - new_password:    what to set the user's password to
 *
 * Password constraints
 * --------------------
 * Same as RegisterInput: min 8 chars. NIST SP 800-63B "user-chosen
 * secret" minimum. We don't enforce complexity rules (mixed case,
 * numbers, symbols) per NIST guidance — those reduce password
 * entropy without improving security in practice (users pick
 * 'Password1!' to satisfy them).
 *
 * Why not require old_password
 * ----------------------------
 * We don't ask for the OLD password because the whole point of
 * password reset is "I forgot it". The OTP verifies possession of
 * the registered phone, which is sufficient credential for resetting.
 * That's also how every major consumer service (Gmail, banks, etc.)
 * handles this flow.
 *
 * If a user wants to change their password while logged in (knowing
 * the old one), that's a separate /v3/account/password endpoint
 * coming in M1.7.
 */
final class ResetConfirmInput
{
    #[Assert\NotBlank(message: 'Verification id is required.')]
    #[Assert\Length(min: 1, max: 100)]
    public readonly string $verification_id;

    #[Assert\NotBlank(message: 'Code is required.')]
    #[Assert\Regex(
        pattern: '/^\d{6}$/',
        message: 'Code must be 6 digits.',
    )]
    public readonly string $code;

    #[Assert\NotBlank(message: 'New password is required.')]
    #[Assert\Length(
        min: 8,
        minMessage: 'Password must be at least {{ limit }} characters.',
    )]
    public readonly string $new_password;

    public function __construct(
        string $verification_id = '',
        string $code = '',
        string $new_password = '',
    ) {
        $this->verification_id = trim($verification_id);
        $this->code = trim($code);
        $this->new_password = $new_password; // Do NOT trim — passwords can contain whitespace.
    }
}
