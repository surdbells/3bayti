import { HttpErrorResponse } from '@angular/common/http';

/**
 * Auth error classification, the client half of the session contract.
 *
 * The product rule is that a customer stays signed in until they tap
 * "Log out" themselves. A transient problem (network drop, timeout, 5xx,
 * 429, a proxy 403, a non-JSON body) must NEVER end the session, so the
 * SPA only treats ONE response as "this session is over":
 *
 *   status 401 AND error code AUTH_REFRESH_TOKEN_INVALID
 *
 * which the BFF (functions/auth-proxy) returns from /auth-proxy/me and
 * /auth-proxy/refresh when the API definitively rejected the refresh
 * cookie (explicit logout, deactivated account, expired / unknown token)
 * or when there is no cookie at all. Every other failure from those two
 * endpoints is transient: keep the session and retry later.
 *
 * On ordinary API endpoints a 401 only means "refresh and retry" when the
 * code says the ACCESS token is stale (AUTH_INVALID_TOKEN /
 * AUTH_MISSING_TOKEN) or is absent. Any other 401 code is a business
 * error (wrong current password, wrong OTP, ...) that belongs to the
 * caller untouched.
 */

/** The single definitive "session is over" code (BFF + API). */
export const AUTH_REFRESH_TOKEN_INVALID = 'AUTH_REFRESH_TOKEN_INVALID';

/** BFF code for "could not refresh right now, the session is intact". */
export const AUTH_REFRESH_UNAVAILABLE = 'AUTH_REFRESH_UNAVAILABLE';

/** 401 codes that mean the ACCESS token is stale, so refresh-and-retry. */
const STALE_ACCESS_TOKEN_CODES: ReadonlySet<string> = new Set([
  'AUTH_INVALID_TOKEN',
  'AUTH_MISSING_TOKEN',
]);

/**
 * Read the error code from an error body. The API's native envelope is
 * { error: { code, message } }; older BFF responses used a flat
 * { error_code } and some proxies a bare { code }. Returns undefined for
 * string / HTML / empty bodies.
 */
export function readAuthErrorCode(body: unknown): string | undefined {
  if (body === null || typeof body !== 'object') return undefined;
  const record = body as Record<string, unknown>;
  const nested = record['error'];
  if (nested !== null && typeof nested === 'object') {
    const code = (nested as Record<string, unknown>)['code'];
    if (typeof code === 'string') return code;
  }
  const flat = record['error_code'] ?? record['code'];
  return typeof flat === 'string' ? flat : undefined;
}

/**
 * True ONLY for the definitive session-ended rejection from the BFF:
 * HTTP 401 with code AUTH_REFRESH_TOKEN_INVALID. Everything else (status
 * 0, timeouts, 403, 429, 5xx, 422, non-JSON, a 401 without that code)
 * is transient and must keep the session.
 */
export function isSessionEndedError(err: unknown): boolean {
  return (
    err instanceof HttpErrorResponse &&
    err.status === 401 &&
    readAuthErrorCode(err.error) === AUTH_REFRESH_TOKEN_INVALID
  );
}

/**
 * True when an API 401 means "the access token is stale": the code is
 * AUTH_INVALID_TOKEN, AUTH_MISSING_TOKEN, or absent. A 401 with any other
 * code (AUTH_INVALID_CREDENTIALS, OTP_VERIFICATION_FAILED, ...) is a
 * business error and must reach the caller without a refresh.
 */
export function isStaleAccessToken401(err: HttpErrorResponse): boolean {
  if (err.status !== 401) return false;
  const code = readAuthErrorCode(err.error);
  return code === undefined || STALE_ACCESS_TOKEN_CODES.has(code);
}
