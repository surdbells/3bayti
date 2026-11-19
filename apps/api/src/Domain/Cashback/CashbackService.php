<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

use Bayti\Api\Domain\Cart\Cart;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\GiftCard\GiftCard;
use Bayti\Api\Domain\Order\Order;
use Bayti\Api\Domain\Order\OrderItem;
use Bayti\Api\Domain\Order\OrderReturnRequest;
use Bayti\Api\Domain\Order\OrderReturnRequestRepository;
use Bayti\Api\Domain\Payment\PaymentTransaction;
use Bayti\Api\Domain\Payment\PaymentTransactionRepository;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Notification\CashbackNotifier;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Cashback lifecycle: accrue at payment, release (issue a cashback gift
 * card) after delivery + hold, cancel before release, reverse (claw back)
 * after release. Plus read-only estimates for the cart and the PDP badge.
 *
 * Fail-safe contract
 * ------------------
 * The hook methods called from checkout, the Noon webhook, the reconcile
 * cron and the cancel/refund/return paths (accrueForPaidOrder,
 * cancelForOrder, reverseForReturn) NEVER throw: every error is caught and
 * logged, so cashback can never break a payment or a cancellation. The
 * admin-driven methods (releaseAward, reverseAward, cancelAward) DO throw,
 * so the admin endpoints can report the problem.
 *
 * Money safety
 * ------------
 *   - One award per order: UNIQUE order_id + the order row is locked
 *     (FOR UPDATE) before the existence check, so concurrent paid hooks
 *     (webhook vs reconcile cron) serialise and the loser sees the award.
 *   - Budgets: the matched rules are locked before their spend is read, so
 *     two accruals can't both spend the last of a budget.
 *   - Release is single-shot: the award row is locked (FOR UPDATE) and
 *     re-checked for `pending` inside the transaction, so a cron run racing
 *     an admin "release now" issues ONE card (UNIQUE gift_card_id backs it).
 *   - No cashback on a cashbacked sale: orders.used_cashback_credit at
 *     accrual, re-checked from the gift-card ledger at release.
 */
class CashbackService
{
    public const OUTCOME_ISSUED = 'issued';
    public const OUTCOME_CANCELLED = 'cancelled';
    public const OUTCOME_NOT_DUE = 'not_due';
    public const OUTCOME_HELD_RETURN = 'held_open_return';
    public const OUTCOME_NOT_PENDING = 'not_pending';
    public const OUTCOME_NOT_FOUND = 'not_found';
    public const OUTCOME_WOULD_ISSUE = 'would_issue';
    public const OUTCOME_WOULD_CANCEL = 'would_cancel';

    /** Order statuses that mean "the customer paid and still has the order". */
    private const PAID_STATES = [
        Order::STATUS_PAID,
        Order::STATUS_FULFILLING,
        Order::STATUS_SHIPPED,
        Order::STATUS_DELIVERED,
    ];

    /** Order statuses that void a pending award. */
    private const DEAD_STATES = [
        Order::STATUS_CANCELLED,
        Order::STATUS_REFUNDED,
        Order::STATUS_FAILED,
    ];

    /** Item statuses that earn nothing at release. */
    private const DROPPED_ITEM_STATES = [
        OrderItem::ITEM_STATUS_CANCELLED,
        OrderItem::ITEM_STATUS_REJECTED,
        OrderItem::ITEM_STATUS_RETURNED,
        OrderItem::ITEM_STATUS_REFUNDED,
    ];

    /** Return requests still in flight (release waits for them). */
    private const OPEN_RETURN_STATES = [
        OrderReturnRequest::STATUS_PENDING,
        OrderReturnRequest::STATUS_APPROVED,
        OrderReturnRequest::STATUS_PICKED_UP,
    ];

    /** Return requests whose items are back with the vendor (earn nothing). */
    private const RETURNED_STATES = [
        OrderReturnRequest::STATUS_DELIVERED_TO_VENDOR,
        OrderReturnRequest::STATUS_REFUNDED,
    ];

