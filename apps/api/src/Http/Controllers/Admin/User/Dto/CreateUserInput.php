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

    /**
     * Optional initial RBAC role ids assigned at creation. Letting an admin
     * stamp a role on the new account means the user is "born" with a role and
     * is therefore immediately visible + manageable on the Staff screen, rather
     * than appearing only after a separate role-assignment round-trip.
     *
     * @var list<int>
     */
    public readonly array $role_ids;

    /**
     * @param array<int|string, mixed> $role_ids
     */
    public function __construct(
        string $first_name = '',
        string $last_name = '',
        string $email = '',
        string $password = '',
        bool $is_finance = false,
        bool $is_support = false,
        bool $is_sub_admin = false,
        array $role_ids = [],
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
        $this->role_ids = array_values(array_unique(array_filter(
            array_map(static fn ($i): int => (int) $i, $role_ids),
            static fn (int $i): bool => $i > 0,
        )));
    }
}
