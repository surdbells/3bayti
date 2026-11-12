<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

/**
 * Ain's guided-discovery slot engine — DETERMINISTIC and server-side, never
 * the model's whim.
 *
 * Before showing products Ain asks short questions, ONE per turn, for the
 * highest-priority unfilled slot:
 *
 *   recipient → occasion → type → style_or_colour → budget
 *
 * A slot is FILLED when the (history-merged) intent or the shopper's own words
 * already say it ({@see DiscoveryLexicon}), or when it was already ASKED (an
 * answer like "No preference" counts as answered). Products are shown when:
 * the shopper explicitly asks ("show me…", the skip chip / show_now), 4
 * questions have been asked, every slot is filled, or the very first request is
 * already specific (>= 3 slots filled before any question). Small talk keeps
 * its own friendly path.
 *
 * Stateless: the client echoes the previous response's asked_slots. Once
 * products have been shown the echoed list contains EVERY slot (discovery
 * complete), so later refinements ("in black", "cheaper") go straight to new
 * results. Old clients that send no asked_slots still work: questions already
 * asked are recognised from Ain's question templates in the history (so the
 * 4-question cap can never loop), and a history that already holds results (or
 * a pre-guided-discovery conversation) is treated as complete.
 */
final class DiscoveryPlanner
{
    public const SLOT_RECIPIENT = 'recipient';
    public const SLOT_OCCASION = 'occasion';
    public const SLOT_TYPE = 'type';
    public const SLOT_STYLE = 'style_or_colour';
    public const SLOT_BUDGET = 'budget';

    /** Priority order. */
    public const SLOTS = [
        self::SLOT_RECIPIENT,
        self::SLOT_OCCASION,
        self::SLOT_TYPE,
        self::SLOT_STYLE,
        self::SLOT_BUDGET,
    ];

    /** At most this many guided questions per conversation. */
    public const MAX_QUESTIONS = 4;

    /** A first request filling at least this many slots goes straight to results. */
    public const SPECIFIC_ENOUGH = 3;

    /**
     * Sanitise a client-sent asked_slots value: null when absent / not a list
     * (legacy client); otherwise the known slot names, deduped, in the order
     * sent, max 5. Unknown values are dropped silently.
     *
     * @return list<string>|null
     */
    public static function sanitizeAskedSlots(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $out = [];
        foreach ($raw as $slot) {
            if (!is_string($slot)) {
                continue;
            }
            $slot = strtolower(trim($slot));
            if (in_array($slot, self::SLOTS, true) && !in_array($slot, $out, true)) {
                $out[] = $slot;
            }
            if (count($out) >= count(self::SLOTS)) {
                break;
            }
        }
        return $out;
    }

    /**
     * Decide this turn.
     *
     * @param ConciergeIntent $intent      the merged intent (already lexically enriched)
     * @param list<string>|null $askedSlots sanitised client asked_slots; null = legacy client
     */
    public static function plan(
        ConciergeIntent $intent,
        string $query,
        ConversationHistory $history,
        ?array $askedSlots,
        bool $showNow,
        bool $smallTalk,
    ): DiscoveryDecision {
        $asked = self::orderedUnion($askedSlots ?? [], self::inferAskedFromHistory($history));
        // An empty echoed list (a thread restored without its asked_slots) is
        // read like a legacy client's: a history that already holds results is
        // a refinement, never a restart of the questions.
        $complete = count(array_intersect(self::SLOTS, $asked)) === count(self::SLOTS)
            || (($askedSlots === null || $askedSlots === []) && self::legacyResultsShown($history));
        $showNow = $showNow || DiscoveryLexicon::isShowNow($query);

        $texts = [...$history->userTexts(), $query];
        $filled = self::filledSlots($intent, $texts);

        $results = static fn (): DiscoveryDecision => new DiscoveryDecision(
            DiscoveryDecision::ACTION_RESULTS,
            null,
            self::SLOTS,
            $asked,
            $filled,
            $complete,
            $showNow,
        );

        // The skip (flag or "show me…" wording) always wins — even over a model
        // that mislabels "Show me what you have" as small talk.
        if ($showNow) {
            return $results();
        }

        $midDiscovery = !$complete && $asked !== [];
        if ($smallTalk && $midDiscovery && !self::isRealSmallTalk($query)) {
            // A chip answer ("No preference", "لا يهم") or any recognised
            // detail is an ANSWER to the pending question, whatever the model said.
            $smallTalk = false;
        }
        if ($smallTalk) {
            return new DiscoveryDecision(
                DiscoveryDecision::ACTION_SMALL_TALK,
                null,
                $complete ? self::SLOTS : $asked,
                $asked,
                $filled,
                $complete,
                false,
                $midDiscovery ? self::pendingSlot($history, $asked) : null,
            );
        }

        if ($complete || count($asked) >= self::MAX_QUESTIONS) {
            return $results();
        }
        if ($asked === [] && count($filled) >= self::SPECIFIC_ENOUGH) {
            return $results();
        }

        foreach (self::SLOTS as $slot) {
            if (!in_array($slot, $filled, true) && !in_array($slot, $asked, true)) {
                return new DiscoveryDecision(
                    DiscoveryDecision::ACTION_ASK,
                    $slot,
                    [...$asked, $slot],
                    $asked,
                    $filled,
                    false,
                    false,
                );
            }
        }
        return $results();
    }

