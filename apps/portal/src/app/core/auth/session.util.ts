/**
 * Portal session utilities.
 *
 * Single source of truth for reading the decoded session from sessionStorage.
 * Components historically re-decoded inline; guards (and anything else) should
 * use these helpers so the shape and validation live in one place.
 *
 * The session is stored base64-encoded under the 'SESSION' key by
 * PortalAuthService. It carries role flags used for authorization.
 */

export interface PortalSession {
  id: number;
  token: string;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  is_2fa?: boolean;
  is_active: boolean;
  is_admin: boolean;
  is_vendor: boolean;
  is_customer?: boolean;
  is_finance?: boolean;
  is_support?: boolean;
  is_phone_verified?: boolean;
  /** Provisioned with a temporary password — must change it before using the app. */
  must_change_password?: boolean;
}

const SESSION_KEY = 'SESSION';

function decodeBase64<T>(base64: string): T | null {
  try {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) bytes[i] = binary.charCodeAt(i);
    const json = new TextDecoder().decode(bytes);
    return JSON.parse(json) as T;
  } catch {
    return null;
  }
}

/** Read and decode the current session, or null if absent/corrupt. */
export function readSession(): PortalSession | null {
  if (typeof sessionStorage === 'undefined') return null; // SSR safety
  const raw = sessionStorage.getItem(SESSION_KEY);
  if (!raw) return null;
  return decodeBase64<PortalSession>(raw);
}

/** True if there is a decodable session with a token and an active account. */
export function isAuthenticated(): boolean {
  const s = readSession();
  return !!s && !!s.token && s.is_active === true;
}

/** True if the session holds any admin-tier role (admin/finance/support). */
export function isAdminTier(s: PortalSession | null): boolean {
  return !!s && (s.is_admin === true || s.is_finance === true || s.is_support === true);
}

/** True if the session is a vendor. */
export function isVendor(s: PortalSession | null): boolean {
  return !!s && s.is_vendor === true;
}

/** The correct landing route for a session, by role. */
export function homeRouteFor(s: PortalSession | null): string {
  if (isAdminTier(s)) return '/backend';
  if (isVendor(s)) return '/account';
  return '/login';
}

/** Route the portal forces onto users who must replace a temporary password. */
export const CHANGE_PASSWORD_ROUTE = '/change-password';

/** True if the session holder must change a provisioned temporary password. */
export function mustChangePassword(s: PortalSession | null): boolean {
  return !!s && s.must_change_password === true;
}

/**
 * Merge a patch into the stored session and re-encode it (same
 * base64(UTF-8(JSON)) format PortalAuthService writes). No-op if there is no
 * session. `undefined` values are skipped so they never clobber existing
 * fields. Loosely typed because the stored session is a superset of the read
 * shape (it also carries refresh_token + token expiries) — used to update the
 * rotated tokens and clear must_change_password after a forced change.
 */
export function patchSession(patch: Record<string, unknown>): void {
  if (typeof sessionStorage === 'undefined') return;
  const current = readSession();
  if (!current) return;
  const clean: Record<string, unknown> = {};
  for (const [k, v] of Object.entries(patch)) {
    if (v !== undefined) clean[k] = v;
  }
  const json = JSON.stringify({ ...current, ...clean });
  const bytes = new TextEncoder().encode(json);
  let binary = '';
  for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
  sessionStorage.setItem(SESSION_KEY, btoa(binary));
}

/** Clear the session (used on logout / invalid). */
export function clearSession(): void {
  if (typeof sessionStorage !== 'undefined') sessionStorage.removeItem(SESSION_KEY);
}
