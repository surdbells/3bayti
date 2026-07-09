<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Account\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body shape for the PUBLIC POST /v3/account/deletion-request.
 *
 * Required: email.
 * Optional: reason (free text), phone.
 *
 * This is the web-facing account + data deletion request form required
 * by Google Play. It is intentionally NOT auth-gated (social-only
 * accounts can't authenticate a DELETE) and does NOT confirm whether an
 * account exists (anti-enumeration — see the controller).
 *
 * Email is lowercased + trimmed in the constructor (mirrors the vendor
 * application DTO); phone is whitespace/format-stripped.
 */
final class RequestAccountDeletionInput
{
    #[Assert\NotBlank(message: 'email is required.')]
    #[Assert\Email(message: 'email must be a valid email.')]
    #[Assert\Length(max: 255)]
    public readonly string $email;

    #[Assert\Length(max: 2000)]
    public readonly ?string $reason;

    #[Assert\Length(max: 20)]
    public readonly ?string $phone;

    public function __construct(
        string $email = '',
        ?string $reason = null,
        ?string $phone = null,
    ) {
        $this->email = strtolower(trim($email));

        $reason = $reason !== null ? trim($reason) : null;
        $this->reason = ($reason === '' || $reason === null) ? null : $reason;

        $phone = $phone !== null ? preg_replace('/[\s\-()]/', '', $phone) : null;
        $this->phone = ($phone === '' || $phone === null) ? null : $phone;
    }
}
