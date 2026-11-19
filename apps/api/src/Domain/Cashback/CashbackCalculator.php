<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

/**
 * Pure cashback maths: (order lines + live rules + programme settings) →
 * per-line rewards and a total. No I/O — CashbackService resolves every fact
 * (first order? per-customer counts? budget spent?) up front.
 *
 * Rules (see the cashback plan):
 *   - Ineligible orders earn nothing: programme off; the order spent ANY
 *     cashback gift card (used_cashback_credit); synthetic orders (gift-card
 *     purchases, customization payments) or orders with no lines.
 *   - A rule is a candidate for the order when it is active and inside its
 *     window, on the right channel, passes the promo toggle
 *     (allow_with_promo), the first-order flag, the per-customer limit and
 *     still has budget.
 *   - Line base = line subtotal minus its pro-rata share of the order
 *     discount (delivery fee never counts). Cashback is on what was paid.
 *   - One rule per line: highest priority wins, then the bigger reward for
 *     that line, then the older rule. No stacking.
 *   - Percentage applies per line; fixed applies once per rule per order
 *     (spread across that rule's lines pro-rata, never above their base).
 *   - Clamps, in order: rule min_eligible_amount (on the rule's lines) →
 *     rule max_reward_per_order → rule remaining budget → programme
 *     max per order → programme minimum (below it the award is 0).
 */
