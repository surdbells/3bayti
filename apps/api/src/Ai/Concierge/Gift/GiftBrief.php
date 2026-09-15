<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge\Gift;

use Bayti\Api\Ai\Concierge\ConciergeIntent;

/**
 * The structured inputs of the Ain Gift Concierge's guided flow. Because the
 * form already delivers clean fields, we build a {@see ConciergeIntent} directly
 * — NO LLM parse — and feed it to the exact same retrieval + ranking pipeline as
 * the style concierge, so every surfaced product is a real, in-stock catalogue
 * item (the anti-hallucination guard is inherited, not re-implemented).
 *
 * `recipient` is intentionally kept OUT of the retrieval keywords (recipient
 * nouns like "sister"/"mum" never appear in product text and only dilute the
 * substring search); it rides only in {@see toRankerQuery()} as LLM context and
 * the recorded query. `size` is not a retrieval filter — it drives only the
 * gift-card fallback decision.
 */
final class GiftBrief
{
    /** Product-type tokens that mean "sized apparel", so a missing size is a real gifting risk. */
    private const APPAREL_TOKENS = ['abaya', 'kaftan', 'kaftans', 'caftan', 'mukhawar', 'kandura', 'thobe', 'thob', 'dress', 'gown', 'jalabiya', 'jalabiyah', 'clothing', 'wear', 'set', 'suit'];

    /**
     * @param list<string> $colours
     * @param list<string> $styles
     */
    public function __construct(
        public readonly ?string $recipient,
        public readonly ?string $occasion,
        public readonly array $colours,
        public readonly array $styles,
        public readonly ?string $size,
        public readonly ?float $budgetMin,
        public readonly ?float $budgetMax,
        public readonly ?string $productType,
        public readonly ?string $categorySlug,
    ) {
    }

    /**
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        return new self(
            recipient: self::nullableString($a['recipient'] ?? null),
            occasion: self::nullableString($a['occasion'] ?? null),
            colours: self::stringList($a['colours'] ?? $a['colors'] ?? []),
            styles: self::stringList($a['styles'] ?? []),
            size: self::nullableString($a['size'] ?? null),
            budgetMin: self::nullableFloat($a['budget_min'] ?? null),
            budgetMax: self::nullableFloat($a['budget_max'] ?? null),
            productType: self::nullableString($a['product_type'] ?? null),
            categorySlug: self::nullableString($a['category_slug'] ?? null),
        );
    }

    /**
     * Build the structured intent that drives retrieval. Keywords carry only
     * terms that plausibly appear in product text (occasion + styles + product
     * type) — never the recipient.
     */
    public function toIntent(): ConciergeIntent
    {
        $keywords = [];
        if ($this->occasion !== null) {
            $keywords[] = $this->occasion;
        }
        foreach ($this->styles as $style) {
            $keywords[] = $style;
        }
        if ($this->productType !== null) {
            $keywords[] = $this->productType;
        }
        /** @var list<string> $keywords */
        $keywords = array_values(array_unique($keywords));

        return new ConciergeIntent(
            productType: $this->productType,
            categorySlug: $this->categorySlug,
            occasions: $this->occasion !== null ? [$this->occasion] : [],
            colours: $this->colours,
            styles: $this->styles,
            budgetMin: $this->budgetMin,
            budgetMax: $this->budgetMax,
            keywords: $keywords,
            vendorHints: [],
            isGift: true,
            confidence: $this->confidence(),
        );
    }

    /**
     * A natural-language brief for the ranker LLM (context only) and the recorded
     * query_text. This is the ONE place the recipient is used.
     */
    public function toRankerQuery(): string
    {
        $parts = [];
        $lead = 'A gift';
        if ($this->occasion !== null) {
            $lead = 'A ' . $this->occasion . ' gift';
        }
        if ($this->recipient !== null) {
            $lead .= ' for my ' . $this->recipient;
        }
        $parts[] = $lead;

        if ($this->styles !== []) {
            $parts[] = implode(', ', $this->styles) . ' style';
        }
        if ($this->colours !== []) {
            $parts[] = 'in ' . implode(', ', $this->colours);
        }
        if ($this->productType !== null) {
            $parts[] = 'ideally ' . $this->productType;
        }
        if ($this->budgetMax !== null && $this->budgetMax > 0) {
            $parts[] = 'under ' . rtrim(rtrim(number_format($this->budgetMax, 2, '.', ''), '0'), '.') . ' AED';
        }

        return implode(', ', $parts) . '.';
    }

    /** True when the brief carries at least one retrieval signal (recipient alone is not enough). */
    public function hasEnoughSignal(): bool
    {
        return $this->occasion !== null
            || $this->productType !== null
            || $this->colours !== []
            || $this->styles !== []
            || ($this->budgetMax !== null && $this->budgetMax > 0);
    }

    /** Whether this gift targets sized apparel, so a missing size warrants the gift-card fallback. */
    public function isApparel(): bool
    {
        if ($this->productType === null) {
            return false;
        }
        $t = strtolower($this->productType);
        foreach (self::APPAREL_TOKENS as $token) {
            if (str_contains($t, $token)) {
                return true;
            }
        }
        return false;
    }

    /** Synthetic confidence (no LLM parse): how completely the brief was filled in. */
    private function confidence(): float
    {
        $present = 0;
        foreach ([$this->recipient, $this->occasion, $this->size, $this->productType] as $v) {
            if ($v !== null) {
                $present++;
            }
        }
        if ($this->colours !== []) {
            $present++;
        }
        if ($this->styles !== []) {
            $present++;
        }
        if ($this->budgetMax !== null && $this->budgetMax > 0) {
            $present++;
        }
        return round(min(1.0, $present / 7), 2);
    }

    private static function nullableString(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);
        return $v === '' ? null : $v;
    }

    private static function nullableFloat(mixed $v): ?float
    {
        if (is_int($v) || is_float($v)) {
            return (float) $v;
        }
        if (is_string($v) && is_numeric(trim($v))) {
            return (float) trim($v);
        }
        return null;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }
        $out = [];
        foreach ($v as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }
        return array_values(array_unique($out));
    }
}
