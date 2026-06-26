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
import { CATALOG_RESPONSE_TRANSFORMS, type ResponseTransform } from './transforms/catalog-response.transforms';
import { MUTATION_RESPONSE_TRANSFORMS } from './transforms/mutation-response.transforms';

/**
 * HTTP method narrowed to the five verbs the v3 client issues.
 *
 * Previously imported from the (now-removed) url-route-resolver, which
 * served the legacy strangler-fig path. The v3-direct client is the only
 * remaining consumer, so the type lives here.
 */
type HttpMethod = 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE';

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
  meta?: unknown;
} {
  // Heuristic: if v3Response is an object with a `data` key, unwrap.
  // Otherwise pass the whole thing as `data`.
  let data: unknown;
  let meta: unknown;
  if (
    v3Response !== null &&
    typeof v3Response === 'object' &&
    !Array.isArray(v3Response) &&
    'data' in (v3Response as Record<string, unknown>)
  ) {
    data = (v3Response as Record<string, unknown>)['data'];
    // Preserve the v3 pagination envelope (`{data, meta}`) so paginated
    // direct call sites (e.g. product-reviews load-more) can read
    // meta.total. Additive: present only when v3 sent it, so call sites
    // that read just response_code/status/data are unaffected.
    meta = (v3Response as Record<string, unknown>)['meta'];
  } else {
    data = v3Response;
  }

  return {
    response_code: 200,
    status: 'success',
    message: '',
    data,
    ...(meta !== undefined ? { meta } : {}),
  };
}

/**
 * MobileNetworkAdapter — the mobile app's v3 API client.
 *
 * Every page calls v3 endpoints DIRECTLY by route-key via the typed
 * methods get_v3 / post_v3 / patch_v3 / put_v3 / delete_v3 (→ callV3Direct).
 * A route-key (e.g. 'GET /cart') is resolved to its URL through
 * @3bayti/api-client's ENDPOINT_ROUTING (resolveConfig / resolveUrl); the
 * request hits v3BaseUrl with an `Authorization: Bearer <token>` header
 * when the call passes { authToken }, plus optional pathParams/queryParams.
 *
 * Response envelope + transforms
 * ==============================
 * The raw v3 response is wrapped into the legacy envelope shape
 *   { response_code: 200, status: 'success', data, message: '' }
 * via toLegacyEnvelope, then the registered RESPONSE transform for the
 * route-key (CATALOG_RESPONSE_TRANSFORMS / MUTATION_RESPONSE_TRANSFORMS)
 * reshapes `data` into what the page binds. So call sites keep their
 * `if (response.response_code === 200) { use response.data }` pattern.
 * NOTE: the *_v3 methods do NOT apply a request transform — each call site
 * passes the explicit v3 body / query params.
 *
 * Errors
 * ======
 * HTTP failures are surfaced through the SUCCESS channel as
 *   { response_code: <status>, status: 'error', message, error_code,
 *     error_details, data: null }
 * (translateError) so call sites read them in their `next` handler;
 * only network-level errors (status 0) propagate via the rxjs error channel.
 *
 * Auth refresh
 * ============
 * A 401 with a stored refresh_token triggers a single-flight
 * POST /v3/auth/refresh (refreshInFlight$); the original request retries
 * once with the new access token. Refresh failure clears the stored user
 * and surfaces the 401 (call sites treat that as "session expired").
 *
 * History
 * =======
 * This began as the strangler-fig adapter bridging 120+ legacy
 * post_request / get_request call sites to v3. All call sites are now on
 * direct v3, and the legacy machinery (post_request, get_request, route,
 * tryConvertPostToGet, the legacy callV3, the request transforms, and
 * url-route-resolver) has been deleted — this is now a pure v3 client.
 */
