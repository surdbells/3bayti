import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AccountHubPageComponent } from './account-hub-page';
import { AuthService } from '../../core/auth/auth.service';
import { provideI18n } from '../../core/i18n';
import type { AuthUser } from '../../core/auth/auth.types';

function makeUser(o: Partial<AuthUser> = {}): AuthUser {
  return {
    id: 1, email: 'a@b.co', phone: '+971500000000', country_code: 'AE',
    first_name: 'Sara', last_name: 'Khan', gender: null, dob: null,
    locale: 'en', timezone: null, is_phone_verified: true,
    is_email_verified: true, roles: [], is_store_approved: false,
    is_store_active: false, last_login_at: null, ...o,
  } as AuthUser;
}

class StubAuthService {
  user = signal<AuthUser | null>(makeUser());
  currentUser = this.user.asReadonly();
}

function setup(user: AuthUser | null = makeUser()): {
  fixture: ComponentFixture<AccountHubPageComponent>;
} {
  const auth = new StubAuthService();
  auth.user.set(user);
  TestBed.configureTestingModule({
    imports: [AccountHubPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: AuthService, useValue: auth },
    ],
  });
  const fixture = TestBed.createComponent(AccountHubPageComponent);
  fixture.detectChanges();
  return { fixture };
}

describe('AccountHubPageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => {
        if (!req.cancelled) req.flush({});
      });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders all six account tiles', () => {
    const { fixture } = setup();
    for (const id of ['profile', 'orders', 'addresses', 'measurements', 'wishlist', 'password']) {
      expect(fixture.nativeElement.querySelector(`[data-testid="account-tile-${id}"]`)).not.toBeNull();
    }
  });

  it('tiles link to the correct routes', () => {
    const { fixture } = setup();
    const expected: Record<string, string> = {
      profile: '/account/profile',
      orders: '/account/orders',
      addresses: '/account/addresses',
      measurements: '/account/measurements',
      wishlist: '/account/wishlist',
      password: '/account/password',
    };
    for (const [id, href] of Object.entries(expected)) {
      const a = fixture.nativeElement.querySelector(`[data-testid="account-tile-${id}"]`) as HTMLAnchorElement;
      expect(a.getAttribute('href')).toBe(href);
    }
  });

  it('shows the named greeting branch when a first name is available', () => {
    const { fixture } = setup(makeUser({ first_name: 'Mariam' }));
    expect(fixture.nativeElement.querySelector('[data-testid="greeting-named"]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="greeting-generic"]')).toBeNull();
  });

  it('shows the generic greeting branch when no first name', () => {
    const { fixture } = setup(makeUser({ first_name: null }));
    expect(fixture.nativeElement.querySelector('[data-testid="greeting-generic"]')).not.toBeNull();
    expect(fixture.nativeElement.querySelector('[data-testid="greeting-named"]')).toBeNull();
  });

  it('treats a blank first name as no name', () => {
    const { fixture } = setup(makeUser({ first_name: '   ' }));
    expect(fixture.nativeElement.querySelector('[data-testid="greeting-generic"]')).not.toBeNull();
  });

  it('renders fine when signed out (generic greeting, no crash)', () => {
    const { fixture } = setup(null);
    expect(fixture.nativeElement.querySelector('[data-testid="account-hub-page"]')).not.toBeNull();
  });
});
