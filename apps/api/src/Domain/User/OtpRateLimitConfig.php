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
        // Product decision: NO hard send caps for web or mobile — the only
        // gate on OTP sends is the 60s resend cooldown above (the client
        // counts down 60s, then allows a fresh send). The per-destination
        // and per-IP hour/day caps are disabled by default (0). An operator
        // can still re-enable any of them via the OTP_SENDS_* env vars if
        // abuse ever forces the issue.
        public readonly int $sendsPerHour = 0,
        public readonly int $sendsPerDay = 0,
        public readonly int $sendsPerIpHour = 0,
        public readonly int $sendsPerIpDay = 0,
        // Verify-guessing protection is NOT a send limit — it bounds how
        // many times a single code can be guessed before it burns. Kept on
        // (5) so codes can't be brute-forced.
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
            // Default 0 (disabled) — see the constructor note. An operator
            // can still cap sends by setting these env vars to a positive
            // value; unset/blank means "no hard limit".
            sendsPerHour: self::readInt($env, 'OTP_SENDS_PER_HOUR', 0),
            sendsPerDay: self::readInt($env, 'OTP_SENDS_PER_DAY', 0),
            sendsPerIpHour: self::readInt($env, 'OTP_SENDS_PER_IP_HOUR', 0),
            sendsPerIpDay: self::readInt($env, 'OTP_SENDS_PER_IP_DAY', 0),
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
