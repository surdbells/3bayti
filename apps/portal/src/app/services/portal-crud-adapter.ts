import { Injectable } from '@angular/core';
import {
  HttpClient,
  HttpHeaders,
  HttpParams,
  HttpErrorResponse,
} from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError, finalize, map, shareReplay, switchMap } from 'rxjs/operators';
import { Router } from '@angular/router';

import { resolveUrl } from '@3bayti/api-client';
import { GlobalComponent } from '../global-component';
import type { V3RouteKey } from './v3-route-keys.generated';

/**
 * PortalCrudAdapter — the portal's counterpart to MobileNetworkAdapter.
 *
 * Provides typed, route-key-driven HTTP verbs over the v3 API, mirroring
 * the naming convention used in the mobile app so component migrations are
 * mechanical: replace `crudService.post_request(body, GlobalComponent.X)`
 * with `adapter.post_v3('POST /vendor/products', body)`.
 *
 * Authentication
 * -------------
 * Reads the JWT access token from sessionStorage('SESSION') — the same
 * source all portal components use — and attaches it as a Bearer header.
 * `getToken()` is called per-request so a token refresh (e.g. after a
 * re-login) is immediately reflected without re-injecting the service.
 *
 * Base URLs
 * ---------
 * OLD = https://api.3bayti.ae/        (legacy — used only during shadow)
 * NEW = https://api-v3.3bayti.ae      (v3)
 *
 * Route keys (M3.3.0-B)
 * ---------------------
 * Each route key (`'GET /vendor/orders'`, `'POST /vendor/products'`, …)
 * maps to a concrete URL via `resolveUrl` from @3bayti/api-client.
 * Keys with `target: 'new'` → v3 base; `target: 'old'` → legacy base.
 * The feature-flag routing table is the single place to flip a feature
 * from legacy to v3.
 *
 * Path params
 * -----------
 * Colon-prefixed tokens in the newPath are substituted from `params`:
 *   `get_v3('GET /vendor/orders/:id', { id: '42' })` → `/v3/vendor/orders/42`
 *
 * Error handling
 * --------------
 * All verbs pipe through `handleError`, which logs and re-throws so
 * component `error` callbacks receive the `HttpErrorResponse`. This
 * mirrors the existing `CrudService.error()` behaviour so error-handling
 * code in components needs no changes.
 */

const BASES = {
  old: 'https://api.3bayti.ae/',
  new: 'https://api-v3.3bayti.ae',
} as const;

export interface V3RequestOptions {
  /** Path parameter substitutions for colon tokens in the route. */
  params?: Record<string, string | number>;
  /** Query string parameters appended to the URL. */
  query?: Record<string, string | number | boolean | undefined | null>;
  /** Override the auto-resolved token for this call only. */
  authToken?: string;
}

@Injectable({ providedIn: 'root' })
export class PortalCrudAdapter {
  constructor(private readonly http: HttpClient, private readonly router: Router) {}

  /**
   * Single in-flight refresh, shared across all requests that 401 at the
   * same time. Critical: the API rotates refresh tokens single-use and
   * treats a re-presented (already-rotated) token as theft — revoking the
   * whole family. Deduping to one refresh keeps concurrent 401s from
   * tripping that and logging the user out everywhere.
   */
  private refresh$: Observable<string> | null = null;

  // ── Read ────────────────────────────────────────────────────────────

  get_v3(routeKey: V3RouteKey, opts?: V3RequestOptions): Observable<any> {
    const url = this.url(routeKey, opts);
    return this.send(
      routeKey,
      (token) => this.http.get<any>(url, { headers: this.headersFor(token), params: this.qp(opts?.query) }),
      opts?.authToken,
    );
  }

  // ── Write ───────────────────────────────────────────────────────────

  post_v3(routeKey: V3RouteKey, body: any, opts?: V3RequestOptions): Observable<any> {
    const url = this.url(routeKey, opts);
    return this.send(
      routeKey,
      (token) => this.http.post<any>(url, body, { headers: this.headersFor(token), params: this.qp(opts?.query) }),
      opts?.authToken,
    );
  }