final class CashbackCalculator
{
    /**
     * @param list<CashbackRule> $rules live rules (the calculator re-checks liveness anyway)
     */
    public function calculate(CashbackOrderInput $in, array $rules, CashbackProgramSettings $settings): CashbackResult
    {
        $hold = $settings->defaultHoldDays;
        $validity = $settings->defaultValidityDays;

        if (!$settings->enabled) {
            return CashbackResult::none(CashbackResult::REASON_PROGRAM_DISABLED, $hold, $validity);
        }
        if ($in->usedCashbackCredit) {
            return CashbackResult::none(CashbackResult::REASON_PAID_WITH_CASHBACK, $hold, $validity);
        }
        if ($in->isSyntheticOrder || $in->lines === []) {
            return CashbackResult::none(CashbackResult::REASON_INELIGIBLE_ORDER, $hold, $validity);
        }

        $bases = self::lineBases($in->lines, $in->discount);

        // ── Candidate rules for this order ───────────────────────────
        $candidates = [];
        $budgetBlocked = false;
        foreach ($rules as $rule) {
            if (!$rule->isLiveAt($in->at) || !$rule->appliesToChannel($in->channel)) {
                continue;
            }
            if ($in->hasPromo && !$rule->allowsWithPromo()) {
                continue;
            }
            if ($rule->isFirstOrderOnly() && !$in->isFirstOrder) {
                continue;
            }
            $rid = $rule->getId() ?? 0;
            $limit = $rule->getPerCustomerLimit();
            if ($limit !== null && ($in->customerAwardCounts[$rid] ?? 0) >= $limit) {
                continue;
            }
            $remaining = self::budgetRemaining($rule, $in->ruleSpent[$rid] ?? '0.00');
            if ($remaining !== null && bccomp($remaining, '0.00', 2) <= 0) {
                $budgetBlocked = true;
                continue;
            }
            $candidates[] = $rule;
        }
        if ($candidates === []) {
            return CashbackResult::none(
                $budgetBlocked ? CashbackResult::REASON_BUDGET_EXHAUSTED : CashbackResult::REASON_NO_MATCHING_RULE,
                $hold,
                $validity,
            );
        }

        // ── Assign each line to its best rule ────────────────────────
        /** @var array<int, array{rule: CashbackRule, bases: array<int, string>}> $groups */
        $groups = [];
        foreach ($in->lines as $i => $line) {
            $base = $bases[$i];
            if (bccomp($base, '0.00', 2) <= 0) {
                continue;
            }
            $best = null;
            foreach ($candidates as $rule) {
                if (!$rule->covers($line->vendorId, $line->productId, $line->categoryIds)) {
                    continue;
                }
                if ($best === null || self::beats($rule, $best, $base)) {
                    $best = $rule;
                }
            }
            if ($best !== null) {
                $oid = spl_object_id($best);
                $groups[$oid] ??= ['rule' => $best, 'bases' => []];
                $groups[$oid]['bases'][$i] = $base;
            }
        }
        if ($groups === []) {
            return CashbackResult::none(CashbackResult::REASON_NO_MATCHING_RULE, $hold, $validity);
        }

        // ── Reward per rule group, with rule-level clamps ────────────
        /** @var array<int, array{rule: CashbackRule, amount: string, base: string}> $lineAmounts index => … */
        $lineAmounts = [];
        $anyBelowMin = false;
        foreach ($groups as $group) {
            $rule = $group['rule'];
            $groupBases = $group['bases'];
            $groupBase = self::sum($groupBases);

            $min = $rule->getMinEligibleAmount();
            if ($min !== null && bccomp($groupBase, $min, 2) < 0) {
                $anyBelowMin = true;
                continue;
            }

            if ($rule->getRewardType() === CashbackRule::REWARD_PERCENTAGE) {
                $amounts = [];
                foreach ($groupBases as $i => $b) {
                    $amounts[$i] = self::round2(bcdiv(bcmul($b, $rule->getRewardValue(), 6), '100', 6));
                }
            } else {
                $fixed = self::min($rule->getRewardValue(), $groupBase);
                $amounts = self::distribute($fixed, $groupBases);
            }

            $cap = $rule->getMaxRewardPerOrder();
            if ($cap !== null && bccomp(self::sum($amounts), $cap, 2) > 0) {
                $amounts = self::distribute($cap, $amounts);
            }
            $remaining = self::budgetRemaining($rule, $in->ruleSpent[$rule->getId() ?? 0] ?? '0.00');
            if ($remaining !== null && bccomp(self::sum($amounts), $remaining, 2) > 0) {
                $amounts = self::distribute($remaining, $amounts);
            }

            foreach ($amounts as $i => $amt) {
                $lineAmounts[$i] = ['rule' => $rule, 'amount' => $amt, 'base' => $groupBases[$i]];
            }
        }

        $amountsOnly = array_map(static fn (array $r): string => $r['amount'], $lineAmounts);
        $total = self::sum($amountsOnly);

        // ── Programme cap per order ──────────────────────────────────
        if ($settings->maxAwardPerOrder !== null && bccomp($total, $settings->maxAwardPerOrder, 2) > 0) {
            $scaled = self::distribute($settings->maxAwardPerOrder, $amountsOnly);
            foreach ($scaled as $i => $amt) {
                $lineAmounts[$i]['amount'] = $amt;
            }
            $total = self::sum($scaled);
        }

        // ── Programme minimum ────────────────────────────────────────
        if (bccomp($total, '0.00', 2) <= 0 || bccomp($total, $settings->minAward, 2) < 0) {
            return CashbackResult::none(
                ($anyBelowMin || bccomp($total, '0.00', 2) > 0)
                    ? CashbackResult::REASON_BELOW_MINIMUM
                    : CashbackResult::REASON_NO_MATCHING_RULE,
                $hold,
                $validity,
            );
        }

        ksort($lineAmounts);
        $lines = [];
        $holdDays = null;
        $validityDays = null;
        foreach ($lineAmounts as $i => $row) {
            if (bccomp($row['amount'], '0.00', 2) <= 0) {
                continue;
            }
            $lines[] = new CashbackResultLine($in->lines[$i]->key, $row['rule'], $row['base'], $row['amount']);
            $holdDays = max($holdDays ?? 0, $row['rule']->getHoldDays());
            $validityDays = max($validityDays ?? 0, $row['rule']->getGiftCardValidityDays());
        }

        return new CashbackResult($total, $lines, null, $holdDays ?? $hold, $validityDays ?? $validity);
    }

    // ── Helpers (public static: reused by the service at release) ───

