<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

/**
 * Ain's guided-discovery slot engine — DETERMINISTIC and server-side, never
 * the model's whim.
 *
 * Before showing products Ain has a short conversation, asking ONE question per
 * turn for the highest-priority unresolved slot:
 *
 *   recipient → occasion → type → colour → style → budget
 *
 * A slot is RESOLVED when the (history-merged) intent or the shopper's own
 * words already say it ({@see DiscoveryLexicon}), when it was already ASKED (an
 * answer like "No preference" counts as answered), or when a LATER slot was
 * already asked (questions go in priority order, so every earlier slot was
 * resolved at the time — even if the message that filled it has since scrolled
 * out of the capped history). Ain asks EVERY unresolved slot — there is no
 * "specific enough, skip the questions" shortcut. Products are shown only when:
 * the shopper explicitly asks ("show me…", the skip chip / show_now), 6
 * questions have been asked, or every slot is resolved. Small talk gets a
 * friendly line followed by the next (or still pending) question, so Ain's
 * latest message always ends with a recognisable question template; after
 * results it keeps its own friendly path.
 *
 * Stateless: the client echoes the previous response's asked_slots. Once
 * products have been shown the echoed list contains EVERY slot (discovery
 * complete), so later refinements ("in black", "cheaper") go straight to new
 * results. Older clients that echo the retired 'style_or_colour' slot have it
 * read as both 'colour' and 'style'. Clients that send no asked_slots still
 * work: questions already asked are recognised from Ain's question templates in
 * the history, they are capped at {@see self::LEGACY_MAX_QUESTIONS} (what the
 * 8-entry history they send can always see, so they can never loop), and a
 * history that already holds results (or a pre-guided-discovery conversation)
 * is treated as complete.
 */
final class DiscoveryPlanner
{
    public const SLOT_RECIPIENT = 'recipient';
    public const SLOT_OCCASION = 'occasion';
    public const SLOT_TYPE = 'type';
    public const SLOT_COLOUR = 'colour';
    public const SLOT_STYLE = 'style';
    public const SLOT_BUDGET = 'budget';

    /**
     * RETIRED combined slot (pre six-slot clients / stored WhatsApp memory).
     * Accepted on input as meaning BOTH colour and style were asked; never
     * emitted.
     */
    public const LEGACY_SLOT_STYLE_OR_COLOUR = 'style_or_colour';

    /** Priority order. */
    public const SLOTS = [
        self::SLOT_RECIPIENT,
        self::SLOT_OCCASION,
        self::SLOT_TYPE,
        self::SLOT_COLOUR,
        self::SLOT_STYLE,
        self::SLOT_BUDGET,
    ];

    /** At most this many guided questions per conversation ("Question N of up to 6"). */
    public const MAX_QUESTIONS = 6;

    /**
     * The cap for clients that echo no asked_slots: they cut the history to 8
     * entries (4 question/answer pairs), so asked slots inferred from Ain's
     * question templates stay visible — and the conversation can never loop.
     */
    public const LEGACY_MAX_QUESTIONS = 4;

    /**
     * Sanitise a client-sent asked_slots value: null when absent / not a list
     * (legacy client); otherwise the known slot names, deduped, in the order
     * sent, max {@see self::SLOTS} count. The retired 'style_or_colour' expands
     * to 'colour' + 'style'. Unknown values are dropped silently.
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
            foreach (self::expandSlot(strtolower(trim($slot))) as $one) {
                if (!in_array($one, $out, true)) {
                    $out[] = $one;
                }
            }
            if (count($out) >= count(self::SLOTS)) {
                break;
            }
        }
        return array_slice($out, 0, count(self::SLOTS));
    }

    /**
     * A slot name as current slots: the retired 'style_or_colour' is both
     * colour and style; unknown names are nothing.
     *
     * @return list<string>
     */
    public static function expandSlot(string $slot): array
    {
        if ($slot === self::LEGACY_SLOT_STYLE_OR_COLOUR) {
            return [self::SLOT_COLOUR, self::SLOT_STYLE];
        }
        return in_array($slot, self::SLOTS, true) ? [$slot] : [];
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
        $echo = $askedSlots === null ? null : (self::sanitizeAskedSlots($askedSlots) ?? []);
        $asked = self::orderedUnion($echo ?? [], self::inferAskedFromHistory($history));
        // No echo (legacy client), or an empty echo (a thread restored without
        // its asked_slots): a history that already holds results is a
        // refinement, never a restart of the questions.
        $noEcho = $echo === null || $echo === [];
        // Every slot asked = products were shown (the "complete" marker) —
        // unless Ain's latest message is still a question: then the 6th
        // question was just asked and this message answers it.
        $allAsked = count(array_intersect(self::SLOTS, $asked)) === count(self::SLOTS);
        $complete = ($allAsked && self::latestQuestionSlot($history) === null)
            || ($noEcho && self::legacyResultsShown($history));
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
        $smallTalkDecision = static fn (?string $pending): DiscoveryDecision => new DiscoveryDecision(
            DiscoveryDecision::ACTION_SMALL_TALK,
            null,
            $complete ? self::SLOTS : $asked,
            $asked,
            $filled,
            $complete,
            false,
            $pending,
        );
        if ($smallTalk && ($complete || $midDiscovery)) {
            return $smallTalkDecision($midDiscovery ? self::pendingSlot($history, $asked) : null);
        }

        // Clients that echo no asked_slots — and installed six-slot-unaware
        // guided clients (they drop 'colour'/'style' from the echo, so their
        // "of up to 4" progress hint stays true) — keep the 4-question cap.
        $cap = ($noEcho || self::isFiveSlotClient($echo, $history))
            ? self::LEGACY_MAX_QUESTIONS
            : self::MAX_QUESTIONS;
        if ($complete || count($asked) >= $cap) {
            return $results();
        }

        $next = self::nextSlot($asked, $filled);
        if ($next === null) {
            // Nothing left to ask: small talk stays small talk, anything else
            // gets the products.
            return $smallTalk ? $smallTalkDecision(null) : $results();
        }
        // Small talk BEFORE any question ("hi", "what can you do?") starts the
        // questions right away (the service leads with a friendly line): a
        // greeting-only reply would echo an empty asked_slots, and the next
        // real request would then be mistaken for a refinement of results.
        return new DiscoveryDecision(
            DiscoveryDecision::ACTION_ASK,
            $next,
            [...$asked, $next],
            $asked,
            $filled,
            false,
            false,
            null,
            $smallTalk,
        );
    }