    private readonly CashbackCalculator $calculator;

    /** @var list<CashbackRule>|null per-request cache for PDP badges */
    private ?array $liveRulesCache = null;

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CashbackSettingsStore $settings,
        private readonly CashbackNotifier $notifier,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
        $this->calculator = new CashbackCalculator();
    }

    // ── Accrual ──────────────────────────────────────────────────────

    /**
     * Create the pending award for a just-paid order. Idempotent (returns
     * the existing award on a repeat call). Returns null when the order
     * earns nothing. Never throws.
     */
    public function accrueForPaidOrder(Order $order): ?CashbackAward
    {
        try {
            $settings = $this->settings->load();
            if (!$settings->enabled) {
                return null;
            }
            if (!in_array($order->getStatus(), self::PAID_STATES, true)) {
                return null;
            }
            // Gift-card purchases and customization payments are item-less
            // synthetic orders: they never earn cashback.
            if ($order->getItems()->isEmpty()) {
                return null;
            }
            $orderId = $order->getId();
            if ($orderId === null) {
                return null;
            }

            $existing = $this->awards()->findOneByOrder($order);
            if ($existing !== null) {
                return $existing;
            }

            $at = $order->getPaidAt() ?? $this->now();
            $rules = $this->rules()->findLive($at);
            if ($rules === []) {
                return null;
            }

            $usedCashback = $order->hasUsedCashbackCredit() || $this->ledgerUsedCashback($order);

            /** @var CashbackAward|null $award */
            $award = $this->inTransaction(function () use ($order, $orderId, $rules, $settings, $at, $usedCashback): ?CashbackAward {
                // Serialise concurrent paid hooks for this order.
                $this->em->getConnection()->executeQuery('SELECT id FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
                $existing = $this->awards()->findOneByOrder($order);
                if ($existing !== null) {
                    return $existing;
                }

                $ruleIds = self::ruleIds($rules);
                $this->rules()->lockByIds($ruleIds);
                $userId = (int) $order->getUser()->getId();

                $input = new CashbackOrderInput(
                    at: $at,
                    channel: $order->getChannel(),
                    lines: $this->orderLines($order),
                    discount: $order->getDiscount(),
                    hasPromo: $order->getPromoRedemption() !== null || bccomp($order->getDiscount(), '0.00', 2) > 0,
                    usedCashbackCredit: $usedCashback,
                    isSyntheticOrder: false,
                    isFirstOrder: $this->isFirstOrder($order->getUser(), $orderId),
                    customerAwardCounts: $this->awards()->customerAwardCounts($userId, $ruleIds, $orderId),
                    ruleSpent: $this->rules()->spentByRuleIds($ruleIds),
                );
                $result = $this->calculator->calculate($input, $rules, $settings);
                if (!$result->isPositive()) {
                    $this->logger->info('cashback.accrue.nothing_earned', [
                        'order_id' => $orderId,
                        'reason'   => $result->reason,
                    ]);
                    return null;
                }

                $award = new CashbackAward(
                    order: $order,
                    user: $order->getUser(),
                    amount: $result->total,
                    currency: $order->getCurrency(),
                    holdDays: $result->holdDays,
                    giftCardValidityDays: $result->giftCardValidityDays,
                    snapshot: $this->accrualSnapshot($order, $result, $settings, $usedCashback, $input->isFirstOrder),
                );
                foreach ($result->lines as $line) {
                    $award->addLine($line->rule, $line->key, $line->base, $line->amount);
                }
                $this->em->persist($award);
                $this->em->flush();
                return $award;
            });

            if ($award !== null) {
                $this->logger->info('cashback.accrued', [
                    'order_id' => $orderId,
                    'award_id' => $award->getId(),
                    'amount'   => $award->getAmount(),
                ]);
            }
            return $award;
        } catch (\Throwable $e) {
            $this->logger->error('cashback.accrue_failed', [
                'order_id'        => $order->getId(),
                'order_reference' => $order->getOrderReference(),
                'error'           => $e->getMessage(),
                'class'           => $e::class,
            ]);
            return null;
        }
    }

    // ── Cancel / reverse hooks (fail-safe) ───────────────────────────

    /**
     * The order was cancelled or refunded in full: a pending award is
     * cancelled, an issued one is reversed (clawed back). Never throws.
     */
    public function cancelForOrder(Order $order, string $reason): void
    {
        try {
            $award = $this->awards()->findOneByOrder($order);
            if ($award === null) {
                return;
            }
            if ($award->isPending()) {
                $this->cancelAward($award, $reason);
            } elseif ($award->isIssued()) {
                $this->reverseAward($award, $reason);
            }
        } catch (\Throwable $e) {
            $this->logger->error('cashback.cancel_for_order_failed', [
                'order_id' => $order->getId(),
                'error'    => $e->getMessage(),
            ]);
        }
    }

    /**
     * A return was refunded. If the award was already issued, claw back
     * what the returned items earned. (A pending award needs nothing here:
     * release drops returned items.) Never throws.
     */
    public function reverseForReturn(OrderReturnRequest $return, string $reason): void
    {
        try {
            $award = $this->awards()->findOneByOrder($return->getOrder());
            if ($award === null || !$award->isIssued()) {
                return;
            }
            $itemIds = [];
            foreach ($return->getItems() as $item) {
                $id = $item->getOrderItem()->getId();
                if ($id !== null) {
                    $itemIds[$id] = true;
                }
            }
            $value = '0.00';
            foreach ($award->getLines() as $line) {
                $oid = $line->getOrderItemId();
                if ($oid !== null && isset($itemIds[$oid])) {
                    $value = bcadd($value, $line->getAmount(), 2);
                }
            }
            if (bccomp($value, '0.00', 2) <= 0) {
                return;
            }
            $this->reverseAward($award, $reason, null, $value);
        } catch (\Throwable $e) {
            $this->logger->error('cashback.reverse_for_return_failed', [
                'return_id' => $return->getId(),
                'error'     => $e->getMessage(),
            ]);
        }
    }

    // ── Admin-grade operations (throw) ───────────────────────────────

    /**
     * Cancel a pending award. Returns false when it was no longer pending.
     */
    public function cancelAward(CashbackAward $award, string $reason): bool
    {
        $awardId = (int) $award->getId();
        return (bool) $this->inTransaction(function () use ($awardId, $reason): bool {
            $locked = $this->awards()->lockById($awardId);
            if ($locked === null || !$locked->isPending()) {
                return false;
            }
            $locked->markCancelled($reason, $this->now());
            return true;
        });
    }

    /**
     * Claw back an issued award. $value null = everything still earned
     * (full reversal, status → reversed); otherwise a partial amount. Debits
     * min(card balance, value) from the cashback card through its ledger;
     * anything the customer already spent is written off.
     *
     * @return array{value: string, collected: string}
     * @throws \DomainException when the award is not issued
     */
    public function reverseAward(CashbackAward $award, string $reason, ?User $actor = null, ?string $value = null): array
    {
        $awardId = (int) $award->getId();
        /** @var array{value: string, collected: string} $out */
        $out = $this->inTransaction(function () use ($awardId, $reason, $actor, $value): array {
            $locked = $this->awards()->lockById($awardId);
            if ($locked === null) {
                throw new \DomainException('Cashback award not found.');
            }
            if (!$locked->isIssued()) {
                throw new \DomainException("Only an issued cashback award can be reversed (status: {$locked->getStatus()}).");
            }
            $remaining = $locked->remainingAmount();
            $target = $value === null ? $remaining : CashbackCalculator::min($value, $remaining);
            if (bccomp($target, '0.00', 2) <= 0) {
                return ['value' => '0.00', 'collected' => '0.00'];
            }

            $collected = '0.00';
            $card = $locked->getGiftCard();
            if ($card !== null) {
                $this->em->lock($card, LockMode::PESSIMISTIC_WRITE);
                $this->em->refresh($card);
                if (!$card->isVoided()) {
                    $collect = CashbackCalculator::min($card->getBalance(), $target);
                    if (bccomp($collect, '0.00', 2) > 0) {
                        $card->adminDebit(
                            $collect,
                            mb_substr('Cashback reversed (order ' . $locked->getOrder()->getOrderReference() . '): ' . $reason, 0, 255),
                            $actor?->getId(),
                        );
                        $collected = $collect;
                    }
                }
            }
            $locked->recordReversal($target, $collected, $reason, $value === null, $this->now());
            return ['value' => $target, 'collected' => $collected];
        });

        $this->logger->info('cashback.reversed', [
            'award_id'  => $awardId,
            'value'     => $out['value'],
            'collected' => $out['collected'],
            'reason'    => $reason,
        ]);
        return $out;
    }

    // ── Release ──────────────────────────────────────────────────────

    /**
     * Release every due award (cron). Each award is handled on its own;
     * one failure never stops the batch.
     *
     * @param (callable(int, string): void)|null $onProgress called per award with (award id, outcome)
     * @return array{due: int, issued: int, cancelled: int, skipped: int, errors: int}
     */
    public function releaseDue(DateTimeImmutable $now, int $limit = 200, bool $dryRun = false, ?callable $onProgress = null): array
    {
        $ids = $this->awards()->findDueIds($now, $limit);
        $stats = ['due' => count($ids), 'issued' => 0, 'cancelled' => 0, 'skipped' => 0, 'errors' => 0];
        foreach ($ids as $id) {
            try {
                $outcome = $this->releaseAwardById($id, $now, false, $dryRun);
            } catch (\Throwable $e) {
                $outcome = 'error';
                $this->logger->error('cashback.release_failed', ['award_id' => $id, 'error' => $e->getMessage()]);
            }
            match ($outcome) {
                self::OUTCOME_ISSUED, self::OUTCOME_WOULD_ISSUE => $stats['issued']++,
                self::OUTCOME_CANCELLED, self::OUTCOME_WOULD_CANCEL => $stats['cancelled']++,
                'error' => $stats['errors']++,
                default => $stats['skipped']++,
            };
            if ($onProgress !== null) {
                $onProgress($id, $outcome);
            }
        }
        return $stats;
    }

    /**
     * Release one award. $force (admin "release now") skips the
     * delivery + hold wait, but never an open return or a dead order.
     * Returns an OUTCOME_* constant. Throws on unexpected errors.
     */
    public function releaseAwardById(int $awardId, ?DateTimeImmutable $now = null, bool $force = false, bool $dryRun = false): string
    {
        $now ??= $this->now();
        $issued = null;

        $outcome = (string) $this->inTransaction(function () use ($awardId, $now, $force, $dryRun, &$issued): string {
            $award = $this->awards()->lockById($awardId);
            if ($award === null) {
                return self::OUTCOME_NOT_FOUND;
            }
            if (!$award->isPending()) {
                return self::OUTCOME_NOT_PENDING;
            }
            $order = $award->getOrder();
            $this->em->refresh($order);

            if (in_array($order->getStatus(), self::DEAD_STATES, true)) {
                return $this->cancelLocked($award, 'order_' . $order->getStatus(), $dryRun);
            }
            if ($force) {
                if (!in_array($order->getStatus(), self::PAID_STATES, true)) {
                    return self::OUTCOME_NOT_DUE;
                }
            } elseif (!$this->isDue($award, $order, $now)) {
                return self::OUTCOME_NOT_DUE;
            }

            $returns = $this->returnsFor($order);
            foreach ($returns as $rr) {
                if (in_array($rr->getStatus(), self::OPEN_RETURN_STATES, true)) {
                    return self::OUTCOME_HELD_RETURN;
                }
            }

            if ($order->hasUsedCashbackCredit() || $this->ledgerUsedCashback($order)) {
                return $this->cancelLocked($award, CashbackResult::REASON_PAID_WITH_CASHBACK, $dryRun);
            }

            $calc = $this->releaseAmount($award, $order, $returns);
            $settings = $this->settings->load();
            if (bccomp($calc['amount'], '0.00', 2) <= 0 || bccomp($calc['amount'], $settings->minAward, 2) < 0) {
                return $this->cancelLocked($award, 'nothing_left_after_returns_or_refunds', $dryRun);
            }
            if ($dryRun) {
                return self::OUTCOME_WOULD_ISSUE;
            }

            foreach ($award->getLines() as $i => $line) {
                $line->setAmount($calc['lines'][$i] ?? '0.00');
            }
            $award->adjustAmount($calc['amount']);

            $card = GiftCard::issueCashback(
                customer: $award->getUser(),
                amount: $calc['amount'],
                expiresAt: $now->modify('+' . $award->getGiftCardValidityDays() . ' days'),
                cashbackAwardId: $awardId,
                orderReference: $order->getOrderReference(),
            );
            $this->em->persist($card);
            $award->markIssued($card, $now);
            $award->mergeSnapshot(['release' => [
                'at'                => $now->format(DATE_ATOM),
                'forced'            => $force,
                'accrued_amount'    => $award->getAccruedAmount(),
                'released_amount'   => $calc['amount'],
                'excluded_item_ids' => $calc['excluded_item_ids'],
                'refunded_share'    => $calc['refunded_share'],
            ]]);
            $this->em->flush();
            $issued = [$award, $card];
            return self::OUTCOME_ISSUED;
        });

        if ($issued !== null) {
            [$award, $card] = $issued;
            $this->logger->info('cashback.issued', [
                'award_id'     => $awardId,
                'gift_card_id' => $card->getId(),
                'amount'       => $card->getDenomination(),
            ]);
            $this->notifier->cashbackIssued($award, $card);
        }
        return $outcome;
    }

    /**
     * What the award is worth at release: drop lines on cancelled /
     * rejected / returned / refunded items (and items of returns that reached
     * the vendor), then scale by the share of the order already refunded
     * (card refunds + gift-card spend given back).
     *
     * @param list<OrderReturnRequest> $returns
     * @return array{amount: string, lines: array<int, string>, excluded_item_ids: list<int>, refunded_share: string}
     */
    public function releaseAmount(CashbackAward $award, Order $order, array $returns): array
    {
        $excluded = [];
        foreach ($order->getItems() as $item) {
            if (in_array($item->getItemStatus(), self::DROPPED_ITEM_STATES, true) && $item->getId() !== null) {
                $excluded[$item->getId()] = true;
            }
        }
        foreach ($returns as $rr) {
            if (!in_array($rr->getStatus(), self::RETURNED_STATES, true)) {
                continue;
            }
            foreach ($rr->getItems() as $ri) {
                $id = $ri->getOrderItem()->getId();
                if ($id !== null) {
                    $excluded[$id] = true;
                }
            }
        }

        $kept = [];
        foreach ($award->getLines() as $i => $line) {
            $oid = $line->getOrderItemId();
            $kept[$i] = ($oid !== null && isset($excluded[$oid])) ? '0.00' : $line->getAmount();
        }
        $keptTotal = CashbackCalculator::sum($kept);

        $share = '0';
        $total = $order->getTotal();
        if (bccomp($total, '0.00', 2) > 0) {
            $refunded = bcadd($this->gatewayRefunded($order), $this->giftCardRestored($order), 2);
            $share = bcdiv($refunded, $total, 6);
            if (bccomp($share, '1', 6) > 0) {
                $share = '1';
            }
        }
        $amount = bcmul($keptTotal, bcsub('1', $share, 6), 2);
        if (bccomp($amount, '0.00', 2) < 0) {
            $amount = '0.00';
        }

        return [
            'amount'            => bcadd($amount, '0', 2),
            'lines'             => CashbackCalculator::distribute($amount, $kept),
            'excluded_item_ids' => array_map('intval', array_keys($excluded)),
            'refunded_share'    => $share,
        ];
    }

    // ── Read-only estimates ──────────────────────────────────────────

    /**
     * Estimated cashback for the customer's cart. $discount is the promo
     * discount the cart would get (0 when none). Never throws.
     */
    public function previewForCart(
        Cart $cart,
        User $user,
        ?string $channel,
        string $discount = '0.00',
        bool $hasPromo = false,
        bool $usesCashbackCredit = false,
    ): CashbackResult {
        try {
            $settings = $this->settings->load();
            if (!$settings->enabled) {
                return CashbackResult::none(CashbackResult::REASON_PROGRAM_DISABLED);
            }
            if ($cart->getItems()->isEmpty()) {
                return CashbackResult::none(CashbackResult::REASON_EMPTY_CART);
            }
            $lines = [];
            foreach ($cart->getItems() as $item) {
                $product = $item->getProduct();
                $lines[] = new CashbackLineInput(
                    key: null,
                    vendorId: $product->getVendor()->getId(),
                    productId: $product->getId(),
                    categoryIds: self::categoryChain($product->getCategory()),
                    subtotal: bcmul($item->getUnitPriceSnapshot(), (string) $item->getQuantity(), 2),
                );
            }
            $now = $this->now();
            $rules = $this->rules()->findLive($now);
            $ruleIds = self::ruleIds($rules);
            $userId = (int) $user->getId();
            $input = new CashbackOrderInput(
                at: $now,
                channel: $channel,
                lines: $lines,
                discount: $discount,
                hasPromo: $hasPromo,
                usedCashbackCredit: $usesCashbackCredit,
                isSyntheticOrder: false,
                isFirstOrder: $this->isFirstOrder($user, null),
                customerAwardCounts: $ruleIds === [] ? [] : $this->awards()->customerAwardCounts($userId, $ruleIds),
                ruleSpent: $ruleIds === [] ? [] : $this->rules()->spentByRuleIds($ruleIds),
            );
            return $this->calculator->calculate($input, $rules, $settings);
        } catch (\Throwable $e) {
            $this->logger->error('cashback.preview_failed', ['user_id' => $user->getId(), 'error' => $e->getMessage()]);
            return CashbackResult::none(CashbackResult::REASON_UNAVAILABLE);
        }
    }

    /**
     * The "Earn X cashback" badge for a product page: the best live rule
     * covering the product (highest priority, then bigger reward on its
     * price), skipping rules whose budget is spent. Customer-specific limits
     * (first order, per-customer) are shown as flags, not applied. Never
     * throws.
     *
     * @return array{type: string, value: string, max: string|null, min_eligible_amount: string|null, channel: string, first_order_only: bool, allow_with_promo: bool}|null
     */
    public function badgeForProduct(Product $product): ?array
    {
        try {
            if (!$this->settings->load()->enabled) {
                return null;
            }
            $now = $this->now();
            $this->liveRulesCache ??= $this->rules()->findLive($now);
            $categories = self::categoryChain($product->getCategory());
            $vendorId = $product->getVendor()->getId();
            $price = $product->effectivePrice();

            $candidates = [];
            foreach ($this->liveRulesCache as $rule) {
                if ($rule->isLiveAt($now) && $rule->covers($vendorId, $product->getId(), $categories)) {
                    $candidates[] = $rule;
                }
            }
            usort($candidates, static function (CashbackRule $a, CashbackRule $b) use ($price): int {
                if ($a->getPriority() !== $b->getPriority()) {
                    return $b->getPriority() <=> $a->getPriority();
                }
                $cmp = bccomp(CashbackCalculator::estimate($b, $price), CashbackCalculator::estimate($a, $price), 2);
                return $cmp !== 0 ? $cmp : (($a->getId() ?? 0) <=> ($b->getId() ?? 0));
            });

            $budgeted = array_values(array_filter($candidates, static fn (CashbackRule $r): bool => $r->getBudgetTotal() !== null));
            $spent = $budgeted === [] ? [] : $this->rules()->spentByRuleIds(self::ruleIds($budgeted));
            foreach ($candidates as $rule) {
                $budget = $rule->getBudgetTotal();
                if ($budget !== null && bccomp($spent[$rule->getId() ?? 0] ?? '0.00', $budget, 2) >= 0) {
                    continue;
                }
                return [
                    'type'                => $rule->getRewardType(),
                    'value'               => $rule->getRewardValue(),
                    'max'                 => $rule->getMaxRewardPerOrder(),
                    'min_eligible_amount' => $rule->getMinEligibleAmount(),
                    'channel'             => $rule->getChannel(),
                    'first_order_only'    => $rule->isFirstOrderOnly(),
                    'allow_with_promo'    => $rule->allowsWithPromo(),
                ];
            }
            return null;
        } catch (\Throwable $e) {
            $this->logger->warning('cashback.badge_failed', ['product_id' => $product->getId(), 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Customer-facing shape of an order's award, or null when the order
     * has none. Never throws.
     *
     * @return array{status: string, amount: string, currency: string, release_at: string|null, gift_card_id: int|null}|null
     */
    public function orderSummary(Order $order): ?array
    {
        try {
            $award = $this->awards()->findOneByOrder($order);
            if ($award === null) {
                return null;
            }
            return [
                'status'       => $award->getStatus(),
                'amount'       => $award->getAmount(),
                'currency'     => $award->getCurrency(),
                'release_at'   => $award->expectedReleaseAt()?->format(DATE_ATOM),
                'gift_card_id' => $award->getGiftCard()?->getId(),
            ];
        } catch (\Throwable $e) {
            $this->logger->warning('cashback.order_summary_failed', ['order_id' => $order->getId(), 'error' => $e->getMessage()]);
            return null;
        }
    }

    // ── Internals ────────────────────────────────────────────────────

    private function cancelLocked(CashbackAward $award, string $reason, bool $dryRun): string
    {
        if ($dryRun) {
            return self::OUTCOME_WOULD_CANCEL;
        }
        $award->markCancelled($reason, $this->now());
        return self::OUTCOME_CANCELLED;
    }

    private function isDue(CashbackAward $award, Order $order, DateTimeImmutable $now): bool
    {
        if ($order->getStatus() !== Order::STATUS_DELIVERED) {
            return false;
        }
        $deliveredAt = $order->getDeliveredAt();
        if ($deliveredAt === null) {
            return false;
        }
        return $deliveredAt->modify('+' . $award->getHoldDays() . ' days') <= $now;
    }

    /** @return list<OrderReturnRequest> */
    private function returnsFor(Order $order): array
    {
        $repo = $this->em->getRepository(OrderReturnRequest::class);
        if (!$repo instanceof OrderReturnRequestRepository || $order->getId() === null) {
            return [];
        }
        return $repo->findAllByOrder((int) $order->getId());
    }

    private function gatewayRefunded(Order $order): string
    {
        $repo = $this->em->getRepository(PaymentTransaction::class);
        return $repo instanceof PaymentTransactionRepository ? $repo->sumRefundsForOrder($order) : '0.00';
    }

    /** Gift-card spend of this order already given back (order-tagged credits). */
    private function giftCardRestored(Order $order): string
    {
        if (bccomp($order->getGiftCardAmount(), '0.00', 2) <= 0) {
            return '0.00';
        }
        return $this->awards()->giftCardRestoredForOrder($order);
    }

    /**
     * Did checkout debit a cashback card for this order? Read from the
     * ledger (defence in depth behind orders.used_cashback_credit).
     */
    private function ledgerUsedCashback(Order $order): bool
    {
        if (bccomp($order->getGiftCardAmount(), '0.00', 2) <= 0) {
            return false;
        }
        return $this->awards()->orderSpentCashbackCredit($order);
    }

    /** No earlier paid product order for this customer. */
    private function isFirstOrder(User $user, ?int $orderId): bool
    {
        return $this->awards()->countEarlierPaidProductOrders($user, $orderId) === 0;
    }

    /** @return list<CashbackLineInput> */
    private function orderLines(Order $order): array
    {
        $lines = [];
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $lines[] = new CashbackLineInput(
                key: $item->getId(),
                vendorId: $item->getVendor()->getId(),
                productId: $product->getId(),
                categoryIds: self::categoryChain($product->getCategory()),
                subtotal: $item->getSubtotal(),
            );
        }
        return $lines;
    }

    /**
     * The category and its ancestors (v3 ids), so a rule on a parent
     * category covers its sub-categories.
     *
     * @return list<int>
     */
    public static function categoryChain(?Category $category): array
    {
        $ids = [];
        $guard = 0;
        while ($category !== null && $guard++ < 20) {
            $id = $category->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
            $category = $category->getParent();
        }
        return $ids;
    }

    /**
     * @param list<CashbackRule> $rules
     * @return list<int>
     */
    private static function ruleIds(array $rules): array
    {
        $ids = [];
        foreach ($rules as $r) {
            $id = $r->getId();
            if ($id !== null) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** @return array<string, mixed> */
    private function accrualSnapshot(
        Order $order,
        CashbackResult $result,
        CashbackProgramSettings $settings,
        bool $usedCashback,
        bool $firstOrder,
    ): array {
        $rules = [];
        foreach ($result->lines as $line) {
            $r = $line->rule;
            $rules[(string) ($r->getId() ?? 0)] = [
                'id'                      => $r->getId(),
                'name'                    => $r->getName(),
                'priority'                => $r->getPriority(),
                'reward_type'             => $r->getRewardType(),
                'reward_value'            => $r->getRewardValue(),
                'max_reward_per_order'    => $r->getMaxRewardPerOrder(),
                'min_eligible_amount'     => $r->getMinEligibleAmount(),
                'allow_with_promo'        => $r->allowsWithPromo(),
                'hold_days'               => $r->getHoldDays(),
                'gift_card_validity_days' => $r->getGiftCardValidityDays(),
            ];
        }
        return [
            'order_reference'    => $order->getOrderReference(),
            'paid_at'            => $order->getPaidAt()?->format(DATE_ATOM),
            'channel'            => $order->getChannel(),
            'subtotal'           => $order->getSubtotal(),
            'discount'           => $order->getDiscount(),
            'delivery_fee'       => $order->getDeliveryFee(),
            'total'              => $order->getTotal(),
            'gift_card_amount'   => $order->getGiftCardAmount(),
            'used_cashback'      => $usedCashback,
            'first_order'        => $firstOrder,
            'program'            => $settings->toArray(),
            'rules'              => $rules,
            'lines'              => array_map(static fn (CashbackResultLine $l): array => [
                'order_item_id' => $l->key,
                'rule_id'       => $l->rule->getId(),
                'base'          => $l->base,
                'amount'        => $l->amount,
            ], $result->lines),
        ];
    }

    /**
     * Run $fn in a DB transaction (flushes before commit).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    private function inTransaction(callable $fn): mixed
    {
        $conn = $this->em->getConnection();
        $conn->beginTransaction();
        try {
            $result = $fn();
            $this->em->flush();
            $conn->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($conn->isTransactionActive()) {
                $conn->rollBack();
            }
            throw $e;
        }
    }

    private function awards(): CashbackAwardRepository
    {
        $repo = $this->em->getRepository(CashbackAward::class);
        if (!$repo instanceof CashbackAwardRepository) {
            throw new \LogicException('CashbackAwardRepository unavailable.');
        }
        return $repo;
    }

    private function rules(): CashbackRuleRepository
    {
        $repo = $this->em->getRepository(CashbackRule::class);
        if (!$repo instanceof CashbackRuleRepository) {
            throw new \LogicException('CashbackRuleRepository unavailable.');
        }
        return $repo;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
