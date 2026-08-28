<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\User\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body for PUT /v3/admin/users/{id}.
 *
 * Admin support-edit of a user's CONTACT details (name / email / phone).
 * Deliberately narrow: no role, flag, password, or status changes here
 * (those have their own dedicated endpoints). An admin uses this to correct
 * a customer's details on their behalf.
 *
 *  - email is required + validated; changing it resets email-verification.
 *  - phone is optional. A non-empty value must be E.164 (same rule as the
 *    vendor contact phone + registration); empty string clears the phone.
 *    Changing it resets phone-verification (User::setPhone).
 *  - first/last name are optional (some accounts carry only one, or none).
 */
final class UpdateUserInput
{
    #[Assert\Length(max: 100)]
    public readonly ?string $first_name;

    #[Assert\Length(max: 100)]
    public readonly ?string $last_name;

    #[Assert\NotBlank(message: 'Email is required.')]
    #[Assert\Email(message: 'Please provide a valid email address.')]
    #[Assert\Length(max: 255)]
    public readonly string $email;

    #[Assert\Length(min: 8, max: 20)]
    #[Assert\Regex(
        pattern: '/^\+[1-9]\d{6,18}$/',
        message: 'Phone must be in international format, e.g. +9715XXXXXXXX.',
    )]
    public readonly ?string $phone;

    public function __construct(
        ?string $first_name = null,
        ?string $last_name = null,
        string $email = '',
        ?string $phone = null,
    ) {
        $first_name = $first_name !== null ? trim($first_name) : null;
        $this->first_name = $first_name === '' ? null : $first_name;

        $last_name = $last_name !== null ? trim($last_name) : null;
        $this->last_name = $last_name === '' ? null : $last_name;

        $this->email = strtolower(trim($email));

        // Strip formatting characters so "+971 50 123 4567" validates as
        // E.164, matching UpdateVendorInput's normalisation. Empty → null.
        $phone = $phone !== null ? (preg_replace('/[\s\-()]/', '', $phone) ?? '') : null;
        $this->phone = ($phone === '' || $phone === null) ? null : $phone;
    }
}
