import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Observable, catchError, throwError } from 'rxjs';
import { ApiConfigService } from './api-config.service';

/**
 * Thin HTTP client for the v2 (public, GET-only) API.
 *
 * @deprecated As of Day 5 of the 10-day rollout (M2.2.5), this service
 * is legacy. New code SHOULD inject `RoutedHttpClient` from
 * `app/core/http/routed-http-client.ts` instead. That client knows
 * about ENDPOINT_ROUTING in `@3bayti/api-client` and picks the right
 * backend (legacy v2 vs new v3) per logical endpoint.
 *
 * Existing consumers (HomeDataService, product-detail.ts, category-
 * detail.ts) are being migrated incrementally in Phase 5.D. Once
 * every call site has moved, this file gets deleted.
 *
 * Why it's still here right now:
 *   - Keeping the migration to one commit per consumer (rather than a
 *     big-bang rewrite) lets us flip endpoints one at a time and
 *     verify rendering between each step.
 *   - Some consumers might stay on the legacy path for a release if
 *     v3 has a parity gap we discover at runtime — having the v2
 *     escape hatch reduces risk.
 *
 * Original docstring (legacy context, preserved for posterity):
 *
 *   Why we don't reuse the mobile app's NetworkService:
 *     - The mobile NetworkService uses `post_request` / `get_request`
 *       naming conventions that don't fit a typed Angular Universal app
 *     - It assumes a token + id pattern in every call body — irrelevant
 *       for v2 which is unauthenticated
 *     - SSR-safe HTTP needs a slightly different setup (HttpClient via
 *       `provideHttpClient(withFetch())` which we'll wire in app.config.ts
 *       in a later commit)
 *
 *   What this gives us:
 *     - Strongly-typed `get<T>()` + `getList<T>()` returning Observables
 *     - Centralized URL composition via ApiConfigService
 *     - Consistent error handling (logs once, propagates HttpErrorResponse)
 *     - Cache headers stripped on errors so we don't leak debug info
 *     - Works in both SSR (Node fetch) and browser contexts
 */
@Injectable({ providedIn: 'root' })
export class ApiClientService {
  private http = inject(HttpClient);
  private config = inject(ApiConfigService);

  /**
   * @deprecated Use `RoutedHttpClient.get(routeKey, options)` instead.
   */
  get<T>(path: string, params?: Record<string, string | number | boolean>): Observable<T> {
    const url = this.config.v2BaseUrl + path;
    const httpParams = this.buildParams(params);
    return this.http
      .get<{ data: T }>(url, { params: httpParams })
      .pipe(
        // Map to unwrapped data, guard against malformed responses.
        catchError((err: HttpErrorResponse) => this.handle(err, path)),
      ) as unknown as Observable<T>;
    // Note: we don't actually unwrap here — this method returns the raw
    // `{data, meta}`. Concrete feature services (e.g. ProductService)
    // should call `getList` / `getOne` below for unwrapping. Keeping
    // raw `get<T>` available for endpoints that don't follow the envelope.
  }

  /**
   * @deprecated Use `RoutedHttpClient.getData(routeKey, options)` instead.
   */
  getOne<T>(path: string, params?: Record<string, string | number | boolean>): Observable<T> {
    const url = this.config.v2BaseUrl + path;
    const httpParams = this.buildParams(params);
    return this.http.get<{ data: T }>(url, { params: httpParams }).pipe(
      catchError((err: HttpErrorResponse) => this.handle(err, path)),
    ).pipe(
      // Unwrap the envelope.
      // Using a manual map to avoid an extra rxjs operator import in
      // a service that should stay tiny.
      this.unwrapData<T>()
    );
  }

  /**
   * @deprecated Use `RoutedHttpClient.get(routeKey, options)` instead —
   * it returns `NormalisedResponse<T>` which has both `data` and `meta`.
   */
  getList<T>(
    path: string,
    params?: Record<string, string | number | boolean>,
  ): Observable<{ data: T[]; meta: PaginationMeta }> {
    const url = this.config.v2BaseUrl + path;
    const httpParams = this.buildParams(params);
    return this.http.get<{ data: T[]; meta: PaginationMeta }>(url, { params: httpParams }).pipe(
      catchError((err: HttpErrorResponse) => this.handle(err, path)),
    );
  }

  /* ----- Internals ------------------------------------------------------- */

  private buildParams(params?: Record<string, string | number | boolean>): HttpParams | undefined {
    if (!params) return undefined;
    let httpParams = new HttpParams();
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined && value !== null && value !== '') {
        httpParams = httpParams.set(key, String(value));
      }
    }
    return httpParams;
  }

  private handle(err: HttpErrorResponse, path: string): Observable<never> {
    // Log once to console (server-side appears in Cloudflare Pages function
    // logs; browser-side surfaces in dev tools). Don't swallow — let the
    // caller decide UX (404 page, retry, etc.).
    console.error(`[ApiClient] GET ${path} failed:`, err.status, err.message);
    return throwError(() => err);
  }

  private unwrapData<T>() {
    return (source: Observable<{ data: T }>): Observable<T> =>
      new Observable<T>((subscriber) => {
        return source.subscribe({
          next: (envelope) => subscriber.next(envelope?.data),
          error: (err) => subscriber.error(err),
          complete: () => subscriber.complete(),
        });
      });
  }
}

/**
 * @deprecated Use `PaginationMeta` from `@3bayti/api-client` (re-exported
 * via `RoutedHttpClient`'s `NormalisedResponse` type).
 */
export interface PaginationMeta {
  total: number;
  limit: number;
  offset: number;
  has_more: boolean;
}
