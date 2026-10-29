import { HttpErrorResponse } from '@angular/common/http';

/**
 * How the portal reads auth failures, the client half of the session contract
 * the API, web and mobile apps share:
 *
 *  - POST /auth/refresh answering 401 + AUTH_REFRESH_TOKEN_INVALID is the ONE
 *    definitive "this session is over" signal (logged out, account deactivated,
 *    token expired/unknown). Only that ends a portal session. Anything else
 *    (network error, timeout, 403, 429, 5xx, 422, a malformed body, a 401
 *    without that code) is transient: keep the session and retry later.
 *  - On a normal endpoint, refresh-and-retry only on a 401 whose code says the
 *    access token is stale or missing (or that carries no code at all). Any
 *    other 401 (wrong current password, wrong OTP, ...) is a business error the
 *    caller handles, not an expired session.
 */

/** The API's definitive "this session is over" refresh error code. */
export const REFRESH_TOKEN_INVALID = 'AUTH_REFRESH_TOKEN_INVALID';

/** Thrown by the refresh path when the tab holds no refresh token at all. */
export const NO_REFRESH_TOKEN = 'no_refresh_token';

/**
 * Thrown by the refresh path when the tab switched to another session
 * (impersonation exit, sign-out + re-login) while a refresh was in flight.
 * Its outcome belonged to the old session, so it is neither stored nor allowed
 * to end the new one. Transient: the next refresh checks the current session.
 */
export const SESSION_CHANGED = 'session_changed';

/** 401 codes meaning "the access token needs refreshing" (AuthMiddleware). */
const ACCESS_TOKEN_CODES: ReadonlySet<string> = new Set(['AUTH_INVALID_TOKEN', 'AUTH_MISSING_TOKEN']);

/** The `{ error: { code } }` code of a failed v3 call, or null when absent. */
export function apiErrorCode(err: unknown): string | null {
  if (!(err instanceof HttpErrorResponse)) return null;
  const code = err.error?.error?.code;
  return typeof code === 'string' && code !== '' ? code : null;
}

/**
 * True when a refresh failure means the session is over for good: the API's
 * definitive answer, or no refresh token stored at all (nothing to retry
 * with). Every other failure is transient and must keep the session.
 */
export function isSessionOver(err: unknown): boolean {
  if (err instanceof HttpErrorResponse) {
    return err.status === 401 && apiErrorCode(err) === REFRESH_TOKEN_INVALID;
  }
  return err instanceof Error && err.message === NO_REFRESH_TOKEN;
}

/** True when a refresh's outcome was dropped because the tab changed sessions. */
export function isSessionChanged(err: unknown): boolean {
  return err instanceof Error && err.message === SESSION_CHANGED;
}

/** True when a 401 from a normal endpoint means "refresh the access token". */
export function needsTokenRefresh(err: unknown): boolean {
  if (!(err instanceof HttpErrorResponse) || err.status !== 401) return false;
  const code = apiErrorCode(err);
  return code === null || ACCESS_TOKEN_CODES.has(code);
}
