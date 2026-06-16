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
});
