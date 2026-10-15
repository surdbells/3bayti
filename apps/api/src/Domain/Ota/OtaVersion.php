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
    /** The only accepted shape for a stored bundle / min-native version. */
    public const PATTERN = '/^(0|[1-9]\d{0,8})(\.(0|[1-9]\d{0,8})){0,3}\z/';

    /**
     * Shape of a device-reported NATIVE build (version_build) that the
     * min_native gate can trust: numeric, 1-4 bounded segments. Leading zeros
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
