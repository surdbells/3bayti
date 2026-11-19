<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

use Bayti\Api\Domain\Cashback\CashbackAward;
use Bayti\Api\Domain\Cashback\CashbackAwardLine;
use Bayti\Api\Domain\Cashback\CashbackRule;
use Bayti\Api\Domain\Cashback\CashbackRuleTarget;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Response shapes for the cashback programme (admin + customer). Money is
 * always a DECIMAL(10,2) string, dates are ISO-8601 (ATOM), ids are v3 ids.
 */
final class CashbackSerializer
{
    public const RULE_STATUS_ACTIVE = 'active';
    public const RULE_STATUS_INACTIVE = 'inactive';
    public const RULE_STATUS_SCHEDULED = 'scheduled';
    public const RULE_STATUS_EXPIRED = 'expired';

    /**
     * @param array{spent?: string, awards_count?: int} $stats
     * @param array<string, string> $targetLabels "type:id" => display name
     * @return array<string, mixed>
     */
    public function ruleShape(CashbackRule $rule, array $stats = [], array $targetLabels = []): array
    {
        $spent = $stats['spent'] ?? '0.00';
        $budget = $rule->getBudgetTotal();
        $remaining = null;
        if ($budget !== null) {
            $remaining = bcsub($budget, $spent, 2);
            if (bccomp($remaining, '0.00', 2) < 0) {
                $remaining = '0.00';
            }
        }

        return [
            'id'                      => $rule->getId(),
            'name'                    => $rule->getName(),
            'description'             => $rule->getDescription(),
            'is_active'               => $rule->isActive(),
            'status'                  => self::ruleStatus($rule),
            'priority'                => $rule->getPriority(),
            'reward_type'             => $rule->getRewardType(),
            'reward_value'            => $rule->getRewardValue(),
            'max_reward_per_order'    => $rule->getMaxRewardPerOrder(),
            'min_eligible_amount'     => $rule->getMinEligibleAmount(),
            'valid_from'              => self::date($rule->getValidFrom()),
            'valid_until'             => self::date($rule->getValidUntil()),
            'scope'                   => $rule->getScope(),
            'channel'                 => $rule->getChannel(),
            'first_order_only'        => $rule->isFirstOrderOnly(),
            'per_customer_limit'      => $rule->getPerCustomerLimit(),
            'budget_total'            => $budget,
            'budget_spent'            => $spent,
            'budget_remaining'        => $remaining,
            'awards_count'            => $stats['awards_count'] ?? 0,
            'allow_with_promo'        => $rule->allowsWithPromo(),
            'hold_days'               => $rule->getHoldDays(),
            'gift_card_validity_days' => $rule->getGiftCardValidityDays(),
            'targets'                 => array_values(array_map(
                static fn (CashbackRuleTarget $t): array => [
                    'id'           => $t->getId(),
                    'target_type'  => $t->getTargetType(),
                    'target_id'    => $t->getTargetId(),
                    'is_exclusion' => $t->isExclusion(),
                    'label'        => $targetLabels[$t->getTargetType() . ':' . $t->getTargetId()] ?? null,
                ],
                $rule->getTargets()->toArray(),
            )),
            'created_by'              => $rule->getCreatedBy(),
            'created_at'              => self::date($rule->getCreatedAt()),
            'updated_at'              => self::date($rule->getUpdatedAt()),
        ];
    }

    public static function ruleStatus(CashbackRule $rule, ?DateTimeImmutable $now = null): string
    {
        $now ??= new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if (!$rule->isActive()) {
            return self::RULE_STATUS_INACTIVE;
        }
        if ($rule->getValidUntil() !== null && $rule->getValidUntil() < $now) {
            return self::RULE_STATUS_EXPIRED;
        }
        if ($rule->getValidFrom() !== null && $rule->getValidFrom() > $now) {
            return self::RULE_STATUS_SCHEDULED;
        }
        return self::RULE_STATUS_ACTIVE;
    }

