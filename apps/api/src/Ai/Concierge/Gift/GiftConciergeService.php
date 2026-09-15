<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge\Gift;

use Bayti\Api\Ai\Concierge\ConciergeRanker;
use Bayti\Api\Ai\Concierge\ConciergeResult;
use Bayti\Api\Ai\Concierge\ProductRetrievalService;

/**
 * Orchestrates the Ain Gift Concierge. It reuses the Phase-1 retrieval + ranking
 * pipeline UNCHANGED — a {@see GiftBrief} is turned into a structured intent and
 * run through the exact same {@see ProductRetrievalService} (which re-validates
 * every candidate with Product::isOrderable() && isInStock()) and
 * {@see ConciergeRanker}, so gift ideas are always real, in-stock products. On
 * top of that it decides whether to attach a gift-card fallback nudge.
 *
 * The NL style path ({@see \Bayti\Api\Ai\Concierge\ConciergeService}) is left
 * completely untouched.
 */
final class GiftConciergeService
{
    /** Gift results read better as a tighter, curated set than the style default. */
    public const GIFT_LIMIT = 8;

    /** Below this many strong matches, a gift card is the safer bet. */
    private const THIN_MATCHES = 3;

    public function __construct(
        private readonly ProductRetrievalService $retrieval,
        private readonly ConciergeRanker $ranker,
    ) {
    }

    public function askGift(GiftBrief $brief, int $limit = self::GIFT_LIMIT): GiftConciergeResult
    {
        $intent = $brief->toIntent();
        $shortlist = $this->retrieval->retrieve($intent, $limit);
        $items = $this->ranker->rank($intent, $shortlist, $brief->toRankerQuery(), $limit);

        $result = new ConciergeResult($intent, $items);

        return new GiftConciergeResult($result, $this->decideSuggestion($brief, count($items)));
    }

    /**
     * Deterministic, testable fallback rule: too few matches → a card is safer;
     * otherwise a size-less APPAREL gift is the classic sizing risk. A card is
     * never suggested for a well-matched, non-apparel (or sized) gift.
     */
    private function decideSuggestion(GiftBrief $brief, int $matchCount): ?GiftCardSuggestion
    {
        if ($matchCount < self::THIN_MATCHES) {
            return GiftCardSuggestion::make(GiftCardSuggestion::REASON_FEW_MATCHES, $brief->budgetMax);
        }
        if ($brief->size === null && $brief->isApparel()) {
            return GiftCardSuggestion::make(GiftCardSuggestion::REASON_SIZE_UNKNOWN, $brief->budgetMax);
        }
        return null;
    }
}
