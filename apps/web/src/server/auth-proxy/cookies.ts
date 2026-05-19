import type { AuthProxyConfig } from './config';

/**
 * Cookie helpers for the auth-proxy.
 *
 * Pure functions — no Express, no fetch — so each is trivially unit-
 * testable. The route handlers compose these to write Set-Cookie
 * headers and to parse incoming Cookie headers.
 *
 * We don't depend on a cookie library (cookie / cookie-parser) because:
 *   1. Express 5's req.headers.cookie is a string — fine to parse
 *      ourselves with one pass and one split.
 *   2. The single cookie we care about (bayti_refresh) has a known,
 *      simple shape — no fancy serialisation.
 *   3. Fewer deps → smaller serverless cold start.
 */

/**
 * Build a Set-Cookie header value for the refresh token.
 *
 * Attributes:
 *   - HttpOnly             — not readable by document.cookie / JS
 *   - Secure (if config)   — HTTPS only
 *   - SameSite=<lax/strict/none>
 *   - Path=/auth-proxy     — only sent on auth-proxy paths
 *   - Max-Age=<seconds>    — long-lived (7 days)
 *
 * Idempotent: same input → same output. No randomness.
 */
export function buildRefreshCookie(token: string, config: AuthProxyConfig): string {
  /* Defensive: the refresh token must not contain characters that
     would break Set-Cookie. JWT-shaped tokens are URL-safe base64 +
     dots, which are all cookie-safe. Reject anything else loudly
     rather than silently emitting a malformed Set-Cookie. */
  assertCookieSafe(token);

  const parts = [
    `${config.refreshCookieName}=${token}`,
    'HttpOnly',
    `Path=${config.refreshCookiePath}`,
    `Max-Age=${config.refreshCookieMaxAgeSeconds}`,
    `SameSite=${capitaliseSameSite(config.cookieSameSite)}`,
  ];

  if (config.cookieSecure) {
    parts.push('Secure');
  }

  /* SameSite=None REQUIRES Secure. If a misconfig pairs them, fix it
     here rather than silently emitting a browser-rejected cookie. */
  if (config.cookieSameSite === 'none' && !config.cookieSecure) {
    /* Don't fall through silently — but throwing in the cookie
       builder would crash login. Append Secure defensively and rely
       on a one-time runtime warning. */
    parts.push('Secure');
  }

  return parts.join('; ');
}

/**
 * Build a Set-Cookie value that EXPIRES the refresh cookie.
 *
 * Used on logout and on refresh failure. Sets Max-Age=0 with the same
 * Path / SameSite / Secure attributes so the browser sees this as a
 * delete instruction (RFC 6265 §5.3 step 11).
 *
 * Cookie attributes MUST match those of the cookie being deleted, or
 * the browser may not match. We re-use buildRefreshCookie's attribute
 * shape with an empty value and Max-Age=0.
 */
export function buildClearRefreshCookie(config: AuthProxyConfig): string {
  const parts = [
    `${config.refreshCookieName}=`,
    'HttpOnly',
    `Path=${config.refreshCookiePath}`,
    'Max-Age=0',
    `SameSite=${capitaliseSameSite(config.cookieSameSite)}`,
  ];

  if (config.cookieSecure || config.cookieSameSite === 'none') {
    parts.push('Secure');
  }

  return parts.join('; ');
}

/**
 * Parse the refresh cookie out of an incoming Cookie header.
 *
 * Returns null if the cookie is absent or the header is missing.
 *
 * Handles:
 *   - Multiple cookies separated by ';' or '; '
 *   - Whitespace around keys and values
 *   - Values with '=' in them (split on first '=' only)
 *
 * Does NOT URL-decode the value: JWT tokens use URL-safe base64 and
 * don't need decoding; introducing decodeURIComponent here would
 * silently mutate tokens that happen to contain percent characters.
 */
export function parseRefreshCookie(
  cookieHeader: string | undefined | null,
  config: AuthProxyConfig,
): string | null {
  if (cookieHeader === undefined || cookieHeader === null || cookieHeader === '') {
    return null;
  }

  const parts = cookieHeader.split(/;\s*/);
  for (const part of parts) {
    const eqIndex = part.indexOf('=');
    if (eqIndex < 0) continue;
    const key = part.substring(0, eqIndex).trim();
    if (key !== config.refreshCookieName) continue;
    const value = part.substring(eqIndex + 1).trim();
    return value === '' ? null : value;
  }
  return null;
}

/* ----------------------------------------------------------------
   Internals
   ---------------------------------------------------------------- */

/**
 * Validate a string is safe to put in a Set-Cookie value.
 *
 * RFC 6265 cookie-value allows ASCII excluding control chars, whitespace,
 * double-quote, comma, semicolon, backslash. JWTs only contain
 * [A-Za-z0-9_-] and '.', all of which pass.
 *
 * Throws Error rather than returns false because a caller-side bug
 * that produces a bad token is a programming error, not a runtime
 * branching case. Failing loudly here surfaces it during dev tests
 * rather than silently writing a malformed Set-Cookie that the
 * browser strips.
 */
function assertCookieSafe(value: string): void {
  /* Forbidden chars per RFC 6265 §4.1.1 cookie-octet:
     CTLs, whitespace, DQUOTE, comma, semicolon, backslash. */
  if (/[\x00-\x20",;\\\x7F]/.test(value)) {
    throw new Error('[auth-proxy] refresh token contains characters unsafe for Set-Cookie');
  }
  /* Cookies have a practical 4 KB size limit per cookie; JWTs typically
     run 200-500 bytes. A token > 4 KB is almost certainly a bug. */
  if (value.length > 4096) {
    throw new Error('[auth-proxy] refresh token exceeds 4 KB; refusing to set cookie');
  }
}

function capitaliseSameSite(value: 'lax' | 'strict' | 'none'): 'Lax' | 'Strict' | 'None' {
  switch (value) {
    case 'lax':
      return 'Lax';
    case 'strict':
      return 'Strict';
    case 'none':
      return 'None';
  }
}
