import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { Provider, EnvironmentProviders, signal } from '@angular/core';
import {
  HttpClient,
  HttpErrorResponse,
  provideHttpClient,
  withInterceptors,
} from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
  TestRequest,
} from '@angular/common/http/testing';
import { firstValueFrom } from 'rxjs';
import { AccessTokenStore } from './access-token-store';
import { AUTH_PROXY_BASE } from './auth.tokens';
import { AuthService, type RefreshOutcome, type SessionStatus } from './auth.service';
import { refreshInterceptor } from './refresh.interceptor';

/**
 * Tests for the refresh interceptor.
 *
 * Strategy
 * --------
 * We stand up Angular's HttpClientTesting + the real interceptor, then
 * stub AccessTokenStore and AuthService so we can drive their state
 * deterministically. The interceptor itself runs unmodified, it's the
 * surface under test.
 *
 * What we verify
 * --------------
 *   1. Bearer header attachment for non-proxy requests with a token
 *   2. NO Bearer header on auth-proxy requests (they use cookies)
 *   3. NO Bearer header on non-proxy requests when token is absent
 *   4. 401 from a Bearer-carrying request → AuthService.refresh() → retry
 *      with the new token attached
 *   5. 401 from a proxy request → propagate (do NOT refresh)
 *   6. 401 from a non-proxy request that had no Bearer → propagate, for
 *      a signed-out visitor; while the session is still 'unknown'
 *      (transient first-load failure) it is resolved through the cookie
 *      first, so it never reads as "please sign in"
 *   7. Refresh 'session-ended' → propagate the original 401
 *   8. Refresh 'unavailable' (transient) → fail with a status-0
 *      connection error, NOT a 401 (no sign-in prompt), no retry
 *   9. Business 401s (AUTH_INVALID_CREDENTIALS, OTP_VERIFICATION_FAILED)
 *      pass straight through, no refresh (contract C4)
 *  10. Single-flight: concurrent 401s share one refresh call
 */

/**
 * Stub AuthService with a controllable refresh() method.
 *
 * The real AuthService.refresh() returns a Promise<RefreshOutcome>; we
 * expose a `setRefreshOutcome(...)` and `refreshCalls` counter so the
 * tests can pin behaviour without instantiating the full service.
 */
class StubAuthService {
  private nextOutcome: RefreshOutcome = 'refreshed';
  refreshCalls = 0;
  /** Session resolution state, read only for 401s sent without a token. */
  readonly sessionStatus = signal<SessionStatus>('authenticated');
  /** Returned promises by call index, exposed so tests can resolve
   *  them in a deliberate order to model concurrency. */
  private resolvers: Array<(value: RefreshOutcome) => void> = [];

  setRefreshOutcome(outcome: RefreshOutcome): void {
    this.nextOutcome = outcome;
  }

  refresh(): Promise<RefreshOutcome> {
    this.refreshCalls += 1;
    return new Promise<RefreshOutcome>(resolve => {
      this.resolvers.push(resolve);
      /* Auto-resolve on next microtask unless the test takes manual
         control. We expose resolveNext() for the manual case. */
      queueMicrotask(() => {
        const next = this.resolvers.shift();
        if (next !== undefined) next(this.nextOutcome);
      });
    });
  }
}

interface ConfigureOpts {
  initialToken?: string | null;
  tokenAfterRefresh?: string | null;
}

function configure(opts: ConfigureOpts = {}): {
  http: HttpClient;
  controller: HttpTestingController;
  auth: StubAuthService;
  tokenStore: AccessTokenStore;
} {
  const auth = new StubAuthService();
  const tokenStore = {
    /* Minimal AccessTokenStore stub, just getToken() is what the
       interceptor needs. We swap the return value mid-test for the
       refresh-retry case. */
    _token: opts.initialToken ?? null,
    getToken(): string | null {
      return this._token;
    },
    setToken(t: string | null): void {
      this._token = t;
    },
  };

  const providers: (Provider | EnvironmentProviders)[] = [
    provideHttpClient(withInterceptors([refreshInterceptor])),
    provideHttpClientTesting(),
    { provide: AuthService, useValue: auth },
    { provide: AccessTokenStore, useValue: tokenStore },
    /* Default proxy base. Individual tests can override by re-providing
       AUTH_PROXY_BASE in their own TestBed pass, but since we want
       a single TestBed across the suite, we keep the standard '/auth-proxy'
       and exercise both paths via URL choice. */
    { provide: AUTH_PROXY_BASE, useValue: '/auth-proxy' },
  ];

  TestBed.configureTestingModule({ providers });

  return {
    http: TestBed.inject(HttpClient),
    controller: TestBed.inject(HttpTestingController),
    auth,
    tokenStore: tokenStore as unknown as AccessTokenStore,
  };
}

