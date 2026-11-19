<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Controllers\Admin\Cashback;

use Bayti\Api\Domain\Cashback\CashbackRule;
use Bayti\Api\Domain\Cashback\CashbackRuleTarget;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\Product;
use Bayti\Api\Domain\Catalog\Vendor;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Applies an admin create/update body to a CashbackRule (partial: only keys
 * present are applied; null clears a nullable field). Collects field
 * errors instead of throwing so the controller returns one 422 with all of
 * them. Targets are v3 ids and must exist.
 *
 * Body keys: name, description, is_active, priority, reward_type,
 * reward_value, max_reward_per_order, min_eligible_amount, valid_from,
 * valid_until, scope, channel, first_order_only, per_customer_limit,
 * budget_total, allow_with_promo, hold_days, gift_card_validity_days,
 * targets[{target_type, target_id, is_exclusion}].
 */
final class CashbackRuleInput
{
    private const TARGET_CLASSES = [
        CashbackRuleTarget::TYPE_VENDOR   => Vendor::class,
        CashbackRuleTarget::TYPE_PRODUCT  => Product::class,
        CashbackRuleTarget::TYPE_CATEGORY => Category::class,
    ];

    private const SCOPE_TARGET_TYPE = [
        CashbackRule::SCOPE_STORES     => CashbackRuleTarget::TYPE_VENDOR,
        CashbackRule::SCOPE_PRODUCTS   => CashbackRuleTarget::TYPE_PRODUCT,
        CashbackRule::SCOPE_CATEGORIES => CashbackRuleTarget::TYPE_CATEGORY,
    ];

