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
} from '@angular/common/http/testing';
import { BehaviorSubject } from 'rxjs';
import { AuthService } from './auth.service';
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
 * canned responses). LocaleService and ApplicationRef are stubbed —
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
 *   - refresh() failure: clears local state, returns false
 *   - logout(): calls BFF, then clears state regardless of API success/failure
 *   - hydrate() success: applies state from /me
 *   - hydrate() 401: clears state silently
 *   - hydrate() network error: clears state, doesn't propagate
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
    roles: ['customer'],
    is_store_approved: false,
    is_store_active: false,
    last_login_at: '2026-05-19T12:00:00+00:00',
    ...overrides,
  };
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

  TestBed.configureTestingModule({ providers });

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
      /* The frontend just passes the value through — the API's fake-prefix
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
     refresh() — single-flight
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
      expect(r1).toBe(true);
      expect(r2).toBe(true);
      expect(r3).toBe(true);

      /* And no second outgoing request was queued. */
      controller.expectNone('/auth-proxy/refresh');
    });

    it('a subsequent refresh() after completion fires a new request', async () => {
      const { service, controller } = setup();

      /* First refresh. */
      const p1 = service.refresh();
      const r1 = controller.expectOne('/auth-proxy/refresh');
      r1.flush(makeLoginResponse());
      expect(await p1).toBe(true);

      /* Second refresh — should issue a NEW request since the first
         resolved. */
      const p2 = service.refresh();
      const r2 = controller.expectOne('/auth-proxy/refresh');
      r2.flush(makeLoginResponse());
      expect(await p2).toBe(true);
    });

    it('returns false and clears state on refresh failure', async () => {
      const { service, controller } = setup();

      /* Seed the service with a logged-in state so we can observe the clear. */
      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await Promise.resolve();
      expect(service.isAuthenticated()).toBe(true);

      /* Now refresh fails. */
      const p = service.refresh();
      const req = controller.expectOne('/auth-proxy/refresh');
      req.flush('Refresh token invalid', { status: 401, statusText: 'Unauthorized' });

      const result = await p;
      expect(result).toBe(false);
      expect(service.currentUser()).toBeNull();
      expect(service.isAuthenticated()).toBe(false);
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
      const req = controller.expectOne('/auth-proxy/logout');
      expect(req.request.method).toBe('POST');
      req.flush(null, { status: 204, statusText: 'No Content' });

      await logoutPromise;
      expect(service.currentUser()).toBeNull();
      expect(service.isAuthenticated()).toBe(false);
    });

    it('still clears local state when the BFF logout call fails', async () => {
      const { service, controller } = setup();

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
    });

    it('silently clears state on 401 (no rejection)', async () => {
      const { service, controller } = setup();

      const hydratePromise = service.hydrate();
      const req = controller.expectOne('/auth-proxy/me');
      req.flush(null, { status: 401, statusText: 'Unauthorized' });

      /* Must resolve, not reject. */
      await expect(hydratePromise).resolves.toBeUndefined();
      expect(service.currentUser()).toBeNull();
      expect(service.isAuthenticated()).toBe(false);
    });

    it('silently clears state on a 5xx (logs a warning but does not throw)', async () => {
      const { service, controller } = setup();
      const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

      const hydratePromise = service.hydrate();
      const req = controller.expectOne('/auth-proxy/me');
      req.flush('Server error', { status: 500, statusText: 'Server error' });

      await expect(hydratePromise).resolves.toBeUndefined();
      expect(service.currentUser()).toBeNull();
      expect(warn).toHaveBeenCalled();

      warn.mockRestore();
    });
  });

  /* -----------------------------------------------------------------
     Pre-emptive refresh scheduler
     ----------------------------------------------------------------- */
  describe('pre-emptive refresh scheduler', () => {
    it('does NOT arm the scheduler on the server', async () => {
      const { service, controller, emitIsStable } = setup({ platform: 'server' });

      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(makeLoginResponse());
      await Promise.resolve();

      /* Even if we emit isStable, nothing should happen on the server. */
      emitIsStable(true);
      await Promise.resolve();

      controller.expectNone('/auth-proxy/refresh');
    });

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
           — well INSIDE the refresh lead window. Scheduler should fire
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

      /* Switch to ar — fires one PATCH. */
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

    it('does NOT push on the server (SSR build)', async () => {
      const { service, controller, locale } = setup({ platform: 'server' });
      locale._setCurrent('en');

      service.login({ email: 'a@b.com', password: 'x' });
      controller.expectOne('/auth-proxy/login').flush(
        makeLoginResponse({ user: makeUser({ locale: 'en' }) }),
      );
      await Promise.resolve();

      /* On the server the effect doesn't register at all. */
      await locale.setLocale('ar');
      controller.expectNone('https://api-v3.3bayti.ae/v3/me/profile');
    });
  });
});
