<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Ota;

/**
 * Numeric version helpers for OTA bundle versions ("1.6.8", "1.6", "1.0.0.4").
 *
 * Shared by the update check (is the bundle newer than the device's?), the
 * one-click rollback (which re-publishes an old bundle under the next version
 * so devices that already moved past it will download it again) and every
 * write path (upload controller, `ota:publish`, the release script and the
 * portal form all accept exactly {@see PATTERN}).
 *
 * Versions are NUMERIC ONLY, 1 to 4 dot-separated segments. Pre-release
 * suffixes ("1.7.0-beta.1") are rejected on write rather than half-supported:
 * semver orders "1.7.0-beta" BELOW "1.7.0", and silently ignoring the suffix
 * (as an earlier version of this class did) made "1.7.0-beta" and "1.7.0"
 * compare equal, so the final release would never reach a device on the beta.
 * Use a channel (e.g. "beta") to stage pre-releases instead.
 *
 * Stored versions are also CANONICAL: no leading zeros ("1.08" would compare
 * equal to "1.8" yet pass a string-based duplicate check), at most 9 digits
 * per segment (no integer overflow in compare(), and well inside the 64-char
 * column), and `\z` so a trailing newline can't sneak through.
 */
final class OtaVersion
{
    /** The only accepted shape for a stored bundle / min-native / max-native version. */
    public const PATTERN = '/^(0|[1-9]\d{0,8})(\.(0|[1-9]\d{0,8})){0,3}\z/';

    /**
     * Shape of a device-reported NATIVE version (version_build) that the
     * native-range gate can trust: numeric, 1-4 bounded segments. Leading zeros
     * are tolerated here (we don't control how a store build was labelled and
     * compare() reads "08" as 8); anything else fails the gate closed.
     */
    private const COMPARABLE = '/^\d{1,9}(\.\d{1,9}){0,3}\z/';

    public static function isValid(string $version): bool
    {
        return (bool) preg_match(self::PATTERN, $version);
    }

    /** Whether a device-reported version can be compared reliably (see COMPARABLE). */
    public static function isComparable(string $version): bool
    {
        return (bool) preg_match(self::COMPARABLE, $version);
    }

    /**
     * Why [$min, $max] is not an acceptable native range for a NEW bundle, or
     * null when it is. Shared by every server-side write path (the upload
     * controller and `ota:publish`), so they refuse exactly the same input.
     *
     * $max is the already-defaulted upper bound (max_native, or $min when the
     * caller sent none). $minLabel / $maxLabel name the inputs in the message
     * ("min_native" for the API, "--min-native" for the CLI).
     *
     *  - min is REQUIRED: a bundle serves min <= device <= max, so a silent
     *    "0.0.0" default (the old behaviour) would now serve nobody;
     *  - both ends must be canonical versions ({@see PATTERN}) and max >= min;
     *  - a range ending at 0 ("0.0.0", e.g. a stale portal/script that still
     *    sends its old 0.0.0 default) matches no installed app, since devices
     *    report their real native version (e.g. "1.8"), so it is refused too.
     */
    public static function nativeRangeError(
        string $min,
        string $max,
        string $minLabel = 'min_native',
        string $maxLabel = 'max_native',
    ): ?string {
        if ($min === '') {
            return "{$minLabel} is required: the native app version this bundle was built for (e.g. 1.8). "
                . "Devices on any other native version are not offered the bundle unless {$maxLabel} widens the range.";
        }
        if (!self::isValid($min)) {
            return "{$minLabel} must be numeric with 1-4 segments (e.g. 1.8).";
        }
        if (!self::isValid($max)) {
            return "{$maxLabel} must be numeric with 1-4 segments (e.g. 1.9).";
        }
        if (self::compare($max, $min) < 0) {
            return "{$maxLabel} ({$max}) must not be lower than {$minLabel} ({$min}).";
        }
        if (self::isZero($max)) {
            return "A native range ending at {$max} matches no installed app (devices report their native app version, "
                . "e.g. 1.8), so the bundle would never be served. Set {$minLabel} to the native app version this bundle "
                . "was built for.";
        }

        return null;
    }

    /**
     * Whether $version is zero in any padding ("0", "0.0.0"). A native range
     * whose upper end is zero matches no installed app, since every device
     * reports its real native version (e.g. "1.8"). The single definition of
     * that rule: new bundles ({@see nativeRangeError()}), rollbacks and
     * re-activation ({@see OtaBundle::reachesNoShell()}) all refuse it.
     */
    public static function isZero(string $version): bool
    {
        return self::compare($version, '0') === 0;
    }

    /** A native range for messages: "1.8" when min == max, else "1.8 - 1.9". */
    public static function rangeLabel(string $min, string $max): string
    {
        return self::compare($min, $max) === 0 ? $min : "{$min} - {$max}";
    }

    /**
     * Negative if a<b, positive if a>b, 0 if equal.
     *
     * Stored versions always satisfy {@see isValid()}. Device-reported values
     * (version_name / version_build) are parsed leniently: anything after a
     * "-" is dropped and missing/non-numeric segments count as 0, so a device
     * reporting e.g. "builtin" is treated as older than any published bundle.
     * Callers that must not trust a malformed value (the native-build gate)
     * check {@see isComparable()} first.
     */
    public static function compare(string $a, string $b): int
    {
        $pa = self::segments($a);
        $pb = self::segments($b);
        $len = max(count($pa), count($pb));
        for ($i = 0; $i < $len; $i++) {
            $va = $pa[$i] ?? 0;
            $vb = $pb[$i] ?? 0;
            if ($va !== $vb) {
                return $va <=> $vb;
            }
        }

        return 0;
    }

    /**
     * The highest version in the list, or null for an empty list.
     *
     * @param list<string> $versions
     */
    public static function max(array $versions): ?string
    {
        $max = null;
        foreach ($versions as $v) {
            if ($max === null || self::compare($v, $max) > 0) {
                $max = $v;
            }
        }

        return $max;
    }

    /**
     * The next version after $version: pads to at least major.minor.patch and
     * increments the last segment ("1.6.8" → "1.6.9", "1.6" → "1.6.1",
     * "1.0.0.4" → "1.0.0.5").
     */
    public static function next(string $version): string
    {
        $parts = self::segments($version);
        while (count($parts) < 3) {
            $parts[] = 0;
        }
        $parts[count($parts) - 1]++;

        return implode('.', $parts);
    }

    /**
     * @return list<int>
     */
    private static function segments(string $version): array
    {
        return array_map('intval', explode('.', explode('-', trim($version))[0]));
    }
}