    /**
     * Validate the fields a NEW rule needs before it can be constructed.
     *
     * @param array<string, mixed> $body
     * @return array{0: ?CashbackRule, 1: array<string, list<string>>}
     */
    public static function create(array $body): array
    {
        $errors = [];
        $name = $body['name'] ?? null;
        if (!is_string($name) || trim($name) === '') {
            $errors['name'][] = 'name is required.';
        }
        $type = $body['reward_type'] ?? null;
        if (!is_string($type)) {
            $errors['reward_type'][] = 'reward_type is required (percentage or fixed).';
        }
        $value = self::money($body['reward_value'] ?? null);
        if ($value === null) {
            $errors['reward_value'][] = 'reward_value is required (a positive amount, max 2 decimals).';
        }
        if ($errors !== [] || !is_string($name) || !is_string($type) || $value === null) {
            return [null, $errors];
        }
        try {
            return [new CashbackRule($name, $type, $value), []];
        } catch (\InvalidArgumentException $e) {
            return [null, ['_root' => [$e->getMessage()]]];
        }
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, list<string>> field => messages (empty = OK)
     */
    public static function apply(CashbackRule $rule, array $body, EntityManagerInterface $em): array
    {
        $errors = [];
        $try = static function (string $field, callable $fn) use (&$errors): void {
            try {
                $fn();
            } catch (\InvalidArgumentException $e) {
                $errors[$field][] = $e->getMessage();
            }
        };

        if (array_key_exists('name', $body)) {
            $v = $body['name'];
            is_string($v) ? $try('name', static fn () => $rule->setName($v)) : $errors['name'][] = 'name must be a string.';
        }
        if (array_key_exists('description', $body)) {
            $v = $body['description'];
            if ($v === null || is_string($v)) {
                $rule->setDescription($v);
            } else {
                $errors['description'][] = 'description must be a string or null.';
            }
        }
        foreach (['is_active' => 'setActive', 'first_order_only' => 'setFirstOrderOnly', 'allow_with_promo' => 'setAllowWithPromo'] as $field => $setter) {
            if (array_key_exists($field, $body)) {
                if (is_bool($body[$field])) {
                    $rule->{$setter}($body[$field]);
                } else {
                    $errors[$field][] = "{$field} must be a boolean.";
                }
            }
        }
        if (array_key_exists('priority', $body)) {
            $v = self::int($body['priority']);
            $v === null ? $errors['priority'][] = 'priority must be an integer.' : $try('priority', static fn () => $rule->setPriority($v));
        }
        if (array_key_exists('reward_type', $body) || array_key_exists('reward_value', $body)) {
            $type = array_key_exists('reward_type', $body) ? $body['reward_type'] : $rule->getRewardType();
            $value = array_key_exists('reward_value', $body) ? self::money($body['reward_value']) : $rule->getRewardValue();
            if (!is_string($type)) {
                $errors['reward_type'][] = 'reward_type must be percentage or fixed.';
            } elseif ($value === null) {
                $errors['reward_value'][] = 'reward_value must be a positive amount with up to 2 decimals.';
            } else {
                $try('reward_value', static fn () => $rule->setReward($type, $value));
            }
        }
        foreach (['max_reward_per_order' => 'setMaxRewardPerOrder', 'min_eligible_amount' => 'setMinEligibleAmount', 'budget_total' => 'setBudgetTotal'] as $field => $setter) {
            if (array_key_exists($field, $body)) {
                if ($body[$field] === null || $body[$field] === '') {
                    $try($field, static fn () => $rule->{$setter}(null));
                    continue;
                }
                $v = self::money($body[$field], $field === 'budget_total' ? 10 : 8);
                $v === null
                    ? $errors[$field][] = "{$field} must be an amount with up to 2 decimals, or null."
                    : $try($field, static fn () => $rule->{$setter}($v));
            }
        }
        if (array_key_exists('valid_from', $body) || array_key_exists('valid_until', $body)) {
            $dates = ['valid_from' => $rule->getValidFrom(), 'valid_until' => $rule->getValidUntil()];
            $ok = true;
            foreach (array_keys($dates) as $field) {
                if (!array_key_exists($field, $body)) {
                    continue;
                }
                $raw = $body[$field];
                if ($raw === null || $raw === '') {
                    $dates[$field] = null;
                    continue;
                }
                $d = is_string($raw) ? self::date($raw) : null;
                if ($d === null) {
                    $errors[$field][] = "{$field} must be an ISO-8601 datetime or null.";
                    $ok = false;
                } else {
                    $dates[$field] = $d;
                }
            }
            $from = $dates['valid_from'];
            $until = $dates['valid_until'];
            if ($ok) {
                $try('valid_until', static fn () => $rule->setValidity($from, $until));
            }
        }
        foreach (['scope' => 'setScope', 'channel' => 'setChannel'] as $field => $setter) {
            if (array_key_exists($field, $body)) {
                $v = $body[$field];
                is_string($v) ? $try($field, static fn () => $rule->{$setter}($v)) : $errors[$field][] = "{$field} must be a string.";
            }
        }
        if (array_key_exists('per_customer_limit', $body)) {
            $raw = $body['per_customer_limit'];
            $v = ($raw === null || $raw === '') ? null : self::int($raw);
            if ($raw !== null && $raw !== '' && $v === null) {
                $errors['per_customer_limit'][] = 'per_customer_limit must be a positive integer or null.';
            } else {
                $try('per_customer_limit', static fn () => $rule->setPerCustomerLimit($v));
            }
        }
        foreach (['hold_days' => 'setHoldDays', 'gift_card_validity_days' => 'setGiftCardValidityDays'] as $field => $setter) {
            if (array_key_exists($field, $body)) {
                $v = self::int($body[$field]);
                $v === null ? $errors[$field][] = "{$field} must be an integer." : $try($field, static fn () => $rule->{$setter}($v));
            }
        }
        if (array_key_exists('targets', $body)) {
            $targets = self::parseTargets($body['targets'], $em, $errors);
            if ($targets !== null) {
                $rule->replaceTargets($targets);
            }
        }

        if ($errors === []) {
            foreach (self::scopeErrors($rule) as $msg) {
                $errors['targets'][] = $msg;
            }
        }
        return $errors;
    }

    /**
     * @param array<string, list<string>> $errors
     * @return list<array{target_type: string, target_id: int, is_exclusion: bool}>|null
     */
    private static function parseTargets(mixed $raw, EntityManagerInterface $em, array &$errors): ?array
    {
        if (!is_array($raw)) {
            $errors['targets'][] = 'targets must be an array.';
            return null;
        }
        $out = [];
        foreach (array_values($raw) as $i => $t) {
            if (!is_array($t)) {
                $errors["targets.{$i}"][] = 'Each target must be an object.';
                continue;
            }
            $type = $t['target_type'] ?? null;
            $id = self::int($t['target_id'] ?? null);
            $excl = $t['is_exclusion'] ?? false;
            if (!is_string($type) || !isset(self::TARGET_CLASSES[$type])) {
                $errors["targets.{$i}.target_type"][] = 'target_type must be vendor, product or category.';
                continue;
            }
            if ($id === null || $id < 1) {
                $errors["targets.{$i}.target_id"][] = 'target_id must be a positive v3 id.';
                continue;
            }
            if (!is_bool($excl)) {
                $errors["targets.{$i}.is_exclusion"][] = 'is_exclusion must be a boolean.';
                continue;
            }
            if ($em->find(self::TARGET_CLASSES[$type], $id) === null) {
                $errors["targets.{$i}.target_id"][] = "No {$type} with id {$id}.";
                continue;
            }
            $out[] = ['target_type' => $type, 'target_id' => $id, 'is_exclusion' => $excl];
        }
        return isset($errors['targets']) ? null : $out;
    }

    /**
     * Scope ↔ targets consistency: scope=all takes exclusions only; a
     * scoped rule needs at least one inclusion of its own type and no
     * inclusions of another type.
     *
     * @return list<string>
     */
    private static function scopeErrors(CashbackRule $rule): array
    {
        $scope = $rule->getScope();
        $includes = [];
        foreach ($rule->getTargets() as $t) {
            if (!$t->isExclusion()) {
                $includes[$t->getTargetType()] = true;
            }
        }
        if ($scope === CashbackRule::SCOPE_ALL) {
            return $includes === [] ? [] : ['A rule with scope "all" can only have exclusion targets.'];
        }
        $type = self::SCOPE_TARGET_TYPE[$scope] ?? null;
        $msgs = [];
        if ($type !== null && !isset($includes[$type])) {
            $msgs[] = "A rule with scope \"{$scope}\" needs at least one {$type} target.";
        }
        foreach (array_keys($includes) as $t) {
            if ($t !== $type) {
                $msgs[] = "A rule with scope \"{$scope}\" cannot include {$t} targets (use is_exclusion=true to exclude).";
            }
        }
        return $msgs;
    }

    /**
     * $digits = integer digits allowed: 8 for the NUMERIC(10,2) columns
     * (reward_value, max_reward_per_order, min_eligible_amount), 10 for
     * budget_total (NUMERIC(12,2)). Anything wider is a 422, not a DB overflow.
     */
    private static function money(mixed $v, int $digits = 8): ?string
    {
        if (is_int($v) || is_float($v)) {
            $v = number_format((float) $v, 2, '.', '');
        }
        if (!is_string($v) || !preg_match('/^\d{1,' . $digits . '}(\.\d{1,2})?$/', trim($v))) {
            return null;
        }
        return bcadd(trim($v), '0', 2);
    }

    private static function int(mixed $v): ?int
    {
        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && preg_match('/^-?\d{1,9}$/', trim($v))) {
            return (int) trim($v);
        }
        return null;
    }

    private static function date(string $raw): ?DateTimeImmutable
    {
        try {
            return (new DateTimeImmutable($raw, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
        } catch (\Exception) {
            return null;
        }
    }
}
