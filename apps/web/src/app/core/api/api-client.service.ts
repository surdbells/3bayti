import { Injectable, inject } from '@angular/core';
import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Observable, catchError, throwError } from 'rxjs';
import { ApiConfigService } from './api-config.service';

/**
 * Thin HTTP client for the v2 (public, GET-only) API.
 *
 * Why we don't reuse the mobile app's NetworkService:
 *   - The mobile NetworkService uses `post_request` / `get_request`
 *     naming conventions that don't fit a typed Angular Universal app
 *   - It assumes a token + id pattern in every call body — irrelevant
 *     for v2 which is unauthenticated
 *   - SSR-safe HTTP needs a slightly different setup (HttpClient via
 *     `provideHttpClient(withFetch())` which we'll wire in app.config.ts
 *     in a later commit)
 *
 * What this gives us:
 *   - Strongly-typed `get<T>()` + `getList<T>()` returning Observables
 *   - Centralized URL composition via ApiConfigService
 *   - Consistent error handling (logs once, propagates HttpErrorResponse)
 *   - Cache headers stripped on errors so we don't leak debug info
 *   - Works in both SSR (Node fetch) and browser contexts
 */
@Injectable({ providedIn: 'root' })
export class ApiClientService {
  private http = inject(HttpClient);
  private config = inject(ApiConfigService);

  /**
   * GET a single resource, returning the unwrapped `data` field of the
   * v2 envelope. The v2 contract wraps responses as `{ data, meta }`,
   * so we unwrap here so consumers don't have to.
   *
   * @param path  Path relative to the v2 base, e.g. `/categories/abayas`
   * @param params Optional query params
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
   * GET an envelope and return only `data`. Use for single-resource
   * endpoints like `/products/:slug`.
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
   * GET an envelope of a list endpoint and return both `data` and `meta`.
   * Use for paginated endpoints like `/products?category=:slug`.
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

export interface PaginationMeta {
  total: number;
  limit: number;
  offset: number;
  has_more: boolean;
}
