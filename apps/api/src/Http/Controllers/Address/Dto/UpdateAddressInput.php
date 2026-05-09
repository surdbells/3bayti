<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Address\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for PUT /v3/me/addresses/{id}.
 *
 * Full-replace semantics: all required fields must be present
 * (recipient_name, recipient_phone, emirate, area). Optional
 * fields can be omitted to clear them.
 *
 * Why PUT not PATCH
 * -----------------
 * Addresses are usually rewritten wholesale (you moved). Partial
 * updates of one field are rare. PUT is more honest for the use
 * case and avoids the JSON Merge Patch tristate ambiguity that
 * we have on /v3/me/profile.
 *
 * Why is_default isn't here
 * --------------------------
 * Default-flag changes go through PATCH /v3/me/addresses/{id}/default
 * which has its own body shape ({shipping, billing}) and well-defined
 * semantics. Mixing the address body and default flags would force
 * us to choose: does PUT preserve defaults? change them? clear them?
 * Splitting the concerns avoids the ambiguity.
 *
 * Identical validation to CreateAddressInput
 * -------------------------------------------
 * Same field rules. Could DRY this up via inheritance or a trait,
 * but PHP attributes don't compose nicely across class boundaries
 * and the duplication is small enough to be acceptable.
 */
final class UpdateAddressInput
{
    #[Assert\NotBlank(message: 'recipient_name is required.')]
    #[Assert\Length(
        max: 200,
        maxMessage: 'recipient_name must not exceed 200 characters.',
    )]
    public readonly string $recipient_name;

    #[Assert\NotBlank(message: 'recipient_phone is required.')]
    #[Assert\Length(min: 8, max: 20)]
    #[Assert\Regex(
        pattern: '/^\+[1-9]\d{6,18}$/',
        message: 'recipient_phone must be in E.164 format with leading "+" and country code.',
    )]
    public readonly string $recipient_phone;

    #[Assert\NotBlank(message: 'emirate is required.')]
    #[Assert\Length(
        max: 50,
        maxMessage: 'emirate must not exceed 50 characters.',
    )]
    public readonly string $emirate;

    #[Assert\NotBlank(message: 'area is required.')]
    #[Assert\Length(
        max: 100,
        maxMessage: 'area must not exceed 100 characters.',
    )]
    public readonly string $area;

    #[Assert\Length(
        max: 255,
        maxMessage: 'street_address must not exceed 255 characters.',
    )]
    public readonly ?string $street_address;

    public readonly ?string $building_details;

    #[Assert\Length(
        max: 20,
        maxMessage: 'postal_code must not exceed 20 characters.',
    )]
    public readonly ?string $postal_code;

    #[Assert\Length(
        max: 50,
        maxMessage: 'label must not exceed 50 characters.',
    )]
    public readonly ?string $label;

    public function __construct(
        string $recipient_name = '',
        string $recipient_phone = '',
        string $emirate = '',
        string $area = '',
        ?string $street_address = null,
        ?string $building_details = null,
        ?string $postal_code = null,
        ?string $label = null,
    ) {
        $this->recipient_name = trim($recipient_name);
        $this->recipient_phone = preg_replace('/[\s\-()]/', '', $recipient_phone) ?? '';
        $this->emirate = trim($emirate);
        $this->area = trim($area);
        $this->street_address = $street_address !== null ? trim($street_address) : null;
        $this->building_details = $building_details !== null ? trim($building_details) : null;
        $this->postal_code = $postal_code !== null ? trim($postal_code) : null;
        $this->label = $label !== null ? trim($label) : null;
    }
}
