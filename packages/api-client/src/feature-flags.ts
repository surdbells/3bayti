/**
 * Endpoint feature flags.
 *
 * Per Decision 10 (strangler-fig migration), each endpoint can be
 * routed to either the legacy PHP backend (`api.3bayti.ae/v2/*`) or
 * the new Slim 4 backend (`api-v3.3bayti.ae/v3/*`). Flipping a single
 * endpoint from `'old'` → `'new'` is a one-line change here; rolling
 * back is the same.
 *
 * As of M0.4 (this commit), every endpoint routes to `'old'`. M1
 * onwards flips them one at a time.
 *
 * This file deliberately uses literal strings rather than an enum so
 * a quick `git grep "/v3/auth/login"` finds where it's referenced
 * without going through type-system indirection.
 */

export type EndpointTarget = 'old' | 'new';

export interface EndpointConfig {
  /** Where this endpoint is currently served from. */
  target: EndpointTarget;
  /** Path on the OLD backend, including version prefix (e.g. '/users/login'). */
  oldPath: string;
  /** Path on the NEW backend, including version prefix (e.g. '/v3/auth/login'). */
  newPath: string;
}

/**
 * Endpoint registry. Key is `{METHOD} {logical-path}`. Logical paths
 * are stable (e.g. `/auth/login`); the actual URL is computed by the
 * client based on `target`.
 */
export const ENDPOINT_ROUTING: Record<string, EndpointConfig> = {
  // System
  'GET /health': {
    target: 'new', // /v3/health is M0.3, no legacy equivalent
    oldPath: '',
    newPath: '/v3/health',
  },

  // Catalog (already shipped on /v2/* — DO NOT flip until M2)
  'GET /products': {
    target: 'old',
    oldPath: '/v2/products',
    newPath: '/v3/products',
  },
  'GET /products/:slug': {
    target: 'old',
    oldPath: '/v2/products/:slug',
    newPath: '/v3/products/:slug',
  },
  'GET /categories': {
    target: 'old',
    oldPath: '/v2/categories',
    newPath: '/v3/categories',
  },
  'GET /categories/:slug': {
    target: 'old',
    oldPath: '/v2/categories/:slug',
    newPath: '/v3/categories/:slug',
  },
  'GET /vendors': {
    target: 'old',
    oldPath: '/v2/vendors',
    newPath: '/v3/vendors',
  },
  'GET /sitemap-data': {
    target: 'old',
    oldPath: '/v2/sitemap-data',
    newPath: '/v3/sitemap-data',
  },

  // Auth — all flip to 'new' in M1
  'POST /auth/login': {
    target: 'old',
    oldPath: '/users/login',
    newPath: '/v3/auth/login',
  },
  'POST /auth/register': {
    target: 'old',
    oldPath: '/users/register',
    newPath: '/v3/auth/register',
  },

  // Cart, checkout, orders — flip to 'new' in M3
  // (entries added when those endpoints are built)
};

/**
 * Resolve the actual URL for a given `{METHOD} {logical-path}` key.
 *
 * Returns the absolute URL (including base host) the client should
 * call. Path parameters in `:param` form are interpolated from
 * the optional `params` argument.
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
