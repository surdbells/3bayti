<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge\Gift;

use Bayti\Api\Domain\GiftCard\GiftCard;

/**
 * A "buy a gift card instead" nudge the Gift Concierge attaches when a physical
 * gift is risky — the recipient's size is unknown, or the brief yielded too few
 * strong matches. It references NO product (only echoes the gift-card preset
 * constants), so it can never surface invented catalogue data; the client turns
 * it into a CTA into the existing gift-card purchase flow.
 */
final class GiftCardSuggestion
{
    public const REASON_SIZE_UNKNOWN = 'size_unknown';
    public const REASON_FEW_MATCHES = 'few_matches';

    /**
     * @param self::REASON_* $reason
     */
    public function __construct(
        public readonly string $reason,
        public readonly ?string $suggestedDenomination,
    ) {
    }

    /**
     * Build a suggestion, picking the largest preset denomination that does not
     * exceed the shopper's stated max budget (decimal-safe via bccomp). Returns a
     * null denomination — never one over budget — when the budget is below the
     * minimum card value or unspecified (the buyer then picks from the presets).
     *
     * @param self::REASON_* $reason
     */
    public static function make(string $reason, ?float $budgetMax): self
    {
        return new self($reason, self::denominationFor($budgetMax));
    }

    private static function denominationFor(?float $budgetMax): ?string
    {
        if ($budgetMax === null || $budgetMax <= 0) {
            return null;
        }
        $budget = number_format($budgetMax, 2, '.', '');

        // Largest preset that fits the budget (presets are ascending decimal strings).
        $best = null;
        foreach (GiftCard::PRESET_DENOMINATIONS as $preset) {
            if (bccomp($preset, $budget, 2) <= 0) {
                $best = $preset;
            }
        }
        return $best; // null when budget < MIN_DENOMINATION — never suggest over budget.
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'reason' => $this->reason,
            'suggested_denomination' => $this->suggestedDenomination,
            'presets' => GiftCard::PRESET_DENOMINATIONS,
            'currency' => 'AED',
            'min_denomination' => GiftCard::MIN_DENOMINATION,
            'max_denomination' => GiftCard::MAX_DENOMINATION,
        ];
    }
}
