<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Vendor\Dto;

use Bayti\Api\Domain\Common\PhoneNumber;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body shape for POST /v3/admin/vendors.
 *
 * Slug optional → auto-generated from name.
 * Commission rate defaults to 10.00 if omitted.
 */
final class CreateVendorInput
{
    #[Assert\NotBlank(message: 'name is required.')]
    #[Assert\Length(max: 200)]
    public readonly string $name;

    #[Assert\NotBlank(message: 'contact_email is required.')]
    #[Assert\Email(message: 'contact_email must be a valid email.')]
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
    #[Assert\Regex(
        pattern: '/^\+[1-9]\d{6,18}$/',
        message: 'contact_phone must be E.164 (+ country code + digits).',
    )]
    public readonly ?string $contact_phone;

    #[Assert\Range(min: 0, max: 100)]
    public readonly ?float $commission_rate;

    public function __construct(
        string $name = '',
        string $contact_email = '',
        ?string $slug = null,
        ?string $description = null,
        ?string $logo_url = null,
        ?string $cover_image_url = null,
        ?string $contact_phone = null,
        ?float $commission_rate = null,
    ) {
        $this->name = trim($name);
        $this->contact_email = trim($contact_email);
        $slug = $slug !== null ? trim($slug) : null;
        $this->slug = ($slug === '' || $slug === null) ? null : $slug;
        $this->description = $description !== null ? trim($description) : null;
        $this->logo_url = $logo_url !== null ? trim($logo_url) : null;
        $this->cover_image_url = $cover_image_url !== null ? trim($cover_image_url) : null;
        // Canonicalise to E.164 so a locally-entered UAE number is accepted
        // and stored as "+9715…" instead of failing the strict assertion.
        $phone = $contact_phone !== null ? trim($contact_phone) : null;
        $this->contact_phone = ($phone === null || $phone === '')
            ? null
            : (PhoneNumber::toE164($phone) ?? $phone);
        $this->commission_rate = $commission_rate;
    }
}
