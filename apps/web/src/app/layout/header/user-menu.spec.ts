import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { UserMenuComponent } from './user-menu';
import { AuthService } from '../../core/auth/auth.service';
import { provideI18n } from '../../core/i18n';
import type { AuthUser } from '../../core/auth/auth.types';

class StubAuthService {
  logoutCalls = 0;
  async logout(): Promise<void> {
    this.logoutCalls += 1;
  }
  currentUser = signal<AuthUser | null>(null).asReadonly();
  isAuthenticated = signal(true).asReadonly();
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

function setup(user: AuthUser): {
  fixture: ComponentFixture<UserMenuComponent>;
  component: UserMenuComponent;
  auth: StubAuthService;
  navigateSpy: ReturnType<typeof vi.fn>;
} {
  const auth = new StubAuthService();
  TestBed.configureTestingModule({
    imports: [UserMenuComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: AuthService, useValue: auth },
    ],
  });
  const router = TestBed.inject(Router);
  const navigateSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;
  const fixture = TestBed.createComponent(UserMenuComponent);
  fixture.componentRef.setInput('user', user);
  fixture.detectChanges();
  return { fixture, component: fixture.componentInstance, auth, navigateSpy };
}

describe('UserMenuComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('display name and initials', () => {
    it('renders full name when first + last provided', () => {
      const { fixture } = setup(makeUser());
      const trigger: HTMLElement = fixture.nativeElement.querySelector('[data-testid="user-menu-trigger"]');
      expect(trigger.textContent).toContain('Jane Doe');
    });

    it('shows initials in avatar (first + last)', () => {
      const { fixture } = setup(makeUser());
      const avatar: HTMLElement = fixture.nativeElement.querySelector('.user-menu__avatar');
      expect(avatar.textContent?.trim()).toBe('JD');
    });

    it('falls back to email when name missing', () => {
      const { fixture } = setup(makeUser({ first_name: null, last_name: null }));
      const trigger: HTMLElement = fixture.nativeElement.querySelector('[data-testid="user-menu-trigger"]');
      expect(trigger.textContent).toContain('jane@example.com');
    });

    it('avatar uses first 2 chars of email when no names', () => {
      const { fixture } = setup(makeUser({ first_name: null, last_name: null, email: 'sam@example.com' }));
      const avatar: HTMLElement = fixture.nativeElement.querySelector('.user-menu__avatar');
      expect(avatar.textContent?.trim()).toBe('SA');
    });

    it('avatar uses first 2 chars of first_name when only first present', () => {
      const { fixture } = setup(makeUser({ last_name: null }));
      const avatar: HTMLElement = fixture.nativeElement.querySelector('.user-menu__avatar');
      expect(avatar.textContent?.trim()).toBe('JA');
    });
  });

  describe('menu open/close', () => {
    it('is closed by default — panel not rendered', () => {
      const { fixture } = setup(makeUser());
      expect(fixture.nativeElement.querySelector('[data-testid="user-menu-panel"]')).toBeNull();
    });

    it('opens on trigger click', () => {
      const { fixture } = setup(makeUser());
      const trigger: HTMLButtonElement = fixture.nativeElement.querySelector('[data-testid="user-menu-trigger"]');
      trigger.click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="user-menu-panel"]')).not.toBeNull();
    });

    it('aria-expanded reflects open state', () => {
      const { fixture } = setup(makeUser());
      const trigger: HTMLButtonElement = fixture.nativeElement.querySelector('[data-testid="user-menu-trigger"]');
      expect(trigger.getAttribute('aria-expanded')).toBe('false');
      trigger.click();
      fixture.detectChanges();
      expect(trigger.getAttribute('aria-expanded')).toBe('true');
    });

    it('toggles closed on second click', () => {
      const { fixture } = setup(makeUser());
      const trigger: HTMLButtonElement = fixture.nativeElement.querySelector('[data-testid="user-menu-trigger"]');
      trigger.click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="user-menu-panel"]')).not.toBeNull();
      trigger.click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="user-menu-panel"]')).toBeNull();
    });

    it('closes on Escape key', () => {
      const { fixture } = setup(makeUser());
      const trigger: HTMLButtonElement = fixture.nativeElement.querySelector('[data-testid="user-menu-trigger"]');
      trigger.click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="user-menu-panel"]')).not.toBeNull();

      document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="user-menu-panel"]')).toBeNull();
    });

    it('closes on outside click', () => {
      const { fixture } = setup(makeUser());
      const trigger: HTMLButtonElement = fixture.nativeElement.querySelector('[data-testid="user-menu-trigger"]');
      trigger.click();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="user-menu-panel"]')).not.toBeNull();

      /* Synthesize a click on document.body. */
      document.body.dispatchEvent(new MouseEvent('click', { bubbles: true }));
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="user-menu-panel"]')).toBeNull();
    });
  });

  describe('sign out', () => {
    it('calls AuthService.logout when the Sign out item is clicked', async () => {
      const { fixture, auth } = setup(makeUser());
      const trigger: HTMLButtonElement = fixture.nativeElement.querySelector('[data-testid="user-menu-trigger"]');
      trigger.click();
      fixture.detectChanges();
      const signOut: HTMLButtonElement = fixture.nativeElement.querySelector('[data-testid="user-menu-signout"]');
      signOut.click();
      await Promise.resolve();
      await Promise.resolve();

      expect(auth.logoutCalls).toBe(1);
    });

    it('navigates to / after sign out', async () => {
      const { fixture, navigateSpy } = setup(makeUser());
      const trigger: HTMLButtonElement = fixture.nativeElement.querySelector('[data-testid="user-menu-trigger"]');
      trigger.click();
      fixture.detectChanges();
      const signOut: HTMLButtonElement = fixture.nativeElement.querySelector('[data-testid="user-menu-signout"]');
      signOut.click();
      await Promise.resolve();
      await Promise.resolve();

      expect(navigateSpy).toHaveBeenCalledWith('/');
    });
  });
});
