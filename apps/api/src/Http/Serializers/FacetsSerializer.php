<?php

declare(strict_types=1);

namespace Bayti\Api\Http\Serializers;

/**
 * Serialize FacetAggregator output into the public response shape
 * for GET /v3/products/facets (M3.2.X.10-B).
 *
 * The aggregator's compute() return value is ALREADY very close to
 * the wire shape — this serializer mostly just wraps it in the
 * canonical { data, meta } envelope and surfaces the applied filter
 * set so the UI can render an "active filters" chip row without
 * re-parsing the query string.
 *
 * Response shape:
 *
 *   {
 *     "data": {
 *       "size":     { "values": [{value, count}, ...], "total_distinct": int },
 *       "color":    { "values": [{value, count}, ...], "total_distinct": int },
 *       "price":    { "values": [{value, count, min, max}, ...] },
 *       "vendor":   { "values": [{value, label, count}, ...], "total_distinct": int },
 *       "category": { "values": [{value, label, count}, ...], "total_distinct": int }
 *     },
 *     "meta": {
 *       "total_products": int,
 *       "applied_filters": {
 *         "category": "abayas",        // slug
 *         "vendor":   "almas-fashion",
 *         "min_price": "50.00",
 *         "max_price": "500.00",
 *         "sizes":    ["S","M"],
 *         "colors":   ["Black"],
 *         "q":        "evening dress"
 *       }
 *     }
 *   }
 */
final class FacetsSerializer
{
    /**
     * @param array{
     *     size: array{values: list<array<string, mixed>>, total_distinct: int},
     *     color: array{values: list<array<string, mixed>>, total_distinct: int},
     *     price: array{values: list<array<string, mixed>>},
     *     vendor: array{values: list<array<string, mixed>>, total_distinct: int},
     *     category: array{values: list<array<string, mixed>>, total_distinct: int},
     *     total_products: int
     * } $aggregatorResult
     * @param array<string, mixed> $appliedFilters
     * @return array{data: array<string, mixed>, meta: array<string, mixed>}
     */
    public function shape(array $aggregatorResult, array $appliedFilters): array
    {
        return [
            'data' => [
                'size'     => $aggregatorResult['size'],
                'color'    => $aggregatorResult['color'],
                'price'    => $aggregatorResult['price'],
                'vendor'   => $aggregatorResult['vendor'],
                'category' => $aggregatorResult['category'],
            ],
            'meta' => [
                'total_products' => $aggregatorResult['total_products'],
                'applied_filters' => $appliedFilters,
            ],
        ];
    }
}
