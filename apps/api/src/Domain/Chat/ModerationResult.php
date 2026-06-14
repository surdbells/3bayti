<?php

declare(strict_types=1);

namespace Bayti\Api\Domain\Chat;

/**
 * Outcome of a moderation check: whether personal info was detected, which
 * categories, and the matched snippets (for redaction and for telling the
 * sender what tripped the filter).
 */
final class ModerationResult
{
    /**
     * @param list<string>                $flagTypes  e.g. ['phone','email']
     * @param array<string, list<string>> $matches    category => snippets
     */
    private function __construct(
        public readonly bool $isFlagged,
        public readonly array $flagTypes,
        public readonly array $matches,
    ) {
    }

    public static function clean(): self
    {
        return new self(false, [], []);
    }

    /** @param array<string, list<string>> $matchesByType */
    public static function flagged(array $matchesByType): self
    {
        return new self(true, array_keys($matchesByType), $matchesByType);
    }

    /** Comma-separated category list for storage, e.g. 'phone,email'. */
    public function flagTypeString(): ?string
    {
        return $this->flagTypes === [] ? null : implode(',', $this->flagTypes);
    }

    /** All matched snippets across every category, de-duplicated. */
    public function allMatches(): array
    {
        $all = [];
        foreach ($this->matches as $list) {
            foreach ($list as $m) {
                $all[$m] = true;
            }
        }
        // Replace longer snippets first so masking doesn't leave fragments.
        $keys = array_keys($all);
        usort($keys, static fn ($a, $b) => mb_strlen((string) $b) <=> mb_strlen((string) $a));
        return $keys;
    }

    /** Human-readable category labels for a warning message. */
    public function labels(): string
    {
        $map = [
            ModerationService::FLAG_PHONE   => 'phone number',
            ModerationService::FLAG_EMAIL   => 'email address',
            ModerationService::FLAG_SOCIAL  => 'social media handle',
            ModerationService::FLAG_ADDRESS => 'address',
        ];
        $labels = array_map(static fn ($t) => $map[$t] ?? $t, $this->flagTypes);
        return implode(', ', $labels);
    }
}
