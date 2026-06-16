<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Cart\Dto;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Input DTO for POST /v3/cart/resolve (public — no auth).
 *
 * Resolves a guest device-local cart payload into a server-priced cart
 * for display: current product name, image, and unit price per line,
 * with computed line + cart subtotals. No persistence, no auth — it is
 * a read-only price/display resolution so the storefront drawer and
 * cart page show authoritative, up-to-date prices even if a product's
 * price changed since it was added locally.
 *
 * Body shape mirrors POST /v3/cart/merge:
 *
 *   {
 *     "items": [
 *       { product_id, quantity, size, color, is_custom,
 *         measurement, extra_measurement, note },
 *       ...
 *     ]
 *   }
 *
 * Empty items[] returns an empty cart in 200 OK. Unknown / inactive
 * product_ids are dropped by the controller and reported under
 * `removed` so the client can prune its local cart.
 *
 * Why items is `array` not a typed list: RequestValidator's hydrator is
 * a single-level constructor mapper and can't auto-instantiate nested
 * DTOs. The controller normalises each item inline; the `All` +
 * `Collection` constraints run the field-level checks here.
 */
final class ResolveCartInput
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
            allowExtraFields: true,
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
