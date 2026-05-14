<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for PATCH /v3/me/password.
 *
 * Two-field body: the user must prove possession of the current
 * password before setting a new one. This is the canonical
 * authenticated-password-change pattern.
 *
 * Why both fields are required
 * ----------------------------
 *   - current_password: defense against session-hijack abuse. Even
 *     if an attacker steals an access token, they can't lock the
 *     real owner out by changing the password without knowing the
 *     existing one. This is the "re-auth check" that every consumer
 *     service requires (Gmail, Banks, Apple, etc.).
 *   - new_password: minimum 8 chars per NIST SP 800-63B; no complexity
 *     rules (mixed case, special chars) — those reduce entropy in
 *     practice. Matches ResetConfirmInput's policy for consistency.
 *
 * Distinct from password reset
 * ----------------------------
 * POST /v3/auth/reset/confirm uses an OTP as the auth credential
 * (user forgot the password). This endpoint uses the existing
 * password (user knows it, wants to rotate). The two should never
 * be confused: rate-limiting rules, audit categorisation, and
 * client UX are all different.
 *
 * What this DTO does NOT validate
 * --------------------------------
 *   - That current_password is correct (controller does that via
 *     password_verify on the user's hash)
 *   - That new_password != current_password (controller does that
 *     after both are known to be valid format)
 *   - Common-password blocklist (M4+ hardening)
 *   - Per-user password history (M4+ if regulatory drives it)
 */
final class ChangePasswordInput
{
    #[Assert\NotBlank(message: 'current_password is required.')]
    public readonly string $current_password;

    #[Assert\NotBlank(message: 'new_password is required.')]
    #[Assert\Length(
        min: 8,
        max: 200,
        minMessage: 'new_password must be at least {{ limit }} characters.',
        maxMessage: 'new_password must not exceed {{ limit }} characters.',
    )]
    public readonly string $new_password;

    public function __construct(
        string $current_password = '',
        string $new_password = '',
    ) {
        // Do NOT trim passwords. Legacy passwords with whitespace
        // exist (Day 4 migration preserves them as-is). Trimming
        // would break login for those users. The login flow also
        // does not trim — keep symmetric.
        $this->current_password = $current_password;
        $this->new_password = $new_password;
    }
}
