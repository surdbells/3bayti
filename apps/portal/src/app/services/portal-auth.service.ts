import { Injectable } from '@angular/core';
import { Observable, map } from 'rxjs';
import { PortalCrudAdapter } from './portal-crud-adapter';
import { GlobalComponent } from '../global-component';

/**
 * PortalAuthService, bridges v3 auth to the portal's SESSION format.
 *
 * The portal stores the authenticated user in sessionStorage('SESSION')
 * as a base64-encoded JSON object (the "user session") with flat boolean
 * role flags (`is_admin`, `is_vendor`, etc.). All 100+ components read
 * this format via `GlobalComponent.decodeBase64(sessionStorage.getItem('SESSION'))`.
 *
 * The v3 auth endpoints return a different shape:
 *   {
 *     access_token: string,
 *     refresh_token: string,
 *     user: {
 *       id, email, phone, first_name, last_name, locale,
 *       is_phone_verified, created_at,
 *       roles: ['vendor' | 'admin' | 'customer'],
 *     }
 *   }
 *
 * This service adapts that v3 shape to the legacy SESSION format so
 * EVERY component that reads `user_session.is_admin` / `.is_vendor` /
 * `.token` continues to work without modification. The access_token is
 * stored as `.token` in the session so `PortalCrudAdapter.getToken()`
 * picks it up automatically.
 *
 * M3.3.0-C: used by the login component only. As M3.3.1/M3.3.2 flip
 * features, the refresh_token can be used to build a silent-refresh
 * flow (deferred to M3.3.5).
 */

/** The legacy session shape every portal component already expects. */
export interface PortalSession {
  id: number;
  token: string;          // access token (Bearer)
  refresh_token: string;
  access_token_expires_at: string;   // ISO, drives the proactive refresh
  refresh_token_expires_at: string;  // ISO
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  avatar: string;
  id_front: string;
  id_back: string;
  is_2fa: boolean;
  is_active: boolean;
  is_admin: boolean;
  is_support: boolean;
  is_finance: boolean;
  _sub_admin: boolean;
  is_vendor: boolean;
  is_customer: boolean;
  /** Provisioned with a temporary password, force a change before use. */
  must_change_password: boolean;
  /** True when an admin is impersonating this (vendor) account. */
  impersonating?: boolean;
  /** Store/vendor name shown in the impersonation banner. */
  impersonated_name?: string;
}

@Injectable({ providedIn: 'root' })
export class PortalAuthService {
  constructor(private readonly adapter: PortalCrudAdapter) {}

  /**
   * Login via v3, normalize the response to the legacy SESSION format,
   * and persist it to sessionStorage. Returns the full session object.
   *
   * Caller is responsible for navigating after subscription.
   */
  login(email: string, password: string): Observable<{
    response_code: number;
    status: string;
    message: string;
    data: PortalSession;
  }> {
    return this.adapter.post_v3('POST /auth/login', { email, password }).pipe(
      map((res: any) => {
        // v3 API returns the payload directly at the top level, no
        // response_code wrapper. Success is indicated by access_token
        // presence (HTTP 200; Angular HttpClient throws on non-2xx).
        if (res?.access_token) {
          const session = this.mapToSession(res);
          const encoded = GlobalComponent.encodeBase64(session);
          sessionStorage.setItem('SESSION', encoded);
          return {
            response_code: 200,
            status: 'success',
            message: 'Login successful',
            data: session,
          };
        }
        // Unexpected 200 body without a token, treat as failure.
        return {
          response_code: 400,
          status: 'failed',
          message: res?.message ?? 'Login failed. Please try again.',
          data: null as any,
        };
      }),
    );
  }

  /**
   * Map the v3 auth response `data` block to the legacy PortalSession
   * shape. Preserves every boolean role flag so all existing guards
   * (`if (user_session.is_admin)` etc.) work without changes.
   */
  private mapToSession(v3Data: any): PortalSession {
    const user = v3Data?.user ?? {};
    const roles: string[] = Array.isArray(v3Data?.roles ?? user?.roles)
      ? (v3Data?.roles ?? user?.roles)
      : [];

    return {
      id: user.id ?? 0,
      token: v3Data?.access_token ?? '',
      refresh_token: v3Data?.refresh_token ?? '',
      // Expiries drive the proactive silent refresh + idle session manager.
      access_token_expires_at: v3Data?.access_token_expires_at ?? '',
      refresh_token_expires_at: v3Data?.refresh_token_expires_at ?? '',
      first_name: user.first_name ?? '',
      last_name: user.last_name ?? '',
      email: user.email ?? '',
      phone: user.phone ?? '',
      avatar: user.avatar?.url ?? user.avatar ?? '',
      id_front: '',
      id_back: '',
      is_2fa: false,
      is_active: true,   // v3 only returns a user if active
      is_admin: roles.includes('admin'),
      is_support: roles.includes('support'),
      is_finance: roles.includes('finance'),
      _sub_admin: roles.includes('sub_admin'),
      is_vendor: roles.includes('vendor'),
      is_customer: roles.includes('customer'),
      must_change_password: user.must_change_password === true,
    };
  }