    /**
     * An installed guided client from before the six slots: it echoes
     * asked_slots but silently drops the 'colour' / 'style' names it does not
     * know, so a colour/style question visible in the history is missing from
     * its (non-empty) echo. Six-slot clients echo every asked slot.
     *
     * @param list<string>|null $echo
     */
    private static function isFiveSlotClient(?array $echo, ConversationHistory $history): bool
    {
        if ($echo === null || $echo === []) {
            return false;
        }
        foreach ($history->entries() as $e) {
            if ($e['role'] !== ConversationHistory::ROLE_AIN) {
                continue;
            }
            $slot = ConciergeCopy::questionSlotFor($e['text']);
            if (($slot === self::SLOT_COLOUR || $slot === self::SLOT_STYLE) && !in_array($slot, $echo, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * The highest-priority slot neither filled nor asked, and not before a
     * slot that was already asked (questions go in priority order, so an
     * earlier slot was resolved when the later one was asked). Null = all
     * resolved.
     *
     * @param list<string> $asked
     * @param list<string> $filled
     */
    public static function nextSlot(array $asked, array $filled): ?string
    {
        $latestAsked = -1;
        foreach (self::SLOTS as $i => $slot) {
            if (in_array($slot, $asked, true)) {
                $latestAsked = $i;
            }
        }
        foreach (self::SLOTS as $i => $slot) {
            if ($i < $latestAsked || in_array($slot, $filled, true) || in_array($slot, $asked, true)) {
                continue;
            }
            return $slot;
        }
        return null;
    }

    /**
     * Slots the conversation's content already fills (intent + the shopper's
     * own words), in priority order. A palette ("pastels", "earthy tones",
     * "jewel tones") fills the COLOUR slot; a cut/finish ("embellished",
     * "minimal", "casual") fills the STYLE slot.
     *
     * @param list<string> $texts the shopper's messages, oldest first
     * @return list<string>
     */
    public static function filledSlots(ConciergeIntent $intent, array $texts): array
    {
        $lex = DiscoveryLexicon::extract($texts);
        $styles = [...$intent->styles, ...$lex['styles']];
        $palette = array_filter($styles, static fn (string $s): bool => DiscoveryLexicon::isColourStyle($s));
        $cut = array_filter($styles, static fn (string $s): bool => !DiscoveryLexicon::isColourStyle($s) && trim($s) !== '');

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
        if ($intent->colours !== [] || $lex['colours'] !== [] || $palette !== []) {
            $filled[] = self::SLOT_COLOUR;
        }
        if ($cut !== []) {
            $filled[] = self::SLOT_STYLE;
        }
        if ($intent->budgetMin !== null || $intent->budgetMax !== null || $lex['budget_min'] !== null || $lex['budget_max'] !== null) {
            $filled[] = self::SLOT_BUDGET;
        }
        return $filled;
    }

    /**
     * The slots Ain already asked, recognised from her question templates in
     * the history (oldest first, deduped; the retired combined colours/style
     * question counts as both).
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
            foreach (ConciergeCopy::questionSlotsFor($e['text']) as $slot) {
                if (!in_array($slot, $out, true)) {
                    $out[] = $slot;
                }
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
        return self::latestQuestionSlot($history) ?? ($asked === [] ? null : $asked[count($asked) - 1]);
    }

    /** The slot Ain's LATEST message asks, when it is a question template; null otherwise. */
    private static function latestQuestionSlot(ConversationHistory $history): ?string
    {
        $entries = $history->entries();
        for ($i = count($entries) - 1; $i >= 0; $i--) {
            if ($entries[$i]['role'] === ConversationHistory::ROLE_AIN) {
                return ConciergeCopy::questionSlotFor($entries[$i]['text']);
            }
        }
        return null;
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
