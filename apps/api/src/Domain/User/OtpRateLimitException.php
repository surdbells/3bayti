<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\User;

/**
 * Raised by OtpService::send / OtpService::verify when an OTP rate
 * limit is exceeded (per-destination cooldown, per-destination hourly /
 * daily cap, per-IP hourly / daily cap, or per-verification verify
 * cap).
 *
 * Carries a retryAfter (seconds until the client may retry) so the
 * shared 429 contract can surface it:
 *
 *   HTTP 429
 *   { "error_code": "OTP_RATE_LIMITED", "retry_after": <int seconds> }
 *
 * ApiErrorMiddleware maps this exception directly to that envelope, so
 * EVERY OTP path (otp-login, register/initiate, register email-OTP,
 * send-otp resend, password reset, validate-phone) inherits the
 * behaviour without per-controller catches.
 *
 * We deliberately keep the public message generic, it does not reveal
 * which limit tripped or the exact counters, only the retry-after the
 * client needs to drive its lockout countdown.
 */
final class OtpRateLimitException extends \RuntimeException
{
    /**
     * Fallback retry-after (seconds) when a specific window can't be
     * computed. Mirrors the client's own ~60s fallback in the shared
     * contract.
     */
    public const DEFAULT_RETRY_AFTER = 60;

    /**
     * @param int $retryAfter Seconds until the caller may retry. Always
     *                        >= 1 so the client never busy-loops on a 0.
     */
    public function __construct(
        string $message,
        public readonly int $retryAfter = self::DEFAULT_RETRY_AFTER,
    ) {
        parent::__construct($message);
    }
}
