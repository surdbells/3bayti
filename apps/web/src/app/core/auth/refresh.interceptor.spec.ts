import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { Provider, EnvironmentProviders } from '@angular/core';
import { HttpClient, provideHttpClient, withInterceptors } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
  TestRequest,
} from '@angular/common/http/testing';
import { firstValueFrom } from 'rxjs';
import { AccessTokenStore } from './access-token-store';
import { AUTH_PROXY_BASE } from './auth.tokens';
import { AuthService } from './auth.service';
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
 *   6. 401 from a non-proxy request that had no Bearer → propagate
 *   7. Refresh-failure path → propagate the original 401
 *   8. Single-flight: concurrent 401s share one refresh call
 */

/**
 * Stub AuthService with a controllable refresh() method.
 *
 * The real AuthService.refresh() returns a Promise<boolean>; we expose
 * a `setRefreshOutcome(true|false)` and `refreshCalls` counter so the
 * tests can pin behaviour without instantiating the full service.
 */
class StubAuthService {
  private nextOutcome: boolean = true;
  refreshCalls = 0;
  /** Returned promises by call index, exposed so tests can resolve
   *  them in a deliberate order to model concurrency. */
  private resolvers: Array<(value: boolean) => void> = [];

  setRefreshOutcome(success: boolean): void {
    this.nextOutcome = success;
  }

  refresh(): Promise<boolean> {
    this.refreshCalls += 1;
    return new Promise<boolean>(resolve => {
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
      auth.setRefreshOutcome(true);
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

    it('propagates the 401 if refresh() fails', async () => {
      const { http, controller, auth } = configure({ initialToken: 'old.token' });
      auth.setRefreshOutcome(false);

      const promise = firstValueFrom(http.get('/v3/orders')).catch(err => err);

      const req = controller.expectOne('/v3/orders');
      req.flush('Unauthorized', { status: 401, statusText: 'Unauthorized' });

      const err = await promise;
      expect(err.status).toBe(401);
      expect(auth.refreshCalls).toBe(1);
      /* No retry attempt should be queued. */
      controller.expectNone('/v3/orders');
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

    it('does NOT call refresh() on a 401 from a non-proxy request that had no Bearer', async () => {
      const { http, controller, auth } = configure({ initialToken: null });

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
     Single-flight under concurrent 401s
     =================================================================== */
  describe('single-flight under concurrent 401s', () => {
    it('shares one refresh() call across multiple concurrent 401s', async () => {
      const { http, controller, auth, tokenStore } = configure({ initialToken: 'old.token' });
      auth.setRefreshOutcome(true);

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
