<?php

declare(strict_types=1);

namespace Bayti\Api\Ai\Personalization;

/**
 * A customer's pre-computed style/affinity profile — the aggregated output of
 * {@see CustomerStyleProfileBuilder}, persisted by {@see CustomerStyleProfileStore},
 * and consumed by {@see ForYouRailsService}.
 *
 * Tag lists are ordered strongest-affinity first as `{tag|id, weight}` rows.
 * Colours/categories/vendors/budget are the retrieval-actionable fields (they map
 * onto ProductRepository::findActivePaginated filters); styles/occasions/sizes are
 * captured for analytics and future semantic use. Money is a decimal STRING.
 */
final class CustomerStyleProfile
{
    /**
     * @param list<array{tag: string, weight: float}> $colours
     * @param list<array{tag: string, weight: float}> $styles
     * @param list<array{id: int, weight: float}> $categories
     * @param list<array{id: int, weight: float}> $vendors
     * @param list<array{tag: string, weight: float}> $occasions
     * @param list<array{tag: string, weight: float}> $sizes
     * @param list<int> $seedProductIds
     * @param array<string, int> $signalCounts
     */
    public function __construct(
        public readonly array $colours = [],
        public readonly array $styles = [],
        public readonly array $categories = [],
        public readonly array $vendors = [],
        public readonly array $occasions = [],
        public readonly array $sizes = [],
        public readonly ?string $budgetMin = null,
        public readonly ?string $budgetMax = null,
        public readonly array $seedProductIds = [],
        public readonly array $signalCounts = [],
    ) {
    }

    /** @return list<string> */
    public function topColours(int $n): array
    {
        return self::topTags($this->colours, $n);
    }

    /** @return list<string> */
    public function topStyles(int $n): array
    {
        return self::topTags($this->styles, $n);
    }

    /** @return list<string> */
    public function topOccasions(int $n): array
    {
        return self::topTags($this->occasions, $n);
    }

    /** @return list<int> */
    public function topCategoryIds(int $n): array
    {
        return self::topIds($this->categories, $n);
    }

    /** @return list<int> */
    public function topVendorIds(int $n): array
    {
        return self::topIds($this->vendors, $n);
    }

    /**
     * Whether there is enough affinity to build personalised rails. An empty
     * profile means the caller should fall back to the cold (popular) path.
     */
    public function isEmpty(): bool
    {
        return $this->colours === []
            && $this->categories === []
            && $this->vendors === []
            && $this->styles === [];
    }

    /**
     * Persisted shape (jsonb columns + scalar budget). Mirrors the
     * customer_style_profiles table.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'colours' => $this->colours,
            'styles' => $this->styles,
            'categories' => $this->categories,
            'vendors' => $this->vendors,
            'occasions' => $this->occasions,
            'sizes' => $this->sizes,
            'budget_min' => $this->budgetMin,
            'budget_max' => $this->budgetMax,
            'seed_product_ids' => $this->seedProductIds,
            'signal_counts' => $this->signalCounts,
        ];
    }

    /**
     * @param array<string, mixed> $row decoded customer_style_profiles row
     */
    public static function fromArray(array $row): self
    {
        return new self(
            colours: self::tagRows($row['colours'] ?? null),
            styles: self::tagRows($row['styles'] ?? null),
            categories: self::idRows($row['categories'] ?? null),
            vendors: self::idRows($row['vendors'] ?? null),
            occasions: self::tagRows($row['occasions'] ?? null),
            sizes: self::tagRows($row['sizes'] ?? null),
            budgetMin: self::decimalOrNull($row['budget_min'] ?? null),
            budgetMax: self::decimalOrNull($row['budget_max'] ?? null),
            seedProductIds: self::intList($row['seed_product_ids'] ?? null),
            signalCounts: self::intMap($row['signal_counts'] ?? null),
        );
    }

    /**
     * @param list<array{tag: string, weight: float}> $rows
     * @return list<string>
     */
    private static function topTags(array $rows, int $n): array
    {
        $out = [];
        foreach (array_slice($rows, 0, max(0, $n)) as $row) {
            if ($row['tag'] !== '') {
                $out[] = $row['tag'];
            }
        }
        return $out;
    }

    /**
     * @param list<array{id: int, weight: float}> $rows
     * @return list<int>
     */
    private static function topIds(array $rows, int $n): array
    {
        $out = [];
        foreach (array_slice($rows, 0, max(0, $n)) as $row) {
            if ($row['id'] > 0) {
                $out[] = $row['id'];
            }
        }
        return $out;
    }

    /**
     * @return list<array{tag: string, weight: float}>
     */
    private static function tagRows(mixed $raw): array
    {
        $decoded = self::decode($raw);
        $out = [];
        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['tag']) && is_string($row['tag']) && $row['tag'] !== '') {
                $out[] = ['tag' => $row['tag'], 'weight' => (float) ($row['weight'] ?? 0)];
            }
        }
        return $out;
    }

    /**
     * @return list<array{id: int, weight: float}>
     */
    private static function idRows(mixed $raw): array
    {
        $decoded = self::decode($raw);
        $out = [];
        foreach ($decoded as $row) {
            if (is_array($row) && isset($row['id']) && is_numeric($row['id'])) {
                $out[] = ['id' => (int) $row['id'], 'weight' => (float) ($row['weight'] ?? 0)];
            }
        }
        return $out;
    }

    /**
     * @return list<int>
     */
    private static function intList(mixed $raw): array
    {
        $decoded = self::decode($raw);
        $out = [];
        foreach ($decoded as $v) {
            if (is_numeric($v)) {
                $out[] = (int) $v;
            }
        }
        return $out;
    }

    /**
     * @return array<string, int>
     */
    private static function intMap(mixed $raw): array
    {
        $decoded = self::decode($raw);
        $out = [];
        foreach ($decoded as $k => $v) {
            if (is_string($k) && is_numeric($v)) {
                $out[$k] = (int) $v;
            }
        }
        return $out;
    }

    /**
     * Decode a jsonb column that may arrive as a JSON string (raw DBAL) or an
     * already-decoded array.
     *
     * @return array<mixed>
     */
    private static function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        return [];
    }

    private static function decimalOrNull(mixed $raw): ?string
    {
        return is_numeric($raw) ? (string) $raw : null;
    }
}
