<?php

declare(strict_types=1);

namespace Bayti\Api\Http;

/**
 * Build paginated response envelopes matching the v2 contract.
 *
 * apps/web (and shortly mobile + portal via packages/api-client) expects
 * paginated read endpoints to return:
 *
 *   {
 *     "data": [ ...items ],
 *     "meta": {
 *       "total":    1928,
 *       "limit":    24,
 *       "offset":   0,
 *       "has_more": true
 *     }
 *   }
 *
 * This shape is locked by the roadmap §9.3 — endpoints must match the
 * legacy v2 contract on responses so frontends don't need per-endpoint
 * adapters. All v3 catalog list endpoints emit this shape.
 *
 * Why a static helper not a class with state:
 *   - The values are scalar; no behaviour beyond shape assembly
 *   - Easier to call from anywhere without DI
 *   - Equivalent in performance to manual array assembly
 *
 * Computation rules:
 *   - `has_more` is (offset + count(items_in_this_page)) < total
 *     i.e., there's at least one more row past what we returned.
 *     Using items-in-page (not limit) handles the last-page case
 *     where fewer than `limit` rows were returned.
 *   - `total` is the unfiltered-by-offset/limit count from the DB.
 *   - `limit` and `offset` are echoed back as the caller sent them.
 */
final class PaginatedEnvelope
{
    /**
     * @param list<mixed> $items
     * @return array{data: list<mixed>, meta: array{total: int, limit: int, offset: int, has_more: bool}}
     */
    public static function build(
        array $items,
        int $total,
        int $limit,
        int $offset,
    ): array {
        $count = count($items);
        return [
            'data' => $items,
            'meta' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => ($offset + $count) < $total,
            ],
        ];
    }

    /**
     * Wrap a single resource in the envelope shape too — for endpoints
     * that aren't paginated but should still emit { data: ... } for
     * shape consistency. No `meta` for single resources.
     *
     * @return array{data: mixed}
     */
    public static function single(mixed $data): array
    {
        return ['data' => $data];
    }
}
