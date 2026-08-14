<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Notification;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Resolves {{variable}} placeholders in a notification title/body.
 *
 * Two kinds of variable:
 *   - per-recipient (first_name, last_name, full_name) — resolved against the
 *     actual recipient user at send time.
 *   - shared/time (date, time) — computed once per broadcast, timezone-aware.
 *
 * The catalog is the single source of truth: the compose UI reads it to show
 * insertable chips, validation checks against it, and resolution reads it. Add
 * a variable here (+ wire its value in valuesFor / sharedTimeValues) and the
 * whole system extends — nothing is hardcoded elsewhere.
 */
final class TemplateVariableResolver
{
    private const PLACEHOLDER_RE = '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*)\s*\}\}/';

    /**
     * Supported variables (key + human label + sample for the preview).
     *
     * @return list<array{key:string, label:string, sample:string}>
     */
    public static function catalog(): array
    {
        return [
            ['key' => 'first_name', 'label' => 'First name', 'sample' => 'Sarah'],
            ['key' => 'last_name', 'label' => 'Last name', 'sample' => 'Al Mansoori'],
            ['key' => 'full_name', 'label' => 'Full name', 'sample' => 'Sarah Al Mansoori'],
            ['key' => 'date', 'label' => 'Today’s date', 'sample' => '14 Aug 2026'],
            ['key' => 'time', 'label' => 'Current time', 'sample' => '4:30 PM'],
        ];
    }

    /** @return list<string> */
    public static function knownKeys(): array
    {
        return array_map(static fn (array $v): string => $v['key'], self::catalog());
    }

    public function hasVariables(string $text): bool
    {
        return str_contains($text, '{{');
    }

    /**
     * Substitute {{var}} with values. Unknown/unsupplied variables resolve to
     * an empty string (never leave a raw {{x}} in a delivered message).
     *
     * @param array<string, string> $values
     */
    public function render(string $text, array $values): string
    {
        return (string) preg_replace_callback(
            self::PLACEHOLDER_RE,
            static fn (array $m): string => $values[strtolower($m[1])] ?? '',
            $text,
        );
    }

    /**
     * Per-recipient value map from the recipient's user fields + the shared
     * time values.
     *
     * @param array{first_name?: ?string, last_name?: ?string, email?: ?string} $user
     * @param array{date: string, time: string} $shared
     * @return array<string, string>
     */
    public function valuesFor(array $user, array $shared): array
    {
        $first = trim((string) ($user['first_name'] ?? ''));
        $last = trim((string) ($user['last_name'] ?? ''));
        $full = trim($first . ' ' . $last);
        if ($full === '') {
            $email = (string) ($user['email'] ?? '');
            $full = $email !== '' ? explode('@', $email)[0] : 'there';
        }
        if ($first === '') {
            $first = $full;
        }

        return [
            'first_name' => $first,
            'last_name' => $last,
            'full_name' => $full,
            'date' => $shared['date'],
            'time' => $shared['time'],
        ];
    }

    /**
     * Broadcast-level time values (same for every recipient of one send).
     *
     * @return array{date: string, time: string}
     */
    public function sharedTimeValues(?DateTimeImmutable $now = null, string $tz = 'Asia/Dubai'): array
    {
        $now = ($now ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone($tz));
        return [
            'date' => $now->format('j M Y'),
            'time' => $now->format('g:i A'),
        ];
    }

    /**
     * Variable names used in the given text(s) that AREN'T in the catalog —
     * so the composer can flag "unknown variable" before saving/sending.
     *
     * @return list<string>
     */
    public function unknownVariables(string ...$texts): array
    {
        $known = self::knownKeys();
        $unknown = [];
        foreach ($texts as $text) {
            if (preg_match_all(self::PLACEHOLDER_RE, $text, $m)) {
                foreach ($m[1] as $var) {
                    $v = strtolower($var);
                    if (!in_array($v, $known, true) && !in_array($v, $unknown, true)) {
                        $unknown[] = $v;
                    }
                }
            }
        }
        return $unknown;
    }
}
