<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Wishlist\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/me/wishlist — save a product.
 *
 * Just the product id. The controller resolves it to a Product (404
 * if missing/inactive) and is idempotent: saving an already-saved
 * product is a no-op success (Q6.3), not a 409.
 */
final class AddWishlistItemInput
{
    #[Assert\NotNull(message: 'product_id is required.')]
    #[Assert\Positive(message: 'product_id must be a positive integer.')]
    public readonly ?int $product_id;

    public function __construct(?int $product_id = null)
    {
        $this->product_id = $product_id;
    }
}
