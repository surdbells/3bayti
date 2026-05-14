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
 * Naming convention
 * =================
 * Each transform is `transform<Endpoint>Request` where Endpoint matches
 * the mobile constant in GlobalComponent (e.g. `transformNewArrivalsRequest`,
 * `transformSingleProductRequest`). This makes grep'ing from call site
 * to transform trivial.
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

/* ============================================================== *
 * Shared utilities
 * ============================================================== */

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

/**
 * Extract a numeric field from a legacy body for use as a path param.
 *
 * Mobile bodies sometimes have the field as `number`, sometimes as a
 * stringified number (depending on which call site populated it).
 * Returns the value as a string suitable for URL path substitution.
 * Returns "0" if the field is missing or unparseable — the v3
 * controller will then 404 on the legacy-id lookup, which is the same
 * behaviour as a real "not found" lookup, so we degrade gracefully.
 */
function pickIdAsString(body: Record<string, unknown>, key: string): string {
  const v = body[key];
  if (typeof v === 'number' && Number.isFinite(v)) return String(v);
  if (typeof v === 'string' && v.trim() !== '') return v.trim();
  return '0';
}

/**
 * Pick a numeric field for use as a query value, coercing to number.
 * Returns the default if missing/unparseable.
 */
function pickIntOrDefault(body: Record<string, unknown>, key: string, defaultValue: number): number {
  const v = body[key];
  if (typeof v === 'number' && Number.isFinite(v)) return v;
  if (typeof v === 'string' && v.trim() !== '') {
    const n = Number(v);
    if (Number.isFinite(n)) return n;
  }
  return defaultValue;
}

/* ============================================================== *
 * Strip-only transforms (no entity filter, just paging/sort hints)
 * ============================================================== */

/**
 * Home strip: "newest products, top N". Legacy body is just
 * `{id, token}` — no pagination params, no filters. v3 needs an
 * explicit sort + limit for it to behave like a strip.
 *
 * limit=10 matches the home-page strip's UX (~5-10 cards visible). The
 * legacy backend's default count is unknown but the strip renders
 * however many items come back, so 10 is a safe upper bound that
 * shouldn't crowd the UI or starve it.
 */
export function transformNewArrivalsRequest(body: unknown): {
  queryParams: Record<string, string | number | boolean>;
} {
  void asRecord(body); // body has only id/token; both dropped
  return {
    queryParams: {
      sort: 'newest',
      limit: 10,
    },
  };
}

/**
 * Paginated "new arrivals" listing. Legacy body adds limit, offset,
 * and a maxPrice ceiling. Forward all three to v3.
 *
 * maxPrice = 20000 AED is the legacy default the page hard-codes —
 * effectively "no upper bound" for normal use. We pass it through as
 * v3's max_price query param so the legacy-shipped value continues to
 * have no observable effect.
 */
export function transformNewArrivalsListingRequest(body: unknown): {
  queryParams: Record<string, string | number | boolean>;
} {
  const b = asRecord(body);
  const query: Record<string, string | number | boolean> = {
    sort: 'newest',
    limit: pickIntOrDefault(b, 'limit', 10),
    offset: pickIntOrDefault(b, 'offset', 0),
  };
  if (b['maxPrice'] !== undefined) {
    query['max_price'] = pickIntOrDefault(b, 'maxPrice', 20000);
  }
  return { queryParams: query };
}

/**
 * Featured products strip. Legacy body: `{id, token, limit, offset}`.
 */
export function transformFeaturedRequest(body: unknown): {
  queryParams: Record<string, string | number | boolean>;
} {
  const b = asRecord(body);
  return {
    queryParams: {
      featured: true,
      limit: pickIntOrDefault(b, 'limit', 5),
      offset: pickIntOrDefault(b, 'offset', 0),
    },
  };
}

/**
 * Explore feed (vertical scroll). Legacy body: `{id, token, limit, offset}`.
 *
 * No filters, no sort — v3 defaults to sort=newest which is acceptable
 * for the feed (Phase 0 noted the ordering may differ slightly from
 * legacy's curated/random order; flagged in the device-test checklist).
 */
export function transformExploreListingRequest(body: unknown): {
  queryParams: Record<string, string | number | boolean>;
} {
  const b = asRecord(body);
  return {
    queryParams: {
      limit: pickIntOrDefault(b, 'limit', 10),
      offset: pickIntOrDefault(b, 'offset', 0),
    },
  };
}

/* ============================================================== *
 * Transforms that resolve a legacy category id to v3 category_id
 * ============================================================== */

/**
 * Category-filtered product listing. Legacy body:
 *   { id, token, category: <legacy_category_id>, name, limit, offset, maxPrice }
 *
 * `name` is the human-readable category name — display-only metadata
 * the legacy backend ignored. Dropped.
 *
 * `category: 0` is the special "no filter / all products" signal — when
 * we see that, we OMIT the category_id query param entirely (v3 returns
 * the full unfiltered list).
 */
