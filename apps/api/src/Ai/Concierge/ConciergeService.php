<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

/**
 * Orchestrates one "Ask Ain" turn: parse the (history-merged) intent + Ain's
 * conversational fields in ONE structured call → retrieve a real, in-stock
 * shortlist → re-rank + explain. Returns validated entities; the controller
 * serialises them to card shapes (it holds the request/currency context) and
 * records analytics.
 *
 * A clarifying question or small talk skips retrieval entirely (no random dump
 * of products, no extra cost). Every collaborator degrades gracefully when AI is
 * off, so this always returns real catalogue results with a localized template
 * reply — never an error, never an invented product.
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

    /**
     * @param array<mixed>|ConversationHistory $history the conversation BEFORE this
     *        query, oldest first ([{role: 'user'|'ain', text}]); sanitised here.
     */
    public function ask(
        string $query,
        string $locale = 'en',
        int $limit = self::DEFAULT_LIMIT,
        array|ConversationHistory $history = [],
    ): ConciergeResult {
        $locale = ConciergeCopy::locale($locale);
        $history = ConversationHistory::fromMixed($history);
        $turn = $this->intentParser->parseTurn($query, $locale, $history);

        if ($turn->needsClarification || $turn->smallTalk) {
            $reply = $turn->reply !== ''
                ? $turn->reply
                : ConciergeCopy::reply(ConciergeCopy::KIND_GREETING, $locale);
            $followUps = $turn->followUps !== [] || $turn->needsClarification
                ? $turn->followUps
                : ConciergeCopy::followUps(ConciergeCopy::KIND_GREETING, $locale);
            return new ConciergeResult($turn->intent, [], $reply, $followUps, $turn->needsClarification);
        }

        $shortlist = $this->retrieval->retrieve($turn->intent);
        $items = $this->ranker->rank($turn->intent, $shortlist, $history->rankingQuery(trim($query)), $limit);

        if ($items === []) {
            // The model wrote its reply before seeing results — never claim a
            // find that does not exist.
            $reply = ConciergeCopy::reply(ConciergeCopy::KIND_NO_RESULTS, $locale, $turn->subject);
            $followUps = $turn->followUps !== []
                ? $turn->followUps
                : ConciergeCopy::followUps(ConciergeCopy::KIND_NO_RESULTS, $locale);
        } elseif ($this->retrieval->lastUsedFallback()) {
            // Nothing matched the request; retrieval served popular in-budget
            // pieces instead. Say so honestly rather than the model's reply
            // (written before results), which would describe a find that does
            // not exist.
            $reply = ConciergeCopy::reply(ConciergeCopy::KIND_CLOSEST, $locale, $turn->subject);
            $followUps = ConciergeCopy::followUps(ConciergeCopy::KIND_NO_RESULTS, $locale);
        } else {
            $reply = $turn->reply !== ''
                ? $turn->reply
                : ConciergeCopy::reply(ConciergeCopy::KIND_FOUND, $locale, $turn->subject);
            $followUps = $turn->followUps !== []
                ? $turn->followUps
                : ConciergeCopy::followUps(ConciergeCopy::KIND_FOUND, $locale);
        }

        return new ConciergeResult($turn->intent, $items, $reply, $followUps, false);
    }
}
