import { HttpClient, HttpErrorResponse, HttpParams } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, throwError } from 'rxjs';

import {
  ENDPOINT_ROUTING,
  resolveConfig,
  resolveUrl,
  normaliseResponse,
  type NormalisedResponse,
  type ResponseShape,
} from '@3bayti/api-client';

import { ApiConfigService } from '../api/api-config.service';

/**
 * RoutedHttpClient, Angular HttpClient adapter that consumes the
 * `@3bayti/api-client` routing tables.
 *
 * Why this exists
 * ===============
 * `packages/api-client` exposes a runtime-agnostic `fetch()` based
 * client. That's fine for Node scripts and possibly mobile native
 * shells, but apps/web wants Angular's HttpClient:
 *
 *   - HttpClient gives us interceptors (auth, error logging, retry).
 *     Reusing them is preferable to porting them onto fetch.
 *   - Observable-based; matches the existing apps/web idiom and our
 *     RxJS-shaped feature services without an adapter at every call
 *     site.
 *
 * What we keep from `@3bayti/api-client`
 * ======================================
 *   - ENDPOINT_ROUTING table, the single source of truth for which
 *     endpoint is on 'new' vs 'old'.
 *   - resolveUrl / resolveConfig, same path-param substitution and
 *     shape lookup the fetch client uses, so mobile/portal and web
 *     stay consistent.
 *   - normaliseResponse, turns the three different wire shapes
 *     (v2 envelope, v3 envelope, raw) into one `{ data, meta? }`
 *     contract for consumers.
 *
 * Public API
 * ==========
 * All methods take a routing KEY of the form `"GET /products"`,
 * `"GET /products/:slug"`, etc., the same keys ENDPOINT_ROUTING uses.
 * That lets feature services express intent at the logical level
 * ("fetch products") rather than committing to a specific backend.
 *
 *   const products$ = routed.get<Product[]>('GET /products', {
 *     query: { limit: 12, sort: 'newest' },
 *   });
 *   const detail$ = routed.get<ProductDetail>('GET /products/:slug', {
 *     params: { slug: 'abc' },
 *   });
 *   const created$ = routed.post<Address>('POST /me/addresses', {
 *     body: { line1: '...', city: '...' },
 *   });
 *
 * Each call returns `Observable<NormalisedResponse<T>>`. Consumers can
 * map to plain `T` with `.pipe(map(r => r.data))` if they don't need
 * meta; helper `getData$` exists for that.
 *
 * Errors
 * ======
 * HTTP errors propagate as `HttpErrorResponse` through the Observable
 * error channel. We log once at the boundary; callers decide UX
 * (retry, fallback, 404 page). We deliberately do NOT swallow errors
 * here, silently empty results break things in surprising ways. See
 * HomeDataService's per-stream `catchError(() => of([]))` for the
 * "degrade gracefully" pattern at the consumer layer.
 *
 * Not in scope for this adapter
 * =============================
 *   - Retry/backoff. The `@3bayti/api-client` retry helper is fetch-
 *     specific; for Angular we can compose with RxJS's `retry()` at
 *     consumer level when needed (mostly only for mutations, which
 *     this adapter handles below).
 *   - Auth header injection. That happens via Angular HttpInterceptor
 *     (separate concern; will wire up in M2.3 when the web app starts
 *     hitting authenticated v3 endpoints).
 *   - Request cancellation. Angular Observables already do this when
 *     the subscriber unsubscribes, no extra plumbing needed.
 */
@Injectable({ providedIn: 'root' })
export class RoutedHttpClient {
  private http = inject(HttpClient);
  private config = inject(ApiConfigService);

  /**
   * GET an endpoint by routing key.
   *
   * @param routeKey  Routing-table key like `"GET /products"`.
   * @param options   Optional path params + query string params.
   * @returns         Observable of normalised `{ data, meta? }`.
   */
  get<T>(routeKey: string, options: RequestOptions = {}): Observable<NormalisedResponse<T>> {
    return this.request<T>('GET', routeKey, options);
  }

  /**
   * POST an endpoint by routing key.
   *
   * @param routeKey  Routing-table key like `"POST /auth/login"`.
   * @param options   Path params, query string, and request body.
   */
  post<T>(routeKey: string, options: RequestOptions = {}): Observable<NormalisedResponse<T>> {
    return this.request<T>('POST', routeKey, options);
  }

  /**
   * PUT an endpoint by routing key.
   */
  put<T>(routeKey: string, options: RequestOptions = {}): Observable<NormalisedResponse<T>> {
    return this.request<T>('PUT', routeKey, options);
  }

