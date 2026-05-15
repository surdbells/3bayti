import { HttpClient, HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import {
  Observable,
  catchError,
  from,
  finalize,
  map,
  of,
  shareReplay,
  switchMap,
  throwError,
} from 'rxjs';
import { Preferences } from '@capacitor/preferences';

import {
  resolveConfig,
  resolveUrl,
  type EndpointConfig,
} from '@3bayti/api-client';

import { GlobalComponent } from '../../global-component';
import { NetworkService } from '../../service/network.service';
import { resolveRouteKey, resolveRouteKeyAnyMethod, type HttpMethod } from './url-route-resolver';
import { CATALOG_REQUEST_TRANSFORMS, asRecord, type BodyToRouteArgs } from './transforms/catalog-request.transforms';
import { CATALOG_RESPONSE_TRANSFORMS, type ResponseTransform } from './transforms/catalog-response.transforms';
import {
  MUTATION_REQUEST_TRANSFORMS,
  type MutationBodyToRequest,
  type MutationTransformOutput,
} from './transforms/mutation-request.transforms';

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
   * Single-flight refresh observable.
   *
   * When a 401 hits and there's a refresh_token in Preferences, we
   * issue a POST /v3/auth/refresh and stash the resulting observable
   * here. Concurrent 401s that arrive WHILE the refresh is in flight
   * subscribe to the same observable rather than firing N refreshes
   * in parallel. shareReplay(1) ensures every subscriber gets the
   * same result (the new access_token or null for refresh failure).
   *
   * `finalize` clears the field after completion so the next 401
   * (after the access token expires again) can start a fresh refresh.
   *
   * Failure semantics
   * =================
   * If /v3/auth/refresh returns an error (refresh token expired,
   * revoked, malformed) — we clear the cached user blob from
   * Preferences and resolve the observable with null. The 401 caller
   * sees the original 401 surfaced through translateError — which
   * call sites should treat as "session expired, redirect to /login".
   *
   * Why this lives on the adapter and not a separate service
   * ========================================================
   * Refresh is intrinsically coupled to the auth-aware HTTP path —
   * it needs to mutate the same Preferences blob the adapter reads
   * for auth and it needs to be triggerable from the adapter's
   * 401 handling. Adding a separate service would force callers to
   * coordinate; keeping it here makes refresh-on-401 transparent.
   */
  private refreshInFlight$: Observable<string | null> | null = null;

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
   *
   * Three paths:
   *   1. Exact match (URL + method in ENDPOINT_ROUTING for the caller's
   *      verb): route to v3 if target === 'new', else legacy. Same
   *      behaviour as M3.1.4.
   *   2. Cross-verb match (URL is in routing table but for a different
   *      method, AND we have a registered request transform for that
   *      routeKey): convert the body to path/query params and issue
   *      the request with the routing entry's method. This is the
   *      M3.1.5b POST->GET conversion path — mobile call sites are
   *      POST-with-body by legacy convention, but v3 catalog reads
   *      are GET-with-query.
   *   3. No match: fall through to legacy unchanged.
   */
  private route(
    method: HttpMethod,
    body: unknown,
    legacyUrl: string,
  ): Observable<unknown> {
    const routeKey = resolveRouteKey(legacyUrl, method, GlobalComponent.baseURL);

    // Path 1 — exact method match in routing table.
    if (routeKey !== null) {
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

    // Path 2 — cross-verb match. The exact-method lookup failed; check
    // if the URL is in the routing table under a different method that
    // we know how to convert to.
    //
    // Currently the only conversion direction we support is mobile's
    // POST-with-body -> v3's GET-with-query. M3.1.5c populates
    // CATALOG_REQUEST_TRANSFORMS with the per-endpoint extractors.
    if (method === 'POST') {
      const converted = this.tryConvertPostToGet(body, legacyUrl);
      if (converted !== null) {
        return converted;
      }
    }

    // Path 3 — unrouted URL OR unconverted method mismatch. Fall
    // through to legacy. Most current mobile calls land here (only a
    // subset of endpoints are in ENDPOINT_ROUTING).
    return method === 'POST'
      ? this.legacyNetwork.post_request(body, legacyUrl)
      : this.legacyNetwork.get_request(legacyUrl);
  }

  /**
   * Attempt to convert a POST-with-body call into a v3 GET-with-query.
   *
   * Returns the Observable for the converted request if all four
   * conditions are met:
   *   1. The URL exists in the routing table under SOME method (any)
   *   2. That method-map has a GET entry (the only conversion direction
   *      we currently support)
   *   3. The GET entry's target === 'new' (no point converting to hit
   *      legacy via v3)
   *   4. We have a registered request transform for that routeKey in
   *      CATALOG_REQUEST_TRANSFORMS — without one, body-to-query
   *      conversion would be a guess
   *
   * Returns null if any condition fails (caller falls through to legacy).
   */
  private tryConvertPostToGet(
    body: unknown,
    legacyUrl: string,
  ): Observable<unknown> | null {
    const methodMap = resolveRouteKeyAnyMethod(legacyUrl, GlobalComponent.baseURL);
    if (methodMap === undefined) return null;

    const getRouteKey = methodMap.GET;
    if (getRouteKey === undefined) return null;

    let cfg: EndpointConfig;
    try {
      cfg = resolveConfig(getRouteKey);
    } catch {
      return null;
    }
    if (cfg.target !== 'new') return null;

    const transform: BodyToRouteArgs | undefined = CATALOG_REQUEST_TRANSFORMS[getRouteKey];
    if (transform === undefined) return null;

    const { pathParams, queryParams } = transform(asRecord(body));

    const url = this.buildV3UrlWithQuery(getRouteKey, pathParams, queryParams);

    // No body for a GET; no auth token for anonymous catalog reads.
    // If a future converted endpoint needs auth, the transform can
    // return an auth-header indicator and we extend this signature.
    //
    // Pass the routeKey through to withRefreshRetry so the response
    // transform (CATALOG_RESPONSE_TRANSFORMS[getRouteKey]) runs over
    // the v3 data field before it reaches the call site. Without
    // this, mobile pages would see v3-shaped fields (`primary_image:
    // {url, alt}`) where they expect legacy-shaped ones (`image_1`
    // as a flat URL string).
    return this.withRefreshRetry(
      null,
      (currentAuthHeader) =>
        this.executeHttpRequest('GET', url, null, currentAuthHeader),
      getRouteKey,
    );
  }

  /**
   * Build a v3 URL with both path-param substitution and query string.
   *
   * resolveUrl handles path params (`:foo` -> pathParams.foo). Query
   * string is appended here from queryParams (preserving stable
   * insertion order, which matters for log readability and caching).
   *
   * Boolean values are stringified to "true"/"false" — matches v3's
   * parseBool which accepts those tokens. Numbers stringify naturally.
   */
  private buildV3UrlWithQuery(
    routeKey: string,
    pathParams: Record<string, string> | undefined,
    queryParams: Record<string, string | number | boolean> | undefined,
  ): string {
    const baseUrl = resolveUrl(
      routeKey,
      { old: GlobalComponent.baseURL, new: this.v3BaseUrl },
      pathParams,
    );

    if (queryParams === undefined || Object.keys(queryParams).length === 0) {
      return baseUrl;
    }

    const params = new URLSearchParams();
    for (const [key, value] of Object.entries(queryParams)) {
      params.append(key, String(value));
    }
    const separator = baseUrl.includes('?') ? '&' : '?';
    return `${baseUrl}${separator}${params.toString()}`;
  }

  /**
   * Issue a request to the v3 backend, translating body/headers in and
   * envelope out so call sites stay legacy-shaped.
   *
   * 401 handling
   * ============
   * If the response is 401 AND the call had an Authorization header
   * (i.e. wasn't anonymous), the adapter transparently:
   *   1. Triggers a refresh-token flow (single-flight; concurrent 401s
   *      wait on one refresh call)
   *   2. On refresh success: retries the original request with the new
   *      access token, then surfaces the retry result to the caller
   *   3. On refresh failure: clears stored credentials, surfaces the
   *      original 401 (call sites should redirect to /login)
   * See withRefreshRetry + tryRefresh for the implementation.
   */
  private callV3(
    method: HttpMethod,
    routeKey: string,
    cfg: EndpointConfig,
    body: unknown,
  ): Observable<unknown> {
    // Strip id/token from body and extract auth header. ALWAYS runs;
    // every v3 endpoint expects the JWT in a header, never in the body.
    const { translatedBody, authHeader } = translateRequestBody(body);

    // M3.1.6i.2: consult MUTATION_REQUEST_TRANSFORMS for endpoints that
    // need body reshape and/or path-param extraction (e.g. cart-item
    // mutations where the item id needs to move from body to URL path).
    //
    // Absent transform = pass translated body through unchanged. This
    // preserves backwards compatibility — endpoints whose v3 shape
    // matches the legacy shape work without registering a transform.
    const mutationTransform: MutationBodyToRequest | undefined =
      MUTATION_REQUEST_TRANSFORMS[routeKey];

    let pathParams: Record<string, string> | undefined;
    let finalBody: unknown = translatedBody;

    if (mutationTransform !== undefined) {
      // Pass the ORIGINAL body to the transform, not translatedBody.
      // Some transforms inspect keys that translateRequestBody strips
      // (it shouldn't — translateRequestBody only strips id/token —
      // but defensive: transforms get the full picture).
      const out: MutationTransformOutput = mutationTransform(body);
      pathParams = out.pathParams;
      finalBody = out.body; // null is intentional for DELETE-style endpoints
    }

    const url = resolveUrl(
      routeKey,
      { old: GlobalComponent.baseURL, new: this.v3BaseUrl },
      pathParams,
    );

    return this.withRefreshRetry(
      authHeader,
      (currentAuthHeader) =>
        this.executeHttpRequest(method, url, finalBody, currentAuthHeader),
      routeKey,
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
   *
   * 401 handling: same as callV3 — see that method's docblock.
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

    // The refresh endpoint itself MUST NOT trigger refresh-on-401
    // recursion — a 401 from /auth/refresh means the refresh token
    // is dead, period. Same for /auth/login and other anonymous
    // endpoints: they have no Authorization header to refresh.
    if (routeKey === 'POST /auth/refresh' || authHeader === null) {
      return this.executeHttpRequest(method, url, body, authHeader).pipe(
        map((v3Response) => this.envelopeAndTransform(v3Response, routeKey)),
        catchError((err: HttpErrorResponse) => this.translateError(err)),
      );
    }

    return this.withRefreshRetry(
      authHeader,
      (currentAuthHeader) => this.executeHttpRequest(method, url, body, currentAuthHeader),
      routeKey,
    );
  }

  /**
   * Execute the actual HTTP request. Pure dispatch on method — no
   * envelope wrapping or error translation. Used by both callV3
   * (with refresh-retry wrapper) and the refresh-token path itself
   * (which can't refresh-retry on itself).
   *
   * Returns the raw v3 response observable. Caller pipes .map +
   * .catchError to wrap as legacy envelope or trigger refresh.
   */
  private executeHttpRequest(
    method: HttpMethod,
    url: string,
    body: unknown,
    authHeader: string | null,
  ): Observable<unknown> {
    const headers = this.buildHeaders(authHeader);
    return method === 'GET'
      ? this.http.get<unknown>(url, { headers, responseType: 'json' })
      : method === 'POST'
        ? this.http.post<unknown>(url, body, { headers, responseType: 'json' })
        : method === 'PUT'
          ? this.http.put<unknown>(url, body, { headers, responseType: 'json' })
          : method === 'PATCH'
            ? this.http.patch<unknown>(url, body, { headers, responseType: 'json' })
            : this.http.delete<unknown>(url, { headers, responseType: 'json' });
  }

  /**
   * Wrap a request executor with 401-refresh-retry behaviour.
   *
   * Behaviour:
   *   1. Run execute(initialAuthHeader). If success: map to envelope, done.
   *   2. If error and not 401: existing translateError path.
   *   3. If error IS 401 and initialAuthHeader was set (i.e. we had a
   *      token to refresh): trigger tryRefresh.
   *      - If refresh succeeds with a new token: re-run execute with
   *        Bearer <new-token>; wrap that result the same way; if THAT
   *        also 401s, give up (don't refresh-loop).
   *      - If refresh fails: surface the original 401 via translateError.
   *   4. If 401 but no initialAuthHeader: was anonymous; nothing to
   *      refresh; fall through to translateError.
   *
   * The `execute` callback takes a fresh authHeader each time because
   * after refresh succeeds, the original request must be retried with
   * the new token — not the stale one that just failed.
   *
   * The optional `routeKey` enables response shape transformation.
   * When provided AND CATALOG_RESPONSE_TRANSFORMS has an entry for it,
   * the legacy envelope's `data` field is transformed v3-shape →
   * legacy-shape before being surfaced to the caller. Without a
   * routeKey, the v3 data passes through unchanged (current behaviour
   * for auth endpoints which don't use the transform registry).
   */
  private withRefreshRetry(
    initialAuthHeader: string | null,
    execute: (authHeader: string | null) => Observable<unknown>,
    routeKey?: string,
  ): Observable<unknown> {
    return execute(initialAuthHeader).pipe(
      map((v3Response) => this.envelopeAndTransform(v3Response, routeKey)),
      catchError((err: HttpErrorResponse) => {
        // Not a 401, or anonymous call with no refresh path: existing
        // behaviour.
        if (err.status !== 401 || !initialAuthHeader) {
          return this.translateError(err);
        }
        // 401 + had auth: attempt refresh.
        return this.tryRefresh().pipe(
          switchMap((newToken) => {
            if (newToken === null) {
              // Refresh failed (or no refresh_token stored). Surface
              // the original 401 — call sites treat as session expired.
              return this.translateError(err);
            }
            // Retry the original request with the new token. On a
            // second failure, do NOT refresh again — that'd loop on
            // a 401 the new token can't fix.
            return execute(`Bearer ${newToken}`).pipe(
              map((v3Response) => this.envelopeAndTransform(v3Response, routeKey)),
              catchError((retryErr: HttpErrorResponse) => this.translateError(retryErr)),
            );
          }),
        );
      }),
    );
  }

  /**
   * Single-flight refresh.
   *
   * Returns an Observable that resolves with the new access_token on
   * success, or null on failure. Concurrent callers share one in-
   * flight refresh (shareReplay(1) + the refreshInFlight$ field).
   *
   * Source of truth for the refresh_token: Preferences['user'].refresh_token.
   * Sink for new tokens: same blob, mutated in place.
   *
   * On refresh failure (HTTP 4xx/5xx from /auth/refresh):
   *   - Clear Preferences['user'] (forces /login on next read)
   *   - Resolve with null so callers surface their original 401
   */
  private tryRefresh(): Observable<string | null> {
    if (this.refreshInFlight$ !== null) {
      return this.refreshInFlight$;
    }

    this.refreshInFlight$ = from(Preferences.get({ key: 'user' })).pipe(
      switchMap((stored) => {
        if (!stored.value) {
          // No cached user blob at all — nothing to refresh against.
          return of<string | null>(null);
        }
        let parsed: Record<string, unknown>;
        try {
          parsed = JSON.parse(stored.value) as Record<string, unknown>;
        } catch {
          return of<string | null>(null);
        }
        const refreshToken = parsed['refresh_token'];
        if (typeof refreshToken !== 'string' || refreshToken.length === 0) {
          // The cached blob predates M3.1.3 (no refresh_token field)
          // or has been corrupted. Treat as un-refreshable.
          return of<string | null>(null);
        }

        const refreshUrl = resolveUrl(
          'POST /auth/refresh',
          { old: GlobalComponent.baseURL, new: this.v3BaseUrl },
          undefined,
        );

        return this.executeHttpRequest('POST', refreshUrl, { refresh_token: refreshToken }, null).pipe(
          switchMap((response) => {
            // v3 /auth/refresh returns the same shape as /auth/login.
            // Persist the new tokens + user fields into Preferences,
            // then resolve with the new access_token.
            return from(this.updateStoredTokens(parsed, response)).pipe(
              map(() => this.extractAccessToken(response)),
            );
          }),
          catchError(() => {
            // Refresh itself failed — clear stored user so the next
            // app load goes to /login. The original 401 will surface
            // to the caller via withRefreshRetry's null branch.
            return from(Preferences.remove({ key: 'user' })).pipe(
              map(() => null),
            );
          }),
        );
      }),
      finalize(() => {
        // Clear the in-flight slot once this observable completes
        // (success OR error). The next 401 starts a fresh refresh.
        this.refreshInFlight$ = null;
      }),
      shareReplay(1),
    );

    return this.refreshInFlight$;
  }

  /**
   * Pull the access_token out of a /auth/refresh response. Returns
   * null if the response shape doesn't have one (defensive).
   */
  private extractAccessToken(response: unknown): string | null {
    if (response === null || typeof response !== 'object' || Array.isArray(response)) {
      return null;
    }
    const token = (response as Record<string, unknown>)['access_token'];
    return typeof token === 'string' && token.length > 0 ? token : null;
  }

  /**
   * Merge a /auth/refresh response into the cached 'user' Preferences
   * blob.
   *
   * Preserves all existing legacy fields (first_name, billing_*, etc.)
   * — the refresh response only contains auth-related fields. Updates:
   *   - token (from access_token)
   *   - refresh_token
   *   - id (in case it changed — unlikely but defensive)
   *   - any user.* fields v3 returns (first_name, etc.)
   *
   * Why not just store response.user verbatim
   * ==========================================
   * Same reason transformV3LoginResponse exists: the cached blob has
   * legacy-only fields (billing_*, location, etc.) that v3 doesn't
   * return. A naive overwrite would erase them. We update fields v3
   * provides and leave the rest untouched.
   */
  private async updateStoredTokens(
    existingBlob: Record<string, unknown>,
    refreshResponse: unknown,
  ): Promise<void> {
    if (refreshResponse === null || typeof refreshResponse !== 'object' || Array.isArray(refreshResponse)) {
      return;
    }
    const r = refreshResponse as Record<string, unknown>;
    const newBlob: Record<string, unknown> = { ...existingBlob };

    const accessToken = r['access_token'];
    if (typeof accessToken === 'string' && accessToken.length > 0) {
      newBlob['token'] = accessToken;
    }
    const refreshToken = r['refresh_token'];
    if (typeof refreshToken === 'string' && refreshToken.length > 0) {
      newBlob['refresh_token'] = refreshToken;
    }
    // Merge v3 user fields where they overlap with the legacy shape.
    const user = r['user'];
    if (user !== null && typeof user === 'object' && !Array.isArray(user)) {
      const u = user as Record<string, unknown>;
      const overlay: Record<string, unknown> = {};
      if (typeof u['id'] === 'number') overlay['id'] = u['id'];
      if (typeof u['email'] === 'string') overlay['email'] = u['email'];
      if (typeof u['phone'] === 'string') overlay['phone'] = u['phone'];
      if (typeof u['first_name'] === 'string') overlay['first_name'] = u['first_name'];
      if (typeof u['last_name'] === 'string') overlay['last_name'] = u['last_name'];
      if (u['is_store_active'] !== undefined) overlay['is_store_active'] = u['is_store_active'] === true;
      if (u['is_store_approved'] !== undefined) overlay['is_store_approved'] = u['is_store_approved'] === true;
      if (Array.isArray(u['roles'])) {
        overlay['is_vendor'] = (u['roles'] as unknown[]).some((x) => x === 'vendor');
      }
      Object.assign(newBlob, overlay);
    }

    await Preferences.set({ key: 'user', value: JSON.stringify(newBlob) });
  }

  /**
   * Apply legacy-envelope wrapping AND (optionally) per-routeKey
   * response shape transformation.
   *
   * Three behaviours depending on inputs:
   *
   *   1. No routeKey OR routeKey has no registered transform:
   *      Returns the envelope unchanged. Same as the legacy
   *      toLegacyEnvelope behaviour — used for auth and any other
   *      endpoint that doesn't need shape mapping.
   *
   *   2. routeKey has a registered transform AND the envelope's data
   *      is present:
   *      Applies the transform to the data field. The transform
   *      receives the unwrapped v3 data shape and returns the legacy
   *      shape; the result replaces `envelope.data`. The rest of the
   *      envelope (response_code, status, message) is untouched.
   *
   *   3. routeKey has a registered transform BUT data is null/missing:
   *      Returns the envelope unchanged (transform doesn't run).
   *      Defensive — a v3 response without a `data` field is anomalous
   *      and we'd rather surface that to the caller than synthesise.
   *
   * Why a helper instead of inlining: there are four sites that wrap
   * the v3 response in a legacy envelope (withRefreshRetry initial +
   * retry, callV3Direct recursion-guard paths). Inlining would put the
   * transform-lookup duplication in all four; one helper keeps the
   * logic in one place.
   */
  private envelopeAndTransform(
    v3Response: unknown,
    routeKey: string | undefined,
  ): { response_code: number; status: 'success'; message: ''; data: unknown } {
    const envelope = toLegacyEnvelope(v3Response);

    if (routeKey === undefined) return envelope;

    const transform: ResponseTransform | undefined = CATALOG_RESPONSE_TRANSFORMS[routeKey];
    if (transform === undefined) return envelope;

    if (envelope.data === null || envelope.data === undefined) return envelope;

    return {
      ...envelope,
      data: transform(envelope.data),
    };
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
