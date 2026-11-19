<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Cashback;

/**
 * Programme-wide cashback configuration, stored under SettingsService key
 * 'cashback.program' as e.g.
 *   { "enabled": true, "min_award": "1.00", "max_award_per_order": "500.00",
 *     "default_hold_days": 14, "default_validity_days": 365 }
 *
 * Follows NotificationSettings / OtpProviderSettings: fromArray() is
 * defensive — a missing/garbled value reads as the SAFE default (programme
 * OFF), so nothing is accrued until an admin switches it on.
 */
final class CashbackProgramSettings
{
    public const KEY = 'cashback.program';

    public const DEFAULT_MIN_AWARD = '1.00';

    private function __construct(
        public readonly bool $enabled,
        public readonly string $minAward,
        public readonly ?string $maxAwardPerOrder,
        public readonly int $defaultHoldDays,
        public readonly int $defaultValidityDays,
    ) {
    }

    public static function default(): self
    {
        return new self(
            enabled: false,
            minAward: self::DEFAULT_MIN_AWARD,
            maxAwardPerOrder: null,
            defaultHoldDays: CashbackRule::DEFAULT_HOLD_DAYS,
            defaultValidityDays: CashbackRule::DEFAULT_VALIDITY_DAYS,
        );
    }

    /** @param array<string, mixed>|null $raw */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return self::default();
        }
        $d = self::default();

        $min = self::money($raw['min_award'] ?? null) ?? $d->minAward;
        $max = self::money($raw['max_award_per_order'] ?? null);
        if ($max !== null && bccomp($max, '0.00', 2) <= 0) {
            $max = null;
        }

        return new self(
            enabled: ($raw['enabled'] ?? false) === true,
            minAward: $min,
            maxAwardPerOrder: $max,
            defaultHoldDays: self::intIn($raw['default_hold_days'] ?? null, 0, CashbackRule::MAX_HOLD_DAYS) ?? $d->defaultHoldDays,
            defaultValidityDays: self::intIn($raw['default_validity_days'] ?? null, 1, CashbackRule::MAX_VALIDITY_DAYS) ?? $d->defaultValidityDays,
        );
    }

    /**
     * Strictly validate an admin PUT body. Returns the new settings or a map
     * of field => messages.
     *
     * @param array<string, mixed> $body
     * @return array{0: ?self, 1: array<string, list<string>>}
     */
    public static function fromAdminInput(array $body, self $current): array
    {
        $errors = [];
        $enabled = $current->enabled;
        if (array_key_exists('enabled', $body)) {
            if (!is_bool($body['enabled'])) {
                $errors['enabled'][] = 'Must be a boolean.';
            } else {
                $enabled = $body['enabled'];
            }
        }

        $min = $current->minAward;
        if (array_key_exists('min_award', $body)) {
            $m = self::money($body['min_award']);
            if ($m === null) {
                $errors['min_award'][] = 'Must be a non-negative amount with up to 2 decimals.';
            } else {
                $min = $m;
            }
        }

        $max = $current->maxAwardPerOrder;
        if (array_key_exists('max_award_per_order', $body)) {
            if ($body['max_award_per_order'] === null || $body['max_award_per_order'] === '') {
                $max = null;
            } else {
                $m = self::money($body['max_award_per_order']);
                if ($m === null || bccomp($m, '0.00', 2) <= 0) {
                    $errors['max_award_per_order'][] = 'Must be a positive amount, or null for no cap.';
                } else {
                    $max = $m;
                }
            }
        }

        $hold = $current->defaultHoldDays;
        if (array_key_exists('default_hold_days', $body)) {
            $h = self::intIn($body['default_hold_days'], 0, CashbackRule::MAX_HOLD_DAYS);
            if ($h === null) {
                $errors['default_hold_days'][] = 'Must be an integer between 0 and ' . CashbackRule::MAX_HOLD_DAYS . '.';
            } else {
                $hold = $h;
            }
        }

        $validity = $current->defaultValidityDays;
        if (array_key_exists('default_validity_days', $body)) {
            $v = self::intIn($body['default_validity_days'], 1, CashbackRule::MAX_VALIDITY_DAYS);
            if ($v === null) {
                $errors['default_validity_days'][] = 'Must be an integer between 1 and ' . CashbackRule::MAX_VALIDITY_DAYS . '.';
            } else {
                $validity = $v;
            }
        }

        if ($max !== null && bccomp($max, $min, 2) < 0) {
            $errors['max_award_per_order'][] = 'Must be greater than or equal to min_award.';
        }

        if ($errors !== []) {
            return [null, $errors];
        }
        return [new self($enabled, $min, $max, $hold, $validity), []];
    }

    /**
     * @return array{enabled: bool, min_award: string, max_award_per_order: string|null, default_hold_days: int, default_validity_days: int}
     */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'min_award' => $this->minAward,
            'max_award_per_order' => $this->maxAwardPerOrder,
            'default_hold_days' => $this->defaultHoldDays,
            'default_validity_days' => $this->defaultValidityDays,
        ];
    }

    private static function money(mixed $v): ?string
    {
        if (is_int($v) || is_float($v)) {
            $v = number_format((float) $v, 2, '.', '');
        }
        if (!is_string($v) || !preg_match('/^\d{1,8}(\.\d{1,2})?$/', trim($v))) {
            return null;
        }
        return bcadd(trim($v), '0', 2);
    }

    private static function intIn(mixed $v, int $min, int $max): ?int
    {
        if (is_string($v) && ctype_digit($v)) {
            $v = (int) $v;
        }
        if (!is_int($v) || $v < $min || $v > $max) {
            return null;
        }
        return $v;
    }
}