  // ── Sign-out ───────────────────────────────────────────────────────
  /** Longest a caller about to hard-navigate waits for the logout call. */
  static readonly REVOKE_WAIT_MS = 1500;

  /**
   * End this tab's session server-side: POST /auth/logout with the stored
   * refresh token. The API revokes only that session's token family, never
   * the user's other devices, and answers 204 even for an already-dead token.
   *
   * Fire-and-forget: it never throws or rejects, bypasses refresh-and-retry
   * (auth routes are exempt), and a failure changes nothing locally. The
   * token is read synchronously, so callers clear storage right after.
   * Resolves once the call settles, or after REVOKE_WAIT_MS, for a caller
   * that is about to reload the page. See `revoke` for why no bearer is sent.
   */
  revokeCurrentSession(): Promise<void> {
    return this.revoke(this.currentSession()?.refresh_token);
  }

  /**
   * Mid-impersonation the admin's own session sits stashed in localStorage.
   * An explicit sign-out ends it too: revoke it server-side (fire-and-forget,
   * like `revokeCurrentSession`) and drop the stash, so signing out never
   * leaves a live admin credential behind in the browser.
   */
  discardStashedAdminSession(): void {
    const raw = localStorage.getItem(PortalAuthService.ADMIN_BACKUP_KEY);
    if (!raw) {
      return;
    }
    localStorage.removeItem(PortalAuthService.ADMIN_BACKUP_KEY);
    const refreshToken = this.decodeSession(raw)?.refresh_token;
    if (refreshToken && refreshToken !== this.currentSession()?.refresh_token) {
      void this.revoke(refreshToken);
    }
  }

  /**
   * Explicit "Sign out": end this session server-side, and the stashed admin
   * session when impersonating (fire-and-forget, see `revokeCurrentSession`),
   * then clear local state exactly as the sign-out buttons always have.
   */
  signOut(): void {
    void this.revokeCurrentSession();
    this.discardStashedAdminSession();
    localStorage.clear();
    sessionStorage.clear();
  }

  /**
   * POST /auth/logout for one refresh token, never rejecting (see
   * `revokeCurrentSession`).
   *
   * Deliberately sent WITHOUT a bearer (`authToken: ''` stops the adapter
   * falling back to the stored one): the refresh token alone identifies the
   * session, so the token of whichever session is current can never trip the
   * API's cross-user guard. It also keeps this safe against an API that has
   * not been redeployed yet: that one requires a bearer, so it answers 401
   * and revokes nothing, exactly as before the portal called logout at all,
   * instead of revoking just the presented token, whose later re-presentation
   * (a duplicated tab, an in-flight refresh) the old API would treat as reuse
   * and answer by signing the user out on every device.
   */
  private revoke(refreshToken: string | undefined): Promise<void> {
    if (!refreshToken) {
      return Promise.resolve();
    }
    return new Promise<void>((resolve) => {
      const timer = setTimeout(resolve, PortalAuthService.REVOKE_WAIT_MS);
      const settle = () => {
        clearTimeout(timer);
        resolve();
      };
      try {
        this.adapter
          .post_v3('POST /auth/logout', { refresh_token: refreshToken }, { authToken: '' })
          .subscribe({ complete: settle, error: settle });
      } catch {
        settle();
      }
    });
  }

  // ── Impersonation ──────────────────────────────────────────────────
  private static readonly ADMIN_BACKUP_KEY = 'ADMIN_SESSION_BACKUP';

  /**
   * Swap the current admin session for an impersonation session built from the
   * /impersonate response, stashing the admin session so it can be restored on
   * exit. The impersonated account is a vendor only, admin/staff flags are
   * forced off so the elevated console can't be reached while impersonating.
   */
  startImpersonation(res: any, label: string): void {
    const current = sessionStorage.getItem('SESSION');
    if (current) {
      localStorage.setItem(PortalAuthService.ADMIN_BACKUP_KEY, current);
    }
    const session = this.mapToSession(res);
    session.is_admin = false;
    session.is_support = false;
    session.is_finance = false;
    session._sub_admin = false;
    session.impersonating = true;
    session.impersonated_name = label;
    sessionStorage.setItem('SESSION', GlobalComponent.encodeBase64(session));
  }

  /** True when the active session is an admin impersonating a vendor. */
  isImpersonating(): boolean {
    return this.currentSession()?.impersonating === true;
  }

  impersonatedName(): string {
    return this.currentSession()?.impersonated_name ?? '';
  }

  /** Restore the stashed admin session, ending impersonation. */
  stopImpersonation(): boolean {
    const backup = localStorage.getItem(PortalAuthService.ADMIN_BACKUP_KEY);
    if (!backup) {
      return false;
    }
    sessionStorage.setItem('SESSION', backup);
    localStorage.removeItem(PortalAuthService.ADMIN_BACKUP_KEY);
    return true;
  }

  private currentSession(): PortalSession | null {
    return this.decodeSession(sessionStorage.getItem('SESSION'));
  }

  private decodeSession(raw: string | null): PortalSession | null {
    if (!raw) {
      return null;
    }
    try {
      return GlobalComponent.decodeBase64(raw) as PortalSession;
    } catch {
      return null;
    }
  }
}
