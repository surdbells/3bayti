<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Address\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for PATCH /v3/me/billing-address.
 *
 * Upsert semantics: this DTO is used whether the user has an existing
 * billing address (UPDATE) or is setting one for the first time
 * (CREATE). In both modes, the required fields (recipient_name,
 * recipient_phone, emirate, area) must be present.
 *
 * Why required fields on PATCH
 * ----------------------------
 * For the UPDATE case, RFC 7396 (JSON Merge Patch) says omitted fields
 * stay unchanged. But for the CREATE case, we can't create an entity
 * with NULL recipient_name (NOT NULL in schema). To resolve this
 * tension cleanly, we treat this endpoint as an upsert that ALWAYS
 * requires the four mandatory fields, same as POST /v3/me/addresses.
 *
 * The reasoning: a customer "updating" their billing address rarely
 * leaves the name blank. The CREATE flow benefits from a single
 * coherent payload. The UPDATE flow loses the merge-patch convenience
 * of "leave this alone" but gains predictability.
 *
 * If a future need surfaces for true field-level patching of the
 * billing address, we can add a separate endpoint or accept partial
 * fields here behind a feature flag. M3.1.1 ships the simple shape.
 *
 * Mirrors UpdateAddressInput
 * --------------------------
 * Same field rules as UpdateAddressInput (the shipping-address PUT
 * input). Duplicated rather than inherited because Symfony validation
 * attributes don't compose cleanly across class boundaries and the
 * cost of duplication is small enough to be acceptable.
 */
final class UpsertBillingAddressInput
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
        // Strip whitespace/hyphens/parens from phone, then validate.
        // Same pattern as CreateAddressInput.
        $this->recipient_phone = preg_replace('/[\s\-()]/', '', $recipient_phone) ?? '';
        $this->emirate = trim($emirate);
        $this->area = trim($area);
        $this->street_address = $street_address !== null ? trim($street_address) : null;
        $this->building_details = $building_details !== null ? trim($building_details) : null;
        $this->postal_code = $postal_code !== null ? trim($postal_code) : null;
        $this->label = $label !== null ? trim($label) : null;
    }
}
