<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

/**
 * Tunable thresholds for OTP abuse hardening, sourced from env.
 *
 * Every knob is an integer; 0 DISABLES that particular check (so an
 * operator can selectively relax a limit without touching code). The
 * static fromEnv() reader applies the documented defaults when a var
 * is unset or non-numeric.
 *
 * Compiled-container safety
 * -------------------------
 * This is a plain value object with a SCALAR-ONLY constructor and no
 * object/enum defaults — PHP-DI's compiled container can build it from
 * a factory closure that returns `OtpRateLimitConfig::fromEnv()` with
 * no `use()` capture. See config/di.php.
 *
 * What each threshold means
 * -------------------------
 *   - resendCooldownSeconds: minimum gap between two *dispatches* to the
 *     same destination+purpose. Within this window we RE-USE the latest
 *     usable code (true resend-dedup) instead of sending again.
 *   - sendsPerHour / sendsPerDay: rolling per-destination send caps.
 *   - sendsPerIpHour / sendsPerIpDay: rolling per-IP send caps. Skipped
 *     entirely when the client IP can't be resolved.
 *   - maxVerifyAttempts: failed verify guesses allowed per code before
 *     the row is burned (mirrors the email path for SMS, and adds a
 *     per-verification-id counter so one code can't be sprayed).
 */
final class OtpRateLimitConfig
{
    public function __construct(
        public readonly int $resendCooldownSeconds = 60,
        public readonly int $sendsPerHour = 5,
        public readonly int $sendsPerDay = 10,
        public readonly int $sendsPerIpHour = 20,
        public readonly int $sendsPerIpDay = 40,
        public readonly int $maxVerifyAttempts = 5,
    ) {
    }

    /**
     * Build from the process environment, applying defaults. A var that
     * is unset, empty, or non-numeric falls back to the default; a
     * value of 0 is honoured and disables that check.
     *
     * @param array<string, mixed>|null $env Defaults to $_ENV; injectable for tests.
     */
    public static function fromEnv(?array $env = null): self
    {
        $env ??= $_ENV;

        return new self(
            resendCooldownSeconds: self::readInt($env, 'OTP_RESEND_COOLDOWN_SECONDS', 60),
            sendsPerHour: self::readInt($env, 'OTP_SENDS_PER_HOUR', 5),
            sendsPerDay: self::readInt($env, 'OTP_SENDS_PER_DAY', 10),
            sendsPerIpHour: self::readInt($env, 'OTP_SENDS_PER_IP_HOUR', 20),
            sendsPerIpDay: self::readInt($env, 'OTP_SENDS_PER_IP_DAY', 40),
            maxVerifyAttempts: self::readInt($env, 'OTP_MAX_VERIFY_ATTEMPTS', 5),
        );
    }

    /**
     * Read a non-negative integer env var; fall back to $default when
     * unset / empty / non-numeric. Negative values are clamped to 0
     * (treated as "disabled") rather than producing nonsense windows.
     *
     * @param array<string, mixed> $env
     */
    private static function readInt(array $env, string $key, int $default): int
    {
        $raw = $env[$key] ?? null;
        if ($raw === null || $raw === '' || !is_numeric($raw)) {
            return $default;
        }
        $value = (int) $raw;
        return $value < 0 ? 0 : $value;
    }
}
