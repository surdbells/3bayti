import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { TestBed } from '@angular/core/testing';
import { signal, runInInjectionContext, EnvironmentInjector, Provider } from '@angular/core';
import { Router, UrlTree, ActivatedRouteSnapshot, RouterStateSnapshot } from '@angular/router';
import { provideRouter } from '@angular/router';
import { AuthService } from './auth.service';
import { authMatchGuard, authActivateGuard, guestActivateGuard } from './auth.guards';

/**
 * Tests for the three auth guards.
 *
 * Guards are functional (inject-based), so we run them inside
 * runInInjectionContext with the EnvironmentInjector from a configured
 * TestBed. AuthService is stubbed so we can drive isAuthenticated()
 * through its computed signal API.
 *
 * What we verify
 * --------------
 *   authMatchGuard:
 *     - returns true when authenticated
 *     - returns false when anonymous
 *   authActivateGuard:
 *     - returns true when authenticated
 *     - returns UrlTree('/login?returnUrl=<state.url>') when anon
 *     - preserves the full state.url including query string + fragment
 *   guestActivateGuard:
 *     - returns true when anonymous
 *     - returns UrlTree based on returnUrl when authenticated AND
 *       returnUrl is in-app
 *     - rejects external returnUrl (open-redirect defense — both
 *       'https://evil/' and '//evil/' must NOT redirect)
 *     - falls back to '/' when returnUrl is missing
 */

class StubAuthService {
  private _isAuth = signal(false);
  readonly isAuthenticated = this._isAuth.asReadonly();

  setAuthenticated(v: boolean): void {
    this._isAuth.set(v);
  }
}

function setup(): { auth: StubAuthService; injector: EnvironmentInjector; router: Router } {
  const auth = new StubAuthService();
  const providers: Provider[] = [
    { provide: AuthService, useValue: auth },
  ];

  TestBed.configureTestingModule({
    providers: [
      provideRouter([]),
      ...providers,
    ],
  });

  return {
    auth,
    injector: TestBed.inject(EnvironmentInjector),
    router: TestBed.inject(Router),
  };
}

/**
 * Build a minimal RouterStateSnapshot for guard input.
 * Only `url` is read by authActivateGuard; everything else can be
 * an empty placeholder.
 */
function fakeStateSnapshot(url: string): RouterStateSnapshot {
  return { url, root: {} as ActivatedRouteSnapshot };
}

/**
 * Build a minimal ActivatedRouteSnapshot with queryParamMap. Only
 * queryParamMap.get('returnUrl') is read by guestActivateGuard.
 */
function fakeRouteSnapshot(queryParams: Record<string, string> = {}): ActivatedRouteSnapshot {
  return {
    queryParamMap: {
      get: (key: string) => queryParams[key] ?? null,
      has: (key: string) => key in queryParams,
      getAll: (key: string) => (key in queryParams ? [queryParams[key]] : []),
      keys: Object.keys(queryParams),
    },
  } as unknown as ActivatedRouteSnapshot;
}

describe('authMatchGuard (CanMatch)', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('returns true when AuthService reports authenticated', () => {
    const { auth, injector } = setup();
    auth.setAuthenticated(true);

    const result = runInInjectionContext(injector, () =>
      authMatchGuard({} as never, []),
    );
    expect(result).toBe(true);
  });

  it('returns false when AuthService reports anonymous', () => {
    const { auth, injector } = setup();
    auth.setAuthenticated(false);

    const result = runInInjectionContext(injector, () =>
      authMatchGuard({} as never, []),
    );
    expect(result).toBe(false);
  });
});

