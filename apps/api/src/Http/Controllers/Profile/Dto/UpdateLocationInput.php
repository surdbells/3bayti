<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Profile\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for PATCH /v3/me/location.
 *
 * Partial-update semantics (RFC 7396 Merge Patch flavour): every field
 * is optional. Fields omitted from the request body are left unchanged
 * on the entity. The entity's update() method handles the "null means
 * unchanged" convention.
 *
 * Coordinate pairing
 * ------------------
 * Latitude and longitude must be present together or not at all. The
 * #[Assert\Callback] below enforces this at validation time, sending
 * only one results in 422. The entity defensively ignores partial
 * coords too, but catching at the validation layer gives a better
 * error message.
 *
 * Coordinate ranges
 * -----------------
 *   latitude  : -90 to +90
 *   longitude : -180 to +180
 *
 * Values outside these ranges are physically impossible (the GPS
 * stack would never emit them). Reject as invalid input.
 *
 * Country code
 * ------------
 * ISO 3166-1 alpha-2, exactly 2 letters. Validation accepts any case
 * (the entity uppercases). Lowercase 'ae' is fine on input; entity
 * stores 'AE'.
 *
 * Permission flag
 * ---------------
 * permission_granted is the only field that gets explicitly set to
 * false in practice (the OS-permission-denied case). The entity stores
 * it as a non-nullable bool defaulting to false; this DTO accepts
 * null to mean "leave alone".
 *
 * Why no required fields
 * ----------------------
 * Three legitimate client workflows produce partial bodies:
 *   1. First-launch flow: client sends {permission_granted: false}
 *      after user denies the OS permission. No lat/lng. No city.
 *   2. Successful capture: client sends {latitude, longitude,
 *      permission_granted: true}. No city (server may geocode later).
 *   3. Manual city entry: client sends {city, country_code}. No
 *      coordinates (user typed it).
 *
 * Requiring any field would break one of these flows.
 */
final class UpdateLocationInput
{
    /**
     * Decimal latitude in degrees. Optional.
     */
    #[Assert\Range(
        min: -90,
        max: 90,
        notInRangeMessage: 'latitude must be between -90 and 90.',
    )]
    public readonly ?float $latitude;

    /**
     * Decimal longitude in degrees. Optional.
     */
    #[Assert\Range(
        min: -180,
        max: 180,
        notInRangeMessage: 'longitude must be between -180 and 180.',
    )]
    public readonly ?float $longitude;

    /**
     * City name. Optional. Pass empty string to clear an existing
     * city (the entity treats '' as "clear this field").
     */
    #[Assert\Length(
        max: 100,
        maxMessage: 'city must not exceed 100 characters.',
    )]
    public readonly ?string $city;

    /**
     * ISO 3166-1 alpha-2 country code. Optional. Pass empty string
     * to clear. Case-insensitive on input; stored uppercase.
     */
    #[Assert\Length(
        min: 2,
        max: 2,
        exactMessage: 'country_code must be exactly 2 letters (ISO 3166-1 alpha-2).',
    )]
    #[Assert\Regex(
        pattern: '/^[a-zA-Z]{2}$/',
        message: 'country_code must contain only letters.',
    )]
    public readonly ?string $country_code;

    /**
     * Whether the user has granted OS-level location permission.
     * Optional; pass true/false to set, null to leave unchanged.
     */
    public readonly ?bool $permission_granted;

    public function __construct(
        ?float $latitude = null,
        ?float $longitude = null,
        ?string $city = null,
        ?string $country_code = null,
        ?bool $permission_granted = null,
    ) {
        $this->latitude = $latitude;
        $this->longitude = $longitude;
        // Pre-trim string fields. Don't lowercase country_code here -
        // the entity uppercases on write. Don't reject the empty
        // string, that's the documented "clear this field" signal.
        $this->city = $city !== null ? trim($city) : null;
        $this->country_code = $country_code !== null ? trim($country_code) : null;
        $this->permission_granted = $permission_granted;
    }

    /**
     * Cross-field validator: latitude and longitude must be present
     * together or not at all.
     */
    #[Assert\Callback]
    public function validateCoordinatePairing(\Symfony\Component\Validator\Context\ExecutionContextInterface $context): void
    {
        // Special case: empty-string "clear" doesn't apply to floats,
        // so for lat/lng we only care about null vs not-null.
        $hasLat = $this->latitude !== null;
        $hasLng = $this->longitude !== null;

        if ($hasLat !== $hasLng) {
            $context->buildViolation('latitude and longitude must be provided together.')
                ->atPath('latitude')
                ->addViolation();
        }
    }
}