  put_v3(routeKey: V3RouteKey, body: any, opts?: V3RequestOptions): Observable<any> {
    const url = this.url(routeKey, opts);
    return this.send(
      routeKey,
      (token) => this.http.put<any>(url, body, { headers: this.headersFor(token), params: this.qp(opts?.query) }),
      opts?.authToken,
    );
  }

  patch_v3(routeKey: V3RouteKey, body: any, opts?: V3RequestOptions): Observable<any> {
    const url = this.url(routeKey, opts);
    return this.send(
      routeKey,
      (token) => this.http.patch<any>(url, body, { headers: this.headersFor(token), params: this.qp(opts?.query) }),
      opts?.authToken,
    );
  }

  delete_v3(routeKey: V3RouteKey, opts?: V3RequestOptions & { body?: any }): Observable<any> {
    const url = this.url(routeKey, opts);
    return this.send(
      routeKey,
      (token) => this.http.delete<any>(url, {
        headers: this.headersFor(token),
        params: this.qp(opts?.query),
        body: opts?.body ?? null,
      }),
      opts?.authToken,
    );
  }

  // ── Helpers ─────────────────────────────────────────────────────────

  /** Expose the token so components can pass it to legacy CrudService in
   *  parallel during a feature flip, without each component re-reading
   *  sessionStorage. */
  getToken(): string {
    const raw = sessionStorage.getItem('SESSION');
    if (!raw) return '';
    try {
      return GlobalComponent.decodeBase64(raw)?.token ?? '';
    } catch {
      return '';
    }
  }

  /** Returns the v3 API base URL (no trailing slash). Used by
   *  ImageUploadService for direct multipart POSTs that can't go
   *  through the standard JSON adapter. */
  getV3BaseUrl(): string {
    return BASES.new;
  }

  /** Convenience: decode the full session object (same as per-component
   *  `GlobalComponent.decodeBase64(sessionStorage.getItem('SESSION'))`). */
  getSession(): any {
    const raw = sessionStorage.getItem('SESSION');
    if (!raw) return null;
    try {
      return GlobalComponent.decodeBase64(raw);
    } catch {
      return null;
    }
  }

  // ── Private ─────────────────────────────────────────────────────────

  private url(routeKey: string, opts?: V3RequestOptions): string {
    return resolveUrl(routeKey, BASES, opts?.params);
  }

  private headers(overrideToken?: string): HttpHeaders {
    return this.headersFor(overrideToken ?? this.getToken());
  }

  private headersFor(token: string): HttpHeaders {
    return new HttpHeaders({
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    });
  }

  /**
   * Issue a request and, on a 401 (expired/invalid access token),
   * transparently refresh the token and retry once before giving up.
   * Auth endpoints (login/refresh/confirm/reset) are exempt so their own
   * 401s (e.g. bad credentials) surface to the caller instead of
   * triggering a refresh or logout redirect.
   *
   * @param makeReq builds the HTTP call for a given bearer token, so the
   *                retry can re-issue it with the freshly-minted token.
   */
  private send(
    routeKey: string,
    makeReq: (token: string) => Observable<any>,
    authToken?: string,
  ): Observable<any> {
    const isAuthRoute = routeKey.includes('/auth/');
    const firstToken = authToken ?? this.getToken();

    return makeReq(firstToken).pipe(
      catchError((err: HttpErrorResponse) => {
        if (err.status !== 401 || isAuthRoute) {
          return this.logAndRethrow(err);
        }
        // Access token rejected — try the refresh token, then retry once.
        return this.refreshAccessToken().pipe(
          catchError((refreshErr) => {
            // Only log out when the refresh token is genuinely invalid/expired
            // (or absent). A transient failure (network, 5xx) must NOT sign the
            // user out mid-session — surface the original error and keep the
            // session so a later request (or the proactive refresh) can recover.
            if (this.isRefreshAuthFailure(refreshErr)) {
              this.terminate();
            }
            return throwError(() => err);
          }),
          switchMap((newToken) => makeReq(newToken)),
        );
      }),
    );
  }

