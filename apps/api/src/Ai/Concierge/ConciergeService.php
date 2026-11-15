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
 * Guided discovery: the deterministic {@see DiscoveryPlanner} decides whether
 * this turn asks ONE short question (recipient → occasion → type → colour →
 * style → budget: every slot the shopper has not already given, at most 6) or
 * shows products. A guided question, a
 * clarifying question or small talk skips retrieval entirely (no random dump
 * of products, no extra cost) — still exactly one model call per turn. Every collaborator degrades gracefully when AI is
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
     * @param list<string>|null $askedSlots the previous response's asked_slots, echoed
     *        by the client (sanitised); null = legacy client that sends none.
     * @param bool $showNow the shopper tapped "Show me what you have".
     * @param bool $opening the first message of a conversation Ain has already
     *        greeted (the apps' local opening bubble, WhatsApp's greeting prefix or
     *        reset reply): small talk ("hi") then goes straight into the first
     *        guided question instead of a second greeting.
     */
    public function ask(
        string $query,
        string $locale = 'en',
        int $limit = self::DEFAULT_LIMIT,
        array|ConversationHistory $history = [],
        ?array $askedSlots = null,
        bool $showNow = false,
        bool $opening = false,
    ): ConciergeResult {
        $locale = ConciergeCopy::locale($locale);
        $history = ConversationHistory::fromMixed($history);
        $askedSlots = $askedSlots === null ? null : (DiscoveryPlanner::sanitizeAskedSlots($askedSlots) ?? []);
        $turn = $this->intentParser->parseTurn($query, $locale, $history);

        // Pre-guided-discovery clients send no asked_slots and show only 3 chips.
        $compactChips = $askedSlots === null;

        // The shopper's own words fill what the intent misses (always, so the
        // planner sees answers even with AI off); an AI-off keyword intent also
        // loses chip text ("Under 300 AED") as a search keyword but keeps the
        // free-text detail of EVERY message ("something with pearls" → "pearls").
        $texts = [...$history->userTexts(), $query];
        $planningIntent = DiscoveryLexicon::enrich($turn->intent, $texts, !$turn->modelParsed);
        if (!$turn->modelParsed) {
            $planningIntent = self::withKeywords($planningIntent, DiscoveryLexicon::residualKeywords($texts));
        }
        $smallTalk = $turn->smallTalk;
        if ($opening && $history->isEmpty()) {
            // The greeting is already in the reply: start the questions now.
            $smallTalk = false;
        }
        $decision = DiscoveryPlanner::plan($planningIntent, $query, $history, $askedSlots, $showNow, $smallTalk);

        if ($decision->action === DiscoveryDecision::ACTION_SMALL_TALK && $decision->pendingSlot !== null) {
            // Small talk in the middle of the questions: answer kindly, then
            // repeat the pending question with its answer chips (same slot, not
            // a new question — it does not count towards the 6). The reply ENDS
            // with the question template so the next turn still knows a
            // question is pending (never mistaken for results already shown).
            $reply = ConciergeCopy::withQuestion(
                $turn->modelParsed ? $turn->reply : '',
                ConciergeCopy::question($decision->pendingSlot, $locale),
            );
            return new ConciergeResult(
                $turn->intent,
                [],
                $reply,
                ConciergeCopy::questionChips($decision->pendingSlot, $locale, $compactChips),
                true,
                $decision->pendingSlot,
                $decision->askedSlots,
            );
        }

        if ($decision->action === DiscoveryDecision::ACTION_SMALL_TALK) {
            // After results (or with nothing left to ask): a friendly reply.
            $reply = $turn->reply !== ''
                ? $turn->reply
                : ConciergeCopy::reply(ConciergeCopy::KIND_GREETING, $locale);
            $followUps = $turn->followUps !== []
                ? $turn->followUps
                : ConciergeCopy::followUps(ConciergeCopy::KIND_GREETING, $locale);
            return new ConciergeResult($turn->intent, [], $reply, $followUps, false, null, $decision->askedSlots);
        }

        if ($decision->isQuestion() && $decision->questionSlot !== null) {
            // A guided question: no retrieval / ranking at all (fast + cheap).
            // Small talk before the first question ("what can you do?") keeps
            // the model's friendly line in front of the question — never a
            // second greeting with preset starter chips (the conversation
            // already opened with Ain's greeting).
            $question = ConciergeCopy::question($decision->questionSlot, $locale);
            return new ConciergeResult(
                $planningIntent,
                [],
                $decision->fromSmallTalk && $turn->modelParsed
                    ? ConciergeCopy::withQuestion($turn->reply, $question)
                    : $question,
                ConciergeCopy::questionChips($decision->questionSlot, $locale, $compactChips),
                true,
                $decision->questionSlot,
                $decision->askedSlots,
            );
        }

        if ($decision->completeBefore) {
            // Refining results already shown. The model may still ask its own
            // clarifying question for a vague message.
            if ($turn->needsClarification) {
                return new ConciergeResult($turn->intent, [], $turn->reply, $turn->followUps, true, null, $decision->askedSlots);
            }
            if ($turn->modelParsed) {
                // The model merged the conversation itself; only a budget is
                // read from the latest message.
                $intent = DiscoveryLexicon::enrich($turn->intent, [$query], false, true);
            } else {
                // AI off: rebuild the request from the WHOLE conversation so a
                // refine chip ("Something more colourful", "For a wedding
                // instead") keeps the type / occasion / budget already given;
                // the latest colour or style replaces the earlier one. Chip
                // text is never a search phrase — only free-text detail is.
                $intent = DiscoveryLexicon::enrich($turn->intent, $texts, true, false, true);
                $intent = self::withKeywords($intent, DiscoveryLexicon::residualKeywords($texts));
            }
        } else {
            $intent = $planningIntent;
        }

        // Guided results: the latest message is an answer ("Under 300 AED"), so
        // describe the merged request instead (or a neutral "picked for you").
        $subject = $turn->subject;
        if ($decision->isGuidedResults()) {
            $subject = $locale === 'en' ? IntentParser::intentSubject($intent) : '';
        }

        $shortlist = $this->retrieval->retrieve($intent);
        $items = $this->ranker->rank($intent, $shortlist, $history->rankingQuery(trim($query)), $limit);

        if ($items === []) {
            // The model wrote its reply before seeing results — never claim a
            // find that does not exist.
            $reply = ConciergeCopy::reply(ConciergeCopy::KIND_NO_RESULTS, $locale, $subject);
            $followUps = ConciergeCopy::refineChips(
                $intent,
                $locale,
                [],
                $turn->followUps !== [] ? $turn->followUps : ConciergeCopy::followUps(ConciergeCopy::KIND_NO_RESULTS, $locale),
            );
        } elseif ($this->retrieval->lastUsedFallback()) {
            // Nothing matched the request; retrieval served popular in-budget
            // pieces instead. Say so honestly rather than the model's reply
            // (written before results), which would describe a find that does
            // not exist.
            $reply = ConciergeCopy::reply(ConciergeCopy::KIND_CLOSEST, $locale, $subject);
            $followUps = ConciergeCopy::refineChips(
                $intent,
                $locale,
                [],
                ConciergeCopy::followUps(ConciergeCopy::KIND_NO_RESULTS, $locale),
            );
        } else {
            if ($turn->reply !== '') {
                $reply = $turn->reply;
            } elseif ($subject === '') {
                $reply = ConciergeCopy::reply(ConciergeCopy::KIND_PICKS, $locale);
            } else {
                $reply = ConciergeCopy::reply(ConciergeCopy::KIND_FOUND, $locale, $subject);
            }
            $followUps = ConciergeCopy::refineChips($intent, $locale, $turn->followUps);
        }

        return new ConciergeResult($intent, $items, $reply, $followUps, false, null, $decision->askedSlots);
    }

    /** @param list<string> $keywords */
    private static function withKeywords(ConciergeIntent $intent, array $keywords): ConciergeIntent
    {
        return new ConciergeIntent(
            $intent->productType,
            $intent->categorySlug,
            $intent->occasions,
            $intent->colours,
            $intent->styles,
            $intent->budgetMin,
            $intent->budgetMax,
            $keywords,
            $intent->vendorHints,
            $intent->isGift,
            $intent->confidence,
        );
    }
}
