import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed } from '@angular/core/testing';
import {
  PLATFORM_ID,
  Provider,
  EnvironmentProviders,
  ApplicationRef,
  signal,
} from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import {
  HttpTestingController,
  provideHttpClientTesting,
  TestRequest,
} from '@angular/common/http/testing';
import { BehaviorSubject } from 'rxjs';
import { AuthService, LOGOUT_PENDING_KEY } from './auth.service';
import { AccessTokenStore } from './access-token-store';
import { LocaleService } from '../i18n/locale.service';
import {
  AUTH_PROXY_BASE,
  AUTH_REFRESH_LEAD_TIME_MS,
} from './auth.tokens';
import type { AuthUser, BffLoginResponse } from './auth.types';

/**
 * Tests for AuthService.
 *
 * Strategy
 * --------
 * Real HttpClient via HttpClientTesting (so we can match URLs and flush
 * canned responses). LocaleService and ApplicationRef are stubbed -
 * LocaleService because we only care about whether setLocale gets
 * called on a locale mismatch; ApplicationRef because we drive the
 * isStable observable manually to control scheduler arming. The
 * AccessTokenStore is the real one (no need to stub a simple store).
 *
 * Coverage
 * --------
 *   - login(): applies token + user + schedules refresh + syncs locale
 *   - register(): returns RegisterResponse without applying auth state
 *   - confirmRegistration(): same as login on success
 *   - resendOtp(): forwards to /send-otp
 *   - requestPasswordReset(): forwards to /reset
 *   - confirmPasswordReset(): same as login on success
 *   - refresh() single-flight: concurrent callers share one in-flight
 *     promise; both resolve with same boolean
 *   - refresh() success: applies new state
 *   - refresh() definitive 401 AUTH_REFRESH_TOKEN_INVALID: clears state
 *   - refresh() transient failure (network/5xx/429/403/422/non-JSON/401
 *     without the code): KEEPS the session, retries with backoff and on
 *     'online'; a late refresh never resurrects a logged-out session
 *   - logout(): clears local state FIRST, then calls the BFF (queued behind
 *     any refresh on the wire); a pending-logout marker survives a failed
 *     clear and is finished on the next hydrate / 'online'
 *   - cookie ordering: a sign-in waits for an in-flight /me or /refresh
 *     holding the OLD cookie and drops its result; /me + /refresh started
 *     during a sign-in wait for it
 *   - refresh() resolves a still-'unknown' session (interceptor path)
 *   - hydrate() success: applies state from /me
 *   - hydrate() definitive 401: signs out silently
 *   - hydrate() transient error: stays 'unknown', retries, recovers
 *   - whenSessionKnown(): immediate retry kick + optional timeout
 *   - isAuthenticated ignores token expiry / device clock skew
 *   - Scheduler: SSR does not arm; browser arms after isStable
 *   - Locale sync: user.locale === current → no-op; differs → setLocale called
 *   - Locale sync: user.locale === null → no-op
 */

/* ===================================================================
   Stubs
   =================================================================== */

class StubLocaleService {
  private _current = signal<'en' | 'ar'>('en');
  readonly current = this._current.asReadonly();
  setLocaleCalls: Array<'en' | 'ar'> = [];

  setLocale(locale: 'en' | 'ar'): Promise<void> {
    this.setLocaleCalls.push(locale);
    this._current.set(locale);
    return Promise.resolve();
  }

  /** Test helper to seed the current locale without recording a setLocale call. */
  _setCurrent(locale: 'en' | 'ar'): void {
    this._current.set(locale);
  }
}

/* ===================================================================
   Test fixtures
   =================================================================== */

function makeUser(overrides: Partial<AuthUser> = {}): AuthUser {
  return {
    id: 1,
    email: 'jane@example.com',
    phone: '+971501234567',
    country_code: 'AE',
    first_name: 'Jane',
    last_name: 'Doe',
    gender: null,
    dob: null,
    locale: null,
    timezone: null,
    is_phone_verified: true,
    is_email_verified: false,
    needs_email_update: false,
    roles: ['customer'],
    is_store_approved: false,
    is_store_active: false,
    last_login_at: '2026-05-19T12:00:00+00:00',
    avatar_url: null,
    ...overrides,
  };
}

/** The BFF's one-and-only "session is over" body (sent with status 401). */
const SESSION_ENDED_BODY = {
  error: { code: 'AUTH_REFRESH_TOKEN_INVALID', message: 'The session has ended.' },
};

/** The BFF's transient body (sent with status 503); the cookie is untouched. */
const REFRESH_UNAVAILABLE_BODY = {
  error: { code: 'AUTH_REFRESH_UNAVAILABLE', message: 'Try again.' },
};

/** Build an unsigned JWT-shaped token carrying iat/exp (seconds). */
function makeJwt(iatSec: number, expSec: number): string {
  const b64url = (o: object): string =>
    btoa(JSON.stringify(o)).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
  return `${b64url({ alg: 'HS256', typ: 'JWT' })}.${b64url({ sub: '1', iat: iatSec, exp: expSec })}.sig`;
}

function makeLoginResponse(overrides: Partial<BffLoginResponse> = {}): BffLoginResponse {
  return {
    access_token: 'access.jwt.v1',
    access_token_expires_at: new Date(Date.now() + 15 * 60 * 1000).toISOString(),
    refresh_token_expires_at: new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toISOString(),
    user: makeUser(),
    ...overrides,
  };
}

/* ===================================================================
   TestBed setup
   =================================================================== */

interface Harness {
  service: AuthService;
  controller: HttpTestingController;
  tokenStore: AccessTokenStore;
  locale: StubLocaleService;
  /** Push values here to drive AuthService's isStable subscription. */
  emitIsStable: (value: boolean) => void;
}