    /**
     * Slots the conversation's content already fills (intent + the shopper's
     * own words), in priority order.
     *
     * @param list<string> $texts the shopper's messages, oldest first
     * @return list<string>
     */
    public static function filledSlots(ConciergeIntent $intent, array $texts): array
    {
        $lex = DiscoveryLexicon::extract($texts);
        $filled = [];
        if ($intent->isGift || $lex['recipient'] !== null) {
            $filled[] = self::SLOT_RECIPIENT;
        }
        if ($intent->occasions !== [] || $lex['occasions'] !== []) {
            $filled[] = self::SLOT_OCCASION;
        }
        if ($intent->productType !== null || $intent->categorySlug !== null || $lex['type'] !== null) {
            $filled[] = self::SLOT_TYPE;
        }
        if ($intent->styles !== [] || $intent->colours !== [] || $lex['styles'] !== [] || $lex['colours'] !== []) {
            $filled[] = self::SLOT_STYLE;
        }
        if ($intent->budgetMin !== null || $intent->budgetMax !== null || $lex['budget_min'] !== null || $lex['budget_max'] !== null) {
            $filled[] = self::SLOT_BUDGET;
        }
        return $filled;
    }

    /**
     * The slots Ain already asked, recognised from her question templates in
     * the history (oldest first, deduped).
     *
     * @return list<string>
     */
    public static function inferAskedFromHistory(ConversationHistory $history): array
    {
        $out = [];
        foreach ($history->entries() as $e) {
            if ($e['role'] !== ConversationHistory::ROLE_AIN) {
                continue;
            }
            $slot = ConciergeCopy::questionSlotFor($e['text']);
            if ($slot !== null && !in_array($slot, $out, true)) {
                $out[] = $slot;
            }
        }
        return $out;
    }

    /**
     * Legacy clients (no asked_slots) and threads restored without their
     * asked_slots (an empty list): whether this conversation already
     * reached results — an Ain reply that is not a guided question following a
     * real request, or a conversation with real requests and no guided question
     * at all (it predates guided discovery).
     */
    public static function legacyResultsShown(ConversationHistory $history): bool
    {
        $previousUser = null;
        $requests = 0;
        $hasQuestion = false;
        foreach ($history->entries() as $e) {
            if ($e['role'] === ConversationHistory::ROLE_USER) {
                $previousUser = $e['text'];
                if (!ConciergeCopy::isSmallTalk($e['text'])) {
                    $requests++;
                }
                continue;
            }
            if (ConciergeCopy::questionSlotFor($e['text']) !== null) {
                $hasQuestion = true;
                continue;
            }
            if (ConciergeCopy::isGreetingReply($e['text'])) {
                // Ain answered it with her greeting: it was small talk after all.
                if ($previousUser !== null && !ConciergeCopy::isSmallTalk($previousUser)) {
                    $requests--;
                }
                $previousUser = null;
                continue;
            }
            if ($previousUser !== null && !ConciergeCopy::isSmallTalk($previousUser)) {
                return true;
            }
        }
        return $requests > 0 && !$hasQuestion;
    }

    /**
     * Mid-discovery the model's small_talk flag is only trusted for a real
     * greeting/thanks, or a message that names nothing and is not chip/filler
     * text ("haha you're sweet"). "No preference" / "لا يهم" / "Eid" are answers.
     */
    private static function isRealSmallTalk(string $query): bool
    {
        if (ConciergeCopy::isSmallTalk($query)) {
            return true;
        }
        return !DiscoveryLexicon::isConsumed($query) && !DiscoveryLexicon::recognisesAnything($query);
    }

    /**
     * The question still awaiting an answer: the slot of Ain's latest message
     * when it is a question template, else the last asked slot.
     *
     * @param list<string> $asked
     */
    private static function pendingSlot(ConversationHistory $history, array $asked): ?string
    {
        $entries = $history->entries();
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            if ($entries[$i]['role'] === ConversationHistory::ROLE_AIN) {
                $slot = ConciergeCopy::questionSlotFor($entries[$i]['text']);
                if ($slot !== null) {
                    return $slot;
                }
                break;
            }
        }
        return $asked === [] ? null : $asked[count($asked) - 1];
    }

    /**
     * @param list<string> $a
     * @param list<string> $b
     * @return list<string>
     */
    private static function orderedUnion(array $a, array $b): array
    {
        $out = $a;
        foreach ($b as $slot) {
            if (!in_array($slot, $out, true)) {
                $out[] = $slot;
            }
        }
        return $out;
    }
}
