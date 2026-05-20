<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Wishlist\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/me/wishlist/labels and
 * PATCH /v3/me/wishlist/labels/{id} — the label name.
 */
final class WishlistLabelInput
{
    #[Assert\NotBlank(message: 'name is required.')]
    #[Assert\Length(max: 80, maxMessage: 'name must be 80 characters or fewer.')]
    public readonly ?string $name;

    public function __construct(?string $name = null)
    {
        $this->name = $name === null ? null : trim($name);
    }
}
