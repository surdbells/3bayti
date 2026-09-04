<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Vendor\Onboarding\Dto;

use Bayti\Api\Domain\Common\PhoneNumber;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body for POST /v3/vendor/onboarding/submit (M3.2.X.6-D).
 *
 * Vendor self-serve onboarding submission. Any authenticated user
 * may submit; the resulting Vendor record lands in 'pending' state
 * for admin review.
 *
 * Required: slug, name, contact_email.
 * Optional: description, contact_phone, legal_name.
 *
 * Validation rules mirror the admin CreateVendorInput shape, slugs
 * are lowercase kebab-case ASCII, emails are RFC-5321 conforming,
 * etc., so that a self-submitted vendor + admin-created vendor
 * have identical schema integrity.
 *
 * Per Q-OnboardingFlow=A (the implicit Option A locked in the M3.2.X.6
 * plan): admin approval is required before the vendor goes live.
 * This endpoint creates the Vendor entity but leaves it pending.
 *
 * Property assignment pattern matches CreateVendorInput, explicit
 * constructor with default values + trim() normalization so
 * RequestValidator's reflection-based instantiation works correctly.
 */
final class SubmitOnboardingInput
{
    /**
     * URL-safe identifier for the vendor's storefront. Must be
     * unique across all vendors. Lowercase kebab-case ASCII.
     */
    #[Assert\NotBlank(message: 'slug is required.')]
    #[Assert\Length(min: 2, max: 100)]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        message: 'slug must be lowercase kebab-case ASCII.',
    )]
    public readonly string $slug;

    /**
     * Human-readable store name. Visible to customers once approved.
     */
    #[Assert\NotBlank(message: 'name is required.')]
    #[Assert\Length(max: 200)]
    public readonly string $name;

    /**
     * Contact email for vendor-facing communications (order
     * notifications, admin messages). NOT necessarily the same
     * as the user's account email, vendors may want to route
     * business communications to a separate inbox.
     */
    #[Assert\NotBlank(message: 'contact_email is required.')]
    #[Assert\Email(message: 'contact_email must be a valid email.')]
    #[Assert\Length(max: 255)]
    public readonly string $contact_email;

    /**
     * Optional store description. Free-form text.
     */
    public readonly ?string $description;

    /**
     * Optional vendor contact phone in E.164 format.
     */
    #[Assert\Length(min: 8, max: 20)]
    #[Assert\Regex(
        pattern: '/^\+[1-9]\d{6,18}$/',
        message: 'contact_phone must be E.164 (+ country code + digits).',
    )]
    public readonly ?string $contact_phone;

    /**
     * Optional legal/business name. Often distinct from the
     * customer-facing name (e.g. "Acme LLC" vs "Acme Designs").
     */
    #[Assert\Length(max: 200)]
    public readonly ?string $legal_name;

    public function __construct(
        string $slug = '',
        string $name = '',
        string $contact_email = '',
        ?string $description = null,
        ?string $contact_phone = null,
        ?string $legal_name = null,
    ) {
        $this->slug = trim($slug);
        $this->name = trim($name);
        $this->contact_email = trim($contact_email);
        $this->description = $description !== null ? trim($description) : null;
        // Canonicalise to E.164 so a locally-entered UAE number is accepted
        // and stored as "+9715…" instead of failing the strict assertion.
        $phone = $contact_phone !== null ? trim($contact_phone) : null;
        $this->contact_phone = ($phone === null || $phone === '')
            ? null
            : (PhoneNumber::toE164($phone) ?? $phone);
        $this->legal_name = $legal_name !== null ? trim($legal_name) : null;
    }
}