export function transformCategoryListingRequest(body: unknown): {
  queryParams: Record<string, string | number | boolean>;
} {
  const b = asRecord(body);
  const query: Record<string, string | number | boolean> = {
    limit: pickIntOrDefault(b, 'limit', 10),
    offset: pickIntOrDefault(b, 'offset', 0),
  };

  const categoryId = pickIntOrDefault(b, 'category', 0);
  if (categoryId !== 0) {
    query['category_id'] = categoryId;
  }

  if (b['maxPrice'] !== undefined) {
    query['max_price'] = pickIntOrDefault(b, 'maxPrice', 20000);
  }

  return { queryParams: query };
}

/* ============================================================== *
 * Path-param transforms (entity id moves into URL, not query)
 * ============================================================== */

/**
 * Single product detail. Legacy body: `{id, token, product, product_name}`.
 *
 * - `product` is the legacy product id; goes into the URL path
 * - `product_name` is display-only metadata from the calling page;
 *   dropped (v3 returns the canonical name anyway)
 */
export function transformSingleProductRequest(body: unknown): {
  pathParams: Record<string, string>;
} {
  const b = asRecord(body);
  return {
    pathParams: { id: pickIdAsString(b, 'product') },
  };
}

/**
 * Single product via the utility endpoint. Legacy body is just
 * `{product}` (no auth pair — utility endpoints are anonymous-by-design
 * on the legacy backend). Same shape as the customer variant from v3's
 * perspective: just need the product id.
 */
export function transformSingleProductUtilityRequest(body: unknown): {
  pathParams: Record<string, string>;
} {
  const b = asRecord(body);
  return {
    pathParams: { id: pickIdAsString(b, 'product') },
  };
}

/**
 * Vendor's products listing. Legacy body: `{id, token, storeId}`.
 *
 * `storeId` is camelCase (one legacy call site's quirk); maps to the
 * `{id}` path param in `/v3/vendors/by-legacy-id/{id}/products`.
 */
export function transformVendorsProductsListingRequest(body: unknown): {
  pathParams: Record<string, string>;
  queryParams?: Record<string, string | number | boolean>;
} {
  const b = asRecord(body);
  const result: {
    pathParams: Record<string, string>;
    queryParams?: Record<string, string | number | boolean>;
  } = {
    pathParams: { id: pickIdAsString(b, 'storeId') },
  };

  // If the call site sends limit/offset (some do, some don't), forward.
  if (b['limit'] !== undefined || b['offset'] !== undefined) {
    result.queryParams = {
      limit: pickIntOrDefault(b, 'limit', 24),
      offset: pickIntOrDefault(b, 'offset', 0),
    };
  }
  return result;
}

/**
 * Read vendor (storefront header). Legacy body: `{id, token, store_id}`.
 *
 * `store_id` is snake_case here (different from `storeId` above —
 * legacy inconsistency). Maps to the `{id}` path param in
 * `/v3/vendors/by-legacy-id/{id}`.
 */
export function transformReadVendorRequest(body: unknown): {
  pathParams: Record<string, string>;
} {
  const b = asRecord(body);
  return {
    pathParams: { id: pickIdAsString(b, 'store_id') },
  };
}

/**
 * Vendor's latest products. Legacy body:
 *   { id, token, label, store_id, store_name }
 *
 * `store_id` -> path param. The v3 endpoint hardcodes sort=newest
 * (matches the legacy semantics), so we don't need to pass it.
 *
 * Drops: id, token (legacy auth pair); label, store_name (display-only).
 * The label is hardcoded `4` at the call site — Phase 0 noted this
 * suggests a half-built filter feature; v3 has no equivalent so we
 * silently ignore it.
 */
export function transformStoreLatestRequest(body: unknown): {
  pathParams: Record<string, string>;
} {
  const b = asRecord(body);
  return {
    pathParams: { id: pickIdAsString(b, 'store_id') },
  };
}

/* ============================================================== *
 * M3.1.5.5 — Transforms for the deferred catalog endpoints
 * ============================================================== */

/**
 * Product search. Legacy body:
 *   { id, token, search: "<query>" }
 *
 * `search` → v3 query param `q`. The v3 endpoint also accepts
 * `sort=relevance` to rank by ts_rank; we don't force it here
 * because mobile's search.page doesn't expose a sort control. The
 * v3 default is 'newest' which matches the legacy semantic (returns
 * matching products without rank ordering).
 *
 * Drops: id, token (legacy auth).
 *
 * Empty `search` strings get forwarded to v3 as `q=`; the v3
 * controller's parseSearchQuery treats empty as "no search", which
 * effectively makes this a normal product listing — matches the
 * legacy behaviour where customer/search with an empty query
 * returned everything.
 */