    /**
     * Per-line paid base: subtotal minus the line's pro-rata share of the
     * order discount (last line absorbs rounding), floored at 0.
     *
     * @param list<CashbackLineInput> $lines
     * @return array<int, string>
     */
    public static function lineBases(array $lines, string $discount): array
    {
        $subs = [];
        foreach ($lines as $i => $l) {
            $subs[$i] = bccomp($l->subtotal, '0.00', 2) > 0 ? bcadd($l->subtotal, '0', 2) : '0.00';
        }
        $discount = bccomp($discount, '0.00', 2) > 0 ? bcadd($discount, '0', 2) : '0.00';
        if (bccomp($discount, '0.00', 2) === 0) {
            return $subs;
        }
        $total = self::sum($subs);
        if (bccomp($total, '0.00', 2) <= 0) {
            return $subs;
        }
        $discount = self::min($discount, $total);
        $shares = self::distribute($discount, $subs);
        $out = [];
        foreach ($subs as $i => $s) {
            $b = bcsub($s, $shares[$i] ?? '0.00', 2);
            $out[$i] = bccomp($b, '0.00', 2) < 0 ? '0.00' : $b;
        }
        return $out;
    }

    /**
     * Split $total across keys proportionally to $weights (2dp); the last
     * positive-weight key absorbs rounding so the parts sum exactly.
     *
     * @param array<int, string> $weights
     * @return array<int, string>
     */
    public static function distribute(string $total, array $weights): array
    {
        $out = [];
        foreach ($weights as $k => $_) {
            $out[$k] = '0.00';
        }
        $sumW = self::sum($weights);
        if (bccomp($sumW, '0.00', 2) <= 0 || bccomp($total, '0.00', 2) <= 0) {
            return $out;
        }
        $lastKey = null;
        foreach ($weights as $k => $w) {
            if (bccomp($w, '0.00', 2) > 0) {
                $lastKey = $k;
            }
        }
        $allocated = '0.00';
        foreach ($weights as $k => $w) {
            if ($k === $lastKey || bccomp($w, '0.00', 2) <= 0) {
                continue;
            }
            $share = self::floor2(bcdiv(bcmul($total, $w, 8), $sumW, 8));
            $out[$k] = $share;
            $allocated = bcadd($allocated, $share, 2);
        }
        if ($lastKey !== null) {
            $rest = bcsub($total, $allocated, 2);
            $out[$lastKey] = bccomp($rest, '0.00', 2) < 0 ? '0.00' : $rest;
        }
        return $out;
    }

    /** @param array<int|string, string> $values */
    public static function sum(array $values): string
    {
        $s = '0.00';
        foreach ($values as $v) {
            $s = bcadd($s, $v, 2);
        }
        return $s;
    }

    public static function min(string $a, string $b): string
    {
        return bccomp($a, $b, 2) <= 0 ? bcadd($a, '0', 2) : bcadd($b, '0', 2);
    }

    /** Round a non-negative high-precision decimal half-up to 2dp. */
    public static function round2(string $v): string
    {
        return bcadd($v, '0.005', 2);
    }

    private static function floor2(string $v): string
    {
        return bcadd($v, '0', 2);
    }

    private static function budgetRemaining(CashbackRule $rule, string $spent): ?string
    {
        $budget = $rule->getBudgetTotal();
        if ($budget === null) {
            return null;
        }
        $r = bcsub($budget, $spent, 2);
        return bccomp($r, '0.00', 2) < 0 ? '0.00' : $r;
    }

    /** Does $a beat $b for a line with paid base $base? */
    private static function beats(CashbackRule $a, CashbackRule $b, string $base): bool
    {
        if ($a->getPriority() !== $b->getPriority()) {
            return $a->getPriority() > $b->getPriority();
        }
        $cmp = bccomp(self::estimate($a, $base), self::estimate($b, $base), 2);
        if ($cmp !== 0) {
            return $cmp > 0;
        }
        return ($a->getId() ?? PHP_INT_MAX) < ($b->getId() ?? PHP_INT_MAX);
    }

    /** Reward a rule would give on one line, for tie-breaking. */
    public static function estimate(CashbackRule $rule, string $base): string
    {
        $v = $rule->getRewardType() === CashbackRule::REWARD_PERCENTAGE
            ? self::round2(bcdiv(bcmul($base, $rule->getRewardValue(), 6), '100', 6))
            : self::min($rule->getRewardValue(), $base);
        $cap = $rule->getMaxRewardPerOrder();
        return $cap !== null ? self::min($v, $cap) : $v;
    }
}