function setup(opts: {
  platform?: 'browser' | 'server';
  refreshLeadTimeMs?: number;
} = {}): Harness {
  const platform = opts.platform ?? 'browser';
  const locale = new StubLocaleService();
  /* We use a BehaviorSubject so AuthService's `take(1)` subscription
     gets a value on subscribe if one was emitted before construction.
     The Subject is injected via a property-override pattern AFTER
     ApplicationRef is created: TestBed gives us a real ApplicationRef
     (so the framework's internal change-detection wiring is intact),
     then we redefine its `isStable` getter to point at our Subject.

     We hold onto the Subject so tests can call emit on it deliberately. */
  const isStableSubject = new BehaviorSubject<boolean>(false);

  const providers: (Provider | EnvironmentProviders)[] = [
    provideHttpClient(),
    provideHttpClientTesting(),
    { provide: PLATFORM_ID, useValue: platform },
    { provide: LocaleService, useValue: locale },
    { provide: AUTH_PROXY_BASE, useValue: '/auth-proxy' },
    { provide: AUTH_REFRESH_LEAD_TIME_MS, useValue: opts.refreshLeadTimeMs ?? 60_000 },
  ];

  /* destroyAfterEach: resetTestingModule() must run AuthService's
     DestroyRef hooks so its background-retry timers and window
     'online' / 'visibilitychange' listeners don't leak into later tests
     (the global vitest setup disables teardown). */
  TestBed.configureTestingModule({ providers, teardown: { destroyAfterEach: true } });

  /* Force ApplicationRef into the injector first (it's lazy by default)
     then redefine its isStable property to our Subject. */
  const appRef = TestBed.inject(ApplicationRef);
  Object.defineProperty(appRef, 'isStable', {
    configurable: true,
    get: () => isStableSubject.asObservable(),
  });

  const service = TestBed.inject(AuthService);

  return {
    service,
    controller: TestBed.inject(HttpTestingController),
    tokenStore: TestBed.inject(AccessTokenStore),
    locale,
    emitIsStable: (v: boolean) => isStableSubject.next(v),
  };
}

/* ===================================================================
   Tests
   =================================================================== */

