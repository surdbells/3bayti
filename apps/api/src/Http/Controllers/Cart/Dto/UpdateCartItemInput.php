<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Cart\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for PATCH /v3/cart/items/{id}.
 *
 * Mobile's legacy IncreaseItem + decreaseItem endpoints take a
 * delta. v3 unifies these into a single PATCH that sets the
 * absolute quantity (idempotent, no sequence number needed).
 *
 * Setting quantity = 0 is rejected at this layer; clients call
 * DELETE /v3/cart/items/{id} instead.
 */
final class UpdateCartItemInput
{
    #[Assert\NotNull(message: 'quantity is required.')]
    #[Assert\Type(type: 'integer', message: 'quantity must be an integer.')]
    #[Assert\Range(
        min: 1,
        max: 999,
        notInRangeMessage: 'quantity must be between {{ min }} and {{ max }} (use DELETE to remove).',
    )]
    public readonly ?int $quantity;

    public function __construct(?int $quantity = null)
    {
        $this->quantity = $quantity;
    }
}
