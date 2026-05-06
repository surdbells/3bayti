<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Auth\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/auth/register.
 *
 * Minimum-viable registration — collects only the fields required
 * to create the User row + send an OTP. Names are optional and
 * filled in later via /v3/account/profile.
 *
 * Why password validation is stricter here than in LoginInput
 * -----------------------------------------------------------
 * On registration we set the policy; on login we just verify.
 * Stricter rules at registration prevent weak passwords from
 * entering the system; lenient rules at login let legacy users
 * with shorter passwords keep working until they next reset.
 *
 * The 8-char min is consistent with NIST SP 800-63B's "user-
 * chosen secret" minimum (which it sets at 8 chars, with the
 * caveat that systems should support up to 64+ char passphrases).
 *
 * No max — bcrypt handles up to 72 bytes natively, and we hash
 * with PASSWORD_BCRYPT (PASSWORD_DEFAULT in PHP 8.x). Anything
 * beyond 72 bytes is silently truncated by bcrypt; that's a known
 * limitation, not a security flaw, and one we accept for legacy
 * compatibility (M5 may move to argon2id).
 *
 * Country code
 * ------------
 * ISO 3166-1 alpha-2. Defaults to 'AE' (UAE) since the platform
 * is UAE-only for now. The User entity uppercases on construction
 * regardless.
 *
 * Phone format normalisation
 * --------------------------
 * Same as ValidatePhoneInput — strips spaces/hyphens/parens before
 * the Regex check.
 */
final class RegisterInput
{
    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Please provide a valid email address.')]
    #[Assert\Length(max: 255)]
    public readonly string $email;

    #[Assert\NotBlank(message: 'Phone is required.')]
    #[Assert\Length(min: 8, max: 20)]
    #[Assert\Regex(
        pattern: '/^\+[1-9]\d{6,18}$/',
        message: 'Phone must be in E.164 format with leading "+" and country code.',
    )]
    public readonly string $phone;

    #[Assert\NotBlank(message: 'Password is required.')]
    #[Assert\Length(
        min: 8,
        minMessage: 'Password must be at least {{ limit }} characters.',
    )]
    public readonly string $password;

    #[Assert\Length(max: 2)]
    #[Assert\Regex(
        pattern: '/^[A-Z]{2}$/',
        message: 'Country code must be 2 uppercase letters (ISO 3166-1 alpha-2).',
    )]
    public readonly string $country_code;

    #[Assert\Length(max: 100)]
    public readonly ?string $first_name;

    #[Assert\Length(max: 100)]
    public readonly ?string $last_name;

    public function __construct(
        string $email = '',
        string $phone = '',
        string $password = '',
        string $country_code = 'AE',
        ?string $first_name = null,
        ?string $last_name = null,
    ) {
        $this->email = trim($email);
        $this->phone = preg_replace('/[\s\-()]/', '', $phone) ?? '';
        $this->password = $password; // Do NOT trim — passwords with whitespace are valid.
        $this->country_code = strtoupper(trim($country_code));
        $this->first_name = $first_name !== null ? trim($first_name) : null;
        $this->last_name = $last_name !== null ? trim($last_name) : null;
    }
}
