<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Wishlist\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input for POST /v3/me/wishlist — save a product.
 *
 * product_id is required. label_id is optional (Q-Z3=B): when present
 * and > 0, the saved product is filed under that label (must belong to
 * the user, else 404); omitted/null leaves it uncategorized. The
 * controller resolves the product (404 if missing/inactive) and is
 * idempotent: saving an already-saved product is a no-op success
 * (Q6.3) — but a provided label_id will (re)assign the label.
 */
final class AddWishlistItemInput
{
    #[Assert\NotNull(message: 'product_id is required.')]
    #[Assert\Positive(message: 'product_id must be a positive integer.')]
    public readonly ?int $product_id;

    #[Assert\Positive(message: 'label_id must be a positive integer.')]
    public readonly ?int $label_id;

    public function __construct(?int $product_id = null, ?int $label_id = null)
    {
        $this->product_id = $product_id;
        $this->label_id = $label_id;
    }
}