  /**
   * Proactively refresh the session — used by SessionManager's silent
   * pre-expiry refresh and the idle "stay signed in" action. Resolves to the
   * new access token; errors (without logging out) when there's no valid
   * refresh token so the caller can decide what to do.
   */
  refreshSession(): Observable<string> {
    return this.refreshAccessToken();
  }

  /** True when a refresh failure means the session is unrecoverable (invalid/
   *  absent refresh token) — i.e. a real logout — vs a transient blip. */
  private isRefreshAuthFailure(e: unknown): boolean {
    if (e instanceof HttpErrorResponse) {
      return e.status === 401 || e.status === 403;
    }
    const msg = (e as { message?: string })?.message ?? '';
    return msg === 'no_refresh_token' || msg === 'refresh_failed';
  }

  /**
   * Exchange the stored refresh token for a fresh access+refresh pair,
   * persisting both back to the session. Shares one in-flight call across
   * concurrent callers (see `refresh$`).
   */
  private refreshAccessToken(): Observable<string> {
    if (this.refresh$) {
      return this.refresh$;
    }
    const refreshToken = this.getRefreshToken();
    if (!refreshToken) {
      return throwError(() => new Error('no_refresh_token'));
    }

    const url = this.url('POST /auth/refresh');
    this.refresh$ = this.http
      .post<any>(url, { refresh_token: refreshToken }, { headers: this.headersFor('') })
      .pipe(
        map((res: any) => {
          const body = res?.data ?? res;
          const access = body?.access_token;
          if (!access) {
            throw new Error('refresh_failed');
          }
          this.updateSessionTokens(
            access,
            body?.refresh_token,
            body?.access_token_expires_at,
            body?.refresh_token_expires_at,
          );
          return access as string;
        }),
        shareReplay(1),
        finalize(() => {
          this.refresh$ = null;
        }),
      );
    return this.refresh$;
  }

  private getRefreshToken(): string {
    const raw = sessionStorage.getItem('SESSION');
    if (!raw) return '';
    try {
      return GlobalComponent.decodeBase64<any>(raw)?.refresh_token ?? '';
    } catch {
      return '';
    }
  }

  /** Patch the access token (and rotated refresh token + expiries) into the
   *  session so the proactive-refresh scheduler always sees the latest expiry. */
  private updateSessionTokens(
    accessToken: string,
    refreshToken?: string,
    accessExpiresAt?: string,
    refreshExpiresAt?: string,
  ): void {
    const raw = sessionStorage.getItem('SESSION');
    if (!raw) return;
    try {
      const session = GlobalComponent.decodeBase64<any>(raw) ?? {};
      session.token = accessToken;
      if (refreshToken) {
        session.refresh_token = refreshToken;
      }
      if (accessExpiresAt) {
        session.access_token_expires_at = accessExpiresAt;
      }
      if (refreshExpiresAt) {
        session.refresh_token_expires_at = refreshExpiresAt;
      }
      sessionStorage.setItem('SESSION', GlobalComponent.encodeBase64(session));
    } catch {
      /* leave the session untouched on encode failure */
    }
  }

  private terminate(): void {
    sessionStorage.removeItem('SESSION');
    this.router.navigate(['/login']).catch(() => {});
  }

  private logAndRethrow(err: HttpErrorResponse): Observable<never> {
    const message =
      err.error instanceof ErrorEvent
        ? err.error.message
        : `Error ${err.status}: ${err.message}`;
    console.error('[PortalCrudAdapter]', message, err);
    return throwError(() => err);
  }

  private qp(
    query?: Record<string, string | number | boolean | undefined | null>,
  ): HttpParams | undefined {
    if (!query) return undefined;
    let p = new HttpParams();
    for (const [k, v] of Object.entries(query)) {
      if (v !== undefined && v !== null) {
        p = p.set(k, String(v));
      }
    }
    return p;
  }
}
