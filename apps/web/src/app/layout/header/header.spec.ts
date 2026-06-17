import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { HeaderComponent } from './header';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { AuthUser } from '../../core/auth/auth.types';

/**
 * Header rendering tests focused on the auth-aware behaviour.
 *
 * Three states:
 *   1. Logged out + flag=false (default) → no auth CTAs
 *   2. Logged out + flag=true → Sign in + Register CTAs
 *   3. Logged in → user menu trigger visible
 */

class StubAuthService {
  private _user = signal<AuthUser | null>(null);
  currentUser = this._user.asReadonly();
  isAuthenticated = signal(false).asReadonly();

  /* Manual setter for tests; recreates the signals each call so
     readonly derivations recompute correctly. */
  setUser(user: AuthUser | null): void {
    if (user === null) {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (this as any).currentUser = signal<AuthUser | null>(null).asReadonly();
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (this as any).isAuthenticated = signal(false).asReadonly();
    } else {
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (this as any).currentUser = signal<AuthUser | null>(user).asReadonly();
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (this as any).isAuthenticated = signal(true).asReadonly();
    }
  }

  logoutCalls = 0;
  async logout(): Promise<void> { this.logoutCalls += 1; }
}

class StubToastService {
  show(): string { return ''; }
  success(): string { return ''; }
  error(): string { return ''; }
  warning(): string { return ''; }
  info(): string { return ''; }
  dismiss(): void { /* no-op */ }
  clearAll(): void { /* no-op */ }
  toasts = signal<unknown[]>([]).asReadonly();
  hasToasts = signal(false).asReadonly();
}

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
    last_login_at: null,
    ...overrides,
  };
}

function setup(opts: { user?: AuthUser | null } = {}): {
  fixture: ComponentFixture<HeaderComponent>;
  auth: StubAuthService;
} {
  const auth = new StubAuthService();
  if (opts.user !== undefined) {
    auth.setUser(opts.user);
  }

  TestBed.configureTestingModule({
    imports: [HeaderComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: AuthService, useValue: auth },
      { provide: ToastService, useValue: new StubToastService() },
    ],
  });

  const fixture = TestBed.createComponent(HeaderComponent);
  fixture.detectChanges();
  return { fixture, auth };
}

