<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

/**
 * Who gets the SMS-provider alert emails, and how often.
 *
 *   OTP_ALERT_EMAILS            comma-separated; when it yields at least one
 *                               valid address it is used ...
 *   ADMIN_NOTIFICATION_EMAILS   ... otherwise the existing admin list is used
 *                               (parsed the same way as everywhere else:
 *                               comma-separated, trimmed, invalid entries dropped)
 *   OTP_ALERT_COOLDOWN_SECONDS  at most one alert email per provider + reason
 *                               per this many seconds. Default 3600, minimum 60
 *                               (a lower / invalid value is clamped / defaulted
 *                               so a typo can't turn an outage into email spam).
 *
 * Plain value object, built from $_ENV by the DI factory (compiled-container safe).
 */
final class OtpAlertConfig
{
    public const DEFAULT_COOLDOWN_SECONDS = 3600;
    public const MIN_COOLDOWN_SECONDS = 60;

    public const SOURCE_OTP_ALERT_EMAILS = 'OTP_ALERT_EMAILS';
    public const SOURCE_ADMIN_NOTIFICATION_EMAILS = 'ADMIN_NOTIFICATION_EMAILS';

    /**
     * @param list<string> $recipients
     * @param string       $recipientSource which env var the recipients came from
     */
    public function __construct(
        public readonly array $recipients,
        public readonly int $cooldownSeconds = self::DEFAULT_COOLDOWN_SECONDS,
        public readonly string $recipientSource = self::SOURCE_OTP_ALERT_EMAILS,
    ) {
    }

    /**
     * @param array<string, mixed> $env typically $_ENV
     */
    public static function fromEnv(array $env): self
    {
        $recipients = self::parseEmails($env['OTP_ALERT_EMAILS'] ?? '');
        $source = self::SOURCE_OTP_ALERT_EMAILS;
        if ($recipients === []) {
            $recipients = self::parseEmails($env['ADMIN_NOTIFICATION_EMAILS'] ?? '');
            $source = self::SOURCE_ADMIN_NOTIFICATION_EMAILS;
        }

        return new self(
            recipients: $recipients,
            cooldownSeconds: self::parseCooldown($env['OTP_ALERT_COOLDOWN_SECONDS'] ?? null),
            recipientSource: $source,
        );
    }

    /**
     * Same rule as the ADMIN_NOTIFICATION_EMAILS parsing in config/di.php:
     * comma-separated, trimmed, keep only valid addresses (deduplicated here).
     *
     * @return list<string>
     */
    public static function parseEmails(mixed $raw): array
    {
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $out = [];
        foreach (explode(',', $raw) as $email) {
            $email = trim($email);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false && !in_array($email, $out, true)) {
                $out[] = $email;
            }
        }
        return $out;
    }

    private static function parseCooldown(mixed $raw): int
    {
        if (is_int($raw)) {
            $value = $raw;
        } elseif (is_string($raw) && ctype_digit(trim($raw))) {
            $value = (int) trim($raw);
        } else {
            return self::DEFAULT_COOLDOWN_SECONDS;
        }

        return max(self::MIN_COOLDOWN_SECONDS, $value);
    }
}
