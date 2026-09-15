<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

use Bayti\Api\Domain\Catalog\Product;

/**
 * One ranked recommendation: a REAL, validated product plus Ain's one-line
 * reason for choosing it. The product is a live entity resolved from the
 * shortlist, never an AI-invented id.
 */
final class ConciergeItem
{
    public function __construct(
        public readonly Product $product,
        public readonly string $reason,
    ) {
    }
}
