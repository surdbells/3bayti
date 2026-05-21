import { Injectable } from '@angular/core';
import {
  HttpClient,
  HttpHeaders,
  HttpParams,
  HttpErrorResponse,
} from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { catchError } from 'rxjs/operators';

import { resolveUrl } from '@3bayti/api-client';
import { GlobalComponent } from '../global-component';

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
  constructor(private readonly http: HttpClient) {}

  // ── Read ────────────────────────────────────────────────────────────

  get_v3(routeKey: string, opts?: V3RequestOptions): Observable<any> {
    const url = this.url(routeKey, opts);
    return this.http
      .get<any>(url, { headers: this.headers(opts?.authToken), params: this.qp(opts?.query) })
      .pipe(catchError(this.handleError));
  }

  // ── Write ───────────────────────────────────────────────────────────

  post_v3(routeKey: string, body: any, opts?: V3RequestOptions): Observable<any> {
    const url = this.url(routeKey, opts);
    return this.http
      .post<any>(url, body, { headers: this.headers(opts?.authToken), params: this.qp(opts?.query) })
      .pipe(catchError(this.handleError));
  }

  put_v3(routeKey: string, body: any, opts?: V3RequestOptions): Observable<any> {
    const url = this.url(routeKey, opts);
    return this.http
      .put<any>(url, body, { headers: this.headers(opts?.authToken), params: this.qp(opts?.query) })
      .pipe(catchError(this.handleError));
  }

  patch_v3(routeKey: string, body: any, opts?: V3RequestOptions): Observable<any> {
    const url = this.url(routeKey, opts);
    return this.http
      .patch<any>(url, body, { headers: this.headers(opts?.authToken), params: this.qp(opts?.query) })
      .pipe(catchError(this.handleError));
  }

  delete_v3(routeKey: string, opts?: V3RequestOptions & { body?: any }): Observable<any> {
    const url = this.url(routeKey, opts);
    return this.http
      .delete<any>(url, {
        headers: this.headers(opts?.authToken),
        params: this.qp(opts?.query),
        body: opts?.body ?? null,
      })
      .pipe(catchError(this.handleError));
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
    const token = overrideToken ?? this.getToken();
    return new HttpHeaders({
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    });
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

  private handleError = (err: HttpErrorResponse): Observable<never> => {
    // Mirror CrudService.error() so existing component error callbacks work
    const message =
      err.error instanceof ErrorEvent
        ? err.error.message
        : `Error ${err.status}: ${err.message}`;
    console.error('[PortalCrudAdapter]', message, err);
    return throwError(() => err);
  };
}