  /**
   * PATCH an endpoint by routing key. Used for partial updates that
   * follow RFC 7396 JSON Merge Patch semantics (e.g. PATCH /me/profile,
   * PATCH /me/password).
   */
  patch<T>(routeKey: string, options: RequestOptions = {}): Observable<NormalisedResponse<T>> {
    return this.request<T>('PATCH', routeKey, options);
  }

  /**
   * DELETE an endpoint by routing key.
   */
  delete<T>(routeKey: string, options: RequestOptions = {}): Observable<NormalisedResponse<T>> {
    return this.request<T>('DELETE', routeKey, options);
  }

  /**
   * Convenience: GET and immediately unwrap to `T` (drops `meta`).
   * Use when you don't care about pagination.
   */
  getData<T>(routeKey: string, options: RequestOptions = {}): Observable<T> {
    return this.get<T>(routeKey, options).pipe(map((env) => env.data));
  }

  /* ------ Internals ----------------------------------------------- */

  /**
   * Core request method. Resolves the URL via ENDPOINT_ROUTING, picks
   * the right base URL from ApiConfigService, calls Angular HttpClient,
   * and normalises the response shape.
   */
  private request<T>(
    method: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE',
    routeKey: string,
    options: RequestOptions,
  ): Observable<NormalisedResponse<T>> {
    // resolveConfig will throw if the route key isn't registered -
    // good fail-fast for typos. resolveUrl does the path-param
    // substitution.
    const cfg = resolveConfig(routeKey);
    const bases = {
      // legacyBaseUrl is the unversioned legacy host; ENDPOINT_ROUTING
      // entries include the version segment in their `oldPath`
      // (e.g. '/v2/featured-vendors', '/users/login'), so we must NOT
      // use v2BaseUrl here, that would produce a double-prefix URL.
      old: this.config.legacyBaseUrl,
      new: this.config.v3BaseUrl,
    };
    const url = resolveUrl(routeKey, bases, options.params);

    const httpOptions: {
      params?: HttpParams;
      body?: unknown;
      observe: 'body';
      responseType: 'json';
    } = {
      observe: 'body',
      responseType: 'json',
    };
    if (options.query) {
      httpOptions.params = this.buildQueryParams(options.query);
    }
    if (options.body !== undefined) {
      httpOptions.body = options.body;
    }

    // Angular's HttpClient typing for `request` is more permissive
    // than its method-specific overloads (get/post/etc) when both
    // params and body are involved, so we go through `request` for
    // uniformity across verbs.
    return this.http
      .request<unknown>(method, url, httpOptions)
      .pipe(
        map((raw) => normaliseResponse<T>(raw, cfg.shape ?? 'v3-envelope')),
        catchError((err: HttpErrorResponse) => this.handle(err, method, routeKey, url)),
      );
  }

  /**
   * Translate a plain `Record<string, ...>` into Angular's HttpParams,
   * skipping undefined / null / empty-string values (so optional
   * filters don't serialise as `?sort=undefined`).
   */
  private buildQueryParams(query: Record<string, string | number | boolean | undefined | null>): HttpParams {
    let params = new HttpParams();
    for (const [key, value] of Object.entries(query)) {
      if (value !== undefined && value !== null && value !== '') {
        params = params.set(key, String(value));
      }
    }
    return params;
  }

  /**
   * Centralised error logging. We log once (SSR side surfaces in
   * Cloudflare logs; browser side surfaces in dev tools) and rethrow
   * so the caller's `catchError` decides UX.
   */
  private handle(
    err: HttpErrorResponse,
    method: string,
    routeKey: string,
    url: string,
  ): Observable<never> {
    console.error(
      `[RoutedHttpClient] ${method} ${routeKey} -> ${url} failed:`,
      err.status,
      err.statusText || err.message,
    );
    return throwError(() => err);
  }
}

/**
 * Options bag for requests.
 *
 * `params`, path parameters; `:slug` in the routing-table path is
 *            substituted with `params.slug`.
 * `query`, query string parameters. Falsy values dropped.
 * `body` , JSON body for mutations. Serialised by HttpClient.
 */
export interface RequestOptions {
  params?: Record<string, string | number>;
  query?: Record<string, string | number | boolean | undefined | null>;
  body?: unknown;
}

// Re-export the unified response shape so consumers don't need a
// separate import from @3bayti/api-client just for the type.
export type { NormalisedResponse, ResponseShape };

// Re-export ENDPOINT_ROUTING so anyone wiring a new feature can grep
// for `ENDPOINT_ROUTING` and find both the table itself and this
// adapter in one go.
export { ENDPOINT_ROUTING };
