<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

/**
 * Orchestrates one "Ask Ain" query: parse intent → retrieve a real, in-stock
 * shortlist → re-rank + explain. Returns validated entities; the controller
 * serialises them to card shapes (it holds the request/currency context) and
 * records analytics.
 *
 * Every collaborator degrades gracefully when AI is off, so this always returns
 * real catalogue results — never an error, never an invented product.
 */
final class ConciergeService
{
    /** Default number of recommendations returned to the client. */
    public const DEFAULT_LIMIT = 12;

    public function __construct(
        private readonly IntentParser $intentParser,
        private readonly ProductRetrievalService $retrieval,
        private readonly ConciergeRanker $ranker,
    ) {
    }

    public function ask(string $query, string $locale = 'en', int $limit = self::DEFAULT_LIMIT): ConciergeResult
    {
        $intent = $this->intentParser->parse($query, $locale);
        $shortlist = $this->retrieval->retrieve($intent);
        $items = $this->ranker->rank($intent, $shortlist, $query, $limit);

        return new ConciergeResult($intent, $items);
    }
}