export function transformSearchRequest(body: unknown): {
  queryParams: Record<string, string | number | boolean>;
} {
  const b = asRecord(body);
  const raw = b['search'];
  const search = typeof raw === 'string' ? raw : '';
  return {
    queryParams: { q: search },
  };
}

/**
 * Per-vendor labels listing. Legacy body:
 *   { id, token, label, store_id, store_name }
 *
 * `store_id` → path param. `label`, `store_name` are display-only at
 * the call site (vendors.page uses them as static UI hints); they
 * don't need to be forwarded to v3.
 *
 * Drops: id, token (legacy auth); label, store_name (display-only).
 */
export function transformStoreLabelsRequest(body: unknown): {
  pathParams: Record<string, string>;
} {
  const b = asRecord(body);
  return {
    pathParams: { id: pickIdAsString(b, 'store_id') },
  };
}

/**
 * Products filtered by a vendor label. Legacy body:
 *   { id, token, label: <legacy_label_id>, store_id, store_name }
 *
 * Both `label` and `store_id` matter: a label is per-vendor, so the
 * v3 endpoint needs both ids to surface only that vendor's products
 * under that label.
 *
 *   label → query param `label_id` (resolved to v3 internal id by
 *           the ListProductsController.resolveLabelId helper)
 *   store_id → query param `vendor_id` (resolved by resolveVendorId)
 *
 * Drops: id, token, store_name.
 */
export function transformProductsByLabelsRequest(body: unknown): {
  queryParams: Record<string, string | number | boolean>;
} {
  const b = asRecord(body);
  const query: Record<string, string | number | boolean> = {};

  const labelId = pickIntOrDefault(b, 'label', 0);
  if (labelId !== 0) {
    query['label_id'] = labelId;
  }
  const vendorId = pickIntOrDefault(b, 'store_id', 0);
  if (vendorId !== 0) {
    query['vendor_id'] = vendorId;
  }

  return { queryParams: query };
}

/**
 * Styles listing. Legacy body:
 *   { id, token, type: "community" | "editorial", limit, offset }
 *
 * Pass through `type`, `limit`, `offset` as query params; drop the
 * auth pair. v3's ListStylesController has default type=community
 * when absent and clamps limit/offset.
 */
export function transformStylesListRequest(body: unknown): {
  queryParams: Record<string, string | number | boolean>;
} {
  const b = asRecord(body);
  const query: Record<string, string | number | boolean> = {
    limit: pickIntOrDefault(b, 'limit', 10),
    offset: pickIntOrDefault(b, 'offset', 0),
  };

  const typeRaw = b['type'];
  if (typeof typeRaw === 'string' && typeRaw.trim() !== '') {
    query['type'] = typeRaw.trim();
  }

  return { queryParams: query };
}

/* ============================================================== *
 * Registry — keyed by routeKey, matching ENDPOINT_ROUTING keys
 * ============================================================== */

export const CATALOG_REQUEST_TRANSFORMS: Record<string, BodyToRouteArgs> = {
  'GET /mobile/new-arrivals': transformNewArrivalsRequest,
  'GET /mobile/new-arrivals-listing': transformNewArrivalsListingRequest,
  'GET /mobile/featured': transformFeaturedRequest,
  'GET /mobile/explore-listing': transformExploreListingRequest,
  'GET /mobile/category-listing': transformCategoryListingRequest,
  'GET /mobile/single-product': transformSingleProductRequest,
  'GET /mobile/single-product-utility': transformSingleProductUtilityRequest,
  'GET /mobile/vendors-products': transformVendorsProductsListingRequest,
  'GET /mobile/read-vendor': transformReadVendorRequest,
  'GET /mobile/store-latest': transformStoreLatestRequest,
  // M3.1.5.5 catalog additions:
  'GET /mobile/search': transformSearchRequest,
  'GET /mobile/store-labels': transformStoreLabelsRequest,
  'GET /mobile/products-by-labels': transformProductsByLabelsRequest,
  'GET /mobile/styles-list': transformStylesListRequest,
};

/* ============================================================== *
 * Legacy-shared helper retained for callers that may need it
 * ============================================================== */

/**
 * Strip the legacy auth fields (`id`, `token`) from a record. Kept as
 * an export for transforms that need to pass-through arbitrary
 * additional body keys as query params while dropping the auth pair.
 * Currently unused (each transform whitelists what it forwards) but
 * preserved for future endpoints that may need a "forward everything
 * except auth" pattern.
 */
export function stripLegacyAuthFields(body: Record<string, unknown>): Record<string, unknown> {
  const { id, token, ...rest } = body;
  void id;
  void token;
  return rest;
}
