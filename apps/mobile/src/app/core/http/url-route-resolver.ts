/**
 * URL → routing-key resolver for the mobile network adapter.
 *
 * Why this exists
 * ---------------
 * Mobile call sites pass full legacy URLs to NetworkService:
 *
 *   networkService.post_request(body, 'https://api.3bayti.ae/users/login')
 *
 * The api-client's ENDPOINT_ROUTING table is keyed by logical
 * routing keys like `'POST /auth/login'`. To use that routing logic
 * from mobile, we need to translate the legacy URL → routing key:
 *
 *   'https://api.3bayti.ae/users/login' + POST  →  'POST /auth/login'
 *
 * Then the adapter looks up the entry in ENDPOINT_ROUTING and
 * either routes to v3 (when `target: 'new'`) or to legacy (when
 * `target: 'old'`).
 *
 * Single source of truth
 * ----------------------
 * The mapping is derived at module load time by reverse-indexing
 * ENDPOINT_ROUTING — we walk every entry, parse its `routeKey` into
 * `(method, path)`, and store the entry under its `oldPath`. This
 * means:
 *
 *   - No duplicated mapping table that can drift from ENDPOINT_ROUTING
 *   - Every routing-table edit automatically updates this lookup
 *   - Entries with empty `oldPath` (v3-only endpoints like
 *     `PATCH /me/password`) are NOT indexed — they have no legacy
 *     equivalent to translate FROM
 *
 * Same path, different methods
 * ----------------------------
 * Some legacy paths serve multiple HTTP methods. e.g. mobile's
 * `customer/read-cart` is GET, but if a future legacy endpoint
 * happened to share a path with a different method, both must
 * coexist. The lookup table is therefore keyed by oldPath AND
 * method:
 *
 *   Map<oldPath, { GET?: routeKey, POST?: routeKey, ... }>
 *
 * Base-URL stripping
 * ------------------
 * Mobile's GlobalComponent.baseURL ends with `/`, ENDPOINT_ROUTING
 * oldPath values begin with `/`, but the underlying expression
 * `baseURL + 'users/login'` produces an URL where the second
 * segment has NO leading slash. So we accept BOTH forms in the
 * stripping logic (with and without leading slash on the path
 * portion) for forward-compat with any callers that happen to
 * normalise URLs differently.
 *
 * What this module does NOT do
 * ----------------------------
 *   - Body/header translation (that's MobileNetworkAdapter's job)
 *   - Response envelope translation (also adapter)
 *   - Path-parameter substitution (this is URL → routeKey direction;
 *     params are substituted in the OPPOSITE direction by
 *     `resolveUrl` in api-client when issuing the final request)
 *   - Query string handling (queries are passed through as-is by
 *     the adapter; not part of the routing-key concept)
 */

import { ENDPOINT_ROUTING } from '@3bayti/api-client';

/**
 * HTTP method narrowed to the four that ENDPOINT_ROUTING keys use.
 */
export type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

/**
 * Per-method routing-key map for one legacy path.
 */
type MethodMap = Partial<Record<HttpMethod, string>>;

/**
 * Reverse index: legacy path (without base URL) → method-keyed routing keys.
 *
 * Populated once at module load time.
 */
const URL_TO_ROUTE_KEY: Map<string, MethodMap> = (() => {
  const map = new Map<string, MethodMap>();

  for (const [routeKey, entry] of Object.entries(ENDPOINT_ROUTING)) {
    // routeKey is `"METHOD /path"` — split once on the first space.
    const spaceIdx = routeKey.indexOf(' ');
    if (spaceIdx < 0) {
      // Shouldn't happen for well-formed entries; skip silently
      // rather than crash module load.
      continue;
    }
    const method = routeKey.slice(0, spaceIdx) as HttpMethod;
    const oldPath = entry.oldPath;

    // Skip v3-only endpoints — they have no legacy URL to translate from.
    if (!oldPath) {
      continue;
    }

    // Normalise: strip leading slash for storage. We'll match against
    // similarly-stripped inputs in resolveRouteKey.
    const normalisedPath = stripLeadingSlash(oldPath);

    const existing = map.get(normalisedPath) ?? {};
    existing[method] = routeKey;
    map.set(normalisedPath, existing);
  }

  return map;
})();

/**
 * Resolve a full legacy URL + method to a ENDPOINT_ROUTING key.
 *
 * Returns null when:
 *   - The URL has no matching entry in ENDPOINT_ROUTING (caller should
 *     fall back to direct legacy call)
 *   - The URL has an entry but for a different method (e.g. routed
 *     for GET but caller is sending POST)
 *
 * @param legacyUrl  Full URL the call site passed, e.g.
 *                   `'https://api.3bayti.ae/users/login'`
 * @param method     HTTP method the call site is using.
 * @param baseUrl    The legacy base URL to strip. Typically
 *                   `GlobalComponent.baseURL`. Both with and without
 *                   trailing slash are accepted.
 *
 * @example
 *   resolveRouteKey('https://api.3bayti.ae/users/login', 'POST',
 *                   'https://api.3bayti.ae/')
 *     → 'POST /auth/login'
 *
 *   resolveRouteKey('https://api.3bayti.ae/chat/get_messages', 'POST',
 *                   'https://api.3bayti.ae/')
 *     → null   // not in ENDPOINT_ROUTING; caller falls through to legacy
 */
export function resolveRouteKey(
  legacyUrl: string,
  method: HttpMethod,
  baseUrl: string,
): string | null {
  // Strip the base URL prefix to get the path. Tolerate base-URL
  // variations (with/without trailing slash).
  const baseNormalised = stripTrailingSlash(baseUrl);
  let path: string;
  if (legacyUrl.startsWith(baseNormalised)) {
    path = legacyUrl.slice(baseNormalised.length);
  } else {
    // URL is not on the configured base host. Could be a 3rd-party
    // URL (e.g. topex shipping API); never route those through v3.
    return null;
  }

  path = stripLeadingSlash(path);

  // Drop query string before lookup. ENDPOINT_ROUTING keys are paths
  // only; queries are handled separately at request time.
  const queryIdx = path.indexOf('?');
  if (queryIdx >= 0) {
    path = path.slice(0, queryIdx);
  }

  const methodMap = URL_TO_ROUTE_KEY.get(path);
  if (!methodMap) {
    return null;
  }

  return methodMap[method] ?? null;
}

/**
 * Test-only export. Internal callers should use `resolveRouteKey`.
 * Exposed so a future Jasmine spec can assert the index is built
 * correctly without re-implementing the build logic.
 */
export function _getIndexForTest(): ReadonlyMap<string, Readonly<MethodMap>> {
  return URL_TO_ROUTE_KEY;
}

/* ------ Helpers ----------------------------------------------- */

function stripLeadingSlash(s: string): string {
  return s.startsWith('/') ? s.slice(1) : s;
}

function stripTrailingSlash(s: string): string {
  return s.endsWith('/') ? s.slice(0, -1) : s;
}