@Injectable({ providedIn: 'root' })
export class MobileNetworkAdapter {
  private http = inject(HttpClient);

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
    opts?: {
      authToken?: string;
      pathParams?: Record<string, string>;
      queryParams?: Record<string, string | number | boolean>;
    },
  ): Observable<unknown> {
    return this.callV3Direct('POST', routeKey, body, opts);
  }

  /**
   * GET a v3 endpoint directly by routeKey. See post_v3 docblock.
   */
  get_v3(
    routeKey: string,
    opts?: {
      authToken?: string;
      pathParams?: Record<string, string>;
      queryParams?: Record<string, string | number | boolean>;
    },
  ): Observable<unknown> {
    return this.callV3Direct('GET', routeKey, null, opts);
  }

  /**
   * PATCH a v3 endpoint directly by routeKey. Added M3.1.7-I for
   * vendor item-status transitions and similar mutating endpoints
   * that don't have a legacy POST counterpart.
   */
  patch_v3(
    routeKey: string,
    body: unknown,
    opts?: {
      authToken?: string;
      pathParams?: Record<string, string>;
      queryParams?: Record<string, string | number | boolean>;
    },
  ): Observable<unknown> {
    return this.callV3Direct('PATCH', routeKey, body, opts);
  }

  /**
   * PUT a v3 endpoint directly by routeKey. Added for vendor product
   * writes (PUT /vendor/products/:id), which the API exposes as PUT with
   * partial-update semantics (only the fields present are applied).
   */
  put_v3(
    routeKey: string,
    body: unknown,
    opts?: {
      authToken?: string;
      pathParams?: Record<string, string>;
      queryParams?: Record<string, string | number | boolean>;
    },
  ): Observable<unknown> {
    return this.callV3Direct('PUT', routeKey, body, opts);
  }

  /**
   * DELETE a v3 endpoint directly by routeKey. Added M3.2.Z.3-Mobile
   * for wishlist label/item deletion and similar mutating endpoints.
   */
  delete_v3(
    routeKey: string,
    opts?: {
      authToken?: string;
      pathParams?: Record<string, string>;
      queryParams?: Record<string, string | number | boolean>;
      /** Optional request body. Most DELETEs don't carry one; the
       *  device-token deactivation (M3.2.Z.5) sends { token } so the
       *  server can identify which device to deactivate. */
      body?: unknown;
    },
  ): Observable<unknown> {
    return this.callV3Direct('DELETE', routeKey, opts?.body ?? null, opts);
  }

  /* ------ Internals ----------------------------------------------- */

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
    opts?: {
      authToken?: string;
      pathParams?: Record<string, string>;
      queryParams?: Record<string, string | number | boolean>;
    },
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

    // M3.1.7-I — build URL with both path-params and optional query string.
    // Reuses buildV3UrlWithQuery so query strings are stable + URL-encoded
    // identically to the POST-to-GET conversion path.
    const url = opts?.queryParams && Object.keys(opts.queryParams).length > 0
      ? this.buildV3UrlWithQuery(routeKey, opts?.pathParams, opts.queryParams)
      : resolveUrl(
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
  ): { response_code: number; status: 'success'; message: ''; data: unknown; meta?: unknown } {
    const envelope = toLegacyEnvelope(v3Response);

    if (routeKey === undefined) return envelope;

    // Look up the transform in either registry. Catalog has GET-read
    // transforms; Mutation has cart/order/checkout transforms. Each
    // routeKey appears in at most one registry, so an OR lookup is
    // safe.
    const transform: ResponseTransform | undefined =
      CATALOG_RESPONSE_TRANSFORMS[routeKey] ?? MUTATION_RESPONSE_TRANSFORMS[routeKey];
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
    let errorDetails: unknown = null;
    const body = err.error;
    if (body && typeof body === 'object') {
      const e = (body as Record<string, unknown>)['error'];
      if (e && typeof e === 'object') {
        const code = (e as Record<string, unknown>)['code'];
        const msg = (e as Record<string, unknown>)['message'];
        const details = (e as Record<string, unknown>)['details'];
        if (typeof code === 'string') {
          errorCode = code;
        }
        if (typeof msg === 'string' && msg.length > 0) {
          message = msg;
        }
        // v3 structured errors carry a `details` object (e.g. PROMO_MIN_
        // SUBTOTAL_NOT_MET -> { min_subtotal, currency }). 422s are surfaced
        // here via the success channel, so propagate details too — otherwise
        // call sites that interpolate them (promo messages) render blanks.
        if (details !== undefined) {
          errorDetails = details;
        }
      }
      // Some v3 errors are "unstructured" — the human message sits at the top
      // level (`{ message: "Gift card not found." }`) rather than under `error`.
      // Surface it so call sites show a specific message, not a generic one.
      if (message === 'Request failed') {
        const topMsg = (body as Record<string, unknown>)['message'];
        if (typeof topMsg === 'string' && topMsg.length > 0) {
          message = topMsg;
        }
      }
    }

    return of({
      response_code: err.status,
      status: 'error',
      message,
      error_code: errorCode, // extension field; legacy didn't have this
      error_details: errorDetails, // extension field; v3 structured error details
      data: null,
    });
  }
}
