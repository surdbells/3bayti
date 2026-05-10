<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Category\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body for POST /v3/admin/categories.
 *
 * parent_id is the integer id of the parent category (null = root).
 * slug auto-generated from name if not provided.
 */
final class CreateCategoryInput
{
    #[Assert\NotBlank(message: 'name is required.')]
    #[Assert\Length(max: 150)]
    public readonly string $name;

    public readonly ?int $parent_id;

    #[Assert\Length(max: 100)]
    #[Assert\Regex(
        pattern: '/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
        message: 'slug must be lowercase kebab-case ASCII.',
    )]
    public readonly ?string $slug;

    public readonly ?string $description;

    public readonly ?int $display_order;

    #[Assert\Length(max: 500)]
    #[Assert\Url]
    public readonly ?string $image_url;

    public function __construct(
        string $name = '',
        ?int $parent_id = null,
        ?string $slug = null,
        ?string $description = null,
        ?int $display_order = null,
        ?string $image_url = null,
    ) {
        $this->name = trim($name);
        $this->parent_id = $parent_id;
        $slug = $slug !== null ? trim($slug) : null;
        $this->slug = ($slug === '' || $slug === null) ? null : $slug;
        $this->description = $description !== null ? trim($description) : null;
        $this->display_order = $display_order;
        $this->image_url = $image_url !== null ? trim($image_url) : null;
    }
}
