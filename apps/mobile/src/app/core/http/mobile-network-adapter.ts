import { HttpClient, HttpErrorResponse, HttpHeaders } from '@angular/common/http';
import { Injectable, inject } from '@angular/core';
import {
  Observable,
  catchError,
  defer,
  firstValueFrom,
  from,
  map,
  of,
  switchMap,
  throwError,
  timeout,
} from 'rxjs';

import {
  resolveConfig,
  resolveUrl,
  type EndpointConfig,
} from '@3bayti/api-client';

import { GlobalComponent } from '../../global-component';
import { I18nService } from '../../i18n.service';
import { SessionStore, type SessionTokenPair } from '../services/session-store.service';
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
 * error_code the adapter returns (response_code 503) when a token 401 could
 * not be recovered because the refresh itself failed transiently (offline,
 * timeout, 5xx, 429, proxy 403). The session is KEPT; pages show the message
 * ("Connection problem...") instead of a sign-in prompt.
 */
export const NETWORK_UNAVAILABLE = 'NETWORK_UNAVAILABLE';

/** 401 codes that mean "this access token is missing/expired/invalid". */
const TOKEN_ERROR_CODES: ReadonlySet<string> = new Set(['AUTH_INVALID_TOKEN', 'AUTH_MISSING_TOKEN']);

/**
 * Read the v3 error code from an error body ({"error":{"code":"..."}}).
 * Tolerates a body that arrived as unparsed JSON text. Null when absent.
 */
export function apiErrorCode(body: unknown): string | null {
  let b = body;
  if (typeof b === 'string') {
    try {
      b = JSON.parse(b);
    } catch {
      return null;
    }
  }
  if (b === null || typeof b !== 'object') return null;
  const e = (b as Record<string, unknown>)['error'];
  if (e === null || typeof e !== 'object') return null;
  const code = (e as Record<string, unknown>)['code'];
  return typeof code === 'string' && code.length > 0 ? code : null;
}

/**
 * Should a failed authenticated request refresh-and-retry? Only a 401 whose
 * code says the ACCESS TOKEN is the problem (or that carries no code). Any
 * other 401 (AUTH_INVALID_CREDENTIALS for a wrong current password,
 * OTP_VERIFICATION_FAILED for a wrong code, ...) is a business error the
 * caller must see untouched.
 */
export function isTokenRejection(status: number, body: unknown): boolean {
  if (status !== 401) return false;
  const code = apiErrorCode(body);
  return code === null || TOKEN_ERROR_CODES.has(code);
}

/**
 * The ONE definitive "this session is over" signal: POST /auth/refresh
 * answering 401 AUTH_REFRESH_TOKEN_INVALID. Every other refresh failure is
 * transient and must never sign the customer out.
 */
export function isDefinitiveRefreshRejection(status: number, body: unknown): boolean {
  return status === 401 && apiErrorCode(body) === 'AUTH_REFRESH_TOKEN_INVALID';
}

/** Transient refresh failures worth retrying: network/timeout, proxy 403, 429, 5xx. */
export function isRetryableRefreshFailure(status: number): boolean {
  return status === 0 || status === 403 || status === 429 || status >= 500;
}

/** HTTP status of a thrown request error; 0 for network errors and timeouts. */
function httpStatusOf(err: unknown): number {
  if (err instanceof HttpErrorResponse) return err.status;
  const status = Number((err as { status?: unknown } | null)?.status ?? 0);
  return Number.isFinite(status) ? status : 0;
}

