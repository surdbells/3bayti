/**
 * Auth-proxy configuration.
 *
 * The BFF proxy bridges the browser to the v3 API. All paths and
 * attributes that need to vary across environments live here so the
 * route handlers stay deterministic.
 *
 * Why a factory rather than constants
 * -----------------------------------
 * The factory pattern (createAuthProxyConfig) lets tests provide their
 * own values without monkey-patching module state. Production reads
 * process.env once at server boot.
 */

export interface AuthProxyConfig {
  /**
   * Base URL of the upstream v3 API. NO trailing slash. NO version
   * segment — paths under /auth-proxy already encode '/v3/auth/...'.
   *
   * Default: 'https://api-v3.3bayti.ae'.
   */
  readonly upstreamBaseUrl: string;

  /**
   * Name of the HttpOnly cookie that carries the refresh token.
   *
   * Default: 'bayti_refresh'. Distinct from 'bayti_locale' (Y.1-A).
   */
  readonly refreshCookieName: string;

  /**
   * Path scope for the refresh cookie. Browsers only attach the cookie
   * to requests under this path, limiting exposure to the BFF surface.
   *
   * Default: '/auth-proxy'.
   */
  readonly refreshCookiePath: string;

  /**
   * Lifetime of the refresh cookie in seconds. Matches the API's
   * refresh-token TTL (7 days) so cookie expiry and DB-level token
   * expiry align.
   *
   * Default: 7 * 24 * 60 * 60 = 604800.
   */
  readonly refreshCookieMaxAgeSeconds: number;

  /**
   * Whether to add the Secure attribute to the cookie. In production
   * this MUST be true (cookie only travels over HTTPS). In local dev
   * over plain HTTP it must be false or the cookie is silently
   * dropped by every browser.
   *
   * Default: process.env.NODE_ENV !== 'development'. Tests override.
   */
  readonly cookieSecure: boolean;

  /**
   * SameSite attribute for the refresh cookie.
   *
   * 'Lax' is the right default: the cookie is sent on top-level
   * navigations (so an SSR page render gets it) but NOT on cross-site
   * subresource requests (so a third-party site can't trigger a
   * refresh via an image tag or similar). 'Strict' would be safer
   * but blocks the cookie on common flows like clicking a deep-link
   * in an email — undesirable for ecommerce.
   *
   * Default: 'lax'.
   */
  readonly cookieSameSite: 'lax' | 'strict' | 'none';

  /**
   * Request timeout for upstream fetches in milliseconds.
   *
   * Default: 10_000 (10 seconds). The API's SMS provider can take
   * 3-5 seconds end-to-end; this leaves headroom but won't tie up
   * the SSR worker forever on a hung upstream.
   */
  readonly upstreamTimeoutMs: number;
}

/**
 * Default config used in production.
 */
export function createAuthProxyConfig(overrides: Partial<AuthProxyConfig> = {}): AuthProxyConfig {
  const env = (typeof process !== 'undefined' ? process.env : {}) as Record<string, string | undefined>;

  return {
    upstreamBaseUrl: overrides.upstreamBaseUrl ?? env['BAYTI_V3_API_BASE'] ?? 'https://api-v3.3bayti.ae',
    refreshCookieName: overrides.refreshCookieName ?? 'bayti_refresh',
    refreshCookiePath: overrides.refreshCookiePath ?? '/auth-proxy',
    refreshCookieMaxAgeSeconds: overrides.refreshCookieMaxAgeSeconds ?? 7 * 24 * 60 * 60,
    cookieSecure: overrides.cookieSecure ?? env['NODE_ENV'] !== 'development',
    cookieSameSite: overrides.cookieSameSite ?? 'lax',
    upstreamTimeoutMs: overrides.upstreamTimeoutMs ?? 10_000,
  };
}