describe('AuthService', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.useRealTimers();
    /* A failed logout leaves its marker in jsdom's localStorage. */
    localStorage.removeItem(LOGOUT_PENDING_KEY);
  });

  /* -----------------------------------------------------------------
     login()
     ----------------------------------------------------------------- */
  describe('login()', () => {
    it('POSTs to /auth-proxy/login and applies the response state', async () => {
      const { service, controller, tokenStore } = setup();

      const loginPromise = service.login({
        email: 'jane@example.com',
        password: 'secret',
      });

      const req = controller.expectOne('/auth-proxy/login');
      expect(req.request.method).toBe('POST');
      expect(req.request.body).toEqual({ email: 'jane@example.com', password: 'secret' });
      expect(req.request.withCredentials).toBe(true);

      const response = makeLoginResponse();
      req.flush(response);

      const user = await loginPromise;
      expect(user.email).toBe('jane@example.com');
      expect(service.currentUser()).toEqual(response.user);
      expect(service.isAuthenticated()).toBe(true);
      expect(service.accessToken()).toBe('access.jwt.v1');
      expect(tokenStore.getToken()).toBe('access.jwt.v1');
    });

    it('propagates HttpErrorResponse on 401', async () => {
      const { service, controller } = setup();

      const loginPromise = service.login({
        email: 'jane@example.com',
        password: 'wrong',
      }).catch(err => err);

      const req = controller.expectOne('/auth-proxy/login');
      req.flush(
        { error_code: 'AUTH_INVALID_CREDENTIALS', message: 'Email or password is incorrect.' },
        { status: 401, statusText: 'Unauthorized' },
      );

      const err = await loginPromise;
      expect(err.status).toBe(401);
      expect(err.error.error_code).toBe('AUTH_INVALID_CREDENTIALS');
      /* State should remain unauthenticated. */
      expect(service.currentUser()).toBeNull();
      expect(service.isAuthenticated()).toBe(false);
    });
  });

  /* -----------------------------------------------------------------
     register()
     ----------------------------------------------------------------- */
  describe('register()', () => {
    it('POSTs to /auth-proxy/register and returns verification_id WITHOUT applying auth state', async () => {
      const { service, controller } = setup();

      const promise = service.register({
        email: 'new@example.com',
        phone: '+971501234567',
        password: 'pass1234',
        country_code: 'AE',
      });

      const req = controller.expectOne('/auth-proxy/register');
      expect(req.request.method).toBe('POST');
      req.flush({
        verification_id: 'mc-abc123',
        user: {
          email: 'new@example.com',
          phone: '+971501234567',
          country_code: 'AE',
          is_phone_verified: false,
        },
      });

      const result = await promise;
      expect(result.verification_id).toBe('mc-abc123');
      /* Critical: NO tokens issued yet, NO authentication state set. */
      expect(service.currentUser()).toBeNull();
      expect(service.isAuthenticated()).toBe(false);
    });
  });

  /* -----------------------------------------------------------------
     confirmRegistration()
     ----------------------------------------------------------------- */
  describe('confirmRegistration()', () => {
    it('POSTs to /auth-proxy/confirm and applies the auth response (auto-login)', async () => {
      const { service, controller } = setup();

      const promise = service.confirmRegistration({
        verification_id: 'mc-abc123',
        code: '123456',
      });

      const req = controller.expectOne('/auth-proxy/confirm');
      expect(req.request.body).toEqual({ verification_id: 'mc-abc123', code: '123456' });
      req.flush(makeLoginResponse());

      const user = await promise;
      expect(user.email).toBe('jane@example.com');
      expect(service.isAuthenticated()).toBe(true);
    });
  });

  /* -----------------------------------------------------------------
     resendOtp()
     ----------------------------------------------------------------- */
  describe('resendOtp()', () => {
    it('POSTs email to /auth-proxy/send-otp and returns the new verification_id', async () => {
      const { service, controller } = setup();
      const promise = service.resendOtp('new@example.com');

      const req = controller.expectOne('/auth-proxy/send-otp');
      expect(req.request.body).toEqual({ email: 'new@example.com' });
      req.flush({ verification_id: 'mc-newer-456' });

      const result = await promise;
      expect(result.verification_id).toBe('mc-newer-456');
    });
  });

  /* -----------------------------------------------------------------
     requestPasswordReset()
     ----------------------------------------------------------------- */
  describe('requestPasswordReset()', () => {
    it('POSTs email to /auth-proxy/reset and returns a verification_id', async () => {
      const { service, controller } = setup();
      const promise = service.requestPasswordReset('jane@example.com');

      const req = controller.expectOne('/auth-proxy/reset');
      expect(req.request.body).toEqual({ email: 'jane@example.com' });
      req.flush({ verification_id: 'mc-reset-789' });

      const result = await promise;
      expect(result.verification_id).toBe('mc-reset-789');
    });

    it('does NOT distinguish fake-prefixed verification_id (anti-enumeration)', async () => {
      const { service, controller } = setup();
      const promise = service.requestPasswordReset('not-registered@example.com');

      const req = controller.expectOne('/auth-proxy/reset');
      req.flush({ verification_id: 'fake-aaa111' });

      const result = await promise;
      /* The frontend just passes the value through, the API's fake-prefix
         protocol is hidden from us deliberately. */
      expect(result.verification_id).toBe('fake-aaa111');
    });
  });

  /* -----------------------------------------------------------------
     confirmPasswordReset()
     ----------------------------------------------------------------- */
  describe('confirmPasswordReset()', () => {
    it('POSTs to /auth-proxy/reset-confirm and applies auth state (auto-login)', async () => {
      const { service, controller } = setup();

      const promise = service.confirmPasswordReset({
        verification_id: 'mc-reset-789',
        code: '654321',
        new_password: 'newpass1234',
      });

      const req = controller.expectOne('/auth-proxy/reset-confirm');
      req.flush(makeLoginResponse());

      await promise;
      expect(service.isAuthenticated()).toBe(true);
    });
  });

  /* -----------------------------------------------------------------
     refresh(), single-flight
     ----------------------------------------------------------------- */
  describe('refresh() single-flight', () => {
    it('returns the SAME promise when called concurrently', async () => {
      const { service, controller } = setup();

      /* Three concurrent refresh() calls. */
      const p1 = service.refresh();
      const p2 = service.refresh();
      const p3 = service.refresh();

      /* Only ONE outgoing /refresh request should be made. */
      const req = controller.expectOne('/auth-proxy/refresh');
      req.flush(makeLoginResponse());

      const [r1, r2, r3] = await Promise.all([p1, p2, p3]);
      expect(r1).toBe('refreshed');
      expect(r2).toBe('refreshed');
      expect(r3).toBe('refreshed');

      /* And no second outgoing request was queued. */
      controller.expectNone('/auth-proxy/refresh');
    });

    it('a subsequent refresh() after completion fires a new request', async () => {
      const { service, controller } = setup();

      /* First refresh. */
      const p1 = service.refresh();
      const r1 = controller.expectOne('/auth-proxy/refresh');
      r1.flush(makeLoginResponse());
      expect(await p1).toBe('refreshed');

      /* Second refresh, should issue a NEW request since the first
         resolved. */
      const p2 = service.refresh();
      const r2 = controller.expectOne('/auth-proxy/refresh');
      r2.flush(makeLoginResponse());
      expect(await p2).toBe('refreshed');
    });

    it('returns session-ended and clears state ONLY on the definitive 401 AUTH_REFRESH_TOKEN_INVALID', async () => {
      const { service, controller } = setup();

      /* Seed the service with a logged-in state so we can observe the clear. */
      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await Promise.resolve();
      expect(service.isAuthenticated()).toBe(true);

      /* Now the BFF reports the session is over. */
      const p = service.refresh();
      const req = controller.expectOne('/auth-proxy/refresh');
      req.flush(SESSION_ENDED_BODY, { status: 401, statusText: 'Unauthorized' });

      const result = await p;
      expect(result).toBe('session-ended');
      expect(service.currentUser()).toBeNull();
      expect(service.isAuthenticated()).toBe(false);
      expect(service.sessionStatus()).toBe('anonymous');
    });
  });

  /* -----------------------------------------------------------------
     logout()
     ----------------------------------------------------------------- */
  describe('logout()', () => {
    it('POSTs to /auth-proxy/logout and clears local state on success', async () => {
      const { service, controller } = setup();

      /* Log in first so we have state to clear. */
      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await Promise.resolve();
      expect(service.isAuthenticated()).toBe(true);

      const logoutPromise = service.logout();
      /* Local state is cleared at once (contract C3: clients clear the
         local session, then call logout), not after the BFF answers. */
      expect(service.currentUser()).toBeNull();
      expect(service.sessionStatus()).toBe('anonymous');
      expect(localStorage.getItem(LOGOUT_PENDING_KEY)).not.toBeNull();

      const req = controller.expectOne('/auth-proxy/logout');
      expect(req.request.method).toBe('POST');
      req.flush(null, { status: 204, statusText: 'No Content' });

      await logoutPromise;
      expect(service.currentUser()).toBeNull();
      expect(service.isAuthenticated()).toBe(false);
      /* The cookie clear landed: nothing left pending. */
      expect(localStorage.getItem(LOGOUT_PENDING_KEY)).toBeNull();
    });

    it('still clears local state when the BFF logout call fails, and keeps the pending-logout marker', async () => {
      const { service, controller } = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await Promise.resolve();
      expect(service.isAuthenticated()).toBe(true);

      const logoutPromise = service.logout();
      const req = controller.expectOne('/auth-proxy/logout');
      req.flush('Server error', { status: 500, statusText: 'Server error' });

      /* logout() must resolve cleanly even when the call fails. */
      await logoutPromise;
      expect(service.currentUser()).toBeNull();
      expect(service.isAuthenticated()).toBe(false);
      /* The HttpOnly cookie (400 days) was never cleared: remember that. */
      expect(localStorage.getItem(LOGOUT_PENDING_KEY)).not.toBeNull();
      warn.mockRestore();
    });

    it('the next page load finishes a logout whose cookie clear never landed, instead of signing back in via /me', async () => {
      const first = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      const p = first.service.login({ email: 'a@b.com', password: 'x' });
      first.controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await p;

      const loggingOut = first.service.logout();
      first.controller.expectOne('/auth-proxy/logout').error(new ProgressEvent('error'));
      await loggingOut;

      /* Reload: a fresh app instance, the cookie is still in the browser. */
      TestBed.resetTestingModule();
      const reloaded = setup();
      await reloaded.service.hydrate();

      expect(reloaded.service.sessionStatus()).toBe('anonymous');
      expect(reloaded.service.isAuthenticated()).toBe(false);
      reloaded.controller.expectNone('/auth-proxy/me');
      for (let i = 0; i < 10; i++) await Promise.resolve();
      reloaded.controller
        .expectOne('/auth-proxy/logout')
        .flush(null, { status: 204, statusText: 'No Content' });
      for (let i = 0; i < 10; i++) await Promise.resolve();

      expect(localStorage.getItem(LOGOUT_PENDING_KEY)).toBeNull();
      warn.mockRestore();
    });

    it("retries a pending logout when the browser comes back 'online'", async () => {
      const { service, controller } = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      const p = service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await p;

      const loggingOut = service.logout();
      controller.expectOne('/auth-proxy/logout').flush('Bad gateway', {
        status: 502,
        statusText: 'Bad Gateway',
      });
      await loggingOut;

      window.dispatchEvent(new Event('online'));
      controller.expectOne('/auth-proxy/logout').flush(null, { status: 204, statusText: 'No Content' });
      for (let i = 0; i < 10; i++) await Promise.resolve();

      expect(localStorage.getItem(LOGOUT_PENDING_KEY)).toBeNull();
      expect(service.sessionStatus()).toBe('anonymous');
      warn.mockRestore();
    });

    it('a new sign-in drops a stale pending-logout marker (its cookie replaced the old one)', async () => {
      const { service, controller } = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      const p1 = service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await p1;
      const loggingOut = service.logout();
      controller.expectOne('/auth-proxy/logout').error(new ProgressEvent('error'));
      await loggingOut;
      expect(localStorage.getItem(LOGOUT_PENDING_KEY)).not.toBeNull();

      const p2 = service.login({ email: 'b@b.com', password: 'y' });
      controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await p2;

      expect(localStorage.getItem(LOGOUT_PENDING_KEY)).toBeNull();
      expect(service.isAuthenticated()).toBe(true);
      warn.mockRestore();
    });

    it('a refresh() after logout never reaches the BFF (nothing can re-set the cookie)', async () => {
      const { service, controller } = setup();
      const p = service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await p;

      const loggingOut = service.logout();
      const refreshing = service.refresh();
      controller.expectOne('/auth-proxy/logout').flush(null, { status: 204, statusText: 'No Content' });
      await loggingOut;

      expect(await refreshing).toBe('session-ended');
      controller.expectNone('/auth-proxy/refresh');
    });

    it('honours a logout that is pending from another tab instead of refreshing', async () => {
      const { service, controller } = setup();
      const p = service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await p;

      localStorage.setItem(LOGOUT_PENDING_KEY, '1');
      expect(await service.refresh()).toBe('session-ended');
      expect(service.isAuthenticated()).toBe(false);
      controller.expectNone('/auth-proxy/refresh');
      for (let i = 0; i < 10; i++) await Promise.resolve();
      controller.expectOne('/auth-proxy/logout').flush(null, { status: 204, statusText: 'No Content' });
    });
  });

  /* -----------------------------------------------------------------
     hydrate()
     ----------------------------------------------------------------- */
  describe('hydrate()', () => {
    it('applies state from /auth-proxy/me on success', async () => {
      const { service, controller } = setup();

      const hydratePromise = service.hydrate();
      const req = controller.expectOne('/auth-proxy/me');
      expect(req.request.method).toBe('GET');
      req.flush(makeLoginResponse());

      await hydratePromise;
      expect(service.isAuthenticated()).toBe(true);
      expect(service.sessionStatus()).toBe('authenticated');
    });

    it('silently signs out on 401 AUTH_REFRESH_TOKEN_INVALID (no rejection)', async () => {
      const { service, controller } = setup();

      const hydratePromise = service.hydrate();
      const req = controller.expectOne('/auth-proxy/me');
      req.flush(SESSION_ENDED_BODY, { status: 401, statusText: 'Unauthorized' });

      /* Must resolve, not reject. */
      await expect(hydratePromise).resolves.toBeUndefined();
      expect(service.currentUser()).toBeNull();
      expect(service.isAuthenticated()).toBe(false);
      expect(service.sessionStatus()).toBe('anonymous');
    });

    it('does not throw on a 5xx and keeps the session UNKNOWN (not signed out), logging a warning', async () => {
      const { service, controller } = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

      const hydratePromise = service.hydrate();
      const req = controller.expectOne('/auth-proxy/me');
      req.flush('Server error', { status: 500, statusText: 'Server error' });

      await expect(hydratePromise).resolves.toBeUndefined();
      expect(service.currentUser()).toBeNull();
      /* Signed-out-for-now, but NOT definitively anonymous: a retry is pending. */
      expect(service.sessionStatus()).toBe('unknown');
      expect(warn).toHaveBeenCalled();

      warn.mockRestore();
    });
  });

  /* -----------------------------------------------------------------
     Session persistence: transient failures never end the session
     (product rule: signed in until the user taps "Log out")
     ----------------------------------------------------------------- */
  describe('session persistence on transient failures', () => {
    /** Let the firstValueFrom → catch → finally chains settle. */
    async function drain(): Promise<void> {
      for (let i = 0; i < 10; i++) await Promise.resolve();
    }

    async function signIn(h: Harness): Promise<void> {
      const p = h.service.login({ email: 'a@b.com', password: 'x' });
      h.controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await p;
    }

    /** Flush a request with a transient failure of the given kind. */
    function failTransiently(req: TestRequest, kind: number | 'network' | 'non-json'): void {
      if (kind === 'network') {
        req.error(new ProgressEvent('error'));
      } else if (kind === 'non-json') {
        req.flush('<html>Bad gateway</html>', { status: 502, statusText: 'Bad Gateway' });
      } else if (kind === 503) {
        req.flush(REFRESH_UNAVAILABLE_BODY, { status: 503, statusText: 'Service Unavailable' });
      } else if (kind === 401) {
        /* A 401 WITHOUT the definitive code is not a session end. */
        req.flush({ error: { code: 'SOMETHING_ELSE', message: 'x' } }, { status: 401, statusText: 'Unauthorized' });
      } else {
        req.flush({ error: { code: 'UPSTREAM', message: 'x' } }, { status: kind, statusText: 'Error' });
      }
    }

    it.each<number | 'network' | 'non-json'>(['network', 503, 500, 429, 403, 422, 401, 'non-json'])(
      'refresh() failing with %s keeps currentUser and the session',
      async kind => {
        const h = setup();
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
        await signIn(h);
        const userBefore = h.service.currentUser();

        const p = h.service.refresh();
        failTransiently(h.controller.expectOne('/auth-proxy/refresh'), kind);

        expect(await p).toBe('unavailable');
        expect(h.service.currentUser()).toEqual(userBefore);
        expect(h.service.isAuthenticated()).toBe(true);
        expect(h.service.sessionStatus()).toBe('authenticated');
        /* The token is kept too (stale, but the next 401 retries the refresh). */
        expect(h.tokenStore.getToken()).toBe('access.jwt.v1');
        warn.mockRestore();
      },
    );

    it('retries a transient refresh failure in the background with backoff, then recovers', async () => {
      vi.useFakeTimers();
      const h = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      await signIn(h);

      const p = h.service.refresh();
      failTransiently(h.controller.expectOne('/auth-proxy/refresh'), 503);
      expect(await p).toBe('unavailable');

      /* First retry after 2s. */
      vi.advanceTimersByTime(1_999);
      h.controller.expectNone('/auth-proxy/refresh');
      vi.advanceTimersByTime(1);
      failTransiently(h.controller.expectOne('/auth-proxy/refresh'), 'network');
      await drain();

      /* Backoff doubles: the next retry is 4s later. */
      vi.advanceTimersByTime(3_999);
      h.controller.expectNone('/auth-proxy/refresh');
      vi.advanceTimersByTime(1);
      h.controller.expectOne('/auth-proxy/refresh').flush(
        makeLoginResponse({ access_token: 'access.jwt.v2' }),
      );
      await drain();

      expect(h.tokenStore.getToken()).toBe('access.jwt.v2');
      expect(h.service.isAuthenticated()).toBe(true);
      warn.mockRestore();
    });

    it("retries immediately when the browser comes back 'online'", async () => {
      vi.useFakeTimers();
      const h = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      await signIn(h);

      const p = h.service.refresh();
      failTransiently(h.controller.expectOne('/auth-proxy/refresh'), 'network');
      await p;

      window.dispatchEvent(new Event('online'));
      h.controller.expectOne('/auth-proxy/refresh').flush(makeLoginResponse());
      await drain();

      /* The pending backoff timer was consumed (and success reset it):
         nothing else fires before the next pre-emptive refresh (~14m). */
      vi.advanceTimersByTime(120_000);
      await drain();
      h.controller.expectNone('/auth-proxy/refresh');
      expect(h.service.isAuthenticated()).toBe(true);
      warn.mockRestore();
    });

    it('a refresh that resolves after an explicit logout never resurrects the session, and the cookie clear waits for it', async () => {
      const h = setup();
      await signIn(h);

      const refreshing = h.service.refresh();
      const refreshReq = h.controller.expectOne('/auth-proxy/refresh');

      const loggingOut = h.service.logout();
      /* Signed out locally at once... */
      expect(h.service.sessionStatus()).toBe('anonymous');
      /* ...but the BFF clear is held until the refresh on the wire has
         landed, so that refresh's Set-Cookie can't arrive AFTER the
         Max-Age=0 and put a live token back in the cookie. */
      h.controller.expectNone('/auth-proxy/logout');

      refreshReq.flush(makeLoginResponse({ access_token: 'late.token' }));
      expect(await refreshing).toBe('session-ended');
      await drain();

      h.controller.expectOne('/auth-proxy/logout').flush(null, { status: 204, statusText: 'No Content' });
      await loggingOut;

      expect(h.service.currentUser()).toBeNull();
      expect(h.tokenStore.getToken()).toBeNull();
      expect(h.service.sessionStatus()).toBe('anonymous');
    });

    it("refresh() resolves a still-'unknown' session through the cookie (interceptor path)", async () => {
      const h = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      const hydrating = h.service.hydrate();
      failTransiently(h.controller.expectOne('/auth-proxy/me'), 503);
      await hydrating;
      expect(h.service.sessionStatus()).toBe('unknown');

      const p = h.service.refresh();
      h.controller.expectOne('/auth-proxy/refresh').flush(makeLoginResponse());

      expect(await p).toBe('refreshed');
      expect(h.service.sessionStatus()).toBe('authenticated');
      expect(h.service.isAuthenticated()).toBe(true);
      warn.mockRestore();
    });

    it("refresh() on a still-'unknown' session: the definitive rejection settles it as anonymous; a transient one keeps it unknown", async () => {
      const h = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      const hydrating = h.service.hydrate();
      failTransiently(h.controller.expectOne('/auth-proxy/me'), 'network');
      await hydrating;

      const transient = h.service.refresh();
      failTransiently(h.controller.expectOne('/auth-proxy/refresh'), 502);
      expect(await transient).toBe('unavailable');
      expect(h.service.sessionStatus()).toBe('unknown');

      const definitive = h.service.refresh();
      h.controller.expectOne('/auth-proxy/refresh').flush(SESSION_ENDED_BODY, {
        status: 401,
        statusText: 'Unauthorized',
      });
      expect(await definitive).toBe('session-ended');
      expect(h.service.sessionStatus()).toBe('anonymous');

      /* Settled anonymous: no further refresh reaches the BFF. */
      expect(await h.service.refresh()).toBe('session-ended');
      h.controller.expectNone('/auth-proxy/refresh');
      warn.mockRestore();
    });

    it('first-load hydrate: a transient failure leaves the session unknown, then a retry restores it', async () => {
      vi.useFakeTimers();
      const h = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

      const hydrating = h.service.hydrate();
      failTransiently(h.controller.expectOne('/auth-proxy/me'), 503);
      await hydrating;
      expect(h.service.sessionStatus()).toBe('unknown');
      expect(h.service.isAuthenticated()).toBe(false);

      vi.advanceTimersByTime(2_000);
      h.controller.expectOne('/auth-proxy/me').flush(makeLoginResponse());
      await drain();

      expect(h.service.sessionStatus()).toBe('authenticated');
      expect(h.service.isAuthenticated()).toBe(true);
      warn.mockRestore();
    });

    it('first-load hydrate retry: a later definitive 401 settles it as anonymous', async () => {
      vi.useFakeTimers();
      const h = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

      const hydrating = h.service.hydrate();
      failTransiently(h.controller.expectOne('/auth-proxy/me'), 'network');
      await hydrating;

      vi.advanceTimersByTime(2_000);
      h.controller.expectOne('/auth-proxy/me').flush(SESSION_ENDED_BODY, {
        status: 401,
        statusText: 'Unauthorized',
      });
      await drain();

      expect(h.service.sessionStatus()).toBe('anonymous');
      /* Settled: no further retries. */
      vi.advanceTimersByTime(120_000);
      h.controller.expectNone('/auth-proxy/me');
      warn.mockRestore();
    });

    it('whenSessionKnown(): kicks an immediate retry while unknown and resolves when it succeeds', async () => {
      const h = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

      const hydrating = h.service.hydrate();
      failTransiently(h.controller.expectOne('/auth-proxy/me'), 503);
      await hydrating;

      const known = h.service.whenSessionKnown();
      /* No waiting out the 2s backoff: the retry goes out right away. */
      h.controller.expectOne('/auth-proxy/me').flush(makeLoginResponse());

      await expect(known).resolves.toBe('authenticated');
      warn.mockRestore();
    });

    it('whenSessionKnown(timeout): resolves with "unknown" if the session is still unresolved', async () => {
      vi.useFakeTimers();
      const h = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

      const hydrating = h.service.hydrate();
      failTransiently(h.controller.expectOne('/auth-proxy/me'), 503);
      await hydrating;

      const known = h.service.whenSessionKnown(5_000);
      /* The kicked retry hangs (no response). */
      h.controller.expectOne('/auth-proxy/me');
      vi.advanceTimersByTime(5_000);

      await expect(known).resolves.toBe('unknown');
      warn.mockRestore();
    });

    it('whenSessionKnown(): resolves immediately once the session is known', async () => {
      const h = setup();
      await signIn(h);
      await expect(h.service.whenSessionKnown()).resolves.toBe('authenticated');
      h.controller.expectNone('/auth-proxy/me');
    });
  });

  /* -----------------------------------------------------------------
     Cookie ordering (W-3): a /me or /refresh holding the PREVIOUS
     cookie must never land after a sign-in. Its Set-Cookie would sit on
     top of the new session and the next refresh would silently swap
     the signed-in account.
     ----------------------------------------------------------------- */
  describe('cookie ordering around a sign-in', () => {
    async function drain(): Promise<void> {
      for (let i = 0; i < 10; i++) await Promise.resolve();
    }

    const accountA = makeUser({ id: 1, email: 'a@example.com' });
    const accountB = makeUser({ id: 2, email: 'b@example.com' });

    /** First-load hydrate fails transiently: session 'unknown'. */
    async function unknownSession(h: Harness): Promise<void> {
      const hydrating = h.service.hydrate();
      h.controller.expectOne('/auth-proxy/me').flush(
        { error: { code: 'AUTH_REFRESH_UNAVAILABLE', message: 'x' } },
        { status: 503, statusText: 'Service Unavailable' },
      );
      await hydrating;
    }

    it('a sign-in waits for an in-flight /me (old cookie) and drops its result', async () => {
      const h = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      await unknownSession(h);

      /* A hydrate retry for account A is on the wire... */
      void h.service.whenSessionKnown();
      const meReq = h.controller.expectOne('/auth-proxy/me');

      /* ...when the user signs in as B on /login. */
      const signingIn = h.service.login({ email: 'b@example.com', password: 'x' });
      h.controller.expectNone('/auth-proxy/login');

      meReq.flush(makeLoginResponse({ access_token: 'token.A', user: accountA }));
      await drain();
      /* A is never applied (no A→B swap that the cart would miss). */
      expect(h.service.currentUser()).toBeNull();
      expect(h.tokenStore.getToken()).toBeNull();

      /* Only now does the login go out, so ITS Set-Cookie lands last. */
      h.controller
        .expectOne('/auth-proxy/login')
        .flush(makeLoginResponse({ access_token: 'token.B', user: accountB }));
      await signingIn;

      expect(h.service.currentUser()?.id).toBe(2);
      expect(h.tokenStore.getToken()).toBe('token.B');
      expect(h.service.sessionStatus()).toBe('authenticated');
      warn.mockRestore();
    });

    it('a sign-in waits for an in-flight /refresh too, and does not apply its (old) user', async () => {
      const h = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      await unknownSession(h);

      const refreshing = h.service.refresh();
      const refreshReq = h.controller.expectOne('/auth-proxy/refresh');
      const signingIn = h.service.login({ email: 'b@example.com', password: 'x' });
      h.controller.expectNone('/auth-proxy/login');

      refreshReq.flush(makeLoginResponse({ access_token: 'token.A', user: accountA }));
      /* Superseded: reported as transient, never as signed in. */
      expect(await refreshing).toBe('unavailable');
      expect(h.service.currentUser()).toBeNull();
      await drain();

      h.controller
        .expectOne('/auth-proxy/login')
        .flush(makeLoginResponse({ access_token: 'token.B', user: accountB }));
      await signingIn;
      expect(h.service.currentUser()?.id).toBe(2);
      warn.mockRestore();
    });

    it('a /refresh started during a sign-in waits for it, then rotates the NEW cookie', async () => {
      const h = setup();
      const signingIn = h.service.login({ email: 'b@example.com', password: 'x' });
      const loginReq = h.controller.expectOne('/auth-proxy/login');

      const refreshing = h.service.refresh();
      h.controller.expectNone('/auth-proxy/refresh');

      loginReq.flush(makeLoginResponse({ access_token: 'token.B', user: accountB }));
      await signingIn;
      await drain();

      h.controller
        .expectOne('/auth-proxy/refresh')
        .flush(makeLoginResponse({ access_token: 'token.B2', user: accountB }));
      expect(await refreshing).toBe('refreshed');
      expect(h.tokenStore.getToken()).toBe('token.B2');
      expect(h.service.currentUser()?.id).toBe(2);
    });

    it('if the sign-in fails, the superseded session is retried and restored', async () => {
      vi.useFakeTimers();
      const h = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      await unknownSession(h);

      void h.service.whenSessionKnown();
      const meReq = h.controller.expectOne('/auth-proxy/me');
      const signingIn = h.service.login({ email: 'b@example.com', password: 'wrong' }).catch(e => e);
      meReq.flush(makeLoginResponse({ access_token: 'token.A', user: accountA }));
      await drain();

      h.controller.expectOne('/auth-proxy/login').flush(
        { error: { code: 'AUTH_INVALID_CREDENTIALS', message: 'Wrong password.' } },
        { status: 401, statusText: 'Unauthorized' },
      );
      expect((await signingIn).status).toBe(401);
      expect(h.service.sessionStatus()).toBe('unknown');

      /* The background retry (re-armed when the /me was dropped) brings
         back the session the cookie still holds. */
      vi.advanceTimersByTime(60_000);
      h.controller
        .expectOne('/auth-proxy/me')
        .flush(makeLoginResponse({ access_token: 'token.A', user: accountA }));
      await drain();
      expect(h.service.currentUser()?.id).toBe(1);
      expect(h.service.sessionStatus()).toBe('authenticated');
      warn.mockRestore();
    });
  });

  /* -----------------------------------------------------------------
     isAuthenticated is session-based, not token-expiry-based (W5)
     ----------------------------------------------------------------- */
  describe('isAuthenticated vs. token expiry / clock skew', () => {
    it('stays true when the in-memory access token has already expired', async () => {
      const { service, controller } = setup();
      const p = service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(
        makeLoginResponse({
          access_token_expires_at: new Date(Date.now() - 60_000).toISOString(),
        }),
      );
      await p;

      expect(service.isAuthenticated()).toBe(true);
    });

    it('a device clock far ahead of the server neither signs out nor loops the refresh scheduler', async () => {
      vi.useFakeTimers();
      try {
        const h = setup({ refreshLeadTimeMs: 60_000 });
        /* Server clock is 30 minutes BEHIND the device: the token's
           expires_at (server time) already looks past by the local clock,
           but its iat→exp lifetime is a healthy 15 minutes. */
        const serverNowSec = Math.floor(Date.now() / 1000) - 30 * 60;
        const token = makeJwt(serverNowSec, serverNowSec + 15 * 60);

        const p = h.service.login({ email: 'a@b.com', password: 'x' });
        h.controller.expectOne('/auth-proxy/login').flush(
          makeLoginResponse({
            access_token: token,
            access_token_expires_at: new Date((serverNowSec + 15 * 60) * 1000).toISOString(),
          }),
        );
        await p;

        expect(h.service.isAuthenticated()).toBe(true);

        /* Not fired immediately (that would spin a tight refresh loop). */
        vi.advanceTimersByTime(1_000);
        await Promise.resolve();
        h.controller.expectNone('/auth-proxy/refresh');

        /* Fires at lifetime - lead = 14 minutes after receipt. */
        vi.advanceTimersByTime(14 * 60 * 1000 - 1_000);
        await Promise.resolve();
        h.controller.expectOne('/auth-proxy/refresh').flush(makeLoginResponse());
      } finally {
        vi.useRealTimers();
      }
    });
  });

  /* -----------------------------------------------------------------
     hydrate() session resilience (H0.2, "cart disappears on reload")

     Regression guard for the bug where a successful /me hydrate was
     undone by a throwing locale side-effect, logging the user out on
     every full page load and leaving the cart (and all authed
     features) empty.
     ----------------------------------------------------------------- */
  describe('hydrate() session resilience (H0.2)', () => {
    it('keeps the session when the user.locale is undefined (no crash, no logout)', async () => {
      const { service, controller } = setup();
      /* Pre-fix: syncLocale guarded `!== null` only, so an undefined
         locale reached `undefined.startsWith(...)` and threw; hydrate's
         catch then ran applyLogoutState and isAuthenticated became false. */
      const resp = makeLoginResponse({
        user: makeUser({ locale: undefined as unknown as null }),
      });

      const p = service.hydrate();
      controller.expectOne('/auth-proxy/me').flush(resp);
      await p;

      expect(service.isAuthenticated()).toBe(true);
      expect(service.currentUser()).not.toBeNull();
    });

    it('unwraps a double-nested { user: { ... } } body from a mismatched /me build', async () => {
      const { service, controller } = setup();
      const flat = makeUser({ id: 2, email: 'nested@example.com' });
      /* Simulate a stale BFF /me that double-wraps the user. applyAuthState
         must normalise it so _currentUser is the real AuthUser. */
      const resp = makeLoginResponse({ user: { user: flat } as unknown as AuthUser });

      const p = service.hydrate();
      controller.expectOne('/auth-proxy/me').flush(resp);
      await p;

      expect(service.isAuthenticated()).toBe(true);
      expect(service.currentUser()?.id).toBe(2);
      expect(service.currentUser()?.email).toBe('nested@example.com');
    });

    it('does NOT wipe the session if the locale side-effect throws', async () => {
      const { service, controller, locale } = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      /* Force the NON-CRITICAL locale side-effect to blow up. applyAuthState
         must isolate it so the session survives. */
      vi.spyOn(locale, 'setLocale').mockImplementation(() => {
        throw new Error('locale boom');
      });
      locale._setCurrent('en');
      const resp = makeLoginResponse({ user: makeUser({ locale: 'ar' }) });

      const p = service.hydrate();
      controller.expectOne('/auth-proxy/me').flush(resp);
      await p;

      expect(service.isAuthenticated()).toBe(true);
      expect(service.currentUser()).not.toBeNull();
      expect(warn).toHaveBeenCalled();
      warn.mockRestore();
    });
  });

  /* -----------------------------------------------------------------
     Pre-emptive refresh scheduler
     ----------------------------------------------------------------- */
  describe('pre-emptive refresh scheduler', () => {
    it('arms a refresh timer on the browser after isStable emits', async () => {
      vi.useFakeTimers();
      try {
        /* Use a very short lead time so the scheduler fires soon after login.
           access_token_expires_at is 15 minutes ahead; lead time 10 mins
           means fireAtMs is ~5 minutes after now. We'll advance 6 minutes
           to trigger it. */
        const { service, controller, emitIsStable } = setup({
          platform: 'browser',
          refreshLeadTimeMs: 10 * 60 * 1000,
        });

        /* App stabilises (constructor subscription fires). */
        emitIsStable(true);
        await Promise.resolve();

        service.login({ email: 'a@b.com', password: 'x' });
        controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
        await Promise.resolve();

        /* Advance time past the scheduled-refresh fire point. */
        vi.advanceTimersByTime(6 * 60 * 1000);
        await Promise.resolve();
        await Promise.resolve();

        /* A refresh should have been triggered. */
        const refreshReq = controller.expectOne('/auth-proxy/refresh');
        refreshReq.flush(makeLoginResponse());
      } finally {
        vi.useRealTimers();
      }
    });

    it('fires the refresh immediately when the token is already near-expiry on login', async () => {
      vi.useFakeTimers();
      try {
        const { service, controller, emitIsStable } = setup({
          platform: 'browser',
          refreshLeadTimeMs: 60_000,
        });
        emitIsStable(true);
        await Promise.resolve();

        /* Issue a response with expires_at JUST 30 seconds in the future
          , well INSIDE the refresh lead window. Scheduler should fire
           immediately (clamped delay = 0). */
        const loginPromise = service.login({ email: 'a@b.com', password: 'x' });
        controller.expectOne('/auth-proxy/login').flush(
          makeLoginResponse({
            access_token_expires_at: new Date(Date.now() + 30_000).toISOString(),
          }),
        );
        await loginPromise;

        /* setTimeout(0) puts the refresh on the next task queue.
           Advance 1ms to fire it. */
        vi.advanceTimersByTime(1);
        await Promise.resolve();
        await Promise.resolve();

        const refreshReq = controller.expectOne('/auth-proxy/refresh');
        refreshReq.flush(makeLoginResponse());
      } finally {
        vi.useRealTimers();
      }
    });
  });

  /* -----------------------------------------------------------------
     Locale sync
     ----------------------------------------------------------------- */
  describe('locale sync on auth success', () => {
    it('does NOT call setLocale when user.locale is null', async () => {
      const { service, controller, locale } = setup();
      locale._setCurrent('en');

      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(
        makeLoginResponse({ user: makeUser({ locale: null }) }),
      );
      await Promise.resolve();

      expect(locale.setLocaleCalls).toEqual([]);
    });

    it('does NOT call setLocale when user.locale matches current', async () => {
      const { service, controller, locale } = setup();
      locale._setCurrent('en');

      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(
        makeLoginResponse({ user: makeUser({ locale: 'en' }) }),
      );
      await Promise.resolve();

      expect(locale.setLocaleCalls).toEqual([]);
    });

    it('calls setLocale when user.locale differs from current', async () => {
      const { service, controller, locale } = setup();
      locale._setCurrent('en');

      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(
        makeLoginResponse({ user: makeUser({ locale: 'ar' }) }),
      );
      await Promise.resolve();

      expect(locale.setLocaleCalls).toEqual(['ar']);
    });
  });

  /* -----------------------------------------------------------------
     Locale push to /v3/me/profile (Y.1-J)
     ----------------------------------------------------------------- */
  describe('locale push to server (Y.1-J)', () => {
    it('does NOT push when no user is authenticated', async () => {
      const { controller, locale } = setup({ platform: 'browser' });
      /* Change locale BEFORE login → effect fires with user===null,
         must not PATCH. */
      await locale.setLocale('ar');
      TestBed.tick();
      controller.expectNone('https://api-v3.3bayti.ae/v3/me/profile');
    });

    it('does NOT push on login when user.locale already matches the new value', async () => {
      const { service, controller, locale } = setup({ platform: 'browser' });
      locale._setCurrent('en');

      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(
        makeLoginResponse({ user: makeUser({ locale: 'en' }) }),
      );
      await Promise.resolve();
      TestBed.tick();

      controller.expectNone('https://api-v3.3bayti.ae/v3/me/profile');
    });

    it('PATCHES /v3/me/profile when an authenticated user changes locale', async () => {
      const { service, controller, locale } = setup({ platform: 'browser' });
      locale._setCurrent('en');

      /* Step 1: log in. */
      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(
        makeLoginResponse({ user: makeUser({ locale: 'en' }) }),
      );
      await Promise.resolve();
      TestBed.tick();

      /* Step 2: switch locale. The effect should fire and PATCH. */
      await locale.setLocale('ar');
      TestBed.tick();

      const req = controller.expectOne('https://api-v3.3bayti.ae/v3/me/profile');
      expect(req.request.method).toBe('PATCH');
      expect(req.request.body).toEqual({ locale: 'ar' });
      req.flush({});
    });

    it('does NOT push twice for the same locale value', async () => {
      const { service, controller, locale } = setup({ platform: 'browser' });
      locale._setCurrent('en');

      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(
        makeLoginResponse({ user: makeUser({ locale: 'en' }) }),
      );
      await Promise.resolve();
      TestBed.tick();

      /* Switch to ar, fires one PATCH. */
      await locale.setLocale('ar');
      TestBed.tick();
      controller.expectOne('https://api-v3.3bayti.ae/v3/me/profile').flush({});

      /* setLocale('ar') again with the same value is a no-op in
         LocaleService (the signal doesn't transition), so no PATCH. */
      await locale.setLocale('ar');
      TestBed.tick();
      controller.expectNone('https://api-v3.3bayti.ae/v3/me/profile');
    });

    it('silently swallows PATCH failures (does not throw, no toast)', async () => {
      const { service, controller, locale } = setup({ platform: 'browser' });
      const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
      locale._setCurrent('en');

      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(
        makeLoginResponse({ user: makeUser({ locale: 'en' }) }),
      );
      await Promise.resolve();
      TestBed.tick();

      await locale.setLocale('ar');
      TestBed.tick();

      const req = controller.expectOne('https://api-v3.3bayti.ae/v3/me/profile');
      req.flush('Server error', { status: 500, statusText: 'Server error' });

      /* Effect should NOT have crashed; warn should have been called. */
      await Promise.resolve();
      expect(warnSpy).toHaveBeenCalledWith('[AuthService] locale push failed', expect.anything());
      warnSpy.mockRestore();
    });
  });

  describe('applyProfile()', () => {
    it('replaces the current user when signed in', async () => {
      const { service, controller } = setup();
      const loginPromise = service.login({ email: 'jane@example.com', password: 'secret' });
      controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await loginPromise;

      service.applyProfile(makeUser({ first_name: 'Updated', email: 'jane@example.com' }));
      expect(service.currentUser()?.first_name).toBe('Updated');
    });

    it('is a no-op when signed out (cannot resurrect a session)', () => {
      const { service } = setup();
      expect(service.currentUser()).toBeNull();
      service.applyProfile(makeUser({ first_name: 'Ghost' }));
      expect(service.currentUser()).toBeNull();
    });
  });

  describe('applyPasswordChange()', () => {
    it('adopts the fresh token + user when signed in', async () => {
      const { service, controller, tokenStore } = setup();
      const loginPromise = service.login({ email: 'jane@example.com', password: 'secret' });
      controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await loginPromise;

      service.applyPasswordChange({
        access_token: 'rotated.jwt.v2',
        access_token_expires_at: new Date(Date.now() + 3_600_000).toISOString(),
        user: makeUser({ email: 'jane@example.com', first_name: 'Jane2' }),
      });

      expect(tokenStore.getToken()).toBe('rotated.jwt.v2');
      expect(service.currentUser()?.first_name).toBe('Jane2');
      expect(service.isAuthenticated()).toBe(true);
    });

    it('is a no-op when signed out', () => {
      const { service, tokenStore } = setup();
      service.applyPasswordChange({
        access_token: 'should.not.apply',
        access_token_expires_at: new Date(Date.now() + 3_600_000).toISOString(),
        user: makeUser(),
      });
      expect(tokenStore.getToken()).toBeNull();
      expect(service.currentUser()).toBeNull();
    });
  });
});
