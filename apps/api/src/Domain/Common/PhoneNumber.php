<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Common;

/**
 * Phone-number canonicalisation utility.
 *
 * Why a separate class
 * --------------------
 * Several write paths take a raw, human-entered phone (gift-card recipient,
 * and any future capture point) and must persist a clean E.164 value. Clients
 * vary in how they build it — a common mistake is concatenating the country
 * code with a LOCAL number that still carries its national trunk zero, e.g.
 * "+971" + "0508816837" → "+9710508816837". After stripping the country code
 * that stray "0" would be sent to the SMS gateway as part of the subscriber
 * number ("0508816837" instead of "508816837") and the message is rejected /
 * misrouted. Canonicalising once, at the boundary, avoids that.
 *
 * Convention
 * ----------
 * Mirrors UserRepository::phoneMatchCandidates() (the auth-side lookup that
 * already reasons about E.164 vs. local-with-zero): reduce to digits, drop a
 * "00" international prefix, split off a leading GCC dial code when the
 * remaining national part is long enough to be real, strip the national trunk
 * zero, and emit "+{dial}{national}". Bare digits with no recognisable dial
 * code are treated as a local number on the platform default (+971).
 */
final class PhoneNumber
{
    /** GCC dial codes the platform serves, longest-first isn't needed (all 3). */
    private const GCC_DIAL_CODES = ['971', '966', '965', '974', '973', '968'];

    /**
     * Canonicalise a raw phone to E.164 ("+971508816837"), or null when the
     * input carries no usable digits.
     *
     *   "0508816837"        → "+971508816837"  (local, trunk zero dropped)
     *   "+9710508816837"    → "+971508816837"  (stray trunk zero after +971)
     *   "+971 50 881 6837"  → "+971508816837"  (formatting stripped)
     *   "00971508816837"    → "+971508816837"  (00 intl prefix)
     *   "508816837"         → "+971508816837"  (bare national → default dial)
     */
    public static function toE164(string $raw, string $defaultDial = '971'): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        $dial = null;
        $national = $digits;
        foreach (self::GCC_DIAL_CODES as $dc) {
            if (str_starts_with($digits, $dc) && strlen($digits) - strlen($dc) >= 6) {
                $dial = $dc;
                $national = substr($digits, strlen($dc));
                break;
            }
        }
        if ($dial === null) {
            $dial = $defaultDial;
        }

        // A national significant number never carries its trunk zero in E.164.
        $national = ltrim($national, '0');
        if ($national === '') {
            return null;
        }

        return '+' . $dial . $national;
    }
}
