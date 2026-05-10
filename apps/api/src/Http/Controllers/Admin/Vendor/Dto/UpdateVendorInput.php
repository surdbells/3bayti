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
    }
}
