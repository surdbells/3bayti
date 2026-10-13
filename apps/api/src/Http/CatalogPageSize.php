<?php

declare(strict_types=1);

namespace Bayti\Api\Http;

/**
 * Page-size rule for the public product listings
 * (GET /v3/products, GET /v3/vendors/{slug}/products and its legacy-id shim
 * GET /v3/vendors/by-legacy-id/{id}/products).
 *
 * One rule, shared by all three controllers so they cannot drift:
 *
 *   1. `limit` PRESENT in the query string (any value at all, including
 *      '' or garbage): clamp to 1..MAX exactly as these endpoints always
 *      have, i.e. max(1, min(MAX, (int) $value)). So '' / 'abc' / '0' /
 *      '-5' all give 1, '250' gives 100, '10.7' gives 10. Every paginating
 *      client (web store page, web catalog/search, portal) sends an explicit
 *      limit, so nothing they see changes.
 *
 *   2. `limit` ABSENT on a STORE-SCOPED request (a single store's products):
 *      STORE_CATALOGUE_MAX, i.e. the whole store catalogue in practice.
 *      Why: the shipped mobile store page (apps/mobile customer/vendors
 *      vendors.page.ts) loads its "All" grid, its "Latest" rail and every
 *      label chip in ONE request with no limit/offset and has no infinite
 *      scroll, so the old 24-row default silently truncated every store with
 *      more than 24 products (or more than 24 in a label). The fix has to be
 *      server-side because the app cannot change without an OTA push.
 *      (Bundles older than the slug storefront make the same no-limit
 *      requests against the by-legacy-id route and `vendor_id`.)
 *      STORE_CATALOGUE_MAX is a safety bound, not a page size: `offset` is
 *      still honoured and meta.has_more stays accurate, so a store that ever
 *      outgrows it is reported as such instead of being cut off silently.
 *
 *   3. `limit` ABSENT on anything else (category, search, explore, new
 *      arrivals, best sellers, collections, ...): DEFAULT (24), unchanged.
 *
 * "Absent" follows the controllers' historical `$query['limit'] ?? DEFAULT`
 * semantics: a missing key (or a null value, which a real query string never
 * produces) counts as absent; `?limit=` (empty string) counts as PRESENT and
 * yields 1, exactly as before.
 */
final class CatalogPageSize
{
    /** Page size when no limit is sent and the request is not store-scoped. */
    public const DEFAULT = 24;

    /** Upper clamp for an explicit `limit`. */
    public const MAX = 100;

    /** Bound on a store-scoped request that sends no limit (whole catalogue). */
    public const STORE_CATALOGUE_MAX = 500;

    private function __construct()
    {
    }

    /**
     * Resolve the page size for a product-listing request.
     *
     * @param array<string, mixed> $query       the request's query params
     * @param bool                 $storeScoped true when the listing is
     *                                          restricted to a single store
     */
    public static function resolve(array $query, bool $storeScoped): int
    {
        $raw = $query['limit'] ?? null;

        if ($raw === null) {
            return $storeScoped ? self::STORE_CATALOGUE_MAX : self::DEFAULT;
        }

        return max(1, min(self::MAX, (int) $raw));
    }
}
