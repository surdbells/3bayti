<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Cart\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /v3/cart/items.
 *
 * Maps from mobile's legacy add_cart body shape (per Phase 0 audit
 * of product.page.ts):
 *
 *   Legacy add_cart    →  v3 add-cart-item
 *   product_id         →  product_id
 *   quantity           →  quantity (default 1)
 *   size               →  size
 *   color              →  color
 *   is_custom          →  is_custom (boolean)
 *   measurement        →  measurement
 *   extra_measurement  →  extra_measurement
 *   note               →  note
 *
 * Legacy fields NOT included (server derives them):
 *   store, discount, product_name, product_desc, product_image,
 *   customer_name, customer_email, price, cart_code
 *
 * Validator hydrates via constructor params (RequestValidator
 * convention); properties are readonly and copy from params.
 */
final class AddCartItemInput
{
    #[Assert\NotNull(message: 'product_id is required.')]
    #[Assert\Type(type: 'integer', message: 'product_id must be an integer.')]
    #[Assert\Positive(message: 'product_id must be a positive integer.')]
    public readonly ?int $product_id;

    #[Assert\NotNull(message: 'quantity is required.')]
    #[Assert\Type(type: 'integer', message: 'quantity must be an integer.')]
    #[Assert\Range(min: 1, max: 999, notInRangeMessage: 'quantity must be between {{ min }} and {{ max }}.')]
    public readonly ?int $quantity;

    #[Assert\Length(max: 50, maxMessage: 'size is too long (max {{ limit }} chars).')]
    public readonly ?string $size;

    #[Assert\Length(max: 50, maxMessage: 'color is too long (max {{ limit }} chars).')]
    public readonly ?string $color;

    public readonly bool $is_custom;

    #[Assert\Length(max: 2000)]
    public readonly ?string $measurement;

    #[Assert\Length(max: 2000)]
    public readonly ?string $extra_measurement;

    #[Assert\Length(max: 500)]
    public readonly ?string $note;

    public function __construct(
        ?int $product_id = null,
        ?int $quantity = 1,
        ?string $size = null,
        ?string $color = null,
        ?bool $is_custom = false,
        ?string $measurement = null,
        ?string $extra_measurement = null,
        ?string $note = null,
    ) {
        $this->product_id = $product_id;
        $this->quantity = $quantity;
        $this->size = $size !== null ? trim($size) : null;
        $this->color = $color !== null ? trim($color) : null;
        $this->is_custom = $is_custom ?? false;
        $this->measurement = $measurement;
        $this->extra_measurement = $extra_measurement;
        $this->note = $note;
    }
}
