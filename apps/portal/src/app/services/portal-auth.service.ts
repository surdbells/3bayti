import { Injectable } from '@angular/core';
import { Observable, map } from 'rxjs';
import { PortalCrudAdapter } from './portal-crud-adapter';
import { GlobalComponent } from '../global-component';

/**
 * PortalAuthService — bridges v3 auth to the portal's SESSION format.
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
  access_token_expires_at: string;   // ISO — drives the proactive refresh
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
        // v3 API returns the payload directly at the top level — no
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
        // Unexpected 200 body without a token — treat as failure.
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
    };
  }
}
