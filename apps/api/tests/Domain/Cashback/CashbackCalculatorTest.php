<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Domain\Cashback;

use Bayti\Api\Domain\Cashback\CashbackCalculator;
use Bayti\Api\Domain\Cashback\CashbackLineInput;
use Bayti\Api\Domain\Cashback\CashbackOrderInput;
use Bayti\Api\Domain\Cashback\CashbackProgramSettings;
use Bayti\Api\Domain\Cashback\CashbackResult;
use Bayti\Api\Domain\Cashback\CashbackRule;
use Bayti\Api\Domain\Cashback\CashbackRuleTarget;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CashbackCalculator::class)]
#[CoversClass(CashbackRule::class)]
#[CoversClass(CashbackResult::class)]
#[CoversClass(CashbackProgramSettings::class)]
final class CashbackCalculatorTest extends TestCase
{
    private const VENDOR_A = 10;
    private const VENDOR_B = 20;

    private CashbackCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new CashbackCalculator();
    }

    #[Test]
    public function percentageRuleOnAllScopeEarnsPerLineOnPaidAmount(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '5.00');
        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00'), $this->line(2, self::VENDOR_B, '50.00')]), [$rule], $this->settings());

        self::assertSame('7.50', $r->total);
        self::assertCount(2, $r->lines);
        self::assertSame('5.00', $r->lines[0]->amount);
        self::assertSame('2.50', $r->lines[1]->amount);
        self::assertNull($r->reason);
    }

    #[Test]
    public function discountIsSpreadProRataAndDeliveryFeeNeverCounts(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00');
        // 300 subtotal, 30 discount -> bases 90 + 180; 10% = 27.00
        $r = $this->calc->calculate(
            $this->order([$this->line(1, self::VENDOR_A, '100.00'), $this->line(2, self::VENDOR_A, '200.00')], discount: '30.00', hasPromo: true),
            [$this->withPromo($rule)],
            $this->settings(),
        );
        self::assertSame('27.00', $r->total);
        self::assertSame('90.00', $r->lines[0]->base);
        self::assertSame('180.00', $r->lines[1]->base);
    }

    #[Test]
    public function storeScopeAndExclusionsDecideWhichLinesEarn(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00', CashbackRule::SCOPE_STORES);
        $rule->addTarget(CashbackRuleTarget::TYPE_VENDOR, self::VENDOR_A);
        $rule->addTarget(CashbackRuleTarget::TYPE_PRODUCT, 502, true); // "this store except product 502"

        $r = $this->calc->calculate($this->order([
            $this->line(1, self::VENDOR_A, '100.00', productId: 501),
            $this->line(2, self::VENDOR_A, '100.00', productId: 502),
            $this->line(3, self::VENDOR_B, '100.00', productId: 503),
        ]), [$rule], $this->settings());

        self::assertSame('10.00', $r->total);
        self::assertCount(1, $r->lines);
        self::assertSame(1, $r->lines[0]->key);
    }

    #[Test]
    public function allScopeWithStoreExclusionSkipsThatStore(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00');
        $rule->addTarget(CashbackRuleTarget::TYPE_VENDOR, self::VENDOR_B, true);
        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00'), $this->line(2, self::VENDOR_B, '100.00')]), [$rule], $this->settings());
        self::assertSame('10.00', $r->total);
    }

    #[Test]
    public function categoryScopeMatchesAncestorCategories(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00', CashbackRule::SCOPE_CATEGORIES);
        $rule->addTarget(CashbackRuleTarget::TYPE_CATEGORY, 7); // parent category
        $r = $this->calc->calculate($this->order([
            $this->line(1, self::VENDOR_A, '100.00', categories: [9, 7]), // child of 7
            $this->line(2, self::VENDOR_A, '100.00', categories: [3]),
        ]), [$rule], $this->settings());
        self::assertSame('10.00', $r->total);
    }

    #[Test]
    public function highestPriorityWinsPerLineWithoutStacking(): void
    {
        $low = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '20.00', priority: 0);
        $high = $this->rule(2, CashbackRule::REWARD_PERCENTAGE, '5.00', priority: 10);
        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')]), [$low, $high], $this->settings());
        self::assertSame('5.00', $r->total);
        self::assertSame(2, $r->lines[0]->rule->getId());
    }

    #[Test]
    public function samePriorityBiggerRewardWins(): void
    {
        $pct = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '5.00');
        $fixed = $this->rule(2, CashbackRule::REWARD_FIXED, '15.00');
        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')]), [$pct, $fixed], $this->settings());
        self::assertSame('15.00', $r->total);
        self::assertSame(2, $r->lines[0]->rule->getId());
    }

    #[Test]
    public function fixedRewardAppliesOncePerRulePerOrder(): void
    {
        $fixed = $this->rule(1, CashbackRule::REWARD_FIXED, '20.00');
        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00'), $this->line(2, self::VENDOR_A, '300.00')]), [$fixed], $this->settings());
        self::assertSame('20.00', $r->total);
        self::assertSame('5.00', $r->lines[0]->amount);
        self::assertSame('15.00', $r->lines[1]->amount);
    }

    #[Test]
    public function ruleCapAndProgrammeCapClampTheReward(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '50.00');
        $rule->setMaxRewardPerOrder('30.00');
        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')]), [$rule], $this->settings());
        self::assertSame('30.00', $r->total);

        $r2 = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')]), [$rule], $this->settings(max: '12.00'));
        self::assertSame('12.00', $r2->total);
    }

    #[Test]
    public function minEligibleAmountIsCheckedOnTheRulesLines(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00');
        $rule->setMinEligibleAmount('200.00');
        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '150.00')]), [$rule], $this->settings());
        self::assertSame('0.00', $r->total);
        self::assertSame(CashbackResult::REASON_BELOW_MINIMUM, $r->reason);
    }

    #[Test]
    public function budgetRemainingClampsAndExhaustedBudgetBlocks(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00');
        $rule->setBudgetTotal('100.00');

        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '500.00')], ruleSpent: [1 => '96.00']), [$rule], $this->settings());
        self::assertSame('4.00', $r->total);

        $r2 = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '500.00')], ruleSpent: [1 => '100.00']), [$rule], $this->settings());
        self::assertSame('0.00', $r2->total);
        self::assertSame(CashbackResult::REASON_BUDGET_EXHAUSTED, $r2->reason);
    }

    #[Test]
    public function programmeMinimumZeroesTinyAwards(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '1.00');
        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '50.00')]), [$rule], $this->settings());
        self::assertSame('0.00', $r->total); // 0.50 < AED 1 minimum
        self::assertSame(CashbackResult::REASON_BELOW_MINIMUM, $r->reason);
    }

    #[Test]
    public function promoToggleSkipsRulesThatDoNotAllowPromo(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00');
        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')], discount: '10.00', hasPromo: true), [$rule], $this->settings());
        self::assertSame('0.00', $r->total);
        self::assertSame(CashbackResult::REASON_NO_MATCHING_RULE, $r->reason);

        $rule->setAllowWithPromo(true);
        $r2 = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')], discount: '10.00', hasPromo: true), [$rule], $this->settings());
        self::assertSame('9.00', $r2->total);
    }

    #[Test]
    public function firstOrderOnlyAndPerCustomerLimit(): void
    {
        $first = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00');
        $first->setFirstOrderOnly(true);
        self::assertSame('0.00', $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')], firstOrder: false), [$first], $this->settings())->total);
        self::assertSame('10.00', $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')], firstOrder: true), [$first], $this->settings())->total);

        $limited = $this->rule(2, CashbackRule::REWARD_PERCENTAGE, '10.00');
        $limited->setPerCustomerLimit(2);
        self::assertSame('10.00', $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')], counts: [2 => 1]), [$limited], $this->settings())->total);
        self::assertSame('0.00', $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')], counts: [2 => 2]), [$limited], $this->settings())->total);
    }

    #[Test]
    public function channelMustMatch(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00');
        $rule->setChannel(CashbackRule::CHANNEL_MOBILE);
        self::assertSame('0.00', $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')], channel: 'WEB'), [$rule], $this->settings())->total);
        self::assertSame('10.00', $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')], channel: 'MOBILE'), [$rule], $this->settings())->total);
        self::assertSame('0.00', $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')], channel: null), [$rule], $this->settings())->total);
    }

    #[Test]
    public function inactiveOrOutOfWindowRulesNeverApply(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00');
        $rule->setValidity(new DateTimeImmutable('2030-01-01', new DateTimeZone('UTC')), null);
        self::assertSame('0.00', $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')]), [$rule], $this->settings())->total);

        $off = $this->rule(2, CashbackRule::REWARD_PERCENTAGE, '10.00');
        $off->setActive(false);
        self::assertSame('0.00', $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')]), [$off], $this->settings())->total);
    }

    #[Test]
    public function orderThatUsedCashbackCreditEarnsNothing(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00');
        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')], usedCashback: true), [$rule], $this->settings());
        self::assertSame('0.00', $r->total);
        self::assertSame(CashbackResult::REASON_PAID_WITH_CASHBACK, $r->reason);
    }

    #[Test]
    public function giftCardPurchaseAndCustomizationOrdersEarnNothing(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00');
        // Synthetic orders (gift-card purchase / customization payment) have no lines.
        $r = $this->calc->calculate($this->order([]), [$rule], $this->settings());
        self::assertSame(CashbackResult::REASON_INELIGIBLE_ORDER, $r->reason);

        $r2 = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')], synthetic: true), [$rule], $this->settings());
        self::assertSame('0.00', $r2->total);
        self::assertSame(CashbackResult::REASON_INELIGIBLE_ORDER, $r2->reason);
    }

    #[Test]
    public function programmeOffEarnsNothing(): void
    {
        $rule = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00');
        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00')]), [$rule], CashbackProgramSettings::default());
        self::assertSame(CashbackResult::REASON_PROGRAM_DISABLED, $r->reason);
    }

    #[Test]
    public function holdAndValidityAreTheMaxOfTheMatchedRules(): void
    {
        $a = $this->rule(1, CashbackRule::REWARD_PERCENTAGE, '10.00', CashbackRule::SCOPE_STORES);
        $a->addTarget(CashbackRuleTarget::TYPE_VENDOR, self::VENDOR_A);
        $a->setHoldDays(7);
        $b = $this->rule(2, CashbackRule::REWARD_PERCENTAGE, '10.00', CashbackRule::SCOPE_STORES);
        $b->addTarget(CashbackRuleTarget::TYPE_VENDOR, self::VENDOR_B);
        $b->setHoldDays(21);
        $b->setGiftCardValidityDays(90);
        $r = $this->calc->calculate($this->order([$this->line(1, self::VENDOR_A, '100.00'), $this->line(2, self::VENDOR_B, '100.00')]), [$a, $b], $this->settings());
        self::assertSame(21, $r->holdDays);
        self::assertSame(365, $r->giftCardValidityDays);
    }

    #[Test]
    public function distributeSumsExactly(): void
    {
        $parts = CashbackCalculator::distribute('10.00', [0 => '1.00', 1 => '1.00', 2 => '1.00']);
        self::assertSame('10.00', CashbackCalculator::sum($parts));
        self::assertSame(['3.33', '3.33', '3.34'], array_values($parts));
    }

    #[Test]
    public function settingsFromArrayIsSafeByDefaultAndValidatesAdminInput(): void
    {
        self::assertFalse(CashbackProgramSettings::fromArray(null)->enabled);
        self::assertFalse(CashbackProgramSettings::fromArray(['enabled' => 'yes'])->enabled);

        [$ok, $errors] = CashbackProgramSettings::fromAdminInput(['enabled' => true, 'max_award_per_order' => '50'], CashbackProgramSettings::default());
        self::assertSame([], $errors);
        self::assertNotNull($ok);
        self::assertTrue($ok->enabled);
        self::assertSame('50.00', $ok->maxAwardPerOrder);

        [$bad, $errors2] = CashbackProgramSettings::fromAdminInput(['default_hold_days' => -1], CashbackProgramSettings::default());
        self::assertNull($bad);
        self::assertArrayHasKey('default_hold_days', $errors2);
    }

    // ── helpers ─────────────────────────────────────────────────────

    private function settings(?string $max = null): CashbackProgramSettings
    {
        return CashbackProgramSettings::fromArray(['enabled' => true, 'min_award' => '1.00', 'max_award_per_order' => $max]);
    }

    private function rule(int $id, string $type, string $value, string $scope = CashbackRule::SCOPE_ALL, int $priority = 0): CashbackRule
    {
        $rule = new CashbackRule('Rule ' . $id, $type, $value);
        $rule->setActive(true);
        $rule->setScope($scope);
        $rule->setPriority($priority);
        $ref = new \ReflectionProperty(CashbackRule::class, 'id');
        $ref->setValue($rule, $id);
        return $rule;
    }

    private function withPromo(CashbackRule $rule): CashbackRule
    {
        $rule->setAllowWithPromo(true);
        return $rule;
    }

    /** @param list<int> $categories */
    private function line(int $key, int $vendorId, string $subtotal, int $productId = 0, array $categories = []): CashbackLineInput
    {
        return new CashbackLineInput($key, $vendorId, $productId > 0 ? $productId : 1000 + $key, $categories, $subtotal);
    }

    /**
     * @param list<CashbackLineInput> $lines
     * @param array<int, int> $counts
     * @param array<int, string> $ruleSpent
     */
    private function order(
        array $lines,
        string $discount = '0.00',
        bool $hasPromo = false,
        bool $usedCashback = false,
        bool $synthetic = false,
        bool $firstOrder = false,
        ?string $channel = 'WEB',
        array $counts = [],
        array $ruleSpent = [],
    ): CashbackOrderInput {
        return new CashbackOrderInput(
            at: new DateTimeImmutable('2026-11-18 12:00:00', new DateTimeZone('UTC')),
            channel: $channel,
            lines: $lines,
            discount: $discount,
            hasPromo: $hasPromo,
            usedCashbackCredit: $usedCashback,
            isSyntheticOrder: $synthetic,
            isFirstOrder: $firstOrder,
            customerAwardCounts: $counts,
            ruleSpent: $ruleSpent,
        );
    }
}
