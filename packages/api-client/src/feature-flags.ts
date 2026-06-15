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
 *   Cart / order   -> 'new' (M3.1.6 backend + M3.1.6i.2 mobile
 *                     rewrite complete; flipped in M3.1.6i.2-F
 *                     to route traffic to v3. Per-endpoint rollback
 *                     remains via target: 'old')
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
  /**
   * Additional legacy paths that should resolve to THIS same v3 endpoint.
   * Used when several distinct legacy URLs map onto one v3 route (e.g. the
   * old `/customer/IncreaseItem` + `/customer/decreaseItem` both becoming
   * `PATCH /v3/cart/items/:id`). The mobile URL→routeKey resolver maps each
   * alias to this entry's routeKey exactly as it does `oldPath`, so the same
   * request/response transforms apply. Has no effect on the web/portal client.
   */
  oldPathAliases?: string[];
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
  // M3.2.W.1 — per-product recommendations ("you may also like").
  // Net-new v3-only catalog read (no legacy equivalent). Public.
  'GET /products/:slug/recommendations': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/products/:slug/recommendations',
    shape: 'v3-envelope',
  },
  // M3.2.W.1 — personalized "for you" recommendations (authenticated).
  'GET /me/recommendations': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/recommendations',
    shape: 'v3-envelope',
  },
  // M3.2.W.2 — faceted search counts for the catalog listing.
  // Same query vocabulary as GET /products plus sizes[]/colors[].
  'GET /products/facets': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/products/facets',
    shape: 'v3-envelope',
  },
  'GET /categories': {
    target: 'new',
    oldPath: '/v2/categories',
    newPath: '/v3/categories',
    shape: 'v3-envelope',
  },
  // Category detail with embedded products + meta. M3.2.X.3 augmented
  // the v3 endpoint to match the legacy v2 shape exactly:
  //   - data: CategoryDetail (publicShape ∪ {image, icon_name,
  //     product_count, children, products[20]})
  //   - meta: { total_products, page_size }
  // Apps/web's `as unknown as CategoryDetailEnvelope` cast on the
  // RoutedHttpClient return is now safe at runtime (still kept
  // because RoutedHttpClient.get's typed shape doesn't carry the
  // CategoryDetailMeta variant — typed-envelope refactor is M3.2.Z
  // scope).
  'GET /categories/:slug': {
    target: 'new',
    oldPath: '/v2/categories/:slug',
    newPath: '/v3/categories/:slug',
    shape: 'v3-envelope',
  },
  'GET /vendors': {
    target: 'new',
    oldPath: '/customer/vendors_list',
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
  // Designer Spotlight strip on the home page. M3.2.X.2 shipped
  // the v3 endpoint (POST /v3/featured-vendors with the embedded-
  // products + rating aggregate shape that matches apps/web's
  // FeaturedVendor interface). Flipped target='old' → 'new' as the
  // final sub-phase (M3.2.X.2-E).
  //
  // The legacy /v2/featured-vendors was returning 500 in production;
  // the v3 endpoint replaces it with a clean shape and admin-managed
  // curation via is_featured on the Vendor entity.
  'GET /featured-vendors': {
    target: 'new',
    oldPath: '/v2/featured-vendors',
    newPath: '/v3/featured-vendors',
    // Both v2 and v3 use the same `{data:[...]}` shape for this
    // endpoint (no top-level response_code/status wrapper), so
    // 'v3-envelope' is correct on either path.
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
  // Endpoints with active mobile consumers (14 total — all flipped
  // to v3: 10 in M3.1.5, +4 in M3.1.5.5h):
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
  // M3.2.X.1-C — Best sellers. Initially target='old' for the 7-day
  // shadow window (per locked Q-Shadow = A). Flips to 'new' in
  // M3.2.X.1-C-FLIP after operator confirms clean delta logs.
  //
  // Both endpoints route to /v3/products with sort=best_seller per
  // locked Q-Listing = B3 (single endpoint serves both call sites
  // via different limit/offset).
  //
  // Backend (M3.2.X.1-A 86454d3): 'best_seller' = units sold in the
  // last 30 days across paid/fulfilling/shipped/delivered orders.
  'GET /mobile/best-sellers': {
    target: 'new',
    oldPath: '/customer/best_sellers',
    newPath: '/v3/products',
    shape: 'v3-envelope',
  },
  'GET /mobile/best-sellers-listing': {
    target: 'new',
    oldPath: '/customer/best_sellers_listing',
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
    target: 'new',
    oldPath: '/customer/category_listing',
    newPath: '/v3/products',
    shape: 'v3-envelope',
  },
  'GET /mobile/single-product': {
    target: 'new',
    oldPath: '/customer/single_product',
    newPath: '/v3/products/by-legacy-id/:id',
    shape: 'v3-envelope',
  },
  // utility/singleProduct is a separate legacy URL but resolves to the
  // same v3 endpoint. Distinct routeKey + entry so the resolver can
  // map the URL; the request transform is identical (same body shape
  // {product: <id>}) so M3.1.5c reuses the same extractor function.
  'GET /mobile/single-product-utility': {
    target: 'new',
    oldPath: '/utility/singleProduct',
    newPath: '/v3/products/by-legacy-id/:id',
    shape: 'v3-envelope',
  },
  'GET /mobile/vendors-products': {
    target: 'new',
    oldPath: '/customer/vendors_products',
    newPath: '/v3/vendors/by-legacy-id/:id/products',
    shape: 'v3-envelope',
  },
  'GET /mobile/read-vendor': {
    target: 'new',
    oldPath: '/customer/read-vendor',
    newPath: '/v3/vendors/by-legacy-id/:id',
    shape: 'v3-envelope',
  },
  'GET /mobile/store-latest': {
    target: 'new',
    oldPath: '/customer/store_latest',
    newPath: '/v3/vendors/by-legacy-id/:id/products',
    shape: 'v3-envelope',
  },

  // M3.1.5.5 — Catalog endpoints deferred from M3.1.5 because v3
  // didn't yet have the corresponding backends. M3.1.5.5a-f built
  // them; these 4 entries were initially target='old' and flipped
  // to 'new' in M3.1.5.5h after device test. best_sellers +
  // best_sellers_listing remain deferred to post-M3.1.6 (need
  // order_items schema).
  'GET /mobile/search': {
    target: 'new',
    oldPath: '/customer/search',
    newPath: '/v3/products',
    shape: 'v3-envelope',
  },
  'GET /mobile/store-labels': {
    target: 'new',
    oldPath: '/customer/read_vendor_collection',
    newPath: '/v3/vendors/by-legacy-id/:id/labels',
    shape: 'v3-envelope',
  },
  'GET /mobile/products-by-labels': {
    target: 'new',
    oldPath: '/customer/products_by_labels',
    newPath: '/v3/products',
    shape: 'v3-envelope',
  },
  'GET /mobile/styles-list': {
    target: 'new',
    oldPath: '/customer/styles_list',
    newPath: '/v3/styles',
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
    oldPath: '/customer/settings/read-profile',
    newPath: '/v3/me/profile',
    shape: 'v3-envelope',
  },
  // v3 customer Follow (Group B / B3). Vendor id moves from the legacy
  // body (store_id) into the v3 path via the mutation request transforms.
  'POST /following/:vendorId': {
    target: 'new',
    oldPath: '/customer/follow',
    newPath: '/v3/me/following/:vendorId',
    shape: 'v3-envelope',
  },
  'DELETE /following/:vendorId': {
    target: 'new',
    oldPath: '/customer/unfollow',
    newPath: '/v3/me/following/:vendorId',
    shape: 'v3-envelope',
  },
  // v3 customer support tickets (Group B / B2). Owner-scoped /v3/me/tickets.
  // create_ticket body {subject, message, store} -> {subject, message,
  // vendor_id}; the message endpoints move the ticket id into the path.
  'POST /me/tickets': {
    target: 'new',
    oldPath: '/customer/create_ticket',
    newPath: '/v3/me/tickets',
    shape: 'v3-envelope',
  },
  'GET /me/tickets': {
    target: 'new',
    oldPath: '/customer/read_ticket',
    newPath: '/v3/me/tickets',
    shape: 'v3-envelope',
  },
  'GET /me/tickets/:id/messages': {
    target: 'new',
    oldPath: '/customer/read-ticket-messages',
    newPath: '/v3/me/tickets/:id/messages',
    shape: 'v3-envelope',
  },
  'POST /me/tickets/:id/messages': {
    target: 'new',
    oldPath: '/customer/send-ticket-message',
    newPath: '/v3/me/tickets/:id/messages',
    shape: 'v3-envelope',
  },
  // v3 customer reviews (Group B / B1). add-review is store-scoped
  // (vendor id in body); store-reviews + settings/store-reviews both
  // list a vendor's approved reviews (one v3 endpoint via alias).
  'POST /vendors/:vendorId/reviews': {
    target: 'new',
    oldPath: '/customer/add-review',
    newPath: '/v3/vendors/:vendorId/reviews',
    shape: 'v3-envelope',
  },
  'POST /reviews/:id/helpful': {
    target: 'new',
    oldPath: '/customer/helpful',
    newPath: '/v3/reviews/:id/helpful',
    shape: 'v3-envelope',
  },
  'GET /vendors/:vendorId/reviews': {
    target: 'new',
    oldPath: '/customer/store-reviews',
    oldPathAliases: ['/customer/settings/store-reviews'],
    newPath: '/v3/vendors/:vendorId/reviews',
    shape: 'v3-envelope',
  },
  'GET /me/reviews': {
    target: 'new',
    oldPath: '/customer/settings/read-reviews',
    newPath: '/v3/me/reviews',
    shape: 'v3-envelope',
  },
  'DELETE /me/reviews/:id': {
    target: 'new',
    oldPath: '/customer/settings/delete-review',
    newPath: '/v3/me/reviews/:id',
    shape: 'v3-envelope',
  },
  'PATCH /me/profile': {
    target: 'new',
    oldPath: '/users/updateProfile',
    newPath: '/v3/me/profile',
    shape: 'v3-envelope',
    // M3.2.Y.5-A — method corrected PUT → PATCH to match the v3
    // controller's actual registration in apps/api/config/routes.php
    // ($group->patch('/profile', ...)). A live probe confirmed PATCH
    // returns 401 (route + method accepted, auth missing) while PUT
    // returns 500 (method-not-allowed). The v3 UpdateProfileController
    // implements RFC 7396 JSON Merge Patch semantics, so PATCH is also
    // the correct verb. No web caller used the old PUT entry yet
    // (Y.5-B is the first profile-update consumer), so this is a safe
    // pre-consumer correction.
  },
  // Workstream C — profile picture upload. New v3-only endpoint (no legacy
  // route), so oldPath is empty; target is always 'new'.
  'POST /me/avatar': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/avatar',
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
  'GET /me/addresses/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/addresses/:id',
    shape: 'v3-envelope',
  },
  'PATCH /me/addresses/:id/default': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/addresses/:id/default',
    shape: 'v3-envelope',
  },
  'GET /me/measurements': {
    target: 'new',
    oldPath: '/customer/settings/measurement/read-measurement',
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
  // Customer default-measurement update (Group A). Mobile POSTs flat
  // measurement fields; v3 PUTs { values: {...} } to the default set.
  'PUT /me/measurements/default': {
    target: 'new',
    oldPath: '/customer/settings/measurement/update-measurement',
    newPath: '/v3/me/measurements/default',
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

  // ---- Cart, checkout, orders (M3.1.6 backend + M3.1.6i.2 mobile, FLIPPED) ----
  //
  // All entries flipped target='old' -> 'new' in M3.1.6i.2-F after
  // mobile call-site rewrite shipped. Mobile pages support dual-shape
  // responses (legacy AND v3), so per-endpoint rollback to 'old'
  // remains safe — flip any single line back to test against legacy
  // if a regression appears.
  //
  // Strangler-fig invariant preserved: each entry is independently
  // rollback-able. No cross-endpoint coupling.
  'GET /cart': {
    target: 'new',
    oldPath: '/customer/read-cart',
    newPath: '/v3/cart',
    shape: 'v2',
  },
  'POST /cart/items': {
    target: 'new',
    oldPath: '/customer/addToCart',
    newPath: '/v3/cart/items',
    shape: 'v2',
  },
  // M3.1.6d backend uses PATCH (absolute qty, idempotent) — replaces
  // legacy's POST /customer/IncreaseItem + /customer/decreaseItem
  // delta endpoints. The previous PUT mapping here was inconsistent
  // with the v3 backend; corrected to PATCH and flipped back to 'old'
  // pending mobile call-site rewrite.
  'PATCH /cart/items/:id': {
    target: 'new',
    oldPath: '/customer/IncreaseItem',
    oldPathAliases: ['/customer/decreaseItem'],
    newPath: '/v3/cart/items/:id',
    shape: 'v2',
  },
  'DELETE /cart/items/:id': {
    target: 'new',
    oldPath: '/customer/removeFromCart',
    newPath: '/v3/cart/items/:id',
    shape: 'v2',
  },
  // M3.1.6d: new endpoint for guest-cart merge on sign-in (Q7=B).
  // No legacy equivalent — first-class v3 capability. Stays target='old'
  // until M3.1.6j1 because the post-login flow that calls this hasn't
  // been wired yet on mobile.
  'POST /cart/merge': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/cart/merge',
    shape: 'v3-envelope',
  },
  // M3.1.6f1 backend uses POST /v3/checkout/initiate (more specific
  // than the previous /v3/checkout entry). Corrected; flipped back to
  // 'old' pending mobile rewrite.
  'POST /checkout/initiate': {
    target: 'new',
    oldPath: '/customer/payment/initiate_payment',
    newPath: '/v3/checkout/initiate',
    shape: 'v2',
  },
  // M3.1.6f2: status polling. Mobile webview lands on Noon's redirect
  // URL after hosted checkout; the path-based return URL gives mobile
  // the order_reference; mobile then polls this endpoint until
  // status.terminal === true. Reads ONLY v3's local Order state — does
  // NOT call Noon's GET_ORDER (rate-limit ban risk per recon).
  'GET /checkout/status/:order_reference': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/checkout/status/:order_reference',
    shape: 'v3-envelope',
  },
  // M3.1.6i.2-C: oldPath changed from '/customer/read-orders' to
  // '/customer/read_orders_listing' to match what my-orders.page
  // actually calls (the paginated infinite-scroll view, which is
  // the one we want on v3 because it uses limit/offset that v3
  // supports natively).
  //
  // The older 'customer/read-orders' endpoint is still used by
  // customer/orders/orders.page.ts (a simpler list view); that
  // page continues on legacy until a separate sub-phase migrates
  // it. If it should also move to v3, add a sibling routing entry
  // with a different routeKey (e.g. 'GET /orders/simple') so the
  // index can disambiguate.
  'GET /orders': {
    target: 'new',
    oldPath: '/customer/read_orders_listing',
    oldPathAliases: ['/customer/read-customer-orders'],
    newPath: '/v3/orders',
    shape: 'v2',
  },
  'GET /orders/:id': {
    target: 'new',
    oldPath: '/customer/read-order-details',
    newPath: '/v3/orders/:id',
    shape: 'v2',
  },
  // M3.1.7-F — customer self-serve cancel (pending_payment only)
  'POST /orders/:id/cancel': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/orders/:id/cancel',
    shape: 'v3-envelope',
  },
  // M3.1.7-C — vendor order surface (mobile reads in Phase I)
  'GET /vendor/orders': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/vendor/orders',
    shape: 'v3-envelope',
  },
  'GET /vendor/orders/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/vendor/orders/:id',
    shape: 'v3-envelope',
  },
  'GET /vendor/orders/:id/timeline': {
    target: 'new', oldPath: '/vendors/orders/getOrderById', newPath: '/v3/vendor/orders/:id/timeline', shape: 'v3-envelope',
  },

  'PATCH /vendor/orders/:orderId/items/:itemId/status': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/vendor/orders/:orderId/items/:itemId/status',
    shape: 'v3-envelope',
  },
  // Noon webhook receiver — server-to-server, NEVER called by mobile.
  // Listed here for routing-table completeness only; mobile clients
  // never resolve this key. Adapter would refuse to call it
  // (target='old' but oldPath is empty, would fall through to legacy
  // and 404 there). The endpoint exists at api-v3.3bayti.ae/v3/payment
  // /webhook/noon for Noon's direct POSTs.
  'POST /payment/webhook/noon': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/payment/webhook/noon',
    shape: 'v3-envelope',
  },

  // ---- Wishlist (legacy) ----
  'GET /wishlist': {
    target: 'new',
    oldPath: '/customer/wishlist',
    newPath: '/v3/me/wishlist',
    shape: 'v3-envelope',
  },
  'POST /wishlist': {
    target: 'new',
    oldPath: '/customer/addToWishlist',
    newPath: '/v3/me/wishlist',
    shape: 'v3-envelope',
  },
  'DELETE /wishlist/:productId': {
    target: 'new',
    oldPath: '/customer/removeFromWishlist/:productId',
    newPath: '/v3/me/wishlist/:productId',
    shape: 'v3-envelope',
  },

  // ---- Wishlist v3 (label-aware; M3.2.Y.6 + Z.3) ----
  // These target the new /v3/me/wishlist surface directly (used by the
  // mobile wishlist migration in Z.3-Mobile via *_v3 adapter calls).
  'GET /me/wishlist': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/wishlist',
    shape: 'v3-envelope',
  },
  'POST /me/wishlist': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/wishlist',
    shape: 'v3-envelope',
  },
  'PATCH /me/wishlist/:productId': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/wishlist/:productId',
    shape: 'v3-envelope',
  },
  'DELETE /me/wishlist/:productId': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/wishlist/:productId',
    shape: 'v3-envelope',
  },
  'GET /me/wishlist/labels': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/wishlist/labels',
    shape: 'v3-envelope',
  },
  'POST /me/wishlist/labels': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/wishlist/labels',
    shape: 'v3-envelope',
  },
  'PATCH /me/wishlist/labels/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/wishlist/labels/:id',
    shape: 'v3-envelope',
  },
  'DELETE /me/wishlist/labels/:id': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/wishlist/labels/:id',
    shape: 'v3-envelope',
  },

  // M3.2.Z.5-A — push notification device tokens. The mobile app
  // registers its FCM token here on sign-in and deactivates it on
  // logout (see PushRegistrationService). Net-new v3-only routes.
  'POST /me/device-tokens': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/device-tokens',
    shape: 'v3-envelope',
  },
  'DELETE /me/device-tokens': {
    target: 'new',
    oldPath: '',
    newPath: '/v3/me/device-tokens',
    shape: 'v3-envelope',
  },


  // =======================================================================
  // M3.3 — Portal route keys (PortalCrudAdapter).
  // target='old' → legacy; flip to 'new' when the v3 endpoint is ready.
  // =======================================================================
  // Vendor surface
  'DELETE /vendor/coupons/:id': {
    target: 'new', oldPath: '/vendors/coupons/delete-coupon', newPath: '/v3/vendor/coupons/:id', shape: 'v3-envelope',
  },
  'DELETE /vendor/labels/:id': {
    target: 'new', oldPath: '/vendors/labels/delete-label', newPath: '/v3/vendor/labels/:id', shape: 'v3-envelope',
  },
  'DELETE /vendor/measurements/:id': {
    target: 'new', oldPath: '/vendors/measurement/delete-measurement', newPath: '/v3/vendor/measurements/:id', shape: 'v3-envelope',
  },
  'DELETE /vendor/products/:id': {
    target: 'new', oldPath: '/vendors/products/delete-product', newPath: '/v3/vendor/products/:id', shape: 'v3-envelope',
  },
  'GET /vendor/analytics': {
    target: 'new', oldPath: '/vendors/common/dashboard-activity', newPath: '/v3/vendor/analytics', shape: 'v3-envelope',
  },
  'GET /vendor/coupons': {
    target: 'new', oldPath: '/vendors/coupons/get-coupons', newPath: '/v3/vendor/coupons', shape: 'v3-envelope',
  },
  'GET /vendor/coupons/:id': {
    target: 'new', oldPath: '/vendors/coupons/get-coupon-by-id', newPath: '/v3/vendor/coupons/:id', shape: 'v3-envelope',
  },
  'GET /vendor/coupons/:id/analytics': {
    target: 'new', oldPath: '/vendors/coupons/coupon-analytics', newPath: '/v3/vendor/coupons/:id/analytics', shape: 'v3-envelope',
  },
  'GET /vendor/collections': {
    target: 'new', oldPath: '/admin/collections', newPath: '/v3/vendor/collections', shape: 'v3-envelope',
  },
  'GET /vendor/labels': {
    target: 'new', oldPath: '/vendors/labels/read-label', newPath: '/v3/vendor/labels', shape: 'v3-envelope',
  },
  'GET /vendor/measurements': {
    target: 'new', oldPath: '/vendors/measurement/get-measurements', newPath: '/v3/vendor/measurements', shape: 'v3-envelope',
  },
  'GET /vendor/measurements/:id': {
    target: 'new', oldPath: '/vendors/measurement/getMeasurementById', newPath: '/v3/vendor/measurements/:id', shape: 'v3-envelope',
  },
  'POST /vendor/messages/:id/read': {
    target: 'new', oldPath: '/vendors/common/mark_message', newPath: '/v3/vendor/messages/:id/read', shape: 'v3-envelope',
  },
  'GET /vendor/messages': {
    target: 'new', oldPath: '/admin/message-vendor', newPath: '/v3/vendor/messages', shape: 'v3-envelope',
  },
  // Order-scoped customer<->vendor chat (vendor side).
  'GET /vendor/chat/conversations': {
    target: 'new', oldPath: '/vendors/common/chat-conversations', newPath: '/v3/vendor/chat/conversations', shape: 'v3-envelope',
  },
  'GET /vendor/chat/unread-count': {
    target: 'new', oldPath: '/vendors/common/chat-unread', newPath: '/v3/vendor/chat/unread-count', shape: 'v3-envelope',
  },
  'GET /vendor/chat/conversations/:uuid/messages': {
    target: 'new', oldPath: '/vendors/common/chat-messages', newPath: '/v3/vendor/chat/conversations/:uuid/messages', shape: 'v3-envelope',
  },
  'POST /vendor/chat/conversations/:uuid/messages': {
    target: 'new', oldPath: '/vendors/common/chat-send', newPath: '/v3/vendor/chat/conversations/:uuid/messages', shape: 'v3-envelope',
  },
  'POST /vendor/chat/conversations/:uuid/read': {
    target: 'new', oldPath: '/vendors/common/chat-read', newPath: '/v3/vendor/chat/conversations/:uuid/read', shape: 'v3-envelope',
  },
  // Order-scoped customer<->vendor chat (customer side).
  'GET /chat/conversations': {
    target: 'new', oldPath: '/chat/get_conversation', newPath: '/v3/chat/conversations', shape: 'v3-envelope',
  },
  'GET /chat/unread-count': {
    target: 'new', oldPath: '/chat/get_unread_count', newPath: '/v3/chat/unread-count', shape: 'v3-envelope',
  },
  'GET /chat/conversations/:uuid/messages': {
    target: 'new', oldPath: '/chat/get_messages', newPath: '/v3/chat/conversations/:uuid/messages', shape: 'v3-envelope',
  },
  'POST /chat/conversations/:uuid/messages': {
    target: 'new', oldPath: '/chat/send_message', newPath: '/v3/chat/conversations/:uuid/messages', shape: 'v3-envelope',
  },
  'POST /chat/conversations/:uuid/read': {
    target: 'new', oldPath: '/chat/mark_read', newPath: '/v3/chat/conversations/:uuid/read', shape: 'v3-envelope',
  },
  'GET /vendor/dashboard': {
    target: 'new', oldPath: '/vendors/common/dashboard-summary', newPath: '/v3/vendor/dashboard', shape: 'v3-envelope',
  },
  'GET /vendor/metrics': {
    target: 'new', oldPath: '/vendors/common/dashboard-activity', newPath: '/v3/vendor/metrics', shape: 'v3-envelope',
  },
  'GET /vendor/notifications': {
    target: 'new', oldPath: '/vendors/common/notifications', newPath: '/v3/vendor/notifications', shape: 'v3-envelope',
  },
  'GET /vendor/onboarding/status': {
    target: 'new', oldPath: '/vendors/common/compliance', newPath: '/v3/vendor/onboarding/status', shape: 'v3-envelope',
  },
  'GET /vendor/products': {
    target: 'new', oldPath: '/vendors/products/get-products', newPath: '/v3/vendor/products', shape: 'v3-envelope',
  },
  'GET /vendor/products/:id/sales': {
    target: 'new', oldPath: '/vendors/products/product-sales', newPath: '/v3/vendor/products/:id/sales', shape: 'v3-envelope',
  },
  'GET /vendor/products/:id': {
    target: 'new', oldPath: '/vendors/products/getProductById', newPath: '/v3/vendor/products/:id', shape: 'v3-envelope',
  },
  'GET /vendor/returns': {
    target: 'new', oldPath: '/vendors/orders/get-return-orders', newPath: '/v3/vendor/returns', shape: 'v3-envelope',
  },
  'GET /vendor/returns/:id': {
    target: 'new', oldPath: '/vendors/orders/get-return-orders', newPath: '/v3/vendor/returns/:id', shape: 'v3-envelope',
  },
  'GET /vendor/reviews': {
    target: 'new', oldPath: '/vendors/products/get-products-reviews', newPath: '/v3/vendor/reviews', shape: 'v3-envelope',
  },
  'GET /vendor/store': {
    target: 'new', oldPath: '/vendors/settings/vendor-store', newPath: '/v3/vendor/store', shape: 'v3-envelope',
  },
  'GET /vendor/store/notifications': {
    target: 'new', oldPath: '/vendors/settings/vendor-store-notifications', newPath: '/v3/vendor/store/notifications', shape: 'v3-envelope',
  },
  'GET /vendor/store/payment': {
    target: 'new', oldPath: '/vendors/settings/vendor-store-payment', newPath: '/v3/vendor/store/payment', shape: 'v3-envelope',
  },
  'GET /vendor/store/tax': {
    target: 'new', oldPath: '/vendors/settings/vendor-store-tax', newPath: '/v3/vendor/store/tax', shape: 'v3-envelope',
  },
  'PATCH /vendor/compliance': {
    target: 'new', oldPath: '/vendors/settings/update-compliance', newPath: '/v3/vendor/compliance', shape: 'v3-envelope',
  },
  'GET /vendor/compliance': {
    target: 'new', oldPath: '/vendors/common/compliance', newPath: '/v3/vendor/compliance', shape: 'v3-envelope',
  },
  'PATCH /vendor/store': {
    target: 'new', oldPath: '/vendors/settings/update-vendor-store', newPath: '/v3/vendor/store', shape: 'v3-envelope',
  },
  'PATCH /vendor/store/notifications': {
    target: 'new', oldPath: '/vendors/settings/update-vendor-notifications', newPath: '/v3/vendor/store/notifications', shape: 'v3-envelope',
  },
  'PATCH /vendor/store/payment': {
    target: 'new', oldPath: '/vendors/settings/update-vendor-payment', newPath: '/v3/vendor/store/payment', shape: 'v3-envelope',
  },
  'PATCH /vendor/store/status': {
    target: 'new', oldPath: '/vendors/settings/switch-store-status', newPath: '/v3/vendor/store/status', shape: 'v3-envelope',
  },
  'PATCH /vendor/store/tax': {
    target: 'new', oldPath: '/vendors/settings/update-vendor-tax', newPath: '/v3/vendor/store/tax', shape: 'v3-envelope',
  },
  'POST /vendor/coupons': {
    target: 'new', oldPath: '/vendors/coupons/create-coupon', newPath: '/v3/vendor/coupons', shape: 'v3-envelope',
  },
  'POST /vendor/coupons/:id/toggle': {
    target: 'new', oldPath: '/vendors/coupons/toggle-coupon-status', newPath: '/v3/vendor/coupons/:id/toggle', shape: 'v3-envelope',
  },
  'POST /vendor/labels': {
    target: 'new', oldPath: '/vendors/labels/create-label', newPath: '/v3/vendor/labels', shape: 'v3-envelope',
  },
  'POST /vendor/measurements': {
    target: 'new', oldPath: '/vendors/measurement/create-measurement', newPath: '/v3/vendor/measurements', shape: 'v3-envelope',
  },
  'POST /vendor/messages': {
    target: 'new', oldPath: '/admin/message-vendor', newPath: '/v3/vendor/messages', shape: 'v3-envelope',
  },
  'POST /vendor/notifications/mark-read': {
    target: 'new', oldPath: '/vendors/common/mark_notifications', newPath: '/v3/vendor/notifications/mark-read', shape: 'v3-envelope',
  },
  'POST /vendor/onboarding/submit': {
    target: 'new', oldPath: '/vendors/common/compliance', newPath: '/v3/vendor/onboarding/submit', shape: 'v3-envelope',
  },
  'POST /vendor/products': {
    target: 'new', oldPath: '/vendors/products/create-product', newPath: '/v3/vendor/products', shape: 'v3-envelope',
  },
  'POST /vendor/returns/:id/confirm-receipt': {
    target: 'new', oldPath: '/vendors/orders/update-order-status', newPath: '/v3/vendor/returns/:id/confirm-receipt', shape: 'v3-envelope',
  },
  'PUT /vendor/coupons/:id': {
    target: 'new', oldPath: '/vendors/coupons/update-coupon', newPath: '/v3/vendor/coupons/:id', shape: 'v3-envelope',
  },
  'PUT /vendor/labels/:id': {
    target: 'new', oldPath: '/vendors/labels/update-label', newPath: '/v3/vendor/labels/:id', shape: 'v3-envelope',
  },
  'PUT /vendor/measurements/:id': {
    target: 'new', oldPath: '/vendors/measurement/update-measurement', newPath: '/v3/vendor/measurements/:id', shape: 'v3-envelope',
  },
  'PUT /vendor/products/:id': {
    target: 'new', oldPath: '/vendors/products/update-product', newPath: '/v3/vendor/products/:id', shape: 'v3-envelope',
  },
  // Admin surface
  'DELETE /admin/products/:id': {
    target: 'new', oldPath: '/vendors/products/delete-product', newPath: '/v3/admin/products/:id', shape: 'v3-envelope',
  },
  'GET /admin/collections': {
    target: 'new', oldPath: '/admin/collections/get-collection', newPath: '/v3/admin/collections', shape: 'v3-envelope',
  },
  'DELETE /admin/collections/:id': {
    target: 'new', oldPath: '/admin/collections/delete', newPath: '/v3/admin/collections/:id', shape: 'v3-envelope',
  },

  'GET /admin/collections/:id': {
    target: 'new', oldPath: '/admin/collections/read-collection', newPath: '/v3/admin/collections/:id', shape: 'v3-envelope',
  },
  'GET /admin/commissions': {
    target: 'new', oldPath: '/admin/common/commissions', newPath: '/v3/admin/commissions', shape: 'v3-envelope',
  },
  'GET /admin/returns': {
    target: 'new', oldPath: '/admin/common/get-returns', newPath: '/v3/admin/returns', shape: 'v3-envelope',
  },
  'POST /admin/notifications': {
    target: 'new', oldPath: '/admin/send_notifications', newPath: '/v3/admin/notifications', shape: 'v3-envelope',
  },
  'GET /admin/notifications': {
    target: 'new', oldPath: '/vendors/common/notifications', newPath: '/v3/admin/notifications', shape: 'v3-envelope',
  },
  'POST /admin/notifications/mark-read': {
    target: 'new', oldPath: '/vendors/common/mark_notifications', newPath: '/v3/admin/notifications/mark-read', shape: 'v3-envelope',
  },
  'GET /admin/customers': {
    target: 'new', oldPath: '/admin/common/get-customers', newPath: '/v3/admin/users?role=customer', shape: 'v3-envelope',
  },
  'GET /admin/orders': {
    target: 'new', oldPath: '/admin/common/get-store-orders', newPath: '/v3/admin/orders', shape: 'v3-envelope',
  },
  'GET /admin/orders/:id': {
    target: 'new', oldPath: '/admin/common/pluralById', newPath: '/v3/admin/orders/:id', shape: 'v3-envelope',
  },
  'GET /admin/orders/:id/timeline': {
    target: 'new', oldPath: '/admin/common/processingById', newPath: '/v3/admin/orders/:id/timeline', shape: 'v3-envelope',
  },
  'GET /admin/products': {
    target: 'new', oldPath: '/admin/common/products', newPath: '/v3/products', shape: 'v3-envelope',
  },
  'GET /admin/tickets': {
    target: 'new', oldPath: '/admin/common/tickets', newPath: '/v3/admin/tickets', shape: 'v3-envelope',
  },
  'GET /admin/tickets/:id': {
    target: 'new', oldPath: '/admin/common/ticket-by-id', newPath: '/v3/admin/tickets/:id', shape: 'v3-envelope',
  },

  'GET /admin/tickets/:id/messages': {
    target: 'new', oldPath: '/admin/common/ticket-messages', newPath: '/v3/admin/tickets/:id/messages', shape: 'v3-envelope',
  },
  'GET /admin/transactions': {
    target: 'new', oldPath: '/admin/common/transactions', newPath: '/v3/admin/transactions', shape: 'v3-envelope',
  },
  'GET /admin/users': {
    target: 'new', oldPath: '/admin/common/get-users', newPath: '/v3/admin/users', shape: 'v3-envelope',
  },
  'GET /admin/users/:id': {
    target: 'new', oldPath: '/admin/common/get-users', newPath: '/v3/admin/users/:id', shape: 'v3-envelope',
  },
  'GET /admin/vendor-metrics': {
    target: 'new', oldPath: '/admin/common/dashboard-activity', newPath: '/v3/admin/vendor-metrics', shape: 'v3-envelope',
  },
  'GET /admin/vendors/:id': {
    target: 'new', oldPath: '/admin/common/getSingleStore', newPath: '/v3/admin/vendors/:id', shape: 'v3-envelope',
  },
  'GET /admin/vendors/:id/analytics': {
    target: 'new', oldPath: '/admin/common/dashboard-activity', newPath: '/v3/admin/vendors/:id/analytics', shape: 'v3-envelope',
  },
  'GET /admin/vendors/:id/metrics': {
    target: 'new', oldPath: '/admin/common/dashboard-activity', newPath: '/v3/admin/vendors/:id/metrics', shape: 'v3-envelope',
  },
  // Platform-wide admin analytics dashboard
  'GET /admin/analytics': {
    target: 'new', oldPath: '/admin/common/dashboard', newPath: '/v3/admin/analytics', shape: 'v3-envelope',
  },
  // Gift cards
  'GET /gift-cards/themes': {
    target: 'new', oldPath: '/gift-cards/themes', newPath: '/v3/gift-cards/themes', shape: 'v3-envelope',
  },
  'GET /gift-cards/balance': {
    target: 'new', oldPath: '/gift-cards/balance', newPath: '/v3/gift-cards/balance', shape: 'v3-envelope',
  },
  'GET /gift-cards/mine': {
    target: 'new', oldPath: '/gift-cards/mine', newPath: '/v3/gift-cards/mine', shape: 'v3-envelope',
  },
  'POST /gift-cards/purchase': {
    target: 'new', oldPath: '/gift-cards/purchase', newPath: '/v3/gift-cards/purchase', shape: 'v3-envelope',
  },
  'POST /gift-cards/redeem': {
    target: 'new', oldPath: '/gift-cards/redeem', newPath: '/v3/gift-cards/redeem', shape: 'v3-envelope',
  },
  'POST /gift-cards/:id/activate': {
    target: 'new', oldPath: '/gift-cards/activate', newPath: '/v3/gift-cards/:id/activate', shape: 'v3-envelope',
  },
  'PATCH /admin/orders/:id/status': {
    target: 'new', oldPath: '/admin/common/processing', newPath: '/v3/admin/orders/:id/status', shape: 'v3-envelope',
  },
  'PATCH /admin/orders/:orderId/items/:itemId/status': {
    target: 'new', oldPath: '/admin/common/processing', newPath: '/v3/admin/orders/:orderId/items/:itemId/status', shape: 'v3-envelope',
  },
  'PATCH /admin/tickets/:id/priority': {
    target: 'new', oldPath: '/admin/common/ticket-priority', newPath: '/v3/admin/tickets/:id/priority', shape: 'v3-envelope',
  },
  'PATCH /admin/tickets/:id/status': {
    target: 'new', oldPath: '/admin/common/ticket-status', newPath: '/v3/admin/tickets/:id/status', shape: 'v3-envelope',
  },
  'POST /admin/collections': {
    target: 'new', oldPath: '/admin/collections/create-collection', newPath: '/v3/admin/collections', shape: 'v3-envelope',
  },
  'POST /admin/orders/:id/cancel': {
    target: 'new', oldPath: '/admin/common/processing', newPath: '/v3/admin/orders/:id/cancel', shape: 'v3-envelope',
  },
  'POST /admin/orders/:id/refund': {
    target: 'new', oldPath: '/admin/common/processing', newPath: '/v3/admin/orders/:id/refund', shape: 'v3-envelope',
  },
  'POST /admin/products': {
    target: 'new', oldPath: '/vendors/products/create-product', newPath: '/v3/admin/products', shape: 'v3-envelope',
  },
  'POST /admin/tickets/:id/messages': {
    target: 'new', oldPath: '/admin/common/send-ticket-message', newPath: '/v3/admin/tickets/:id/messages', shape: 'v3-envelope',
  },
  'POST /admin/users/:id/activate': {
    target: 'new', oldPath: '/admin/common/activate-customer', newPath: '/v3/admin/users/:id/activate', shape: 'v3-envelope',
  },
  'POST /admin/users/:id/deactivate': {
    target: 'new', oldPath: '/admin/common/deactivate-customer', newPath: '/v3/admin/users/:id/deactivate', shape: 'v3-envelope',
  },
  'POST /admin/users': {
    target: 'new', oldPath: '', newPath: '/v3/admin/users', shape: 'v3-envelope',
  },
  'PATCH /admin/users/:id/password': {
    target: 'new', oldPath: '', newPath: '/v3/admin/users/:id/password', shape: 'v3-envelope',
  },
  'POST /admin/vendors/:id/approve': {
    target: 'new', oldPath: '/admin/common/activate-store', newPath: '/v3/admin/vendors/:id/approve', shape: 'v3-envelope',
  },
  'POST /admin/vendors/:id/reactivate': {
    target: 'new', oldPath: '/admin/common/activate-store', newPath: '/v3/admin/vendors/:id/reactivate', shape: 'v3-envelope',
  },
  'POST /admin/vendors/:id/suspend': {
    target: 'new', oldPath: '/admin/common/deactivate-store', newPath: '/v3/admin/vendors/:id/suspend', shape: 'v3-envelope',
  },
  'GET /admin/vendors/:id/compliance': {
    target: 'new', oldPath: '/admin/common/getSingleStore', newPath: '/v3/admin/vendors/:id/compliance', shape: 'v3-envelope',
  },
  'POST /admin/vendors/:id/compliance/approve': {
    target: 'new', oldPath: '/admin/common/activate-store', newPath: '/v3/admin/vendors/:id/compliance/approve', shape: 'v3-envelope',
  },
  'POST /admin/vendors/:id/compliance/reject': {
    target: 'new', oldPath: '/admin/common/deactivate-store', newPath: '/v3/admin/vendors/:id/compliance/reject', shape: 'v3-envelope',
  },
  'PUT /admin/collections/:id': {
    target: 'new', oldPath: '/admin/collections/update-collection', newPath: '/v3/admin/collections/:id', shape: 'v3-envelope',
  },
  'PUT /admin/products/:id': {
    target: 'new', oldPath: '/vendors/products/update-product', newPath: '/v3/admin/products/:id', shape: 'v3-envelope',
  },
  // Utility / shared
  'GET /utility/categories': {
    target: 'new', oldPath: '/utility/category', newPath: '/v3/categories', shape: 'v3-envelope',
  },
  'GET /utility/collections': {
    target: 'new', oldPath: '/utility/collections', newPath: '/v3/admin/collections', shape: 'v3-envelope',
  },
  'GET /utility/stores': {
    target: 'new', oldPath: '/utility/stores', newPath: '/v3/vendors', shape: 'v3-envelope',
  },

  'GET /vendors/by-legacy-id/:id': {
    target: 'new', oldPath: '', newPath: '/v3/vendors/by-legacy-id/:id', shape: 'v3-envelope',
  },
  'GET /vendors/by-legacy-id/:id/products': {
    target: 'new', oldPath: '', newPath: '/v3/vendors/by-legacy-id/:id/products', shape: 'v3-envelope',
  },

  'GET /products/by-legacy-id/:id': {
    target: 'new', oldPath: '', newPath: '/v3/products/by-legacy-id/:id', shape: 'v3-envelope',
  },

  'POST /admin/vendors/:id/messages': {
    target: 'new', oldPath: '/admin/message-vendor', newPath: '/v3/admin/vendors/:id/messages', shape: 'v3-envelope',
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