    /**
     * Admin ledger row.
     *
     * @return array<string, mixed>
     */
    public function adminAwardShape(CashbackAward $award): array
    {
        $order = $award->getOrder();
        $user = $award->getUser();
        $card = $award->getGiftCard();
        $name = trim(((string) $user->getFirstName()) . ' ' . ((string) $user->getLastName()));

        $rules = [];
        foreach ($award->getLines() as $line) {
            $r = $line->getRule();
            $rules[(int) $r->getId()] = ['id' => $r->getId(), 'name' => $r->getName()];
        }

        return [
            'id'                      => $award->getId(),
            'status'                  => $award->getStatus(),
            'amount'                  => $award->getAmount(),
            'accrued_amount'          => $award->getAccruedAmount(),
            'reversed_amount'         => $award->getReversedAmount(),
            'currency'                => $award->getCurrency(),
            'hold_days'               => $award->getHoldDays(),
            'gift_card_validity_days' => $award->getGiftCardValidityDays(),
            'release_at'              => self::date($award->expectedReleaseAt()),
            'released_at'             => self::date($award->getReleasedAt()),
            'cancelled_at'            => self::date($award->getCancelledAt()),
            'reversed_at'             => self::date($award->getReversedAt()),
            'status_reason'           => $award->getStatusReason(),
            'order' => [
                'id'              => $order->getId(),
                'order_reference' => $order->getOrderReference(),
                'status'          => $order->getStatus(),
                'total'           => $order->getTotal(),
                'paid_at'         => self::date($order->getPaidAt()),
                'delivered_at'    => self::date($order->getDeliveredAt()),
            ],
            'customer' => [
                'id'    => $user->getId(),
                'email' => $user->getEmail(),
                'name'  => $name === '' ? null : $name,
            ],
            'gift_card' => $card !== null ? [
                'id'      => $card->getId(),
                'code'    => $card->formattedCode(),
                'balance' => $card->getBalance(),
                'status'  => $card->getStatus(),
            ] : null,
            'rules'      => array_values($rules),
            'created_at' => self::date($award->getCreatedAt()),
            'updated_at' => self::date($award->getUpdatedAt()),
        ];
    }

    /**
     * Admin detail: ledger row + per-line breakdown + the accrual snapshot.
     *
     * @return array<string, mixed>
     */
    public function adminAwardDetailShape(CashbackAward $award): array
    {
        $data = $this->adminAwardShape($award);
        $data['lines'] = array_values(array_map(
            static fn (CashbackAwardLine $l): array => [
                'id'            => $l->getId(),
                'rule_id'       => $l->getRule()->getId(),
                'rule_name'     => $l->getRule()->getName(),
                'order_item_id' => $l->getOrderItemId(),
                'base_amount'   => $l->getBaseAmount(),
                'amount'        => $l->getAmount(),
            ],
            $award->getLines()->toArray(),
        ));
        $data['snapshot'] = $award->getSnapshot();
        return $data;
    }

    /**
     * Customer-facing award (GET /v3/me/cashback).
     *
     * @return array<string, mixed>
     */
    public function customerAwardShape(CashbackAward $award): array
    {
        $order = $award->getOrder();
        return [
            'id'              => $award->getId(),
            'status'          => $award->getStatus(),
            'amount'          => $award->getAmount(),
            'currency'        => $award->getCurrency(),
            'release_at'      => self::date($award->expectedReleaseAt()),
            'released_at'     => self::date($award->getReleasedAt()),
            'gift_card_id'    => $award->getGiftCard()?->getId(),
            'order_id'        => $order->getId(),
            'order_reference' => $order->getOrderReference(),
            'created_at'      => self::date($award->getCreatedAt()),
        ];
    }

    private static function date(?DateTimeInterface $d): ?string
    {
        return $d?->format(DateTimeInterface::ATOM);
    }
}
