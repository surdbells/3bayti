<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

/**
 * The outcome of one concierge query: the parsed intent (for analytics /
 * echo-back) plus the ordered, validated recommendations. Serialization to card
 * shapes happens in the controller, which holds the request (currency context).
 */
final class ConciergeResult
{
    /** @param list<ConciergeItem> $items */
    public function __construct(
        public readonly ConciergeIntent $intent,
        public readonly array $items,
    ) {
    }

    /** @return list<int> the recommended product ids, in order (for the interaction log). */
    public function productIds(): array
    {
        $ids = [];
        foreach ($this->items as $item) {
            $id = $item->product->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return $ids;
    }
}
