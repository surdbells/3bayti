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
  'GET /categories/:slug': {
    target: 'new',
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
  'GET /sitemap-data': {
    target: 'new',
    oldPath: '/v2/sitemap-data',
    newPath: '/v3/sitemap-data',
    shape: 'raw',
  },

  // ---- Auth (M1 shipped - all on v3) ----
  'POST /auth/register': {
    target: 'new',
    oldPath: '/users/register',
    newPath: '/v3/auth/register',
    shape: 'v3-envelope',
  },
  'POST /auth/verify-phone': {
    target: 'new',
    oldPath: '/users/verifyPhone',
    newPath: '/v3/auth/verify-phone',
    shape: 'v3-envelope',
  },
  'POST /auth/login': {
    target: 'new',
    oldPath: '/users/login',
    newPath: '/v3/auth/login',
    shape: 'v3-envelope',
  },
  'POST /auth/refresh': {
    target: 'new',
    oldPath: '/users/refresh',
    newPath: '/v3/auth/refresh',
    shape: 'v3-envelope',
  },
  'POST /auth/logout': {
    target: 'new',
    oldPath: '/users/logout',
    newPath: '/v3/auth/logout',
    shape: 'v3-envelope',
  },
  'POST /auth/forgot-password': {
    target: 'new',
    oldPath: '/users/forgotPassword',
    newPath: '/v3/auth/forgot-password',
    shape: 'v3-envelope',
  },
  'POST /auth/reset-password': {
    target: 'new',
    oldPath: '/users/resetPassword',
    newPath: '/v3/auth/reset-password',
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
  'PUT /me/password': {
    target: 'new',
    oldPath: '/users/changePassword',
    newPath: '/v3/me/password',
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
    oldPath: '/users/addAddress',
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
    oldPath: '/customer/cart',
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
    target: 'old',
    oldPath: '/customer/updateCartItem/:id',
    newPath: '/v3/cart/items/:id',
    shape: 'v2',
  },
  'DELETE /cart/items/:id': {
    target: 'old',
    oldPath: '/customer/removeFromCart/:id',
    newPath: '/v3/cart/items/:id',
    shape: 'v2',
  },
  'POST /checkout': {
    target: 'old',
    oldPath: '/customer/checkout',
    newPath: '/v3/checkout',
    shape: 'v2',
  },
  'GET /orders': {
    target: 'old',
    oldPath: '/customer/orders',
    newPath: '/v3/orders',
    shape: 'v2',
  },
  'GET /orders/:id': {
    target: 'old',
    oldPath: '/customer/order/:id',
    newPath: '/v3/orders/:id',
    shape: 'v2',
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
