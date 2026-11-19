<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

use DateTimeImmutable;

/**
 * Everything the (pure) CashbackCalculator needs about an order or a cart,
 * pre-resolved by CashbackService so the calculator does no I/O.
 */
final class CashbackOrderInput
{
    /**
     * @param list<CashbackLineInput> $lines
     * @param array<int, int>    $customerAwardCounts rule id => this customer's prior awards
     * @param array<int, string> $ruleSpent           rule id => budget already committed
     */
    public function __construct(
        public readonly DateTimeImmutable $at,
        public readonly ?string $channel,
        public readonly array $lines,
        public readonly string $discount = '0.00',
        public readonly bool $hasPromo = false,
        public readonly bool $usedCashbackCredit = false,
        public readonly bool $isSyntheticOrder = false,
        public readonly bool $isFirstOrder = false,
        public readonly array $customerAwardCounts = [],
        public readonly array $ruleSpent = [],
    ) {
    }
}
