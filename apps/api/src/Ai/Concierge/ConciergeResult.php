<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

/**
 * The outcome of one concierge turn: the parsed (history-merged) intent (for
 * analytics / echo-back), the ordered, validated recommendations, and Ain's
 * conversational fields (reply, follow-ups, whether she is asking a clarifying
 * question). Serialization to card shapes happens in the controller, which
 * holds the request (currency context).
 */
final class ConciergeResult
{
    /**
     * @param list<ConciergeItem> $items
     * @param list<string> $followUps short tappable suggestions in the locale (question
     *        turns: up to 8 answer chips; results: up to 6 refine chips)
     * @param ?string $questionSlot the guided-discovery slot this turn asks (null = not a guided question)
     * @param list<string> $askedSlots every slot asked so far incl. this turn's; all slots once
     *        products were shown (the client echoes it back next turn)
     */
    public function __construct(
        public readonly ConciergeIntent $intent,
        public readonly array $items,
        public readonly string $reply = '',
        public readonly array $followUps = [],
        public readonly bool $needsClarification = false,
        public readonly ?string $questionSlot = null,
        public readonly array $askedSlots = [],
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
