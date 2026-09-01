<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body for PUT /v3/admin/vendors/{id}.
 *
 * Required fields stay required (name, contact_email). All other
 * fields optional. is_active / is_verified can be toggled via PUT.
 */
final class UpdateVendorInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 200)]
    public readonly string $name;

    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 255)]
    public readonly string $contact_email;

    #[Assert\Length(max: 100)]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        message: 'slug must be lowercase kebab-case ASCII.',
    )]
    public readonly ?string $slug;

    public readonly ?string $description;

    #[Assert\Length(max: 500)]
    #[Assert\Url]
    public readonly ?string $logo_url;

    #[Assert\Length(max: 500)]
    #[Assert\Url]
    public readonly ?string $cover_image_url;

    #[Assert\Length(min: 8, max: 20)]
    #[Assert\Regex(pattern: '/^\+[1-9]\d{6,18}$/')]
    public readonly ?string $contact_phone;

    #[Assert\Range(min: 0, max: 100)]
    public readonly ?float $commission_rate;

    /**
     * Delivery lead-time range in days shown to customers ("X-Y days").
     * Both must be sent together to change the range; either omitted leaves
     * the existing values. 0-365; the entity keeps min <= max.
     */
    #[Assert\Range(min: 0, max: 365)]
    public readonly ?int $min_delivery_days;

    #[Assert\Range(min: 0, max: 365)]
    public readonly ?int $max_delivery_days;

    public readonly ?bool $is_active;
    public readonly ?bool $is_verified;

    /**
     * Designer Spotlight curation flag (M3.2.X.2-D).
     *
     * When true: vendor appears on /v3/featured-vendors (the apps/web
     * home-page Designer Spotlight surface), provided is_active is
     * also true.
     *
     * When false: vendor is hidden from the Spotlight surface but
     * remains visible everywhere else (vendor listing, slug pages,
     * product attribution).
     *
     * When null (omitted from request): no change. Existing flag
     * value preserved. Matches the pattern of is_active / is_verified.
     */
    public readonly ?bool $is_featured;

    /**
     * Vendor's preferred locale for email notifications (M3.2.X.7-D).
     *
     * - 'en' / 'ar' / null
     * - null means no preference; falls back to English at send time
     *
     * Q-VendorAdminLocale = A locked: vendor businesses opt in
     * independently of their owner user's locale because:
     *   - A vendor business may be Arabic-first even if individual
     *     staff members prefer English (or vice versa)
     *   - The Vendor entity is shared across multiple staff users in
     *     larger businesses
     *
     * Setting null clears the preference (falls back to English).
     * Field omitted from request → no change to existing value.
     */
    #[Assert\Choice(
        choices: ['en', 'ar'],
        message: 'preferred_locale must be one of: en, ar.',
    )]
    public readonly ?string $preferred_locale;

    /**
     * Emirate the store operates from. One of the seven UAE emirates;
     * null/omitted leaves the existing value unchanged. Empty string is
     * normalised to null (clears the value).
     */
    #[Assert\Length(max: 60)]
    public readonly ?string $emirate;

    /**
     * Store country (UAE-only platform, conceptually always
     * "United Arab Emirates"). Null/omitted leaves the existing value
     * unchanged; empty string clears it.
     */
    #[Assert\Length(max: 60)]
    public readonly ?string $country;

    public function __construct(
        string $name = '',
        string $contact_email = '',
        ?string $slug = null,
        ?string $description = null,
        ?string $logo_url = null,
        ?string $cover_image_url = null,
        ?string $contact_phone = null,
        ?float $commission_rate = null,
        ?int $min_delivery_days = null,
        ?int $max_delivery_days = null,
        ?bool $is_active = null,
        ?bool $is_verified = null,
        ?bool $is_featured = null,
        ?string $preferred_locale = null,
        ?string $emirate = null,
        ?string $country = null,
    ) {
        $this->name = trim($name);
        $this->contact_email = trim($contact_email);
        $slug = $slug !== null ? trim($slug) : null;
        $this->slug = ($slug === '' || $slug === null) ? null : $slug;
        $this->description = $description !== null ? trim($description) : null;
        $this->logo_url = $logo_url !== null ? trim($logo_url) : null;
        $this->cover_image_url = $cover_image_url !== null ? trim($cover_image_url) : null;
        $this->contact_phone = $contact_phone !== null
            ? (preg_replace('/[\s\-()]/', '', $contact_phone) ?? '')
            : null;
        $this->commission_rate = $commission_rate;
        $this->min_delivery_days = $min_delivery_days;
        $this->max_delivery_days = $max_delivery_days;
        $this->is_active = $is_active;
        $this->is_verified = $is_verified;
        $this->is_featured = $is_featured;
        $this->preferred_locale = $preferred_locale !== null ? trim($preferred_locale) : null;
        $emirate = $emirate !== null ? trim($emirate) : null;
        $this->emirate = ($emirate === '' || $emirate === null) ? null : $emirate;
        $country = $country !== null ? trim($country) : null;
        $this->country = ($country === '' || $country === null) ? null : $country;
    }
}
