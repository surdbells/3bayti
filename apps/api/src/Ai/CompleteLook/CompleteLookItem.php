<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\CompleteLook;

use Bayti\Api\Domain\Catalog\Product;

/**
 * One "complete the look" complement: a REAL, validated product that pairs with
 * the seed, its category slug (for client grouping), and a short localized
 * reason. The product is always a live catalogue entity that passed
 * isOrderable() && isInStock().
 */
final class CompleteLookItem
{
    public function __construct(
        public readonly Product $product,
        public readonly string $complementCategorySlug,
        public readonly string $reason,
    ) {
    }
}
