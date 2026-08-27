<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Address\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/me/addresses.
 *
 * Required fields: recipient_name, recipient_phone, emirate, area.
 * Everything else optional.
 *
 * Why these fields are required
 * -----------------------------
 *   - recipient_name + recipient_phone: courier must be able to call
 *     the recipient. Even if recipient is the user themselves,
 *     storing snapshots makes addresses portable for gifts.
 *   - emirate + area: minimum to compute shipping fees and route.
 *     The Topex API rejects requests missing either.
 *
 * Why street_address is optional
 * ------------------------------
 * Some UAE addresses don't have street names, they're identified by
 * landmarks ("villa next to ADCB Jumeirah branch"). The
 * building_details field is where that goes, plus the recipient_phone
 * for live coordination on delivery.
 *
 * Phone format normalisation
 * --------------------------
 * Same as RegisterInput: strip whitespace/hyphens/parens, validate
 * E.164 with leading "+".
 */
final class CreateAddressInput
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

    /**
     * No length cap on building_details, the column is TEXT to allow
     * detailed delivery instructions. The DB and JSON parser will
     * cap at MEDIUMTEXT-ish if abuse hits.
     */
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

    /**
     * If true, this address becomes the user's default after creation.
     * If the user already has a default, it gets unset.
     *
     * If the user has NO addresses at all, the first one created
     * becomes the default automatically regardless of this flag -
     * the controller handles that.
     */
    public readonly bool $is_default;

    public function __construct(
        string $recipient_name = '',
        string $recipient_phone = '',
        string $emirate = '',
        string $area = '',
        ?string $street_address = null,
        ?string $building_details = null,
        ?string $postal_code = null,
        ?string $label = null,
        bool $is_default = false,
    ) {
        $this->recipient_name = trim($recipient_name);
        $this->recipient_phone = preg_replace('/[\s\-()]/', '', $recipient_phone) ?? '';
        $this->emirate = trim($emirate);
        $this->area = trim($area);
        $this->street_address = $street_address !== null ? trim($street_address) : null;
        $this->building_details = $building_details !== null ? trim($building_details) : null;
        $this->postal_code = $postal_code !== null ? trim($postal_code) : null;
        $this->label = $label !== null ? trim($label) : null;
        $this->is_default = $is_default;
    }
}
