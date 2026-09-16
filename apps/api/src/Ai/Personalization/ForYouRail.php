<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Personalization;

use Bayti\Api\Domain\Catalog\Product;

/**
 * One personalised rail: a stable machine `key` (the client maps it to a
 * localised heading), the validated products, and — for "Because you liked…" —
 * the seed product's name so the client can title the rail.
 */
final class ForYouRail
{
    /**
     * @param list<Product> $products
     */
    public function __construct(
        public readonly string $key,
        public readonly array $products,
        public readonly ?string $seedName = null,
    ) {
    }
}
