<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

/**
 * Output of CashbackCalculator. `total` is '0.00' when nothing is earned, in
 * which case `reason` says why (machine-readable).
 */
final class CashbackResult
{
    public const REASON_PROGRAM_DISABLED = 'program_disabled';
    public const REASON_PAID_WITH_CASHBACK = 'paid_with_cashback';
    public const REASON_INELIGIBLE_ORDER = 'ineligible_order';
    public const REASON_NO_MATCHING_RULE = 'no_matching_rule';
    public const REASON_BELOW_MINIMUM = 'below_minimum';
    public const REASON_BUDGET_EXHAUSTED = 'budget_exhausted';
    public const REASON_EMPTY_CART = 'empty_cart';
    /** The estimate could not be computed (internal error; fail-safe). */
    public const REASON_UNAVAILABLE = 'unavailable';

    /**
     * @param list<CashbackResultLine> $lines
     */
    public function __construct(
        public readonly string $total,
        public readonly array $lines,
        public readonly ?string $reason,
        public readonly int $holdDays,
        public readonly int $giftCardValidityDays,
    ) {
    }

    public static function none(string $reason, int $holdDays = 0, int $validityDays = 365): self
    {
        return new self('0.00', [], $reason, $holdDays, $validityDays);
    }

    public function isPositive(): bool
    {
        return bccomp($this->total, '0.00', 2) > 0;
    }

    /** @return list<int> */
    public function ruleIds(): array
    {
        $ids = [];
        foreach ($this->lines as $l) {
            $ids[$l->rule->getId() ?? 0] = true;
        }
        return array_keys($ids);
    }
}
