<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Ota;

/**
 * Numeric semver helpers for OTA bundle versions ("1.6.8", "1.6", "1.0.0.4").
 *
 * Shared by the update check (is the bundle newer than the device's?) and the
 * one-click rollback (which re-publishes an old bundle under the next version
 * so devices that already moved past it will download it again).
 */
final class OtaVersion
{
    /**
     * Negative if a<b, positive if a>b, 0 if equal. Pre-release suffixes are
     * ignored; missing/non-numeric segments count as 0.
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
