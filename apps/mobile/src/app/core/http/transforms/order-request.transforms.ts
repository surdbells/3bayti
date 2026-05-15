/**
 * Authenticated-GET request transforms — legacy POST-with-body →
 * v3 GET-with-query for endpoints that REQUIRE authentication.
 *
 * Why this file exists (vs. catalog-request.transforms.ts)
 * =========================================================
 * `catalog-request.transforms.ts` handles the same POST→GET pattern
 * but for PUBLIC catalog reads — the adapter's tryConvertPostToGet
 * explicitly drops auth at line 447 (`null` auth header) because
 * catalog endpoints are unauthenticated.
 *
 * Order list / order detail are authenticated. Mobile sends:
 *   POST /customer/read_orders_listing
 *   Body: { id, token, limit, offset }
 *
 * v3 expects:
 *   GET /v3/orders?limit=X&offset=Y
 *   Authorization: Bearer <token>
 *
 * We need:
 *   1. POST→GET conversion (same as catalog)
 *   2. Auth header extraction from body's `token` (same as
 *      translateRequestBody handles for callV3)
 *   3. Path param + query param extraction
 *
 * Solution: a parallel registry consulted by the adapter on Path 2
 * when CATALOG_REQUEST_TRANSFORMS doesn't match. The adapter sees
 * a match here and runs the authenticated GET path (translates the
 * body to extract the token, builds the query URL, sends with the
 * Bearer header).
 *
 * Adapter integration
 * ===================
 * The adapter's tryConvertPostToGet checks CATALOG_REQUEST_TRANSFORMS
 * first; if no match, it checks AUTHED_GET_REQUEST_TRANSFORMS. If
 * the latter matches, the adapter uses translateRequestBody to get
 * the auth header AND apply the transform's query/path-param extract.
 *
 * Note: `BodyToRouteArgs` from catalog-request.transforms.ts has the
 * exact signature we need — we reuse the type. Only the runtime
 * registry is separate (so the adapter can decide whether to attach
 * an auth header based on which registry matched).
 */

import { asRecord, type BodyToRouteArgs } from './catalog-request.transforms';

/**
 * Pick an integer from a record, clamping to [min, max].
 * Default if missing or out-of-range.
 */
function pickIntClamped(
  src: Record<string, unknown>,
  key: string,
  defaultValue: number,
  min: number,
  max: number,
): number {
  const v = src[key];
  let n: number;
  if (typeof v === 'number' && Number.isFinite(v)) {
    n = Math.trunc(v);
  } else if (typeof v === 'string' && v.trim() !== '') {
    const parsed = Number(v);
    n = Number.isFinite(parsed) ? Math.trunc(parsed) : defaultValue;
  } else {
    n = defaultValue;
  }
  if (n < min) n = min;
  if (n > max) n = max;
  return n;
}

/* ============================================================== *
 * Orders
 * ============================================================== */

/**
 * `read_orders_listing` — POST /customer/read_orders_listing
 *                       → GET /v3/orders?limit=X&offset=Y.
 *
 * Legacy body: { id, token, limit, offset }
 * v3 query:    ?limit=X&offset=Y   (id+token via Authorization header)
 *
 * v3 enforces limit ∈ [1, 50]; we clamp at the transform level to give
 * faster client-side feedback (mobile sends limit=10 typically; if a
 * future caller asks for 999 we send 50 + log nothing — server still
 * enforces).
 *
 * Default limit/offset matches mobile's existing default (10 / 0).
 */
export function transformReadOrdersListingRequest(body: unknown): {
  queryParams: Record<string, string | number | boolean>;
} {
  const b = asRecord(body);
  return {
    queryParams: {
      limit: pickIntClamped(b, 'limit', 10, 1, 50),
      offset: pickIntClamped(b, 'offset', 0, 0, 1_000_000),
    },
  };
}

/* ============================================================== *
 * Registry
 * ============================================================== */

/**
 * Map of v3-GET routeKey → POST-body-to-query transform for endpoints
 * that REQUIRE authentication.
 *
 * Adapter consults this AFTER CATALOG_REQUEST_TRANSFORMS misses, on
 * the cross-verb POST→GET path (Path 2 in the adapter). When this
 * registry matches, the adapter ALSO extracts the auth header from
 * the original POST body (via translateRequestBody).
 */
export const AUTHED_GET_REQUEST_TRANSFORMS: Record<string, BodyToRouteArgs> = {
  'GET /orders': transformReadOrdersListingRequest,
  // 'GET /orders/:id' — would go here if mobile started calling
  // it via POST with the id in the body. Currently my-orders.page
  // navigates to a detail page that already uses GET; if it sends
  // POST in the future, register here.
};

/** Export individual transforms for direct testing. */
export const _internalTransforms = {
  transformReadOrdersListingRequest,
};
