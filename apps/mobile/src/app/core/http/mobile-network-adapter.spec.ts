/**
 * Unit tests for MobileNetworkAdapter: the pure translate functions, and
 * (bottom of the file) the session contract, auth refresh, 401
 * classification and transient handling, driven through TestBed +
 * HttpTestingController with an in-memory SessionStore backing.
 *
 * The two translate functions and the URL resolver are PURE, they
 * don't touch HttpClient. So they can be tested as straight unit
 * tests without any test harness. They're also where the most subtle
 * bugs would land (id/token extraction edge cases, v3 envelope
 * unwrapping, URL normalization). Covering them gives us regression
 * confidence on the core translation logic.
 *
 * Why the spec exists if CI doesn't run it
 * =========================================
 * Mobile CI is type-check + build only, no `ng test` execution.
 * This spec still earns its place:
 *
 *   1. It compile-checks against the implementation. Any breaking
 *      change to the translate function signatures fails type-check
 *      (CI catches it).
 *   2. It documents expected behaviour with concrete examples that
 *      future devs can read.
 *   3. It's ready when CI test runner gets wired up (M4 hardening).
 *   4. A developer can run it locally with `pnpm --filter @3bayti/mobile test`
 *      to sanity-check refactors.
 */

import { TestBed } from '@angular/core/testing';
import { HttpClient, HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { HttpTestingController, TestRequest, provideHttpClientTesting } from '@angular/common/http/testing';
import { Observable, firstValueFrom } from 'rxjs';

import {
  MobileNetworkAdapter,
  NETWORK_UNAVAILABLE,
  apiErrorCode,
  isDefinitiveRefreshRejection,
  isRetryableRefreshFailure,
  isTokenRejection,
  translateRequestBody,
  toLegacyEnvelope,
} from './mobile-network-adapter';
import {
  SESSION_PERSISTENCE,
  SessionStore,
  type SessionEndReason,
  type SessionPersistence,
} from '../services/session-store.service';
import { I18nService } from '../../i18n.service';

describe('translateRequestBody', () => {
  it('extracts token to Authorization header and drops id', () => {
    const result = translateRequestBody({
      id: 42,
      token: 'abc.def.ghi',
      product: 'red-abaya',
      quantity: 2,
    });
    expect(result.authHeader).toBe('Bearer abc.def.ghi');
    expect(result.translatedBody).toEqual({
      product: 'red-abaya',
      quantity: 2,
    });
  });

  it('returns null authHeader when token is empty string', () => {
    // Legacy convention: unauthenticated calls send token: ''. Don't
    // emit a useless 'Bearer ' header.
    const result = translateRequestBody({
      id: 0,
      token: '',
      email: 'alice@example.com',
    });
    expect(result.authHeader).toBeNull();
    expect(result.translatedBody).toEqual({ email: 'alice@example.com' });
  });

  it('returns null authHeader when token is missing entirely', () => {
    const result = translateRequestBody({ email: 'alice@example.com' });
    expect(result.authHeader).toBeNull();
    expect(result.translatedBody).toEqual({ email: 'alice@example.com' });
  });

  it('handles null body without crashing', () => {
    const result = translateRequestBody(null);
    expect(result.authHeader).toBeNull();
    expect(result.translatedBody).toBeNull();
  });

  it('handles undefined body without crashing', () => {
    const result = translateRequestBody(undefined);
    expect(result.authHeader).toBeNull();
    expect(result.translatedBody).toBeUndefined();
  });

  it('passes arrays through unchanged (no id/token extraction)', () => {
    // Arrays as bodies are unusual but defensive: don't try to walk
    // them as if they were objects.
    const result = translateRequestBody([1, 2, 3]);
    expect(result.authHeader).toBeNull();
    expect(result.translatedBody).toEqual([1, 2, 3]);
  });

  it('does not mutate the input object', () => {
    const original = {
      id: 42,
      token: 'abc',
      product: 'foo',
    };
    const snapshot = { ...original };
    translateRequestBody(original);
    expect(original).toEqual(snapshot);
  });

  it('handles non-string token defensively (numeric token: drop, no header)', () => {
    // Should never happen in practice, but if a call site mis-types
    // token, don't crash and don't emit a bogus header.
    const result = translateRequestBody({ id: 42, token: 12345 });
    expect(result.authHeader).toBeNull();
    expect(result.translatedBody).toEqual({});
  });
});

describe('toLegacyEnvelope', () => {
  it('unwraps v3 {data: T} into envelope.data = T', () => {
    const env = toLegacyEnvelope({ data: { id: 1, name: 'Alice' } });
    expect(env.response_code).toBe(200);
    expect(env.status).toBe('success');
    expect(env.message).toBe('');
    expect(env.data).toEqual({ id: 1, name: 'Alice' });
  });

  it('forwards entire response as data when there is no {data: ...} wrapper', () => {
    // e.g. login response: { access_token, refresh_token, user, ... }
    const loginShape = {
      access_token: 'xyz',
      refresh_token: 'abc',
      user: { id: 1 },
    };
    const env = toLegacyEnvelope(loginShape);
    expect(env.data).toEqual(loginShape);
  });

  it('passes through arrays unchanged (no data unwrap)', () => {
    // Unusual but defensive: top-level array v3 responses
    // (none currently, but possible) should reach call sites
    // as response.data = [...].
    const env = toLegacyEnvelope([1, 2, 3]);
    expect(env.data).toEqual([1, 2, 3]);
  });

  it('handles null v3 response', () => {
    const env = toLegacyEnvelope(null);
    expect(env.data).toBeNull();
  });

  it('handles primitive v3 response (string)', () => {
    const env = toLegacyEnvelope('ok');
    expect(env.data).toBe('ok');
  });

  it('always emits response_code 200 + status success for successful responses', () => {
    // Error responses go through translateError, not toLegacyEnvelope.
    const env = toLegacyEnvelope({ data: { x: 1 } });
    expect(env.response_code).toBe(200);
    expect(env.status).toBe('success');
  });
});

/* ------------------------------------------------------------------------
 * Session contract (refresh / classification). These drive the real
 * adapter against HttpTestingController + an in-memory SessionStore
 * backing, so every 401 / refresh outcome is exercised end-to-end.
 * --------------------------------------------------------------------- */

describe('session error classification', () => {
  it('apiErrorCode reads error.code from an object or JSON text, else null', () => {
    expect(apiErrorCode({ error: { code: 'X', message: 'm' } })).toBe('X');
    expect(apiErrorCode('{"error":{"code":"Y"}}')).toBe('Y');
    expect(apiErrorCode('<html>bad gateway</html>')).toBeNull();
    expect(apiErrorCode({ message: 'flat' })).toBeNull();
    expect(apiErrorCode(null)).toBeNull();
  });

  it('isTokenRejection: only 401 with a token code or no code', () => {
    expect(isTokenRejection(401, { error: { code: 'AUTH_INVALID_TOKEN' } })).toBeTrue();
    expect(isTokenRejection(401, { error: { code: 'AUTH_MISSING_TOKEN' } })).toBeTrue();
    expect(isTokenRejection(401, null)).toBeTrue();
    expect(isTokenRejection(401, { error: { code: 'AUTH_INVALID_CREDENTIALS' } })).toBeFalse();
    expect(isTokenRejection(401, { error: { code: 'OTP_VERIFICATION_FAILED' } })).toBeFalse();
    expect(isTokenRejection(403, { error: { code: 'AUTH_INVALID_TOKEN' } })).toBeFalse();
  });

  it('isDefinitiveRefreshRejection: exactly 401 + AUTH_REFRESH_TOKEN_INVALID', () => {
    expect(isDefinitiveRefreshRejection(401, { error: { code: 'AUTH_REFRESH_TOKEN_INVALID' } })).toBeTrue();
    expect(isDefinitiveRefreshRejection(401, null)).toBeFalse();
    expect(isDefinitiveRefreshRejection(401, { error: { code: 'AUTH_INVALID_TOKEN' } })).toBeFalse();
    expect(isDefinitiveRefreshRejection(403, { error: { code: 'AUTH_REFRESH_TOKEN_INVALID' } })).toBeFalse();
  });

  it('isRetryableRefreshFailure: network/timeout, 403, 429, 5xx only', () => {
    for (const s of [0, 403, 429, 500, 502, 503, 504]) {
      expect(isRetryableRefreshFailure(s)).withContext(String(s)).toBeTrue();
    }
    for (const s of [400, 401, 404, 422]) {
      expect(isRetryableRefreshFailure(s)).withContext(String(s)).toBeFalse();
    }
  });
});

describe('MobileNetworkAdapter session handling', () => {
  class MemoryPersistence implements SessionPersistence {
    /** When true, every read rejects (Preferences unreadable). */
    unreadable = false;
    constructor(public value: string | null) {}
    read(): Promise<string | null> {
      return this.unreadable ? Promise.reject(new Error('storage busy')) : Promise.resolve(this.value);
    }
    write(value: string): Promise<void> { this.value = value; return Promise.resolve(); }
    remove(): Promise<void> { this.value = null; return Promise.resolve(); }
  }

  const PROFILE_URL = 'https://api-v3.3bayti.ae/v3/me/profile';
  const REFRESH_URL = 'https://api-v3.3bayti.ae/v3/auth/refresh';
  const DEVICE_TOKENS_URL = 'https://api-v3.3bayti.ae/v3/me/device-tokens';
  const SESSION = { id: 42, token: 'access-1', refresh_token: 'refresh-1', first_name: 'Mona' };

  let adapter: MobileNetworkAdapter;
  let http: HttpTestingController;
  let store: SessionStore;
  let persistence: MemoryPersistence;
  let ended: SessionEndReason[];

  function setup(initial: Record<string, unknown> | null = SESSION, unreadable = false): void {
    persistence = new MemoryPersistence(initial === null ? null : JSON.stringify(initial));
    persistence.unreadable = unreadable;
    TestBed.configureTestingModule({
      providers: [
        provideHttpClient(),
        provideHttpClientTesting(),
        { provide: SESSION_PERSISTENCE, useValue: persistence },
        { provide: I18nService, useValue: { t: (k: string) => k } },
      ],
    });
    adapter = TestBed.inject(MobileNetworkAdapter);
    http = TestBed.inject(HttpTestingController);
    store = TestBed.inject(SessionStore);
    ended = [];
    store.sessionEnded$.subscribe((r) => ended.push(r));
    // No real back-off in specs.
    (adapter as any).refreshRetryDelaysMs = [0, 0];
  }

  afterEach(() => http.verify());

  /**
   * Let promise / timer hops run until a request matches. Time-boxed rather
   * than tick-counted: an unreadable store retries its read with real
   * (~400ms) back-off before the request goes out.
   */
  async function nextRequest(url: string): Promise<TestRequest> {
    const deadline = Date.now() + 2000;
    while (Date.now() < deadline) {
      const found = http.match((r) => r.url === url);
      if (found.length > 0) {
        expect(found.length).withContext(`one open ${url}`).toBe(1);
        return found[0];
      }
      await new Promise((resolve) => setTimeout(resolve, 0));
    }
    throw new Error(`no request to ${url}`);
  }

  async function settle(): Promise<void> {
    for (let i = 0; i < 5; i++) {
      await new Promise((resolve) => setTimeout(resolve, 0));
    }
  }

  const tokenExpired = { error: { code: 'AUTH_INVALID_TOKEN', message: 'Token expired.' } };
  const unauthorized = { status: 401, statusText: 'Unauthorized' };

  it('sends the CURRENT session token, not the page copy', async () => {
    setup();
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'stale-page-token' }));
    const req = await nextRequest(PROFILE_URL);
    expect(req.request.headers.get('Authorization')).toBe('Bearer access-1');
    req.flush({ data: { user: { id: 42 } } });
    expect((await result).response_code).toBe(200);
  });

  it("falls back to the caller's token when no session is stored", async () => {
    setup(null);
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'caller-token' }));
    const req = await nextRequest(PROFILE_URL);
    expect(req.request.headers.get('Authorization')).toBe('Bearer caller-token');
    req.flush({ data: {} });
    await result;
  });

  it('anonymous calls (no authToken) send no Authorization and never refresh', async () => {
    setup();
    const result = firstValueFrom<any>(adapter.post_v3('POST /auth/logout', { refresh_token: 'refresh-1' }));
    const req = await nextRequest('https://api-v3.3bayti.ae/v3/auth/logout');
    expect(req.request.headers.has('Authorization')).toBeFalse();
    req.flush(tokenExpired, unauthorized);
    expect((await result).response_code).toBe(401);
    await settle();
    expect(http.match(REFRESH_URL).length).toBe(0);
  });

  it('passes a business 401 (wrong current password) through untouched, no refresh', async () => {
    setup();
    const result = firstValueFrom<any>(
      adapter.delete_v3('DELETE /me', { authToken: 'x', body: { current_password: 'nope' } }),
    );
    const req = await nextRequest('https://api-v3.3bayti.ae/v3/me');
    expect(req.request.method).toBe('DELETE');
    expect(req.request.body).toEqual({ current_password: 'nope' });
    req.flush({ error: { code: 'AUTH_INVALID_CREDENTIALS', message: 'Wrong password.' } }, unauthorized);
    const res = await result;
    expect(res.response_code).toBe(401);
    expect(res.error_code).toBe('AUTH_INVALID_CREDENTIALS');
    expect(res.message).toBe('Wrong password.');
    await settle();
    expect(http.match(REFRESH_URL).length).toBe(0);
    expect(store.currentAccessToken()).toBe('access-1');
  });

  it('token 401 -> refresh -> stores the new pair and retries once with it', async () => {
    setup();
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
    (await nextRequest(PROFILE_URL)).flush(tokenExpired, unauthorized);

    const refresh = await nextRequest(REFRESH_URL);
    expect(refresh.request.body).toEqual({ refresh_token: 'refresh-1' });
    expect(refresh.request.headers.has('Authorization')).toBeFalse();
    refresh.flush({
      access_token: 'access-2',
      access_token_expires_at: '2030-01-01T00:00:00Z',
      refresh_token: 'refresh-2',
      refresh_token_expires_at: '2031-01-01T00:00:00Z',
      user: { id: 42, first_name: 'Mona R' },
    });

    const retry = await nextRequest(PROFILE_URL);
    expect(retry.request.headers.get('Authorization')).toBe('Bearer access-2');
    retry.flush({ data: { ok: true } });

    expect((await result).response_code).toBe(200);
    expect(store.currentAccessToken()).toBe('access-2');
    expect(store.currentRefreshToken()).toBe('refresh-2');
    await settle();
    expect(JSON.parse(persistence.value!)['first_name']).toBe('Mona R');
  });

  it('a 401 without an error code also refreshes', async () => {
    setup();
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
    (await nextRequest(PROFILE_URL)).flush(null, unauthorized);
    (await nextRequest(REFRESH_URL)).flush({ access_token: 'access-2', refresh_token: 'refresh-2' });
    (await nextRequest(PROFILE_URL)).flush({ data: {} });
    expect((await result).response_code).toBe(200);
  });

  it('concurrent token 401s share ONE refresh', async () => {
    setup();
    const a = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
    const b = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
    await settle();
    const first = http.match(PROFILE_URL);
    expect(first.length).toBe(2);
    first.forEach((r) => r.flush(tokenExpired, unauthorized));

    const refresh = await nextRequest(REFRESH_URL);
    refresh.flush({ access_token: 'access-2', refresh_token: 'refresh-2' });
    await settle();
    const retries = http.match(PROFILE_URL);
    expect(retries.length).toBe(2);
    retries.forEach((r) => {
      expect(r.request.headers.get('Authorization')).toBe('Bearer access-2');
      r.flush({ data: {} });
    });
    expect((await a).response_code).toBe(200);
    expect((await b).response_code).toBe(200);
    expect(http.match(REFRESH_URL).length).toBe(0);
  });

  it('DEFINITIVE: refresh 401 AUTH_REFRESH_TOKEN_INVALID ends the session; caller sees its 401', async () => {
    setup();
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
    (await nextRequest(PROFILE_URL)).flush(tokenExpired, unauthorized);
    (await nextRequest(REFRESH_URL)).flush(
      { error: { code: 'AUTH_REFRESH_TOKEN_INVALID', message: 'Session ended.' } },
      unauthorized,
    );
    const res = await result;
    expect(res.response_code).toBe(401);
    expect(res.error_code).toBe('AUTH_INVALID_TOKEN');
    expect(store.hasSession()).toBeFalse();
    expect(persistence.value).toBeNull();
    expect(ended).toEqual(['rejected']);
  });

  it('TRANSIENT: refresh 5xx is retried, then 503 NETWORK_UNAVAILABLE and the session is KEPT', async () => {
    setup();
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
    (await nextRequest(PROFILE_URL)).flush(tokenExpired, unauthorized);
    for (let i = 0; i < 3; i++) {
      (await nextRequest(REFRESH_URL)).flush({ error: { code: 'INTERNAL' } }, { status: 502, statusText: 'Bad Gateway' });
    }
    const res = await result;
    expect(res.response_code).toBe(503);
    expect(res.status).toBe('error');
    expect(res.error_code).toBe(NETWORK_UNAVAILABLE);
    expect(res.message).toBe('connection_problem_try_again');
    expect(store.currentAccessToken()).toBe('access-1');
    expect(store.currentRefreshToken()).toBe('refresh-1');
    expect(persistence.value).not.toBeNull();
    expect(ended).toEqual([]);
    await settle();
    expect(http.match(REFRESH_URL).length).toBe(0);
  });

  it('TRANSIENT: network error, proxy 403 and 429 are retried; a later success recovers', async () => {
    setup();
    (adapter as any).refreshRetryDelaysMs = [0, 0, 0];
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
    (await nextRequest(PROFILE_URL)).flush(tokenExpired, unauthorized);
    (await nextRequest(REFRESH_URL)).error(new ProgressEvent('error'), { status: 0 });
    (await nextRequest(REFRESH_URL)).flush('<html>Forbidden</html>', { status: 403, statusText: 'Forbidden' });
    (await nextRequest(REFRESH_URL)).flush({ error: { code: 'RATE_LIMITED' } }, { status: 429, statusText: 'Too Many' });
    (await nextRequest(REFRESH_URL)).flush({ access_token: 'access-2', refresh_token: 'refresh-2' });
    (await nextRequest(PROFILE_URL)).flush({ data: {} });
    expect((await result).response_code).toBe(200);
    expect(store.currentAccessToken()).toBe('access-2');
    expect(ended).toEqual([]);
  });

  it('TRANSIENT: a refresh that times out keeps the session', async () => {
    setup();
    (adapter as any).refreshTimeoutMs = 5;
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
    (await nextRequest(PROFILE_URL)).flush(tokenExpired, unauthorized);
    const res = await result; // three attempts, each abandoned after 5ms
    expect(res.response_code).toBe(503);
    expect(res.error_code).toBe(NETWORK_UNAVAILABLE);
    expect(store.hasSession()).toBeTrue();
    expect(ended).toEqual([]);
    const abandoned = http.match(REFRESH_URL);
    expect(abandoned.length).toBe(3);
    abandoned.forEach((r) => expect(r.cancelled).toBeTrue());
  });

  const nonRetryable: Array<[string, number, unknown]> = [
    ['422', 422, { error: { code: 'VALIDATION_FAILED' } }],
    ['401 without the refresh code', 401, { error: { code: 'AUTH_INVALID_TOKEN' } }],
    ['401 with a non-JSON body', 401, 'Unauthorized'],
  ];
  for (const [label, status, body] of nonRetryable) {
    it(`TRANSIENT (no retry): refresh ${label} keeps the session`, async () => {
      setup();
      const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
      (await nextRequest(PROFILE_URL)).flush(tokenExpired, unauthorized);
      (await nextRequest(REFRESH_URL)).flush(body as any, { status, statusText: 'x' });
      const res = await result;
      expect(res.response_code).toBe(503);
      expect(res.error_code).toBe(NETWORK_UNAVAILABLE);
      expect(store.currentRefreshToken()).toBe('refresh-1');
      expect(ended).toEqual([]);
      await settle();
      expect(http.match(REFRESH_URL).length).toBe(0);
    });
  }

  it('a stored blob WITHOUT a refresh token is unrecoverable: session ends', async () => {
    setup({ id: 42, token: 'legacy-access' });
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
    (await nextRequest(PROFILE_URL)).flush(tokenExpired, unauthorized);
    expect((await result).response_code).toBe(401);
    expect(store.hasSession()).toBeFalse();
    expect(ended).toEqual(['rejected']);
    expect(http.match(REFRESH_URL).length).toBe(0);
  });

  it('a token 401 with no session stored just passes through (no refresh, no session end)', async () => {
    setup(null);
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'old-page-token' }));
    (await nextRequest(PROFILE_URL)).flush(tokenExpired, unauthorized);
    expect((await result).response_code).toBe(401);
    expect(ended).toEqual([]);
    expect(http.match(REFRESH_URL).length).toBe(0);
  });

  it('a second token 401 after a successful refresh is transient (no loop, session kept)', async () => {
    setup();
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
    (await nextRequest(PROFILE_URL)).flush(tokenExpired, unauthorized);
    (await nextRequest(REFRESH_URL)).flush({ access_token: 'access-2', refresh_token: 'refresh-2' });
    (await nextRequest(PROFILE_URL)).flush(tokenExpired, unauthorized);
    const res = await result;
    expect(res.response_code).toBe(503);
    expect(res.error_code).toBe(NETWORK_UNAVAILABLE);
    expect(store.hasSession()).toBeTrue();
    await settle();
    expect(http.match(REFRESH_URL).length).toBe(0);
  });

  it('a late refresh never resurrects a session that was logged out meanwhile', async () => {
    setup();
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
    (await nextRequest(PROFILE_URL)).flush(tokenExpired, unauthorized);
    const refresh = await nextRequest(REFRESH_URL);
    await store.endSession('logout');
    refresh.flush({ access_token: 'access-2', refresh_token: 'refresh-2' });
    expect((await result).response_code).toBe(401);
    expect(store.hasSession()).toBeFalse();
    expect(persistence.value).toBeNull();
  });

  it('retries with a token another request already refreshed (no second refresh)', async () => {
    setup();
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'x' }));
    const first = await nextRequest(PROFILE_URL); // sent with access-1
    await store.replaceTokens('refresh-1', { access_token: 'access-2', refresh_token: 'refresh-2' });
    first.flush(tokenExpired, unauthorized);
    const retry = await nextRequest(PROFILE_URL);
    expect(retry.request.headers.get('Authorization')).toBe('Bearer access-2');
    retry.flush({ data: {} });
    expect((await result).response_code).toBe(200);
    expect(http.match(REFRESH_URL).length).toBe(0);
  });

  it('DELETE sends its body (push deactivation needs { token })', async () => {
    setup();
    const result = firstValueFrom<any>(
      adapter.delete_v3('DELETE /me/device-tokens', { authToken: 'x', body: { token: 'fcm-1' } }),
    );
    const req = await nextRequest(DEVICE_TOKENS_URL);
    expect(req.request.method).toBe('DELETE');
    expect(req.request.body).toEqual({ token: 'fcm-1' });
    req.flush(null, { status: 204, statusText: 'No Content' });
    expect((await result).response_code).toBe(200);
  });

  it('an unreadable stored session makes a token 401 transient, never "signed out"', async () => {
    setup(SESSION, true);
    const result = firstValueFrom<any>(adapter.get_v3('GET /me/profile', { authToken: 'page-token' }));
    const req = await nextRequest(PROFILE_URL);
    // Store unreadable -> the page's own copy is the best token available.
    expect(req.request.headers.get('Authorization')).toBe('Bearer page-token');
    req.flush(tokenExpired, unauthorized);
    const res = await result;
    expect(res.response_code).toBe(503);
    expect(res.error_code).toBe(NETWORK_UNAVAILABLE);
    expect(ended).toEqual([]);
    expect(persistence.value).not.toBeNull();
    expect(http.match(REFRESH_URL).length).toBe(0);
  });

  describe('detached (clean-up after a local sign-out)', () => {
    it('sends exactly the given token, never the stored session\'s, and never refreshes', async () => {
      setup(); // e.g. the NEXT account already signed in with access-1
      const result = firstValueFrom<any>(
        adapter.delete_v3('DELETE /me/device-tokens', {
          authToken: 'captured-old',
          body: { token: 'fcm-1' },
          detached: true,
        }),
      );
      const req = await nextRequest(DEVICE_TOKENS_URL);
      expect(req.request.headers.get('Authorization')).toBe('Bearer captured-old');
      expect(req.request.body).toEqual({ token: 'fcm-1' });
      req.flush(tokenExpired, unauthorized);
      expect((await result).response_code).toBe(401);
      await settle();
      expect(http.match(REFRESH_URL).length).toBe(0);
      expect(store.currentAccessToken()).toBe('access-1');
      expect(ended).toEqual([]);
    });

    it('refreshDetached posts the given refresh token anonymously and leaves the store alone', async () => {
      setup(null);
      const pair = adapter.refreshDetached('captured-refresh');
      const req = await nextRequest(REFRESH_URL);
      expect(req.request.body).toEqual({ refresh_token: 'captured-refresh' });
      expect(req.request.headers.has('Authorization')).toBeFalse();
      req.flush({ access_token: 'fresh-access', refresh_token: 'fresh-refresh' });
      expect(await pair).toEqual({ access_token: 'fresh-access', refresh_token: 'fresh-refresh' });
      expect(store.hasSession()).toBeFalse();
      expect(persistence.value).toBeNull();
    });

    it('refreshDetached resolves null on a failure, without retrying or ending any session', async () => {
      setup();
      const pair = adapter.refreshDetached('captured-refresh');
      (await nextRequest(REFRESH_URL)).flush(
        { error: { code: 'AUTH_REFRESH_TOKEN_INVALID', message: 'Session ended.' } },
        unauthorized,
      );
      expect(await pair).toBeNull();
      await settle();
      expect(http.match(REFRESH_URL).length).toBe(0);
      expect(store.currentRefreshToken()).toBe('refresh-1');
      expect(ended).toEqual([]);
    });

    it('refreshDetached without a token sends nothing', async () => {
      setup();
      expect(await adapter.refreshDetached('')).toBeNull();
      expect(http.match(REFRESH_URL).length).toBe(0);
    });
  });

  describe('authedRequest (raw requests)', () => {
    function rawGet(): Observable<unknown> {
      const client = TestBed.inject(HttpClient);
      return adapter.authedRequest((authHeader) =>
        client.get(PROFILE_URL, { headers: authHeader ? { Authorization: authHeader } : {} }),
      );
    }

    it('uses the session token and refreshes on a token 401', async () => {
      setup();
      const result = firstValueFrom(rawGet());
      const req = await nextRequest(PROFILE_URL);
      expect(req.request.headers.get('Authorization')).toBe('Bearer access-1');
      req.flush(tokenExpired, unauthorized);
      (await nextRequest(REFRESH_URL)).flush({ access_token: 'access-2', refresh_token: 'refresh-2' });
      (await nextRequest(PROFILE_URL)).flush({ raw: true });
      expect(await result).toEqual({ raw: true });
    });

    it('passes business errors through as the original HttpErrorResponse', async () => {
      setup();
      const result = firstValueFrom(rawGet());
      (await nextRequest(PROFILE_URL)).flush(
        { error: { code: 'CHAT_MESSAGE_BLOCKED', message: 'Blocked.' } },
        { status: 422, statusText: 'Unprocessable' },
      );
      const err: any = await result.then(() => null, (e) => e);
      expect(err instanceof HttpErrorResponse).toBeTrue();
      expect(err.status).toBe(422);
      expect(err.error.error.code).toBe('CHAT_MESSAGE_BLOCKED');
    });

    it('fails with a 503 NETWORK_UNAVAILABLE HttpErrorResponse on a transient refresh failure', async () => {
      setup();
      const result = firstValueFrom(rawGet());
      (await nextRequest(PROFILE_URL)).flush(tokenExpired, unauthorized);
      (await nextRequest(REFRESH_URL)).flush({}, { status: 422, statusText: 'x' });
      const err: any = await result.then(() => null, (e) => e);
      expect(err instanceof HttpErrorResponse).toBeTrue();
      expect(err.status).toBe(503);
      expect(err.error.error.code).toBe(NETWORK_UNAVAILABLE);
      expect(store.hasSession()).toBeTrue();
    });
  });
});