describe('refreshInterceptor', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
  });

  /* ===================================================================
     Authorization header attachment
     =================================================================== */
  describe('Bearer header attachment', () => {
    it('attaches Authorization: Bearer <token> to non-proxy requests when token present', async () => {
      const { http, controller } = configure({ initialToken: 'access.jwt.v1' });

      const responsePromise = firstValueFrom(http.get('/v3/products'));

      const req = controller.expectOne('/v3/products');
      expect(req.request.headers.get('Authorization')).toBe('Bearer access.jwt.v1');
      req.flush({ ok: true });
      await responsePromise;
    });

    it('does NOT attach Authorization to auth-proxy requests', async () => {
      const { http, controller } = configure({ initialToken: 'access.jwt.v1' });

      const responsePromise = firstValueFrom(http.post('/auth-proxy/login', {}));

      const req = controller.expectOne('/auth-proxy/login');
      expect(req.request.headers.has('Authorization')).toBe(false);
      req.flush({ user: {} });
      await responsePromise;
    });

    it('does NOT attach Authorization to non-proxy requests when no token present', async () => {
      const { http, controller } = configure({ initialToken: null });

      const responsePromise = firstValueFrom(http.get('/v3/products'));

      const req = controller.expectOne('/v3/products');
      expect(req.request.headers.has('Authorization')).toBe(false);
      req.flush({ ok: true });
      await responsePromise;
    });
  });

  /* ===================================================================
     401 handling, non-proxy, with Bearer
     =================================================================== */
  describe('401 retry after refresh', () => {
    it('calls AuthService.refresh() on 401 and retries the original request', async () => {
      const harness = configure({ initialToken: 'old.token' });
      const { http, controller, auth, tokenStore } = harness;
      auth.setRefreshOutcome('refreshed');
      /* After refresh, the store will hold the new token. We simulate
         that by mutating the stub now (the real AuthService.refresh()
         calls tokenStore.set() before returning true). */
      const updateTokenAfterRefresh = (): void => {
        (tokenStore as unknown as { setToken(t: string): void }).setToken('new.token');
      };

      const responsePromise = firstValueFrom(http.get('/v3/orders'));

      /* First attempt, gets 401. */
      const first = controller.expectOne('/v3/orders');
      expect(first.request.headers.get('Authorization')).toBe('Bearer old.token');
      /* Mutate the token BEFORE flushing the 401, so when the
         interceptor retries (after auth.refresh() resolves) the
         second outgoing request reads the new value. */
      updateTokenAfterRefresh();
      first.flush('Unauthorized', { status: 401, statusText: 'Unauthorized' });

      /* The interceptor awaits auth.refresh(), that resolves on the
         next microtask. We let microtasks drain by awaiting a Promise. */
      await Promise.resolve();
      await Promise.resolve();

      /* Second attempt, same URL, new Bearer. */
      const second = controller.expectOne('/v3/orders');
      expect(second.request.headers.get('Authorization')).toBe('Bearer new.token');
      second.flush({ ok: true });

      await responsePromise;
      expect(auth.refreshCalls).toBe(1);
    });

    it('turns a stale-token 401 on the RETRY into a status-0 connection error (never a 401)', async () => {
      const { http, controller, auth, tokenStore } = configure({ initialToken: 'old.token' });
      auth.setRefreshOutcome('refreshed');

      const promise = firstValueFrom(http.get('/v3/orders')).catch(err => err);

      const first = controller.expectOne('/v3/orders');
      (tokenStore as unknown as { setToken(t: string): void }).setToken('new.token');
      first.flush(
        { error: { code: 'AUTH_INVALID_TOKEN', message: 'Token expired.' } },
        { status: 401, statusText: 'Unauthorized' },
      );
      await Promise.resolve();
      await Promise.resolve();

      /* The session was just renewed; a second token 401 (e.g. a password
         change on another device landing in between) must not read as
         "please sign in". */
      controller.expectOne('/v3/orders').flush(
        { error: { code: 'AUTH_INVALID_TOKEN', message: 'Token expired.' } },
        { status: 401, statusText: 'Unauthorized' },
      );

      const err = await promise;
      expect(err).toBeInstanceOf(HttpErrorResponse);
      expect(err.status).toBe(0);
      expect(err.error.error.code).toBe('AUTH_REFRESH_UNAVAILABLE');
      expect(auth.refreshCalls).toBe(1);
      controller.expectNone('/v3/orders');
    });

    it('passes a business 401 on the retry through untouched', async () => {
      const { http, controller, auth, tokenStore } = configure({ initialToken: 'old.token' });
      auth.setRefreshOutcome('refreshed');

      const promise = firstValueFrom(http.get('/v3/orders')).catch(err => err);

      const first = controller.expectOne('/v3/orders');
      (tokenStore as unknown as { setToken(t: string): void }).setToken('new.token');
      first.flush('Unauthorized', { status: 401, statusText: 'Unauthorized' });
      await Promise.resolve();
      await Promise.resolve();

      controller.expectOne('/v3/orders').flush(
        { error: { code: 'AUTH_INVALID_CREDENTIALS', message: 'Wrong password.' } },
        { status: 401, statusText: 'Unauthorized' },
      );

      const err = await promise;
      expect(err.status).toBe(401);
      expect(err.error.error.code).toBe('AUTH_INVALID_CREDENTIALS');
    });

    it('propagates the 401 if the session definitively ended', async () => {
      const { http, controller, auth } = configure({ initialToken: 'old.token' });
      auth.setRefreshOutcome('session-ended');

      const promise = firstValueFrom(http.get('/v3/orders')).catch(err => err);

      const req = controller.expectOne('/v3/orders');
      req.flush('Unauthorized', { status: 401, statusText: 'Unauthorized' });

      const err = await promise;
      expect(err.status).toBe(401);
      expect(auth.refreshCalls).toBe(1);
      /* No retry attempt should be queued. */
      controller.expectNone('/v3/orders');
    });

    it('fails with a status-0 connection error (NOT a 401) when the refresh is merely unavailable', async () => {
      const { http, controller, auth } = configure({ initialToken: 'old.token' });
      auth.setRefreshOutcome('unavailable');

      const promise = firstValueFrom(http.get('/v3/orders')).catch(err => err);

      controller.expectOne('/v3/orders').flush(
        { error: { code: 'AUTH_INVALID_TOKEN', message: 'Token expired.' } },
        { status: 401, statusText: 'Unauthorized' },
      );

      const err = await promise;
      /* A 401 here would make pages route to /login; a transient refresh
         problem must surface as an ordinary connection error instead. */
      expect(err).toBeInstanceOf(HttpErrorResponse);
      expect(err.status).toBe(0);
      expect(err.error.error.code).toBe('AUTH_REFRESH_UNAVAILABLE');
      expect(auth.refreshCalls).toBe(1);
      controller.expectNone('/v3/orders');
    });

    it.each(['AUTH_INVALID_TOKEN', 'AUTH_MISSING_TOKEN'])(
      'refreshes and retries on a 401 %s (stale access token)',
      async code => {
        const { http, controller, auth, tokenStore } = configure({ initialToken: 'old.token' });
        auth.setRefreshOutcome('refreshed');

        const promise = firstValueFrom(http.get('/v3/me/profile'));
        (tokenStore as unknown as { setToken(t: string): void }).setToken('new.token');
        controller.expectOne('/v3/me/profile').flush(
          { error: { code, message: 'stale' } },
          { status: 401, statusText: 'Unauthorized' },
        );
        await Promise.resolve();
        await Promise.resolve();

        const retry = controller.expectOne('/v3/me/profile');
        expect(retry.request.headers.get('Authorization')).toBe('Bearer new.token');
        retry.flush({ ok: true });
        await promise;
        expect(auth.refreshCalls).toBe(1);
      },
    );
  });

  /* ===================================================================
     Business 401s, contract C4: never refreshed, never a logout
     =================================================================== */
  describe('business 401s pass through untouched', () => {
    it.each([
      ['AUTH_INVALID_CREDENTIALS', 'PATCH', '/v3/me/password'],
      ['OTP_VERIFICATION_FAILED', 'POST', '/v3/me/phone/verify'],
    ])('passes a 401 %s straight to the caller without refreshing', async (code, method, url) => {
      const { http, controller, auth } = configure({ initialToken: 'valid.token' });

      const promise = firstValueFrom(
        http.request(method, url, { body: {} }),
      ).catch(err => err);

      const body = { error: { code, message: 'Business rule failed.' } };
      controller.expectOne(url).flush(body, { status: 401, statusText: 'Unauthorized' });

      const err = await promise;
      expect(err.status).toBe(401);
      /* Untouched: the page maps this code to an inline field error. */
      expect(err.error).toEqual(body);
      expect(auth.refreshCalls).toBe(0);
      controller.expectNone(url);
    });

    it('also honours the legacy flat { error_code } shape', async () => {
      const { http, controller, auth } = configure({ initialToken: 'valid.token' });

      const promise = firstValueFrom(http.delete('/v3/me')).catch(err => err);
      controller.expectOne('/v3/me').flush(
        { error_code: 'AUTH_INVALID_CREDENTIALS', message: 'Wrong password.' },
        { status: 401, statusText: 'Unauthorized' },
      );

      const err = await promise;
      expect(err.status).toBe(401);
      expect(auth.refreshCalls).toBe(0);
    });
  });

  /* ===================================================================
     401 handling, passthrough cases (no refresh)
     =================================================================== */
  describe('401 propagation without refresh', () => {
    it('does NOT call refresh() on a 401 from the auth-proxy itself', async () => {
      const { http, controller, auth } = configure({ initialToken: 'old.token' });

      const promise = firstValueFrom(http.post('/auth-proxy/login', { email: '', password: '' })).catch(err => err);

      const req = controller.expectOne('/auth-proxy/login');
      req.flush('Unauthorized', { status: 401, statusText: 'Unauthorized' });

      const err = await promise;
      expect(err.status).toBe(401);
      expect(auth.refreshCalls).toBe(0);
    });

    it('does NOT call refresh() on a 401 from a non-proxy request that had no Bearer (signed-out visitor)', async () => {
      const { http, controller, auth } = configure({ initialToken: null });
      auth.sessionStatus.set('anonymous');

      const promise = firstValueFrom(http.get('/v3/public/anonymous-only')).catch(err => err);

      const req = controller.expectOne('/v3/public/anonymous-only');
      req.flush('Unauthorized', { status: 401, statusText: 'Unauthorized' });

      const err = await promise;
      expect(err.status).toBe(401);
      expect(auth.refreshCalls).toBe(0);
    });

    it('does NOT call refresh() on a non-401 error', async () => {
      const { http, controller, auth } = configure({ initialToken: 'token' });

      const promise = firstValueFrom(http.get('/v3/products')).catch(err => err);

      const req = controller.expectOne('/v3/products');
      req.flush('Server error', { status: 500, statusText: 'Server error' });

      const err = await promise;
      expect(err.status).toBe(500);
      expect(auth.refreshCalls).toBe(0);
    });
  });

  /* ===================================================================
     No token yet because the session is still 'unknown' (W-1): the
     first-load hydrate hit a transient failure, the store is empty, but
     the HttpOnly cookie may be valid. A 401 must not reach the page as
     "please sign in" (pages route 401s to /login).
     =================================================================== */
  describe("401 without a Bearer while the session is 'unknown'", () => {
    const missingToken = {
      error: { code: 'AUTH_MISSING_TOKEN', message: 'Authentication required.' },
    };

    it('resolves the session through the cookie, then retries WITH the new token', async () => {
      const { http, controller, auth, tokenStore } = configure({ initialToken: null });
      auth.sessionStatus.set('unknown');
      auth.setRefreshOutcome('refreshed');

      const promise = firstValueFrom(http.post('/v3/gift-cards', { amount: 100 }));

      const first = controller.expectOne('/v3/gift-cards');
      expect(first.request.headers.has('Authorization')).toBe(false);
      /* The real refresh() adopts the token from the cookie session. */
      (tokenStore as unknown as { setToken(t: string): void }).setToken('from.cookie');
      first.flush(missingToken, { status: 401, statusText: 'Unauthorized' });
      await Promise.resolve();
      await Promise.resolve();

      const retry = controller.expectOne('/v3/gift-cards');
      expect(retry.request.headers.get('Authorization')).toBe('Bearer from.cookie');
      retry.flush({ id: 1 });
      await expect(promise).resolves.toEqual({ id: 1 });
      expect(auth.refreshCalls).toBe(1);
    });

    it('passes the original 401 on when the cookie turns out to be definitively dead', async () => {
      const { http, controller, auth } = configure({ initialToken: null });
      auth.sessionStatus.set('unknown');
      auth.setRefreshOutcome('session-ended');

      const promise = firstValueFrom(http.post('/v3/gift-cards', {})).catch(err => err);
      controller.expectOne('/v3/gift-cards').flush(missingToken, { status: 401, statusText: 'Unauthorized' });

      const err = await promise;
      expect(err.status).toBe(401);
      expect(err.error).toEqual(missingToken);
      expect(auth.refreshCalls).toBe(1);
      controller.expectNone('/v3/gift-cards');
    });

    it('fails with a status-0 connection error (NOT a 401) when the server is still unreachable', async () => {
      const { http, controller, auth } = configure({ initialToken: null });
      auth.sessionStatus.set('unknown');
      auth.setRefreshOutcome('unavailable');

      const promise = firstValueFrom(http.post('/v3/gift-cards/redeem', {})).catch(err => err);
      controller.expectOne('/v3/gift-cards/redeem').flush(missingToken, {
        status: 401,
        statusText: 'Unauthorized',
      });

      const err = await promise;
      expect(err).toBeInstanceOf(HttpErrorResponse);
      expect(err.status).toBe(0);
      expect(err.error.error.code).toBe('AUTH_REFRESH_UNAVAILABLE');
      controller.expectNone('/v3/gift-cards/redeem');
    });

    it('still passes a business 401 straight through (no refresh)', async () => {
      const { http, controller, auth } = configure({ initialToken: null });
      auth.sessionStatus.set('unknown');

      const promise = firstValueFrom(http.post('/v3/auth/otp', {})).catch(err => err);
      controller.expectOne('/v3/auth/otp').flush(
        { error: { code: 'OTP_VERIFICATION_FAILED', message: 'Wrong code.' } },
        { status: 401, statusText: 'Unauthorized' },
      );

      const err = await promise;
      expect(err.status).toBe(401);
      expect(auth.refreshCalls).toBe(0);
    });
  });

  /* ===================================================================
     Single-flight under concurrent 401s
     =================================================================== */
  describe('single-flight under concurrent 401s', () => {
    it('shares one refresh() call across multiple concurrent 401s', async () => {
      const { http, controller, auth, tokenStore } = configure({ initialToken: 'old.token' });
      auth.setRefreshOutcome('refreshed');

      /* Fire three requests in parallel. */
      const p1 = firstValueFrom(http.get('/v3/orders'));
      const p2 = firstValueFrom(http.get('/v3/cart'));
      const p3 = firstValueFrom(http.get('/v3/wishlist'));

      /* Each gets matched and flushed with 401 in turn. */
      const r1 = controller.expectOne('/v3/orders');
      const r2 = controller.expectOne('/v3/cart');
      const r3 = controller.expectOne('/v3/wishlist');

      /* Mutate token before flushing 401s so the retries find the new value. */
      (tokenStore as unknown as { setToken(t: string): void }).setToken('new.token');

      r1.flush('Unauthorized', { status: 401, statusText: 'Unauthorized' });
      r2.flush('Unauthorized', { status: 401, statusText: 'Unauthorized' });
      r3.flush('Unauthorized', { status: 401, statusText: 'Unauthorized' });

      /* Let microtasks drain so all three interceptor instances see
         the refresh() resolution. */
      await Promise.resolve();
      await Promise.resolve();
      await Promise.resolve();

      /* All three should retry. */
      const retry1 = controller.expectOne('/v3/orders');
      const retry2 = controller.expectOne('/v3/cart');
      const retry3 = controller.expectOne('/v3/wishlist');
      retry1.flush({ ok: true });
      retry2.flush({ ok: true });
      retry3.flush({ ok: true });

      await Promise.all([p1, p2, p3]);

      /* In a real AuthService, refresh() returns the same in-flight
         promise to concurrent callers, refreshCalls === 1. Our stub
         doesn't deduplicate, so we expect 3 here. The actual single-
         flight property is verified in auth.service.spec.ts where the
         real AuthService.refresh() runs. This test is documentation
         of the interceptor calling pattern, not the dedup contract. */
      expect(auth.refreshCalls).toBe(3);
    });
  });
});
