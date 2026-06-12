<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\User\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/admin/users.
 *
 * Admin-initiated creation of a platform staff user (finance / support /
 * sub-admin). This is distinct from public self-registration
 * (POST /v3/auth/register), which creates phone-verified customer accounts
 * via OTP. Here an admin provisions a back-office account directly:
 *
 *  - No phone/OTP step — staff accounts are email-first and created active.
 *  - Roles are assigned explicitly from the admin form (finance / support /
 *    sub_admin). The customer/vendor flags are never set by this path.
 *  - Email uniqueness is enforced both here (friendly pre-flight) and by the
 *    DB UNIQUE constraint (race-safe).
 *
 * Password policy mirrors RegisterInput (NIST SP 800-63B 8-char minimum,
 * bcrypt 72-byte ceiling). Not trimmed — whitespace in passwords is valid.
 */
final class CreateUserInput
{
    #[Assert\NotBlank(message: 'First name is required.')]
    #[Assert\Length(max: 100)]
    public readonly string $first_name;

    #[Assert\NotBlank(message: 'Last name is required.')]
    #[Assert\Length(max: 100)]
    public readonly string $last_name;

    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Please provide a valid email address.')]
    #[Assert\Length(max: 255)]
    public readonly string $email;

    #[Assert\NotBlank(message: 'Password is required.')]
    #[Assert\Length(
        min: 8,
        minMessage: 'Password must be at least {{ limit }} characters.',
    )]
    public readonly string $password;

    public readonly bool $is_finance;
    public readonly bool $is_support;
    public readonly bool $is_sub_admin;

    public function __construct(
        string $first_name = '',
        string $last_name = '',
        string $email = '',
        string $password = '',
        bool $is_finance = false,
        bool $is_support = false,
        bool $is_sub_admin = false,
        // The portal form sends the sub-admin flag under '_sub_admin'.
        ?bool $_sub_admin = null,
    ) {
        $this->first_name = trim($first_name);
        $this->last_name = trim($last_name);
        $this->email = strtolower(trim($email));
        $this->password = $password; // Do NOT trim.
        $this->is_finance = $is_finance;
        $this->is_support = $is_support;
        $this->is_sub_admin = $_sub_admin ?? $is_sub_admin;
    }
}
