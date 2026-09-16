<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge\Outfit;

use Bayti\Api\Ai\Concierge\ConciergeIntent;

/**
 * The structured inputs of the Ain Outfit Generator's guided flow (occasion,
 * style, colour, budget, optional hero garment type). Like the gift concierge,
 * the form already delivers clean fields, so we build a {@see ConciergeIntent}
 * directly — NO LLM parse — and feed it to the same retrieval + ranking pipeline,
 * so every piece the outfit surfaces is a real, in-stock catalogue item (the
 * anti-hallucination guard is inherited, not re-implemented).
 *
 * The brief drives the HERO garment; complementary pieces are then composed
 * cross-category around it (see {@see OutfitComposerService}).
 */
final class OutfitBrief
{
    /**
     * @param list<string> $colours
     * @param list<string> $styles
     */
    public function __construct(
        public readonly ?string $occasion,
        public readonly array $colours,
        public readonly array $styles,
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
            occasion: self::nullableString($a['occasion'] ?? null),
            colours: self::stringList($a['colours'] ?? $a['colors'] ?? []),
            styles: self::stringList($a['styles'] ?? []),
            budgetMin: self::nullableFloat($a['budget_min'] ?? null),
            budgetMax: self::nullableFloat($a['budget_max'] ?? null),
            productType: self::nullableString($a['product_type'] ?? null),
            categorySlug: self::nullableString($a['category_slug'] ?? null),
        );
    }

    /**
     * The structured intent that drives the HERO garment retrieval. Keywords
     * carry only terms that plausibly appear in product text (occasion + styles +
     * product type).
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
            isGift: false,
            confidence: $this->confidence(),
        );
    }

    /** A natural-language brief for the ranker LLM (context only) and the recorded query_text. */
    public function toRankerQuery(): string
    {
        $parts = [];
        $lead = 'An outfit';
        if ($this->styles !== []) {
            $lead = 'A ' . implode(', ', $this->styles) . ' outfit';
        }
        if ($this->occasion !== null) {
            $lead .= ' for ' . $this->occasion;
        }
        $parts[] = $lead;

        if ($this->colours !== []) {
            $parts[] = 'in ' . implode(', ', $this->colours);
        }
        if ($this->productType !== null) {
            $parts[] = 'built around a ' . $this->productType;
        }
        if ($this->budgetMax !== null && $this->budgetMax > 0) {
            $parts[] = 'under ' . rtrim(rtrim(number_format($this->budgetMax, 2, '.', ''), '0'), '.') . ' AED';
        }

        return implode(', ', $parts) . '.';
    }

    /** True when the brief carries at least one retrieval signal. */
    public function hasEnoughSignal(): bool
    {
        return $this->occasion !== null
            || $this->productType !== null
            || $this->colours !== []
            || $this->styles !== []
            || ($this->budgetMax !== null && $this->budgetMax > 0);
    }

    /** Synthetic confidence (no LLM parse): how completely the brief was filled in. */
    private function confidence(): float
    {
        $present = 0;
        foreach ([$this->occasion, $this->productType] as $v) {
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
        return round(min(1.0, $present / 5), 2);
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
