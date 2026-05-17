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

    public function __construct(
        string $name = '',
        string $contact_email = '',
        ?string $slug = null,
        ?string $description = null,
        ?string $logo_url = null,
        ?string $cover_image_url = null,
        ?string $contact_phone = null,
        ?float $commission_rate = null,
        ?bool $is_active = null,
        ?bool $is_verified = null,
        ?bool $is_featured = null,
        ?string $preferred_locale = null,
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
        $this->is_active = $is_active;
        $this->is_verified = $is_verified;
        $this->is_featured = $is_featured;
        $this->preferred_locale = $preferred_locale !== null ? trim($preferred_locale) : null;
    }
}
