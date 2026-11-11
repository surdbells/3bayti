<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

/**
 * The conversation BEFORE the current concierge query, oldest first, as sent by
 * a client (POST /v3/ai/concierge/style `history`) or replayed from WhatsApp
 * memory.
 *
 * History is UNTRUSTED user-supplied text: it is only ever handed to the model
 * as clearly-delimited context (never as instructions) and can never inject a
 * product — products still come only from the catalogue. Sanitising is lenient
 * by contract: malformed entries are dropped silently, only the LAST
 * {@see self::MAX_TURNS} entries are kept, and each text is capped at
 * {@see self::MAX_TEXT_CHARS} characters.
 */
final class ConversationHistory
{
    public const MAX_TURNS = 8;
    public const MAX_TEXT_CHARS = 500;

    public const ROLE_USER = 'user';
    public const ROLE_AIN = 'ain';

    /** @param list<array{role: 'user'|'ain', text: string}> $entries */
    private function __construct(private readonly array $entries)
    {
    }

    public static function empty(): self
    {
        return new self([]);
    }

    /**
     * Build from loosely-typed input (a decoded JSON body or a stored array).
     * Anything that is not a list of {role: 'user'|'ain', text: non-empty string}
     * entries is dropped silently.
     */
    public static function fromMixed(mixed $raw): self
    {
        if ($raw instanceof self) {
            return $raw;
        }
        if (!is_array($raw)) {
            return self::empty();
        }

        $out = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $role = $entry['role'] ?? null;
            $text = $entry['text'] ?? null;
            if (!is_string($role) || !is_string($text)) {
                continue;
            }
            $role = strtolower(trim($role));
            if ($role !== self::ROLE_USER && $role !== self::ROLE_AIN) {
                continue;
            }
            $text = self::cleanText($text);
            if ($text === '') {
                continue;
            }
            $out[] = ['role' => $role, 'text' => $text];
        }

        return new self(array_slice($out, -self::MAX_TURNS));
    }

    /** @return list<array{role: 'user'|'ain', text: string}> */
    public function entries(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    /** The most recent shopper message in the history, if any. */
    public function lastUserText(): ?string
    {
        $texts = $this->userTexts();
        return $texts === [] ? null : $texts[count($texts) - 1];
    }

    /**
     * The shopper's messages in the history, oldest first.
     *
     * @return list<string>
     */
    public function userTexts(): array
    {
        $out = [];
        foreach ($this->entries as $e) {
            if ($e['role'] === self::ROLE_USER) {
                $out[] = $e['text'];
            }
        }
        return $out;
    }

    /**
     * A new history with this turn appended (user message + Ain's reply),
     * trimmed back to the last {@see self::MAX_TURNS} entries.
     */
    public function withTurn(string $userText, string $ainReply): self
    {
        $entries = $this->entries;
        $entries[] = ['role' => self::ROLE_USER, 'text' => $userText];
        $entries[] = ['role' => self::ROLE_AIN, 'text' => $ainReply];
        return self::fromMixed($entries);
    }

    /**
     * The request text handed to the ranker: the earlier shopper messages give
     * the follow-up ("cheaper", "in black") its meaning.
     */
    public function rankingQuery(string $query): string
    {
        $previous = [];
        foreach ($this->entries as $e) {
            if ($e['role'] === self::ROLE_USER) {
                $previous[] = $e['text'];
            }
        }
        $previous = array_slice($previous, -2);
        if ($previous === []) {
            return $query;
        }
        return mb_substr('Earlier: ' . implode(' | ', $previous) . ' — Now: ' . $query, 0, 1200);
    }

    /**
     * The transcript block handed to the model, explicitly framed as untrusted
     * context. Empty when there is no history.
     */
    public function transcript(): string
    {
        $lines = [];
        foreach ($this->entries as $e) {
            $who = $e['role'] === self::ROLE_USER ? 'Shopper' : 'Ain';
            // Collapse newlines so a turn can never fake another speaker line,
            // and neutralise the <<< >>> block delimiters so an entry can never
            // close the untrusted block early and smuggle "instructions" out.
            $text = (string) preg_replace('/\s+/u', ' ', $e['text']);
            $text = str_replace(['<<<', '>>>', '<<', '>>'], ['‹‹‹', '›››', '‹‹', '››'], $text);
            $lines[] = $who . ': ' . $text;
        }
        return implode("\n", $lines);
    }

    private static function cleanText(string $text): string
    {
        // Strip control characters (keep normal whitespace), then trim + cap.
        $text = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        $text = trim($text);
        if (mb_strlen($text) > self::MAX_TEXT_CHARS) {
            $text = rtrim(mb_substr($text, 0, self::MAX_TEXT_CHARS));
        }
        return $text;
    }
}
