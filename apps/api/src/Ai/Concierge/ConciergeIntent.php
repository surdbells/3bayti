<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Concierge;

/**
 * Structured shopping intent extracted from a natural-language concierge query
 * (English or Arabic) by {@see IntentParser}. Deliberately maps onto the fields
 * ProductRepository::findActivePaginated already understands, so the AI only
 * decides WHAT to look for — the database decides what actually exists.
 */
final class ConciergeIntent
{
    /**
     * @param list<string> $occasions   canonical English occasion tags (wedding, eid, …)
     * @param list<string> $colours     free-text colour terms (matched against availableColors)
     * @param list<string> $styles      descriptive style tags (elegant, traditional, …)
     * @param list<string> $keywords    salient keywords for substring search
     * @param list<string> $vendorHints store/designer names the shopper referenced
     */
    public function __construct(
        public readonly ?string $productType,
        public readonly ?string $categorySlug,
        public readonly array $occasions,
        public readonly array $colours,
        public readonly array $styles,
        public readonly ?float $budgetMin,
        public readonly ?float $budgetMax,
        public readonly array $keywords,
        public readonly array $vendorHints,
        public readonly bool $isGift,
        public readonly float $confidence,
    ) {
    }

    /**
     * Build from the model's JSON object, coercing/sanitising every field so a
     * loose response can never produce a malformed intent.
     *
     * @param array<string, mixed> $a
     */
    public static function fromArray(array $a): self
    {
        $fallbackQuery = is_string($a['keywords_fallback'] ?? null) ? trim($a['keywords_fallback']) : '';

        $keywords = self::stringList($a['keywords'] ?? []);
        if ($keywords === [] && $fallbackQuery !== '') {
            $keywords = [$fallbackQuery];
        }

        return new self(
            productType: self::nullableString($a['product_type'] ?? null),
            categorySlug: self::nullableString($a['category_slug'] ?? null),
            occasions: self::stringList($a['occasions'] ?? []),
            colours: self::stringList($a['colours'] ?? $a['colors'] ?? []),
            styles: self::stringList($a['styles'] ?? []),
            budgetMin: self::nullableFloat($a['budget_min'] ?? null),
            budgetMax: self::nullableFloat($a['budget_max'] ?? null),
            keywords: $keywords,
            vendorHints: self::stringList($a['vendor_hints'] ?? []),
            isGift: (bool) ($a['is_gift'] ?? false),
            confidence: self::clamp01(self::nullableFloat($a['confidence'] ?? null) ?? 0.0),
        );
    }

    /** No AI available / empty extraction — search on the raw query alone. */
    public static function keywordFallback(string $query): self
    {
        $query = trim($query);
        return new self(
            productType: null,
            categorySlug: null,
            occasions: [],
            colours: [],
            styles: [],
            budgetMin: null,
            budgetMax: null,
            keywords: $query !== '' ? [$query] : [],
            vendorHints: [],
            isGift: false,
            confidence: 0.0,
        );
    }

    /**
     * Plain array for the API echo-back + the interaction log.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'product_type' => $this->productType,
            'category_slug' => $this->categorySlug,
            'occasions' => $this->occasions,
            'colours' => $this->colours,
            'styles' => $this->styles,
            'budget_min' => $this->budgetMin,
            'budget_max' => $this->budgetMax,
            'keywords' => $this->keywords,
            'vendor_hints' => $this->vendorHints,
            'is_gift' => $this->isGift,
            'confidence' => $this->confidence,
        ];
    }

    /** Free-text search string for the keyword pass (product type + keywords). */
    public function searchText(): string
    {
        $parts = [];
        if ($this->productType !== null) {
            $parts[] = $this->productType;
        }
        foreach ($this->keywords as $kw) {
            $parts[] = $kw;
        }
        return trim(implode(' ', array_unique($parts)));
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

    private static function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }

    /**
     * @param mixed $v
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
