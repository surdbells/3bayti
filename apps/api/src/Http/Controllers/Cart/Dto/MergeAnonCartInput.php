<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Cart\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /v3/cart/merge.
 *
 * Called by mobile right after sign-in for users who built a cart
 * as a guest. Body shape:
 *
 *   {
 *     "items": [
 *       { product_id, quantity, size, color, is_custom,
 *         measurement, extra_measurement, note },
 *       ...
 *     ]
 *   }
 *
 * Server merge semantics (locked Q7=B):
 *   For each incoming item, find an equivalent line in the user's
 *   server cart (matching product_id + size + color + is_custom +
 *   measurement + extra_measurement). If found, add the quantities
 *   (capped at 999 per line). If not, append a new line.
 *
 * Empty items[] is a no-op success, first sign-in for users with
 * no guest cart returns the empty server cart in 200 OK.
 *
 * Unknown product_ids are SKIPPED (not failed) by the controller -
 * deleting a product shouldn't block sign-in. Skipped items return
 * under `skipped` in the response.
 *
 * Why items is `array` not `list<MergeAnonCartItem>`:
 *   RequestValidator's hydrator is a single-level constructor
 *   mapper, it can't auto-instantiate nested DTOs. The controller
 *   handles per-item normalisation inline. Validator's `All`
 *   constraint with a nested `Collection` runs the field-level
 *   checks (positive int, length caps, etc.) without needing the
 *   nested DTO wrapper class.
 */
final class MergeAnonCartInput
{
    /** @var list<array<string, mixed>> */
    #[Assert\Type(type: 'array', message: 'items must be an array.')]
    #[Assert\Count(
        max: 200,
        maxMessage: 'items can contain at most {{ limit }} entries.',
    )]
    #[Assert\All([
        new Assert\Collection(
            fields: [
                'product_id' => [
                    new Assert\NotNull(message: 'product_id is required.'),
                    new Assert\Type(type: 'integer', message: 'product_id must be an integer.'),
                    new Assert\Positive(message: 'product_id must be a positive integer.'),
                ],
                'quantity' => [
                    new Assert\NotNull(message: 'quantity is required.'),
                    new Assert\Type(type: 'integer', message: 'quantity must be an integer.'),
                    new Assert\Range(min: 1, max: 999),
                ],
                'size' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 50)]),
                'color' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 50)]),
                'is_custom' => new Assert\Optional([new Assert\Type('boolean')]),
                'measurement' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 2000)]),
                'extra_measurement' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 2000)]),
                'note' => new Assert\Optional([new Assert\Type('string'), new Assert\Length(max: 500)]),
            ],
            allowExtraFields: true, // mobile may pass extra fields we ignore
            allowMissingFields: false,
        ),
    ])]
    public readonly array $items;

    /**
     * @param list<array<string, mixed>>|null $items
     */
    public function __construct(?array $items = null)
    {
        $this->items = $items ?? [];
    }
}
