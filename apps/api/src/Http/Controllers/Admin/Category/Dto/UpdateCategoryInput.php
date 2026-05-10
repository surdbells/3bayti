<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Category\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Body for PUT /v3/admin/categories/{id}.
 *
 * NOTE: parent_id needs a "no change vs change to root" distinction
 * that our positional-args constructor can't express well. We adopt
 * the convention: parent_id absent in JSON = unchanged (PHP default
 * here is null in BOTH cases, undistinguishable). Workaround:
 * separate body field `move_to_root` (boolean) to explicitly mean
 * "set parent to null". If you want to keep current parent, omit
 * both parent_id and move_to_root.
 *
 * Tradeoff captured: real REST would use PATCH for this. PUT was
 * the existing pattern; we keep it consistent.
 */
final class UpdateCategoryInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 150)]
    public readonly string $name;

    public readonly ?int $parent_id;

    public readonly bool $move_to_root;

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

    public readonly ?bool $is_active;

    public function __construct(
        string $name = '',
        ?int $parent_id = null,
        bool $move_to_root = false,
        ?string $slug = null,
        ?string $description = null,
        ?int $display_order = null,
        ?string $image_url = null,
        ?bool $is_active = null,
    ) {
        $this->name = trim($name);
        $this->parent_id = $parent_id;
        $this->move_to_root = $move_to_root;
        $slug = $slug !== null ? trim($slug) : null;
        $this->slug = ($slug === '' || $slug === null) ? null : $slug;
        $this->description = $description !== null ? trim($description) : null;
        $this->display_order = $display_order;
        $this->image_url = $image_url !== null ? trim($image_url) : null;
        $this->is_active = $is_active;
    }
}