describe('authActivateGuard (CanActivate)', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('returns true when AuthService reports authenticated', () => {
    const { auth, injector } = setup();
    auth.setAuthenticated(true);

    const result = runInInjectionContext(injector, () =>
      authActivateGuard(
        {} as ActivatedRouteSnapshot,
        fakeStateSnapshot('/account/profile'),
      ),
    );
    expect(result).toBe(true);
  });

  it('returns a UrlTree to /login with returnUrl when anonymous', () => {
    const { auth, injector, router } = setup();
    auth.setAuthenticated(false);

    const result = runInInjectionContext(injector, () =>
      authActivateGuard(
        {} as ActivatedRouteSnapshot,
        fakeStateSnapshot('/account/profile'),
      ),
    );
    expect(result).toBeInstanceOf(UrlTree);

    /* Serialise the UrlTree to verify both path and query. */
    const serialised = router.serializeUrl(result as UrlTree);
    expect(serialised).toBe('/login?returnUrl=%2Faccount%2Fprofile');
  });

  it('preserves query string and fragment in returnUrl', () => {
    const { auth, injector, router } = setup();
    auth.setAuthenticated(false);

    const result = runInInjectionContext(injector, () =>
      authActivateGuard(
        {} as ActivatedRouteSnapshot,
        fakeStateSnapshot('/checkout?step=2#shipping'),
      ),
    );
    const serialised = router.serializeUrl(result as UrlTree);
    /* URL-encoded ?step=2#shipping should be visible in the returnUrl
       query parameter — Angular's serializer percent-encodes # and ?
       inside query values. */
    expect(serialised).toContain('returnUrl=');
    expect(decodeURIComponent(serialised.split('returnUrl=')[1] ?? '')).toBe('/checkout?step=2#shipping');
  });
});

describe('guestActivateGuard (CanActivate, inverse)', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('returns true when AuthService reports anonymous', () => {
    const { auth, injector } = setup();
    auth.setAuthenticated(false);

    const result = runInInjectionContext(injector, () =>
      guestActivateGuard(
        fakeRouteSnapshot(),
        fakeStateSnapshot('/login'),
      ),
    );
    expect(result).toBe(true);
  });

  it('redirects to the returnUrl when authenticated and returnUrl is in-app', () => {
    const { auth, injector, router } = setup();
    auth.setAuthenticated(true);

    const result = runInInjectionContext(injector, () =>
      guestActivateGuard(
        fakeRouteSnapshot({ returnUrl: '/account/orders' }),
        fakeStateSnapshot('/login?returnUrl=%2Faccount%2Forders'),
      ),
    );
    expect(result).toBeInstanceOf(UrlTree);
    expect(router.serializeUrl(result as UrlTree)).toBe('/account/orders');
  });

  it('redirects to / when authenticated and no returnUrl', () => {
    const { auth, injector, router } = setup();
    auth.setAuthenticated(true);

    const result = runInInjectionContext(injector, () =>
      guestActivateGuard(
        fakeRouteSnapshot(),
        fakeStateSnapshot('/login'),
      ),
    );
    expect(result).toBeInstanceOf(UrlTree);
    expect(router.serializeUrl(result as UrlTree)).toBe('/');
  });

  describe('open-redirect defense', () => {
    /* Every entry here is an attacker-supplied returnUrl that MUST NOT
       be honored. The guard should fall back to '/'. */
    const malicious = [
      'https://evil.example/',
      'http://evil.example/account',
      '//evil.example/',
      '//evil.example',
      'javascript:alert(1)',
      'data:text/html,<script>alert(1)</script>',
      'evil.example',
      'mailto:victim@example.com',
    ];

    for (const url of malicious) {
      it(`falls back to / for ${url}`, () => {
        const { auth, injector, router } = setup();
        auth.setAuthenticated(true);

        const result = runInInjectionContext(injector, () =>
          guestActivateGuard(
            fakeRouteSnapshot({ returnUrl: url }),
            fakeStateSnapshot(`/login?returnUrl=${encodeURIComponent(url)}`),
          ),
        );
        expect(result).toBeInstanceOf(UrlTree);
        expect(router.serializeUrl(result as UrlTree)).toBe('/');
      });
    }

    it('accepts a deep in-app path with query and fragment', () => {
      const { auth, injector, router } = setup();
      auth.setAuthenticated(true);

      const result = runInInjectionContext(injector, () =>
        guestActivateGuard(
          fakeRouteSnapshot({ returnUrl: '/category/lighting?sort=price#top' }),
          fakeStateSnapshot('/login'),
        ),
      );
      expect(result).toBeInstanceOf(UrlTree);
      const serialised = router.serializeUrl(result as UrlTree);
      expect(serialised).toBe('/category/lighting?sort=price#top');
    });
  });
});
