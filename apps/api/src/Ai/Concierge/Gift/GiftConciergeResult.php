<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge\Gift;

use Bayti\Api\Ai\Concierge\ConciergeResult;

/**
 * The Gift Concierge's outcome: the inner {@see ConciergeResult} (intent +
 * ranked, validated product items) plus an optional gift-card fallback nudge.
 * The controller serialises the products and echoes the suggestion.
 */
final class GiftConciergeResult
{
    public function __construct(
        public readonly ConciergeResult $concierge,
        public readonly ?GiftCardSuggestion $suggestion,
    ) {
    }

    /** @return list<int> */
    public function productIds(): array
    {
        return $this->concierge->productIds();
    }
}
