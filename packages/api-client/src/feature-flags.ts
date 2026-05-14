/**
 * Endpoint feature flags.
 *
 * Per Decision 10 (strangler-fig migration), each endpoint can be
 * routed to either the legacy PHP backend (`api.3bayti.ae/*`) or
 * the new Slim 4 backend (`api-v3.3bayti.ae/*`). Flipping a single
 * endpoint from `'old'` -> `'new'` is a one-line change here; rolling
 * back is the same.
 *
 * Routing state by area (12 May 2026, Day 3 of 10-day rollout):
 *
 *   System         -> 'new' (v3 only)
 *   Catalog reads  -> 'new' (M2.1 + M2.2 shipped; data migrated Day 4)
 *   Auth           -> 'new' (M1 shipped)
 *   Account        -> 'new' (M1.7 shipped)
 *   Cart / order   -> 'old' (legacy; M3 work)
 *   Wishlist       -> 'old' (M3+)
 *   Chat / tickets -> 'old' (M4)
 *   Admin          -> 'new' for catalog (M2.1.A), 'old' for users/orders/payments
 *
 * Response shape contract
 * ------------------------
 *
 * v3 endpoints return one of:
 *   { data: T, meta?: PaginationMeta }   // catalog reads (M2.1+M2.2)
 *   { error: { code, message } }         // errors
 *   raw object                            // /v3/health, /v3/sitemap-data
 *
 * Legacy endpoints return:
 *   { response_code, status, data, message }
 *
 * The client (request()) normalises BOTH to `{ data: T, meta?: ... }`
 * so consumers don't need to know which backend served them.
 *
 * This file deliberately uses literal strings rather than an enum so
 * `git grep "/v3/auth/login"` finds where it's referenced without
 * going through type-system indirection.
 */

export type EndpointTarget = 'old' | 'new';

export interface EndpointConfig {
  /** Where this endpoint is currently served from. */
  target: EndpointTarget;
  /** Path on the OLD backend, including version prefix (e.g. '/users/login'). */
  oldPath: string;
  /** Path on the NEW backend, including version prefix (e.g. '/v3/auth/login'). */
  newPath: string;
  /**
   * Hint about the response envelope shape so the client can pick the
   * right normaliser. 'v2' = legacy `{response_code,status,data,message}`,
   * 'v3-envelope' = `{data,meta?}`, 'raw' = response IS the data (no
   * wrapper, e.g. /v3/sitemap-data).
   */
  shape?: 'v2' | 'v3-envelope' | 'raw';
}

/**
 * Endpoint registry. Key is `{METHOD} {logical-path}`.
 */
