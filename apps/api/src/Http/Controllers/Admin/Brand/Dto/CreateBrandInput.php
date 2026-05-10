<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Brand\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body shape for POST /v3/admin/brands.
 *
 * Slug is optional — if not provided, generated from name. If
 * provided, taken as-authoritative (admin override).
 */
final class CreateBrandInput
{
    #[Assert\NotBlank(message: 'name is required.')]
    #[Assert\Length(
        max: 150,
        maxMessage: 'name must not exceed 150 characters.',
    )]
    public readonly string $name;

    /**
     * Optional admin-provided slug. If absent, the controller
     * generates one from name via SlugHelper.
     *
     * Validation: kebab-case lowercase ASCII. We don't accept the
     * uppercase variants because slug uniqueness checks would have
     * to be case-insensitive — easier to enforce the canonical form
     * up front.
     */
    #[Assert\Length(max: 100)]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        message: 'slug must be lowercase kebab-case ASCII (e.g. "nike-shoes").',
    )]
    public readonly ?string $slug;

    #[Assert\Length(max: 500)]
    #[Assert\Url(message: 'logo_url must be a valid URL.')]
    public readonly ?string $logo_url;

    public function __construct(
        string $name = '',
        ?string $slug = null,
        ?string $logo_url = null,
    ) {
        $this->name = trim($name);
        // Slug arrives lowercase OR null. Trim defensive — empty
        // strings should be null.
        $slug = $slug !== null ? trim($slug) : null;
        $this->slug = ($slug === '' || $slug === null) ? null : $slug;
        $this->logo_url = $logo_url !== null ? trim($logo_url) : null;
    }
}
