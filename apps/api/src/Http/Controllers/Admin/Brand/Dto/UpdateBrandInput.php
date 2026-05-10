<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Brand\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body shape for PUT /v3/admin/brands/{id}.
 *
 * PUT semantics — all fields settable, name required. Slug present
 * in body REPLACES current slug (admin can rename URL).
 */
final class UpdateBrandInput
{
    #[Assert\NotBlank(message: 'name is required.')]
    #[Assert\Length(max: 150)]
    public readonly string $name;

    #[Assert\Length(max: 100)]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        message: 'slug must be lowercase kebab-case ASCII.',
    )]
    public readonly ?string $slug;

    #[Assert\Length(max: 500)]
    #[Assert\Url]
    public readonly ?string $logo_url;

    public readonly ?bool $is_active;

    public function __construct(
        string $name = '',
        ?string $slug = null,
        ?string $logo_url = null,
        ?bool $is_active = null,
    ) {
        $this->name = trim($name);
        $slug = $slug !== null ? trim($slug) : null;
        $this->slug = ($slug === '' || $slug === null) ? null : $slug;
        $this->logo_url = $logo_url !== null ? trim($logo_url) : null;
        $this->is_active = $is_active;
    }
}