describe('HeaderComponent (auth-aware)', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('logged-out', () => {
    it('renders Customer + Vendor CTAs but no user menu', () => {
      const { fixture } = setup({ user: null });
      const root: HTMLElement = fixture.nativeElement;
      expect(root.querySelector('[data-testid="header-customer"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="header-vendor"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="header-user-menu"]')).toBeNull();
    });

    it('Customer CTA links to /login', () => {
      const { fixture } = setup({ user: null });
      const link = fixture.nativeElement.querySelector('[data-testid="header-customer"]') as HTMLAnchorElement;
      /* Angular's RouterLink renders the href once routing resolves; for
         the unit test, the [routerLink] attribute is sufficient evidence. */
      expect(link.getAttribute('href') ?? link.getAttribute('ng-reflect-router-link')).toMatch(/login/);
    });

    it('Vendor CTA links out to the external seller app in a new tab', () => {
      const { fixture } = setup({ user: null });
      const link = fixture.nativeElement.querySelector('[data-testid="header-vendor"]') as HTMLAnchorElement;
      expect(link.getAttribute('href')).toContain('app.3bayti.ae');
      expect(link.getAttribute('target')).toBe('_blank');
      expect(link.getAttribute('rel')).toContain('noopener');
    });
  });

  describe('logged-in', () => {
    it('renders the user menu and HIDES the logged-out CTAs', () => {
      const { fixture } = setup({ user: makeUser() });
      const root: HTMLElement = fixture.nativeElement;
      expect(root.querySelector('[data-testid="header-user-menu"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="header-customer"]')).toBeNull();
      expect(root.querySelector('[data-testid="header-vendor"]')).toBeNull();
    });

    it('renders the user menu trigger button', () => {
      const { fixture } = setup({ user: makeUser() });
      const trigger = fixture.nativeElement.querySelector('[data-testid="user-menu-trigger"]');
      expect(trigger).not.toBeNull();
    });
  });

  describe('phone-verification badge', () => {
    it('shows the verify badge when signed in but phone NOT verified', () => {
      const { fixture } = setup({ user: makeUser({ is_phone_verified: false }) });
      const badge = fixture.nativeElement.querySelector('[data-testid="header-verify-phone"]') as HTMLAnchorElement | null;
      expect(badge).not.toBeNull();
      expect(badge!.getAttribute('href')).toContain('/verify-phone');
    });

    it('HIDES the verify badge when the phone IS verified', () => {
      const { fixture } = setup({ user: makeUser({ is_phone_verified: true }) });
      expect(fixture.nativeElement.querySelector('[data-testid="header-verify-phone"]')).toBeNull();
    });

    it('HIDES the verify badge when logged out', () => {
      const { fixture } = setup({ user: null });
      expect(fixture.nativeElement.querySelector('[data-testid="header-verify-phone"]')).toBeNull();
    });
  });

  describe('locale switcher', () => {
    it('is always rendered regardless of auth state', () => {
      /* Logged-out */
      const { fixture: f1 } = setup({ user: null });
      expect(f1.nativeElement.querySelector('app-locale-switcher')).not.toBeNull();
      TestBed.resetTestingModule();

      /* Logged-in */
      const { fixture: f2 } = setup({ user: makeUser() });
      expect(f2.nativeElement.querySelector('app-locale-switcher')).not.toBeNull();
    });
  });

  describe('primary navigation + mobile drawer', () => {
    const NAV = [
      { id: 'nav-categories', re: /category/ },
      { id: 'nav-stores', re: /stores/ },
      { id: 'nav-bestSellers', re: /best-sellers/ },
      { id: 'nav-newArrivals', re: /new-arrivals/ },
      { id: 'nav-gift', re: /gift-cards/ },
    ];

    it('renders all primary nav items in the desktop nav with correct routerLinks', () => {
      const { fixture } = setup({ user: null });
      const nav = fixture.nativeElement.querySelector('.primary-nav') as HTMLElement;
      expect(nav).not.toBeNull();
      for (const { id, re } of NAV) {
        const a = nav.querySelector(`[data-testid="${id}"]`) as HTMLAnchorElement | null;
        expect(a, id).not.toBeNull();
        const href = a!.getAttribute('href') ?? a!.getAttribute('ng-reflect-router-link') ?? '';
        expect(href, id).toMatch(re);
        // The glyph renders (icon binding resolved, not a no-op).
        expect(a!.querySelector('app-nav-icon svg'), `${id} icon`).not.toBeNull();
      }
    });

    it('renders the Gift Cards nav item (Phase E) in the desktop nav and the drawer', () => {
      const { fixture } = setup({ user: null });
      const root: HTMLElement = fixture.nativeElement;
      const desktop = root.querySelector('[data-testid="nav-gift"]') as HTMLAnchorElement | null;
      expect(desktop).not.toBeNull();
      const href = desktop!.getAttribute('href') ?? desktop!.getAttribute('ng-reflect-router-link') ?? '';
      expect(href).toMatch(/gift-cards/);
      expect(root.querySelector('[data-testid="drawer-nav-gift"]')).not.toBeNull();
    });

    it('drawer is closed initially (toggle collapsed; drawer inert + aria-hidden; no backdrop)', () => {
      const { fixture } = setup({ user: null });
      const root: HTMLElement = fixture.nativeElement;
      const toggle = root.querySelector('[data-testid="nav-toggle"]')!;
      const drawer = root.querySelector('[data-testid="nav-drawer"]')!;
      expect(toggle.getAttribute('aria-expanded')).toBe('false');
      expect(toggle.getAttribute('aria-controls')).toBe('mobile-nav');
      expect(drawer.getAttribute('inert')).toBe('');
      expect(drawer.getAttribute('aria-hidden')).toBe('true');
      expect(drawer.classList.contains('is-open')).toBe(false);
      expect(root.querySelector('[data-testid="nav-drawer-backdrop"]')).toBeNull();
    });

    it('opening via the toggle updates aria + class and shows the backdrop', () => {
      const { fixture } = setup({ user: null });
      const root: HTMLElement = fixture.nativeElement;
      (root.querySelector('[data-testid="nav-toggle"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      const toggle = root.querySelector('[data-testid="nav-toggle"]')!;
      const drawer = root.querySelector('[data-testid="nav-drawer"]')!;
      expect(toggle.getAttribute('aria-expanded')).toBe('true');
      expect(drawer.classList.contains('is-open')).toBe(true);
      expect(drawer.getAttribute('inert')).toBeNull();
      expect(drawer.getAttribute('aria-hidden')).toBe('false');
      expect(root.querySelector('[data-testid="nav-drawer-backdrop"]')).not.toBeNull();
    });

    it('closes via the close button', () => {
      const { fixture } = setup({ user: null });
      const root: HTMLElement = fixture.nativeElement;
      (root.querySelector('[data-testid="nav-toggle"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      (root.querySelector('[data-testid="drawer-close"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      expect(root.querySelector('[data-testid="nav-drawer"]')!.classList.contains('is-open')).toBe(false);
      expect(root.querySelector('[data-testid="nav-drawer-backdrop"]')).toBeNull();
    });

    it('closes when the backdrop is clicked', () => {
      const { fixture } = setup({ user: null });
      const root: HTMLElement = fixture.nativeElement;
      (root.querySelector('[data-testid="nav-toggle"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      (root.querySelector('[data-testid="nav-drawer-backdrop"]') as HTMLElement).click();
      fixture.detectChanges();
      expect(root.querySelector('[data-testid="nav-drawer"]')!.classList.contains('is-open')).toBe(false);
    });

    it('closes on Escape', () => {
      const { fixture } = setup({ user: null });
      const root: HTMLElement = fixture.nativeElement;
      (root.querySelector('[data-testid="nav-toggle"]') as HTMLButtonElement).click();
      fixture.detectChanges();
      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
      fixture.detectChanges();
      expect(root.querySelector('[data-testid="nav-drawer"]')!.classList.contains('is-open')).toBe(false);
    });

    it('drawer mirrors the primary nav items', () => {
      const { fixture } = setup({ user: null });
      const drawer = fixture.nativeElement.querySelector('[data-testid="nav-drawer"]') as HTMLElement;
      for (const id of ['drawer-nav-stores', 'drawer-nav-bestSellers', 'drawer-nav-newArrivals', 'drawer-nav-gift']) {
        expect(drawer.querySelector(`[data-testid="${id}"]`), id).not.toBeNull();
      }
    });

    it('drawer surfaces the Customer + Vendor CTAs when logged out', () => {
      const { fixture } = setup({ user: null });
      const drawer = fixture.nativeElement.querySelector('[data-testid="nav-drawer"]') as HTMLElement;
      expect(drawer.querySelector('[data-testid="drawer-customer"]')).not.toBeNull();
      const vendor = drawer.querySelector('[data-testid="drawer-vendor"]') as HTMLAnchorElement | null;
      expect(vendor).not.toBeNull();
      expect(vendor!.getAttribute('href')).toContain('app.3bayti.ae');
    });

    it('drawer HIDES the audience CTAs when logged in', () => {
      const { fixture } = setup({ user: makeUser() });
      const drawer = fixture.nativeElement.querySelector('[data-testid="nav-drawer"]') as HTMLElement;
      expect(drawer.querySelector('[data-testid="drawer-customer"]')).toBeNull();
      expect(drawer.querySelector('[data-testid="drawer-vendor"]')).toBeNull();
    });
  });
});
