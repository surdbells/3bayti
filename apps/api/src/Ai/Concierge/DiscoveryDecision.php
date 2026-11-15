<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

/**
 * What {@see DiscoveryPlanner} decided for one concierge turn: ask ONE guided
 * question (and which slot), show products, or answer small talk — plus the
 * asked-slot list to echo back to the client.
 */
final class DiscoveryDecision
{
    public const ACTION_ASK = 'ask';
    public const ACTION_RESULTS = 'results';
    public const ACTION_SMALL_TALK = 'small_talk';

    /**
     * @param list<string> $askedSlots   every slot asked so far INCLUDING this turn's question;
     *                                   all slots once products have been shown (discovery complete)
     * @param list<string> $askedBefore  slots asked before this turn
     * @param list<string> $filledSlots  slots the conversation's content already fills
     * @param bool $completeBefore       products were already shown earlier in this conversation
     * @param bool $showNow              the shopper asked to skip the questions this turn
     * @param string|null $pendingSlot   small talk in the MIDDLE of guided discovery: the
     *                                   question still awaiting an answer (its chips are re-offered)
     * @param bool $fromSmallTalk        a question turn triggered by small talk before any
     *                                   question was asked (the reply may lead with a friendly line)
     */
    public function __construct(
        public readonly string $action,
        public readonly ?string $questionSlot,
        public readonly array $askedSlots,
        public readonly array $askedBefore,
        public readonly array $filledSlots,
        public readonly bool $completeBefore,
        public readonly bool $showNow,
        public readonly ?string $pendingSlot = null,
        public readonly bool $fromSmallTalk = false,
    ) {
    }

    public function isQuestion(): bool
    {
        return $this->action === self::ACTION_ASK;
    }

    /**
     * The FIRST results of a guided conversation (questions were asked, or the
     * shopper skipped them): the raw latest message ("Under 300 AED") does not
     * describe the request, so replies describe the merged intent instead.
     */
    public function isGuidedResults(): bool
    {
        return $this->action === self::ACTION_RESULTS
            && !$this->completeBefore
            && ($this->askedBefore !== [] || $this->showNow);
    }
}
