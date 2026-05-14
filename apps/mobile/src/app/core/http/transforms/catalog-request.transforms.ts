/**
 * Catalog request transforms — body-to-route-args extractors.
 *
 * Why this file exists
 * ====================
 * Mobile's catalog call sites are POST-with-body (legacy convention).
 * v3's catalog endpoints are GET-with-query (RESTful). The
 * MobileNetworkAdapter handles the verb conversion transparently, but
 * needs per-endpoint help to know:
 *
 *   - Which body keys become path params (e.g. `store_id: 42` becomes
 *     the `{id}` in `/v3/vendors/by-legacy-id/{id}/products`)
 *   - Which body keys become query params (e.g. `category: 5` becomes
 *     `?category_id=5`)
 *   - Which body keys are dropped (legacy `id` and `token` aren't
 *     needed for anonymous v3 reads — they're not auth tokens; they're
 *     a vestigial legacy-backend session pair)
 *
 * Each entry below extracts these three things from a legacy-shaped
 * body and returns them as `{pathParams, queryParams}`. The adapter
 * applies them via `resolveUrl(routeKey, urls, pathParams)` plus a
 * query-string append.
 *
 * Why mobile-side (not in api-client)
 * ====================================
 * The shared api-client routing table is consumer-agnostic. POST->GET
 * conversion is a mobile-only concern (web's RoutedHttpClient is
 * already GET-native). Putting these transforms here keeps the shared
 * package clean and lets mobile evolve its transform registry without
 * touching anything web or portal depends on.
 *
 * Retirement
 * ==========
 * This entire file is M3.1.5-era compat. Once mobile is rebuilt
 * against v3 GET-native semantics (M3.1.10+), call sites will issue
 * `get_v3(routeKey, opts)` directly and these transforms can be
 * deleted in one cleanup commit.
 *
 * M3.1.5b ships the type + empty registry; M3.1.5c populates entries.
 */

/**
 * Output of a body-to-route-args extractor: the path params and query
 * params the adapter should use when issuing the v3 GET request.
 *
 * pathParams is consumed by `resolveUrl(routeKey, urls, pathParams)`
 * for `:foo` substitutions in the routing entry's newPath. Path-param
 * values are always strings (URL path segments can't carry typed data).
 *
 * queryParams is appended to the URL as a query string. Values may be
 * strings, numbers, or booleans; the adapter coerces them via String().
 * Booleans become "true"/"false" (matches v3's parseBool semantics).
 *
 * Either field may be omitted if not needed (e.g. an endpoint with no
 * path params).
 */
export type BodyToRouteArgs = (body: unknown) => {
  pathParams?: Record<string, string>;
  queryParams?: Record<string, string | number | boolean>;
};

/**
 * Registry keyed by routeKey (matches `ENDPOINT_ROUTING` keys in
 * `@3bayti/api-client`'s `feature-flags.ts`). The adapter looks up by
 * routeKey when converting POST -> GET.
 *
 * Empty in M3.1.5b — actual transforms land in M3.1.5c alongside the
 * mobile-specific routing entries that point at v3 catalog endpoints.
 */
export const CATALOG_REQUEST_TRANSFORMS: Record<string, BodyToRouteArgs> = {};

/**
 * Helper used by individual transforms: strip the legacy auth fields
 * (`id`, `token`) from a record, returning a copy without them.
 *
 * Why this is generic enough to live here
 * ========================================
 * Every legacy catalog call site sends `{id, token, ...rest}` where
 * id/token are the legacy user-session identifiers. None of v3's
 * catalog read endpoints are authenticated (they're public catalog
 * surfaces), so id/token contribute nothing useful and would pollute
 * the query string if forwarded. Every transform in this file will
 * drop them, so the operation lives here as a shared utility rather
 * than being repeated per-transform.
 */
export function stripLegacyAuthFields(body: Record<string, unknown>): Record<string, unknown> {
  const { id, token, ...rest } = body;
  // Underscore-prefix tells the linter the values are intentionally
  // unused; the destructuring itself does the work of removing them.
  void id;
  void token;
  return rest;
}

/**
 * Coerce an `unknown` to a Record<string, unknown> usable by transforms.
 * Returns an empty record for null, undefined, primitives, or arrays —
 * preserving the contract that transforms always receive a record-like
 * input (even if the call site provided something unexpected).
 *
 * Why be lenient
 * ==============
 * Call sites have evolved over years; some send `null` for empty
 * requests, some send `{}`, some omit the body entirely. Rather than
 * have each transform handle these cases, normalise once here.
 */
export function asRecord(body: unknown): Record<string, unknown> {
  if (body === null || body === undefined) return {};
  if (typeof body !== 'object') return {};
  if (Array.isArray(body)) return {};
  return body as Record<string, unknown>;
}
