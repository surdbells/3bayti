<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge\Outfit;

use Bayti\Api\Ai\Concierge\ConciergeIntent;
use Bayti\Api\Ai\Concierge\Gift\GiftCardSuggestion;

/**
 * A composed outfit: the hero intent that drove it, the ordered pieces (hero
 * first, then complements — every one a real, in-stock product), a rationale for
 * the look, and an optional gift-card suggestion when a coherent outfit couldn't
 * be assembled.
 */
final class OutfitResult
{
    /**
     * @param list<OutfitPiece> $pieces
     */
    public function __construct(
        public readonly ConciergeIntent $intent,
        public readonly array $pieces,
        public readonly string $rationale,
        public readonly ?GiftCardSuggestion $suggestion = null,
    ) {
    }

    /**
     * @return list<int>
     */
    public function productIds(): array
    {
        $ids = [];
        foreach ($this->pieces as $piece) {
            $id = $piece->product->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
