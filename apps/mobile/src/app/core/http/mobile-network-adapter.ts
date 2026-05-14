import { HttpClient, HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import { Observable, catchError, map, of, throwError } from 'rxjs';

import {
  resolveConfig,
  resolveUrl,
  type EndpointConfig,
} from '@3bayti/api-client';

import { GlobalComponent } from '../../global-component';
import { NetworkService } from '../../service/network.service';
import { resolveRouteKey, type HttpMethod } from './url-route-resolver';

/**
 * Extract auth credentials from a legacy request body.
 *
 * Exported (not class-private) so it can be unit-tested without
 * standing up TestBed + HttpClient mocking. The adapter calls it
 * internally.
 *
 * Legacy bodies look like:
 *   { id: 42, token: "abc...", ...rest }      // authenticated
 *   { id: 0, token: "", ...rest }              // unauthenticated
 *
 * Returns:
 *   - translatedBody: input minus `id` and `token`
 *   - authHeader: 'Bearer <token>' or null
 *
 * Defensive about non-object bodies: if body isn't a plain object,
 * forward unchanged with no auth header.
 */
export function translateRequestBody(body: unknown): {
  translatedBody: unknown;
  authHeader: string | null;
} {
  if (body === null || body === undefined || typeof body !== 'object' || Array.isArray(body)) {
    return { translatedBody: body, authHeader: null };
  }

  // Treat as a plain string-keyed object. Defensive copy so we don't
  // mutate the caller's reference.
  const src = body as Record<string, unknown>;
  const out: Record<string, unknown> = {};
  let token: string | null = null;

  for (const key of Object.keys(src)) {
    if (key === 'token') {
      const v = src['token'];
      if (typeof v === 'string' && v.length > 0) {
        token = v;
      }
      // Drop `token` regardless — v3 doesn't accept it in body.
    } else if (key === 'id') {
      // Drop `id` — v3 derives user from JWT, not from body.
      // Note: if a future v3 endpoint legitimately needs an `id`
      // in the body (e.g. resource id for a write), it must use
      // a more specific field name (e.g. `address_id`) or appear
      // as a path parameter.
    } else {
      out[key] = src[key];
    }
  }

  return {
    translatedBody: out,
    authHeader: token !== null ? `Bearer ${token}` : null,
  };
}

/**
 * Wrap a v3 response in the legacy envelope shape.
 *
 * Exported for unit testability — see translateRequestBody.
 *
 * v3 typically returns `{data: ...}` or `{data: [...], meta: {...}}`
 * or sometimes custom shapes (e.g. the auth login response with token
 * pair). We forward the inner shape as the legacy `data` field.
 *
 * Special cases handled:
 *   - v3 already returns `{data: ...}`: forward `data` as-is
 *   - v3 returns a custom shape (no `data` wrapper, e.g. login
 *     response with `access_token`, `refresh_token`, `user` at top
 *     level): forward the whole object as `data`
 *
 * Legacy mobile call sites typically do:
 *   if (response.response_code === 200) { use response.data; }
 * So the only fields they read are `response_code`, `status`, and
 * `data`. We populate all three; `message` is empty for success.
 */
export function toLegacyEnvelope(v3Response: unknown): {
  response_code: number;
  status: 'success';
  message: '';
  data: unknown;
} {
  // Heuristic: if v3Response is an object with a `data` key, unwrap.
  // Otherwise pass the whole thing as `data`.
  let data: unknown;
  if (
    v3Response !== null &&
    typeof v3Response === 'object' &&
    !Array.isArray(v3Response) &&
    'data' in (v3Response as Record<string, unknown>)
  ) {
    data = (v3Response as Record<string, unknown>)['data'];
  } else {
    data = v3Response;
  }

  return {
    response_code: 200,
    status: 'success',
    message: '',
    data,
  };
}

/**
 * MobileNetworkAdapter — drop-in replacement for NetworkService that
 * routes through @3bayti/api-client's ENDPOINT_ROUTING when possible.
 *
 * Why this exists
 * ===============
 * The mobile app has 120+ call sites using `networkService.post_request`
 * and `networkService.get_request` with full legacy URLs:
 *
 *   networkService.post_request(body, GlobalComponent.UserLogin)
 *     // body has {id, token, ...} for authenticated calls
 *
 * Migrating each of those 120 sites individually to a new client would
 * be a massive change with high regression risk. Instead this adapter
 * provides the SAME public API as NetworkService (`post_request` /
 * `get_request`) but internally:
 *
 *   1. Checks if the URL is in ENDPOINT_ROUTING
 *      - If yes AND target='new': route to v3
 *      - If yes AND target='old': route to legacy (via NetworkService)
 *      - If no entry: fall through to legacy (via NetworkService)
 *
 *   2. For v3 routes: translates the legacy request body to v3 shape
 *      - Extract `token` from body → Authorization: Bearer header
 *      - Extract `id` from body → drop (v3 derives user from token)
 *      - Anything else in body → forward unchanged
 *
 *   3. For v3 responses: translates back to the legacy envelope shape
 *      `{response_code: 200, status: 'success', data: ..., message: ''}`
 *      so call sites that check `response.response_code === 200` keep
 *      working. This is critical for "no call site changes" — the
 *      adapter is transparent.
 *
 *   4. For errors: maps HTTP errors back to the legacy error envelope
 *      shape so call sites' `subscribe({ error: ... })` handlers
 *      continue working.
 *
 * This is the strangler-fig pattern at the data-layer level. Once all
 * call sites are migrated, NetworkService can be removed and call
 * sites can use the adapter (or its successor) directly.
 *
 * Why mobile uses token-in-body legacy + Bearer headers for v3
 * =============================================================
 * Legacy mobile attaches `{id, token}` to every authenticated request
 * body. The legacy PHP backend reads these to authenticate. v3 (Slim
 * 4) uses standard `Authorization: Bearer <jwt>` headers via
 * AuthMiddleware. The adapter bridges the two.
 *
 * Edge case: when the body lacks token (e.g. unauthenticated calls
 * like /users/login), the adapter forwards an empty Authorization
 * header. v3's AuthMiddleware will 401 if the endpoint requires auth;
 * unauthenticated endpoints (login, register, validate-phone) work
 * regardless.
 *
 * Edge case: when token IS present but empty string, we DON'T inject
 * the header (some legacy code paths populate token: "" for unauth
 * endpoints; preserve that intent).
 *
 * Response envelope translation
 * =============================
 * v3 responses are typically `{data: ...}` (Shape A) or
 * `{data: [...], meta: {...}}` (Shape B for paginated). Mobile call
 * sites expect:
 *
 *   { response_code: 200, status: 'success', data: ..., message: '' }
 *
 * The adapter wraps the v3 payload into this shape. The `data` field
 * passes through (whether scalar, object, array, or paginated wrapper);
 * call sites get the same data shape they would have under legacy.
 *
 * For v3 errors, the adapter constructs:
 *   { response_code: <status>, status: 'error', message: '<msg>', data: null }
 *
 * NOT in scope
 * ============
 *   - Token refresh on 401 (M4 hardening / M3.1.3+ if needed)
 *   - Retry on transient failures (M4 / call-site-level)
 *   - Caching (call sites already manage their own caches)
 *   - Path parameter substitution for routed calls — currently
 *     ENDPOINT_ROUTING entries with :params (e.g. `/me/addresses/:id`)
 *     don't yet have mobile consumers; M3.1.3+ will wire those when
 *     specific call sites are flipped
 */
@Injectable({ providedIn: 'root' })
export class MobileNetworkAdapter {
  private http = inject(HttpClient);
  private legacyNetwork = inject(NetworkService);

  /** v3 backend base URL. Hard-coded for now; M4 may move to env config. */
  private readonly v3BaseUrl = 'https://api-v3.3bayti.ae';

  /**
   * POST a request. Drop-in replacement for NetworkService.post_request.
   *
   * Behavior:
   *   - If URL matches a 'new'-target ENDPOINT_ROUTING entry: send to
   *     v3 with translated body + Bearer header, then wrap response
   *     in legacy envelope.
   *   - Otherwise: delegate to NetworkService (full legacy path).
   *
   * Always returns Observable<any> with the legacy envelope shape so
   * call sites see the same `response.response_code === 200` pattern
   * they always have.
   */
  post_request(body: unknown, legacyUrl: string): Observable<unknown> {
    return this.route('POST', body, legacyUrl);
  }

  /**
   * GET a request. Drop-in replacement for NetworkService.get_request.
   *
   * Note: legacy mobile rarely uses GET; most reads are POST with a
   * body. This is here for API parity.
   */
  get_request(legacyUrl: string): Observable<unknown> {
    // GETs don't carry tokens-in-body. We still try routing but with
    // empty body — the resolver decides based on URL alone.
    return this.route('GET', null, legacyUrl);
  }

  /**
   * Call a v3 endpoint directly by routeKey, without any legacy-URL
   * lookup. Use this for v3-only endpoints (those with `oldPath: ''`
   * in ENDPOINT_ROUTING) — they have no legacy URL to resolve FROM,
   * so post_request / get_request can't route them.
   *
   * Why this is separate from post_request
   * ======================================
   * post_request takes a legacy URL and translates legacy-shaped body
   * (`{id, token, ...rest}`) into v3 shape. For v3-native call sites:
   *   - There's no legacy URL.
   *   - The body is already v3-shaped.
   *   - The token (if needed) is supplied explicitly via opts.authToken.
   *
   * So this method does:
   *   - Look up cfg by routeKey (instead of by legacy URL)
   *   - Build the URL with optional path params
   *   - Forward body unchanged (no shape translation)
   *   - Attach Authorization header IFF opts.authToken provided
   *   - Same envelope wrap and error translation as the legacy path
   *
   * @param routeKey - routing table key (e.g. 'POST /auth/confirm')
   * @param body - v3-shaped request body (forwarded unchanged)
   * @param opts.authToken - optional Bearer token for authenticated calls
   * @param opts.pathParams - optional path parameter substitutions
   */
  post_v3(
    routeKey: string,
    body: unknown,
    opts?: { authToken?: string; pathParams?: Record<string, string> },
  ): Observable<unknown> {
    return this.callV3Direct('POST', routeKey, body, opts);
  }

  /**
   * GET a v3 endpoint directly by routeKey. See post_v3 docblock.
   */
  get_v3(
    routeKey: string,
    opts?: { authToken?: string; pathParams?: Record<string, string> },
  ): Observable<unknown> {
    return this.callV3Direct('GET', routeKey, null, opts);
  }

  /* ------ Internals ----------------------------------------------- */

  /**
   * Core routing decision. Returns an Observable wrapped in the legacy
   * envelope so call sites don't need to care which backend served them.
   */
  private route(
    method: HttpMethod,
    body: unknown,
    legacyUrl: string,
  ): Observable<unknown> {
    const routeKey = resolveRouteKey(legacyUrl, method, GlobalComponent.baseURL);

    // Unrouted URL → straight to legacy. Most current mobile calls
    // land here (only a subset of endpoints are in ENDPOINT_ROUTING).
    if (routeKey === null) {
      return method === 'POST'
        ? this.legacyNetwork.post_request(body, legacyUrl)
        : this.legacyNetwork.get_request(legacyUrl);
    }

    // Routed URL — but look up the config to see if target is 'old'
    // (forced legacy) or 'new' (route to v3).
    let cfg: EndpointConfig;
    try {
      cfg = resolveConfig(routeKey);
    } catch {
      // resolveConfig throws on missing key. Shouldn't happen because
      // the resolver only returns keys it found in ENDPOINT_ROUTING.
      // Defensive fall-back to legacy.
      return method === 'POST'
        ? this.legacyNetwork.post_request(body, legacyUrl)
        : this.legacyNetwork.get_request(legacyUrl);
    }

    if (cfg.target === 'old') {
      // Routing entry says use legacy. Honour it.
      return method === 'POST'
        ? this.legacyNetwork.post_request(body, legacyUrl)
        : this.legacyNetwork.get_request(legacyUrl);
    }

    // target === 'new' — route to v3.
    return this.callV3(method, routeKey, cfg, body);
  }

  /**
   * Issue a request to the v3 backend, translating body/headers in and
   * envelope out so call sites stay legacy-shaped.
   */
  private callV3(
    method: HttpMethod,
    routeKey: string,
    cfg: EndpointConfig,
    body: unknown,
  ): Observable<unknown> {
    const url = resolveUrl(
      routeKey,
      { old: GlobalComponent.baseURL, new: this.v3BaseUrl },
      undefined, // path params: not yet supported via this entry point
                  // — see class docblock (M3.1.3+ extension)
    );

    const { translatedBody, authHeader } = translateRequestBody(body);
    const headers = this.buildHeaders(authHeader);

    const obs =
      method === 'GET'
        ? this.http.get<unknown>(url, { headers, responseType: 'json' })
        : method === 'POST'
          ? this.http.post<unknown>(url, translatedBody, { headers, responseType: 'json' })
          : method === 'PUT'
            ? this.http.put<unknown>(url, translatedBody, { headers, responseType: 'json' })
            : method === 'PATCH'
              ? this.http.patch<unknown>(url, translatedBody, { headers, responseType: 'json' })
              : this.http.delete<unknown>(url, { headers, responseType: 'json' });

    return obs.pipe(
      map((v3Response) => toLegacyEnvelope(v3Response)),
      catchError((err: HttpErrorResponse) => this.translateError(err)),
    );
  }

  /**
   * Issue a v3-direct request — sibling to callV3 but with simpler
   * input semantics: no legacy body translation; explicit auth token;
   * optional path params.
   *
   * Used by post_v3 / get_v3 for v3-native call sites (register OTP
   * confirm, password reset, refresh-token, logout, etc.). See the
   * post_v3 docblock for design rationale.
   */
  private callV3Direct(
    method: HttpMethod,
    routeKey: string,
    body: unknown,
    opts?: { authToken?: string; pathParams?: Record<string, string> },
  ): Observable<unknown> {
    let cfg: EndpointConfig;
    try {
      cfg = resolveConfig(routeKey);
    } catch {
      // Unknown route key — return an error envelope through the
      // success channel so call sites can detect via response_code.
      // This shouldn't happen if callers use string literals matching
      // ENDPOINT_ROUTING; it's defensive against typos.
      return of({
        response_code: 500,
        status: 'error',
        message: `Unknown route key: ${routeKey}`,
        error_code: 'ROUTE_NOT_FOUND',
        data: null,
      });
    }

    if (cfg.target !== 'new') {
      // The routing table says this route is legacy. v3-direct can't
      // serve a legacy-targeted route — that's what post_request /
      // get_request are for. Surface as an error envelope.
      return of({
        response_code: 500,
        status: 'error',
        message: `Route ${routeKey} has target=${cfg.target}; cannot use v3-direct`,
        error_code: 'ROUTE_NOT_NEW',
        data: null,
      });
    }

    const url = resolveUrl(
      routeKey,
      { old: GlobalComponent.baseURL, new: this.v3BaseUrl },
      opts?.pathParams,
    );

    const authHeader = opts?.authToken ? `Bearer ${opts.authToken}` : null;
    const headers = this.buildHeaders(authHeader);

    const obs =
      method === 'GET'
        ? this.http.get<unknown>(url, { headers, responseType: 'json' })
        : method === 'POST'
          ? this.http.post<unknown>(url, body, { headers, responseType: 'json' })
          : method === 'PUT'
            ? this.http.put<unknown>(url, body, { headers, responseType: 'json' })
            : method === 'PATCH'
              ? this.http.patch<unknown>(url, body, { headers, responseType: 'json' })
              : this.http.delete<unknown>(url, { headers, responseType: 'json' });

    return obs.pipe(
      map((v3Response) => toLegacyEnvelope(v3Response)),
      catchError((err: HttpErrorResponse) => this.translateError(err)),
    );
  }

  /**
   * Build the request headers. Always JSON; conditionally adds
   * Authorization when a token was present.
   */
  private buildHeaders(authHeader: string | null): HttpHeaders {
    let h = new HttpHeaders()
      .set('Content-Type', 'application/json')
      .set('Accept', 'application/json');
    if (authHeader !== null) {
      h = h.set('Authorization', authHeader);
    }
    return h;
  }

  /**
   * Map an HttpErrorResponse from v3 back to the legacy error envelope.
   *
   * Legacy mobile error handling typically does:
   *   subscribe({
   *     next: (response) => { if (response.response_code !== 200) showError(response.message); },
   *     error: (e) => { showError(e.toString()); }
   *   })
   *
   * For v3 errors we want call sites to see them through the `next`
   * handler with `response_code: <status>` and `status: 'error'`. This
   * means the Observable resolves SUCCESSFULLY but with an error
   * envelope — the caller checks response_code to detect.
   *
   * For network-level errors (no response), we propagate via the
   * Observable error channel (matches legacy NetworkService behaviour:
   * the `error` callback fires for transport errors).
   */
  private translateError(err: HttpErrorResponse): Observable<unknown> {
    // Network-level error (status 0 or undefined) — propagate via
    // error channel. Matches legacy NetworkService.error() behaviour.
    if (!err.status) {
      return throwError(() => err);
    }

    // HTTP-level error with a response. Surface it as a legacy
    // envelope via the success channel so call sites see it through
    // their `next` handler the same way they would for a legacy
    // backend that returned response_code !== 200.
    let message = 'Request failed';
    let errorCode: string | null = null;
    const body = err.error;
    if (body && typeof body === 'object') {
      const e = (body as Record<string, unknown>)['error'];
      if (e && typeof e === 'object') {
        const code = (e as Record<string, unknown>)['code'];
        const msg = (e as Record<string, unknown>)['message'];
        if (typeof code === 'string') {
          errorCode = code;
        }
        if (typeof msg === 'string' && msg.length > 0) {
          message = msg;
        }
      }
    }

    return of({
      response_code: err.status,
      status: 'error',
      message,
      error_code: errorCode, // extension field; legacy didn't have this
      data: null,
    });
  }
}
