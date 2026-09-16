<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge\Outfit;

use Bayti\Api\Domain\Catalog\Product;

/**
 * One piece of a composed outfit: a real, in-stock product plus its role in the
 * look (`hero` = the statement garment, `complement` = a coordinating piece), its
 * category slug (for display + one-per-category diversity), and a short reason.
 */
final class OutfitPiece
{
    public const ROLE_HERO = 'hero';
    public const ROLE_COMPLEMENT = 'complement';

    public function __construct(
        public readonly Product $product,
        public readonly string $role,
        public readonly string $categorySlug,
        public readonly string $reason,
    ) {
    }
}
