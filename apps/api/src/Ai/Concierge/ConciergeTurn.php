<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

/**
 * One parsed conversational turn from {@see IntentParser::parseTurn()}: the
 * (history-merged) intent plus Ain's conversational fields, all already
 * sanitised. reply may be '' — {@see ConciergeService} then fills a localized
 * template once it knows what retrieval actually found.
 */
final class ConciergeTurn
{
    /**
     * @param list<string> $followUps sanitised, <= 3, each <= 40 chars
     * @param string $subject what the shopper is asking for, for template replies
     */
    public function __construct(
        public readonly ConciergeIntent $intent,
        public readonly string $reply,
        public readonly array $followUps,
        public readonly bool $needsClarification,
        public readonly bool $smallTalk,
        public readonly string $subject,
    ) {
    }
}
