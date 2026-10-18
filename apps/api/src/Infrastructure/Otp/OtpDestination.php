<?php

declare(strict_types=1);

namespace Bayti\Api\Infrastructure\Otp;

/**
 * The destination COUNTRY of an OTP send, as a dial code ("973"), never the
 * number itself.
 *
 * Why: MessageCentral prices SMS per destination country. "Pricing not found"
 * in production (Sept 30 / Oct 7 2026, 7 events from 2 customers) was one
 * customer's country not being priced while every other send succeeded, so a
 * pricing alert (and its "working again" email) must be scoped to the
 * destination country, or one customer's retries make the alert flap.
 *
 * The router derives this from the phone it is about to send to and passes
 * ONLY the dial code to the health monitor; the monitor never sees a number.
 *
 * The list mirrors MessageCentralOtpProvider::KNOWN_DIAL_CODES (the dial codes
 * the app's country picker offers). A number with no recognised code yields
 * null ("unknown destination"), which the monitor treats like before: one
 * provider-wide pricing alert.
 */
final class OtpDestination
{
    /** Dial code => country name (for the alert email). */
    private const COUNTRIES = [
        '971' => 'UAE',
        '966' => 'Saudi Arabia',
        '974' => 'Qatar',
        '973' => 'Bahrain',
        '965' => 'Kuwait',
        '968' => 'Oman',
        '962' => 'Jordan',
        '961' => 'Lebanon',
        '20' => 'Egypt',
    ];

    /**
     * The dial code an E.164-ish number carries ("+97335175253" → "973",
     * "0097150…" → "971"), or null when it has none we recognise.
     */
    public static function dialCode(string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }
        foreach (array_keys(self::COUNTRIES) as $code) {
            $code = (string) $code;
            if (str_starts_with($digits, $code) && strlen($digits) - strlen($code) >= 6) {
                return $code;
            }
        }
        return null;
    }

    /** A dial code is safe to use in a store key / tag: 1-4 digits only. */
    public static function isValid(?string $dialCode): bool
    {
        return $dialCode !== null && preg_match('/^\d{1,4}$/', $dialCode) === 1;
    }

    /** "+973 (Bahrain)", or "+880" for a code we don't have a name for. */
    public static function label(string $dialCode): string
    {
        $name = self::COUNTRIES[$dialCode] ?? null;
        return '+' . $dialCode . ($name !== null ? " ({$name})" : '');
    }
}