/** Outcome of trying to recover from a token 401. */
type RecoveryOutcome =
  | { kind: 'retry'; token: string }   // retry once with this access token
  | { kind: 'transient' }              // refresh failed transiently, session kept
  | { kind: 'ended' }                  // the server ended the session
  | { kind: 'none' };                  // no session to recover (signed out)

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
      // Drop `token` regardless, v3 doesn't accept it in body.
    } else if (key === 'id') {
      // Drop `id`, v3 derives user from JWT, not from body.
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
 * Exported for unit testability, see translateRequestBody.
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
 * MobileNetworkAdapter, the mobile app's v3 API client.
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
 * NOTE: the *_v3 methods do NOT apply a request transform, each call site
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
 * Auth + refresh (session contract)
 * =================================
 * A call that passes { authToken } is authenticated: the adapter sends the
 * CURRENT access token from SessionStore (the page's copy may be stale) and
 * falls back to the caller's token only when no session is stored.
 * Only a 401 whose code is AUTH_INVALID_TOKEN / AUTH_MISSING_TOKEN (or has no
 * code) triggers a single-flight POST /v3/auth/refresh (refreshInFlight),
 * then one retry with the new token. Business 401s (wrong password, wrong
 * OTP) reach the caller untouched. Refresh outcomes:
 *   - 200                                 -> tokens stored (compare-and-set)
 *   - 401 AUTH_REFRESH_TOKEN_INVALID      -> SessionStore.endSession('rejected');
 *                                            the caller sees its original 401
 *   - anything else (offline, timeout, 403, 429, 5xx, ...) -> transient: the
 *     session is KEPT, the refresh is retried (~1.5s, ~4s) and the caller
 *     gets { response_code: 503, error_code: NETWORK_UNAVAILABLE } instead
 *     of the 401, so pages never show a sign-in prompt for a blip. A stored
 *     session that can't be read right now counts as transient too.
 * Clean-up after a local sign-out uses the store-free paths instead:
 * delete_v3 { detached: true } (send exactly the given token) and
 * refreshDetached().
 *
 * History
 * =======
 * This began as the strangler-fig adapter bridging 120+ legacy
 * post_request / get_request call sites to v3. All call sites are now on
 * direct v3, and the legacy machinery (post_request, get_request, route,
 * tryConvertPostToGet, the legacy callV3, the request transforms, and
 * url-route-resolver) has been deleted, this is now a pure v3 client.
 */
@Injectable({ providedIn: 'root' })
export class MobileNetworkAdapter {
  private http = inject(HttpClient);
  private session = inject(SessionStore);
  private i18n = inject(I18nService);

  /** v3 backend base URL. Hard-coded for now; M4 may move to env config. */
  private readonly v3BaseUrl = 'https://api-v3.3bayti.ae';

  /**
   * Back-off before each extra refresh attempt after a retryable transient
   * failure (so up to 3 attempts in total). Instance field so specs can
   * shorten it.
   */
  protected refreshRetryDelaysMs: readonly number[] = [1500, 4000];

  /** One refresh attempt gives up (a transient, retryable failure) after this long. */
  protected refreshTimeoutMs = 20_000;

  /**
   * Single-flight refresh.
   *
   * Concurrent token 401s that arrive WHILE a refresh is in flight await
   * the same promise rather than firing N refreshes. The field is cleared
   * when the promise settles (it never rejects), so the next 401 starts a
   * fresh refresh.
   *
   * Why this lives on the adapter and not a separate service
   * ========================================================
   * Refresh is intrinsically coupled to the auth-aware HTTP path, it must
   * be triggerable from the adapter's 401 handling. SessionStore owns the
   * blob; the adapter only asks it to compare-and-set the new pair or to
   * end the session on a definitive rejection.
   */
  private refreshInFlight: Promise<RecoveryOutcome> | null = null;

  /**
   * Call a v3 endpoint directly by routeKey, without any legacy-URL
   * lookup. Use this for v3-only endpoints (those with `oldPath: ''`
   * in ENDPOINT_ROUTING), they have no legacy URL to resolve FROM,
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
   *   - Attach Authorization header IFF opts.authToken provided (the live
   *     session token is sent, the caller's value marks the call as
   *     authenticated and is only a fallback)
   *   - Same envelope wrap and error translation as the legacy path
   *
   * @param routeKey - routing table key (e.g. 'POST /auth/confirm')
   * @param body - v3-shaped request body (forwarded unchanged)
   * @param opts.authToken - pass (even '') for authenticated calls; omit for anonymous ones
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
      /** Clean-up for a session that ALREADY ENDED locally (logout push
       *  deactivation): send authToken exactly as given, never swap in the
       *  stored session's token (it may already be the next account's) and
       *  never refresh-and-retry or end a session. */
      detached?: boolean;
    },
  ): Observable<unknown> {
    return this.callV3Direct('DELETE', routeKey, opts?.body ?? null, opts);
  }

  /**
   * Run a RAW (non-envelope) authenticated request with the same session
   * handling as the *_v3 methods, for call sites that need HttpClient or
   * fetch directly (multipart uploads, the chat service's raw JSON).
   *
   * `send` builds and issues the request with the given Authorization
   * header value (null when there is no session). It must fail with an
   * HttpErrorResponse for HTTP errors (HttpClient does; fetch callers wrap a
   * 401 themselves). The current session token is used (callerToken only as
   * a fallback when none is stored); a token 401 refreshes and retries
   * once; a transient refresh failure fails with a 503 HttpErrorResponse
   * carrying { error: { code: NETWORK_UNAVAILABLE, message } }; every other
   * error passes through unchanged.
   */
  authedRequest<T>(send: (authHeader: string | null) => Observable<T>, callerToken?: string): Observable<T> {
    return this.authedCall(callerToken ?? '', send);
  }

  /**
   * POST /v3/auth/refresh for a session that is NO LONGER stored: the
   * logout clean-up uses it to get an access token for the push
   * deactivation when the captured one has expired. A single attempt
   * (refreshTimeoutMs) that never reads or writes SessionStore; resolves
   * the new pair, or null on any failure. Safe after rotation (contract
   * C2), and the logout that follows ends the whole family, including the
   * pair minted here (C3).
   */
  async refreshDetached(refreshToken: string): Promise<SessionTokenPair | null> {
    if (refreshToken === '') {
      return null;
    }
    try {
      const response = await firstValueFrom(
        this.executeHttpRequest('POST', this.refreshUrl(), { refresh_token: refreshToken }, null)
          .pipe(timeout(this.refreshTimeoutMs)),
      );
      const accessToken = this.extractAccessToken(response);
      if (accessToken === null) {
        return null;
      }
      const next = (response as Record<string, unknown>)['refresh_token'];
      return {
        access_token: accessToken,
        refresh_token: typeof next === 'string' && next.length > 0 ? next : undefined,
      };
    } catch {
      return null;
    }
  }

  /* ------ Internals ----------------------------------------------- */

  /**
   * Build a v3 URL with both path-param substitution and query string.
   *
   * resolveUrl handles path params (`:foo` -> pathParams.foo). Query
   * string is appended here from queryParams (preserving stable
   * insertion order, which matters for log readability and caching).
   *
   * Boolean values are stringified to "true"/"false", matches v3's
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
   * Issue a v3-direct request, sibling to callV3 but with simpler
   * input semantics: no legacy body translation; explicit auth token;
   * optional path params.
   *
   * Used by post_v3 / get_v3 for v3-native call sites (register OTP
   * confirm, password reset, refresh-token, logout, etc.). See the
   * post_v3 docblock for design rationale.
   *
   * 401 handling: only when opts.authToken is passed, see authedCall.
   */
  private callV3Direct(
    method: HttpMethod,
    routeKey: string,
    body: unknown,
    opts?: {
      authToken?: string;
      pathParams?: Record<string, string>;
      queryParams?: Record<string, string | number | boolean>;
      detached?: boolean;
    },
  ): Observable<unknown> {
    let cfg: EndpointConfig;
    try {
      cfg = resolveConfig(routeKey);
    } catch {
      // Unknown route key, return an error envelope through the
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
      // serve a legacy-targeted route, that's what post_request /
      // get_request are for. Surface as an error envelope.
      return of({
        response_code: 500,
        status: 'error',
        message: `Route ${routeKey} has target=${cfg.target}; cannot use v3-direct`,
        error_code: 'ROUTE_NOT_NEW',
        data: null,
      });
    }

    // M3.1.7-I, build URL with both path-params and optional query string.
    // Reuses buildV3UrlWithQuery so query strings are stable + URL-encoded
    // identically to the POST-to-GET conversion path.
    const url = opts?.queryParams && Object.keys(opts.queryParams).length > 0
      ? this.buildV3UrlWithQuery(routeKey, opts?.pathParams, opts.queryParams)
      : resolveUrl(
          routeKey,
          { old: GlobalComponent.baseURL, new: this.v3BaseUrl },
          opts?.pathParams,
        );

    // The refresh endpoint itself MUST NOT trigger refresh-on-401
    // recursion. Same for /auth/login, /auth/logout and other anonymous
    // calls (no authToken passed): there is no session token to refresh.
    // Detached calls send their own token as-is and leave the store alone.
    if (routeKey === 'POST /auth/refresh' || opts?.authToken === undefined || opts.detached === true) {
      const authHeader = opts?.authToken ? `Bearer ${opts.authToken}` : null;
      return this.executeHttpRequest(method, url, body, authHeader).pipe(
        map((v3Response) => this.envelopeAndTransform(v3Response, routeKey)),
        catchError((err: HttpErrorResponse) => this.translateError(err)),
      );
    }

    // Authenticated (the caller passed a token, possibly '' from a page that
    // thought it was a guest): send the live session token, refresh on a
    // token 401, and surface every outcome through the legacy envelope.
    return this.authedCall(opts.authToken, (authHeader) =>
      this.executeHttpRequest(method, url, body, authHeader),
    ).pipe(
      map((v3Response) => this.envelopeAndTransform(v3Response, routeKey)),
      catchError((err: HttpErrorResponse) => this.translateError(err)),
    );
  }

  /**
   * Execute the actual HTTP request. Pure dispatch on method, no
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
    // DELETE carries its body too: DELETE /me/device-tokens { token } and
    // DELETE /me { current_password } are rejected by the API without it.
    return method === 'GET'
      ? this.http.get<unknown>(url, { headers, responseType: 'json' })
      : method === 'POST'
        ? this.http.post<unknown>(url, body, { headers, responseType: 'json' })
        : method === 'PUT'
          ? this.http.put<unknown>(url, body, { headers, responseType: 'json' })
          : method === 'PATCH'
            ? this.http.patch<unknown>(url, body, { headers, responseType: 'json' })
            : this.http.delete<unknown>(url, { headers, responseType: 'json', body });
  }

  /**
   * Run an authenticated request with the session contract applied.
   *
   * Behaviour:
   *   1. Send with the CURRENT session access token (callerToken only when
   *      no session is stored; no header at all when neither exists).
   *   2. Success, or any error that is not a token 401: pass through.
   *   3. Token 401 (isTokenRejection): recoverFromTokenRejection, then
   *      - retry ONCE with the new token. A second token 401 while the
   *        session is still stored is reported as transient (never a
   *        sign-in prompt) and is not refreshed again, so there is no
   *        refresh loop;
   *      - transient refresh failure: fail with transientError() (503
   *        NETWORK_UNAVAILABLE) instead of the 401, session kept;
   *      - session ended / no session: the original 401 passes through.
   *
   * `send` takes the Authorization header each time because the retry must
   * carry the new token, not the one that just failed. Errors are thrown
   * (HttpErrorResponse); callers decide how to surface them.
   */
  private authedCall<T>(
    callerToken: string,
    send: (authHeader: string | null) => Observable<T>,
  ): Observable<T> {
    return defer(() => this.sessionToken$(callerToken)).pipe(
      switchMap((token) => {
        if (token === '') {
          return send(null);
        }
        return send(`Bearer ${token}`).pipe(
          catchError((err: unknown) => {
            if (!this.isHttpTokenRejection(err)) {
              return throwError(() => err);
            }
            return from(this.recoverFromTokenRejection(token)).pipe(
              switchMap((outcome) => {
                if (outcome.kind === 'retry') {
                  return send(`Bearer ${outcome.token}`).pipe(
                    catchError((retryErr: unknown) =>
                      throwError(() =>
                        this.isHttpTokenRejection(retryErr) && this.session.hasSession()
                          ? this.transientError()
                          : retryErr,
                      ),
                    ),
                  );
                }
                if (outcome.kind === 'transient') {
                  return throwError(() => this.transientError());
                }
                return throwError(() => err);
              }),
            );
          }),
        );
      }),
    );
  }

  /** The token to send: the live session token, else the caller's copy. */
  private sessionToken$(callerToken: string): Observable<string> {
    const pick = (): string => this.session.currentAccessToken() || callerToken || '';
    return this.session.isLoaded() ? of(pick()) : from(this.session.ready().then(pick));
  }

  private isHttpTokenRejection(err: unknown): boolean {
    return err instanceof HttpErrorResponse && isTokenRejection(err.status, err.error);
  }

  /**
   * The error reported instead of a token 401 when the session could not be
   * refreshed for a transient reason. translateError turns it into
   * { response_code: 503, error_code: NETWORK_UNAVAILABLE, message }.
   */
  private transientError(): HttpErrorResponse {
    return new HttpErrorResponse({
      status: 503,
      statusText: 'Service Unavailable',
      error: {
        error: {
          code: NETWORK_UNAVAILABLE,
          message: this.i18n.t('connection_problem_try_again'),
        },
      },
    });
  }

  /**
   * Decide how to recover from a token 401 on a request sent with
   * `usedToken`. When the store already holds a different access token
   * (another request refreshed, or a new sign-in happened while this one
   * was in flight) just retry with it; otherwise refresh.
   */
  private async recoverFromTokenRejection(usedToken: string): Promise<RecoveryOutcome> {
    await this.session.ready();
    if (!this.session.isLoaded()) {
      // The stored session couldn't be READ (storage glitch): unknown is
      // not signed out, so report it like any other transient failure.
      return { kind: 'transient' };
    }
    if (!this.session.hasSession()) {
      return { kind: 'none' };
    }
    const current = this.session.currentAccessToken();
    if (current !== '' && current !== usedToken) {
      return { kind: 'retry', token: current };
    }
    return this.refreshOnce();
  }

  /** Single-flight wrapper around runRefresh (see refreshInFlight). */
  private refreshOnce(): Promise<RecoveryOutcome> {
    if (this.refreshInFlight === null) {
      const run: Promise<RecoveryOutcome> = this.runRefresh()
        .catch((): RecoveryOutcome => ({ kind: 'transient' }))
        .finally(() => {
          if (this.refreshInFlight === run) {
            this.refreshInFlight = null;
          }
        });
      this.refreshInFlight = run;
    }
    return this.refreshInFlight;
  }

  /**
   * POST /v3/auth/refresh with the stored refresh_token and classify the
   * result per the session contract:
   *   - 200: SessionStore.replaceTokens (compare-and-set) -> retry
   *   - 401 AUTH_REFRESH_TOKEN_INVALID: endSession('rejected', used) -> ended
   *   - network/timeout (20s), 403, 429, 5xx: retried after each
   *     refreshRetryDelaysMs entry, then transient
   *   - anything else (422, non-JSON, 401 without that code): transient
   * Transient NEVER touches the stored session.
   */
  private async runRefresh(): Promise<RecoveryOutcome> {
    await this.session.ready();
    if (!this.session.isLoaded()) {
      return { kind: 'transient' };
    }
    if (!this.session.hasSession()) {
      return { kind: 'none' };
    }
    const used = this.session.currentRefreshToken();
    if (used === '') {
      // The cached blob predates M3.1.3 (no refresh_token) or is corrupt:
      // nothing can renew it, so the session is over.
      await this.session.endSession('rejected', '');
      return this.outcomeAfterSessionChange();
    }

    const refreshUrl = this.refreshUrl();

    for (let attempt = 0; ; attempt++) {
      // Logged out, or signed in again, while we were backing off.
      if (this.session.currentRefreshToken() !== used) {
        return this.outcomeAfterSessionChange();
      }

      let response: unknown;
      let failure: unknown = null;
      try {
        response = await firstValueFrom(
          this.executeHttpRequest('POST', refreshUrl, { refresh_token: used }, null)
            .pipe(timeout(this.refreshTimeoutMs)),
        );
      } catch (err) {
        failure = err ?? new Error('refresh failed');
      }

      if (failure === null) {
        return this.applyRefreshResponse(used, response);
      }

      const status = httpStatusOf(failure);
      const body = failure instanceof HttpErrorResponse ? failure.error : null;
      if (isDefinitiveRefreshRejection(status, body)) {
        await this.session.endSession('rejected', used);
        return this.outcomeAfterSessionChange();
      }
      if (!isRetryableRefreshFailure(status) || attempt >= this.refreshRetryDelaysMs.length) {
        return { kind: 'transient' };
      }
      await new Promise<void>((resolve) => setTimeout(resolve, this.refreshRetryDelaysMs[attempt]));
    }
  }

  private refreshUrl(): string {
    return resolveUrl('POST /auth/refresh', { old: GlobalComponent.baseURL, new: this.v3BaseUrl }, undefined);
  }

  /** Store a 200 refresh response; a malformed one counts as transient. */
  private async applyRefreshResponse(used: string, response: unknown): Promise<RecoveryOutcome> {
    const accessToken = this.extractAccessToken(response);
    if (accessToken === null) {
      return { kind: 'transient' };
    }
    const r = response as Record<string, unknown>;
    const refreshToken = typeof r['refresh_token'] === 'string' ? (r['refresh_token'] as string) : undefined;
    const applied = await this.session.replaceTokens(
      used,
      { access_token: accessToken, refresh_token: refreshToken },
      r['user'],
    );
    return applied ? { kind: 'retry', token: accessToken } : this.outcomeAfterSessionChange();
  }

  /**
   * After the stored session changed under a refresh: retry with the newer
   * session's token when there is one, otherwise the session is over.
   */
  private outcomeAfterSessionChange(): RecoveryOutcome {
    const current = this.session.currentAccessToken();
    return this.session.hasSession() && current !== ''
      ? { kind: 'retry', token: current }
      : { kind: 'ended' };
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
   * Apply legacy-envelope wrapping AND (optionally) per-routeKey
   * response shape transformation.
   *
   * Three behaviours depending on inputs:
   *
   *   1. No routeKey OR routeKey has no registered transform:
   *      Returns the envelope unchanged. Same as the legacy
   *      toLegacyEnvelope behaviour, used for auth and any other
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
   *      Defensive, a v3 response without a `data` field is anomalous
   *      and we'd rather surface that to the caller than synthesise.
   *
   * Why a helper instead of inlining: both callV3Direct paths (anonymous
   * and authenticated, the latter covering the post-refresh retry) wrap
   * the v3 response in a legacy envelope; one helper keeps the
   * transform-lookup logic in one place.
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
   * envelope, the caller checks response_code to detect.
   *
   * For network-level errors (no response), we propagate via the
   * Observable error channel (matches legacy NetworkService behaviour:
   * the `error` callback fires for transport errors).
   */
  private translateError(err: HttpErrorResponse): Observable<unknown> {
    // Network-level error (status 0 or undefined), propagate via
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
        // here via the success channel, so propagate details too, otherwise
        // call sites that interpolate them (promo messages) render blanks.
        if (details !== undefined) {
          errorDetails = details;
        }
      }
      // Some v3 errors are "unstructured", the human message sits at the top
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