export const ENDPOINT_ROUTING: Record<string, EndpointConfig> = {
  // ---- System ----
  'GET /health': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/health',
    shape: 'raw',
  },

  // ---- Catalog reads (Day 2 of 10-day rollout - all on v3) ----
  'GET /products': {
    target: 'new',
    oldPath: '/v2/products',
    newPath: '/v3/products',
    shape: 'v3-envelope',
  },
  'GET /products/:slug': {
    target: 'new',
    oldPath: '/v2/products/:slug',
    newPath: '/v3/products/:slug',
    shape: 'v3-envelope',
  },
  'GET /categories': {
    target: 'new',
    oldPath: '/v2/categories',
    newPath: '/v3/categories',
    shape: 'v3-envelope',
  },
  // Category-detail stays on legacy v2 until v3 reaches feature
  // parity. v3's /v3/categories/:slug returns only the category
  // metadata (id, slug, name, description, image_url, children) —
  // it does NOT return the embedded products list or the
  // total_products/page_size meta that apps/web's category-detail
  // page needs. v2's /v2/categories/:slug returns both, so we route
  // this one endpoint back to legacy.
  //
  // To flip to 'new', v3 needs to either:
  //   - Augment /v3/categories/:slug to include products + meta
  //     (matches v2 shape), or
  //   - Have apps/web fetch products separately via
  //     /v3/products?category_slug=:slug and merge client-side.
  // Tracked as Day-5-followup; not blocking the demo.
  'GET /categories/:slug': {
    target: 'old',
    oldPath: '/v2/categories/:slug',
    newPath: '/v3/categories/:slug',
    shape: 'v3-envelope',
  },
  'GET /vendors': {
    target: 'new',
    oldPath: '/v2/vendors',
    newPath: '/v3/vendors',
    shape: 'v3-envelope',
  },
  'GET /vendors/:slug': {
    target: 'new',
    oldPath: '/v2/vendors/:slug',
    newPath: '/v3/vendors/:slug',
    shape: 'v3-envelope',
  },
  'GET /vendors/:slug/products': {
    target: 'new',
    oldPath: '/v2/vendors/:slug/products',
    newPath: '/v3/vendors/:slug/products',
    shape: 'v3-envelope',
  },
  // Designer Spotlight strip on the home page. The legacy endpoint
  // returns vendors WITH their nested products array (a custom shape
  // for that one UI strip). v3 has no equivalent yet — the
  // Designer Spotlight curation rules + embedded-products feature
  // need to land in v3 before we can flip this to 'new'.
  // Tracked as a Day-5-followup; until then this stays on legacy v2
  // and the strangler-fig pattern handles the cohabitation.
  'GET /featured-vendors': {
    target: 'old',
    oldPath: '/v2/featured-vendors',
    newPath: '/v3/featured-vendors',
    // v2 happens to use the same `{data:[...]}` shape as v3 for this
    // endpoint (no top-level response_code/status wrapper), so
    // 'v3-envelope' is correct here even though we're hitting legacy.
    shape: 'v3-envelope',
  },
  'GET /sitemap-data': {
    target: 'new',
    oldPath: '/v2/sitemap-data',
    newPath: '/v3/sitemap-data',
    shape: 'raw',
  },

  // ---- Mobile catalog reads (M3.1.5 — held at 'old' until c/d ship) ----
  //
  // Mobile's catalog call sites are POST-with-body by legacy convention,
  // but v3's catalog endpoints are GET-with-query. The MobileNetworkAdapter
  // handles the verb conversion (see tryConvertPostToGet in
  // mobile-network-adapter.ts); these routing entries provide the
  // URL->newPath mapping the adapter needs.
  //
  // Per-entry transforms live in
  // apps/mobile/src/app/core/http/transforms/catalog-request.transforms.ts.
  // The adapter looks up the transform by routeKey before issuing the
  // converted call.
  //
  // Note the `GET` method on these keys — mobile call sites actually
  // invoke POST, but the routeKey reflects what the request WILL be
  // after conversion. The adapter's verb-mismatch path is what makes
  // this work; see route() path 2.
  //
  // The `/mobile/*` routeKey prefix is purely a naming convention to
  // separate these entries from the web-side `/v2/*` catalog entries
  // above. Both can coexist in the routing table because oldPaths are
  // distinct (`/customer/*` vs `/v2/*`).
  //
  // Target stays 'old' until M3.1.5e flips the anonymous reads and
  // M3.1.5f flips the id-routed reads. Until then mobile traffic
  // continues to hit legacy; these entries exist so the resolver
  // recognises the URLs.
  //
  // Endpoints with active mobile consumers (10 total):
  'GET /mobile/new-arrivals': {
    target: 'new',
    oldPath: '/customer/new_arrivals',
    newPath: '/v3/products',
    shape: 'v3-envelope',
  },
  'GET /mobile/new-arrivals-listing': {
    target: 'new',
    oldPath: '/customer/new_arrivals_listing',
    newPath: '/v3/products',
    shape: 'v3-envelope',
  },
  'GET /mobile/featured': {
    target: 'new',
    oldPath: '/customer/featured',
    newPath: '/v3/products',
    shape: 'v3-envelope',
  },
  'GET /mobile/explore-listing': {
    target: 'new',
    oldPath: '/customer/explore_listing',
    newPath: '/v3/products',
    shape: 'v3-envelope',
  },
  'GET /mobile/category-listing': {
    target: 'old',
    oldPath: '/customer/category_listing',
    newPath: '/v3/products',
    shape: 'v3-envelope',
  },
  'GET /mobile/single-product': {
    target: 'old',
    oldPath: '/customer/single_product',
    newPath: '/v3/products/by-legacy-id/:id',
    shape: 'v3-envelope',
  },
  // utility/singleProduct is a separate legacy URL but resolves to the
  // same v3 endpoint. Distinct routeKey + entry so the resolver can
  // map the URL; the request transform is identical (same body shape
  // {product: <id>}) so M3.1.5c reuses the same extractor function.
  'GET /mobile/single-product-utility': {
    target: 'old',
    oldPath: '/utility/singleProduct',
    newPath: '/v3/products/by-legacy-id/:id',
    shape: 'v3-envelope',
  },
  'GET /mobile/vendors-products': {
    target: 'old',
    oldPath: '/customer/vendors_products',
    newPath: '/v3/vendors/by-legacy-id/:id/products',
    shape: 'v3-envelope',
  },
  'GET /mobile/read-vendor': {
    target: 'old',
    oldPath: '/customer/read-vendor',
    newPath: '/v3/vendors/by-legacy-id/:id',
    shape: 'v3-envelope',
  },
  'GET /mobile/store-latest': {
    target: 'old',
    oldPath: '/customer/store_latest',
    newPath: '/v3/vendors/by-legacy-id/:id/products',
    shape: 'v3-envelope',
  },

  // ---- Auth (M1 shipped - all on v3) ----
  //
  // M3.1.4 audit: this section had 5 entries whose `newPath` did not
  // match the actual v3 routes wired in apps/api/config/routes.php
  // ($app->group('/v3/auth', ...)). The entries were wired in M2 Day 5
  // against anticipated names; M1 shipped with different final names.
  //
  // M3.1.4 corrections (matched against routes.php):
  //   verify-phone     -> validate-phone (the v3 route is /v3/auth/validate-phone)
  //   forgot-password  -> renamed key to /auth/reset; v3 is /v3/auth/reset
  //   reset-password   -> renamed key to /auth/reset/confirm; v3 is /v3/auth/reset/confirm
  // M3.1.4 additions (no precedent; v3-only or no legacy 1:1):
  //   send-otp         -> /v3/auth/send-otp (re-send registration OTP)
  //   confirm          -> /v3/auth/confirm (validate registration OTP, returns tokens)
  //
  // For send-otp / confirm / reset / reset/confirm / refresh / logout,
  // `oldPath: ''` is used because there's either no 1:1 legacy
  // equivalent or the legacy flow is multi-step / multi-endpoint and
  // can't be mapped through the URL-resolver. Mobile call sites use
  // the adapter's v3-direct methods (post_v3 / get_v3) to invoke these
  // by routeKey rather than by legacy URL — see MobileNetworkAdapter
  // (M3.1.2 + M3.1.4 extensions).
  'POST /auth/register': {
    target: 'new',
    oldPath: '/users/register',
    newPath: '/v3/auth/register',
    shape: 'v3-envelope',
  },
  'POST /auth/validate-phone': {
    target: 'new',
    oldPath: '/users/verifyPhone',
    newPath: '/v3/auth/validate-phone',
    shape: 'v3-envelope',
  },
  'POST /auth/validate-email': {
    target: 'new',
    oldPath: '/users/validate-email',
    newPath: '/v3/auth/validate-email',
    shape: 'v3-envelope',
  },
  'POST /auth/login': {
    target: 'new',
    oldPath: '/users/login',
    newPath: '/v3/auth/login',
    shape: 'v3-envelope',
  },
  'POST /auth/send-otp': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/auth/send-otp',
    shape: 'v3-envelope',
  },
  'POST /auth/confirm': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/auth/confirm',
    shape: 'v3-envelope',
  },
  'POST /auth/refresh': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/auth/refresh',
    shape: 'v3-envelope',
  },
  'POST /auth/logout': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/auth/logout',
    shape: 'v3-envelope',
  },
  'POST /auth/logout-all': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/auth/logout-all',
    shape: 'v3-envelope',
  },
  'GET /auth/me': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/auth/me',
    shape: 'v3-envelope',
  },
  'POST /auth/reset': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/auth/reset',
    shape: 'v3-envelope',
  },
  'POST /auth/reset/confirm': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/auth/reset/confirm',
    shape: 'v3-envelope',
  },

  // ---- Account / Me (M1.7 shipped - all on v3) ----
  'GET /me/profile': {
    target: 'new',
    oldPath: '/users/profile',
    newPath: '/v3/me/profile',
    shape: 'v3-envelope',
  },
  'PUT /me/profile': {
    target: 'new',
    oldPath: '/users/updateProfile',
    newPath: '/v3/me/profile',
    shape: 'v3-envelope',
  },
  // M3.1.1f — change password (authenticated, current_password required).
  //
  // Method changed from PUT to PATCH to match the v3 controller's actual
  // registration in apps/api/config/routes.php. Previous PUT entry was
  // pre-implementation scaffolding from M2 Day 5.
  //
  // oldPath is empty: legacy mobile did not have an authenticated
  // change-password UI flow (legacy customers had only the password
  // reset flow via OTP). The /utility/shared/change-user-password
  // path from the original scaffolding is an ADMIN-side endpoint,
  // not customer self-service — not the right legacy mapping. v3
  // serves a new capability for customer self-service rotation.
  //
  // Response shape: v3-envelope. Note that the v3 response includes
  // a fresh access_token + refresh_token pair (revoke-all + reissue
  // pattern, mirroring /v3/auth/reset/confirm). Consumers must
  // store the new tokens AND treat any cached tokens elsewhere as
  // invalidated.
  'PATCH /me/password': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/password',
    shape: 'v3-envelope',
  },

  // M3.1.1c — billing address (singleton convenience accessor).
  //
  // Backed by the same `addresses` table as /me/addresses but exposes
  // only the user's default-billing row (see
  // docs/runbooks/m3/m3.1.1b-billing-address-decision.md).
  //
  // GET returns { address: ... | null }. PATCH is an UPSERT (creates
  // if no billing address exists; updates otherwise). This is a
  // deviation from the 0e.2 contract's 404-when-not-set plan,
  // documented in the controller. Legacy parity: mobile's
  // updateBilling is also upsert.
  'GET /me/billing-address': {
    target: 'new',
    oldPath: '/customer/settings/billing/read-billings',
    newPath: '/v3/me/billing-address',
    shape: 'v3-envelope',
  },
  'PATCH /me/billing-address': {
    target: 'new',
    oldPath: '/customer/settings/billing/update-billing',
    newPath: '/v3/me/billing-address',
    shape: 'v3-envelope',
  },

  // M3.1.1e — current location (upsert).
  //
  // Backed by user_locations (Version20260514000001). One row per
  // user enforced by UNIQUE index. PATCH creates if missing, updates
  // otherwise. See controller docblock for the structured-vs-string
  // schema rationale.
  //
  // The legacy endpoint (customer/settings/update-location) stored
  // only a free-form text label in users.location. v3 stores
  // structured lat/lng + city + country_code + permission flag.
  // Legacy data is NOT backfilled — UserLocation rows are lazy-
  // created on first PATCH after the v3 endpoint lands.
  //
  // Response shape: { location: ... } (deviation from 0e.2's
  // { user: ... }; documented in controller).
  'PATCH /me/location': {
    target: 'new',
    oldPath: '/customer/settings/update-location',
    newPath: '/v3/me/location',
    shape: 'v3-envelope',
  },

  'GET /me/addresses': {
    target: 'new',
    oldPath: '/users/addresses',
    newPath: '/v3/me/addresses',
    shape: 'v3-envelope',
  },
  'POST /me/addresses': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/addresses',
    shape: 'v3-envelope',
  },
  'PUT /me/addresses/:id': {
    target: 'new',
    oldPath: '/users/updateAddress/:id',
    newPath: '/v3/me/addresses/:id',
    shape: 'v3-envelope',
  },
  'DELETE /me/addresses/:id': {
    target: 'new',
    oldPath: '/users/deleteAddress/:id',
    newPath: '/v3/me/addresses/:id',
    shape: 'v3-envelope',
  },
  'GET /me/measurements': {
    target: 'new',
    oldPath: '/users/measurements',
    newPath: '/v3/me/measurements',
    shape: 'v3-envelope',
  },
  'POST /me/measurements': {
    target: 'new',
    oldPath: '/users/addMeasurement',
    newPath: '/v3/me/measurements',
    shape: 'v3-envelope',
  },
  'PUT /me/measurements/:id': {
    target: 'new',
    oldPath: '/users/updateMeasurement/:id',
    newPath: '/v3/me/measurements/:id',
    shape: 'v3-envelope',
  },
  'DELETE /me/measurements/:id': {
    target: 'new',
    oldPath: '/users/deleteMeasurement/:id',
    newPath: '/v3/me/measurements/:id',
    shape: 'v3-envelope',
  },

  // ---- Admin catalog (M2.1.A - on v3, raw shapes) ----
  // Note: admin endpoints don't follow the v3-envelope shape; they
  // return flat objects ({ brand: {...} } etc.). Marked 'raw' so the
  // client doesn't try to unwrap.
  'GET /admin/brands': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/admin/brands',
    shape: 'raw',
  },
  'POST /admin/brands': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/admin/brands',
    shape: 'raw',
  },
  'PUT /admin/brands/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/admin/brands/:id',
    shape: 'raw',
  },
  'DELETE /admin/brands/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/admin/brands/:id',
    shape: 'raw',
  },
  'GET /admin/vendors': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/admin/vendors',
    shape: 'raw',
  },
  'POST /admin/vendors': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/admin/vendors',
    shape: 'raw',
  },
  'PUT /admin/vendors/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/admin/vendors/:id',
    shape: 'raw',
  },
  'DELETE /admin/vendors/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/admin/vendors/:id',
    shape: 'raw',
  },
  'GET /admin/categories': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/admin/categories',
    shape: 'raw',
  },
  'POST /admin/categories': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/admin/categories',
    shape: 'raw',
  },
  'PUT /admin/categories/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/admin/categories/:id',
    shape: 'raw',
  },
  'DELETE /admin/categories/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/admin/categories/:id',
    shape: 'raw',
  },

  // ---- Cart, checkout, orders (LEGACY - M3 work flips these) ----
  'GET /cart': {
    target: 'old',
    oldPath: '/customer/read-cart',
    newPath: '/v3/cart',
    shape: 'v2',
  },
  'POST /cart/items': {
    target: 'old',
    oldPath: '/customer/addToCart',
    newPath: '/v3/cart/items',
    shape: 'v2',
  },
  'PUT /cart/items/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/cart/items/:id',
    shape: 'v3-envelope',
  },
  'DELETE /cart/items/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/cart/items/:id',
    shape: 'v3-envelope',
  },
  'POST /checkout': {
    target: 'old',
    oldPath: '/customer/payment/initiate_payment',
    newPath: '/v3/checkout',
    shape: 'v2',
  },
  'GET /orders': {
    target: 'old',
    oldPath: '/customer/read-orders',
    newPath: '/v3/orders',
    shape: 'v2',
  },
  'GET /orders/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/orders/:id',
    shape: 'v3-envelope',
  },

  // ---- Wishlist (legacy) ----
  'GET /wishlist': {
    target: 'old',
    oldPath: '/customer/wishlist',
    newPath: '/v3/wishlist',
    shape: 'v2',
  },
  'POST /wishlist': {
    target: 'old',
    oldPath: '/customer/addToWishlist',
    newPath: '/v3/wishlist',
    shape: 'v2',
  },
  'DELETE /wishlist/:productId': {
    target: 'old',
    oldPath: '/customer/removeFromWishlist/:productId',
    newPath: '/v3/wishlist/:productId',
    shape: 'v2',
  },

  // Chat, tickets, vendor self-service, admin orders/users/payments:
  // all live on legacy. Entries added when M3 / M4 lands.
};

/**
 * Resolve the actual URL for a given `{METHOD} {logical-path}` key.
 */
export function resolveUrl(
  routeKey: string,
  bases: { old: string; new: string },
  params?: Record<string, string | number>,
): string {
  const entry = ENDPOINT_ROUTING[routeKey];
  if (!entry) {
    throw new Error(`No routing entry for: ${routeKey}`);
  }

  const base = entry.target === 'new' ? bases.new : bases.old;
  let path = entry.target === 'new' ? entry.newPath : entry.oldPath;

  if (params) {
    for (const [key, value] of Object.entries(params)) {
      path = path.replace(`:${key}`, String(value));
    }
  }

  return `${base}${path}`;
}

/**
 * Resolve just the configuration entry (without URL building). Useful
 * for callers who need to know `shape` to decide how to parse the
 * response.
 */
export function resolveConfig(routeKey: string): EndpointConfig {
  const entry = ENDPOINT_ROUTING[routeKey];
  if (!entry) {
    throw new Error(`No routing entry for: ${routeKey}`);
  }
  return entry;
}
