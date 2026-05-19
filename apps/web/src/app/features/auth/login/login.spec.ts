import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Router, provideRouter, ActivatedRoute, convertToParamMap } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { LoginComponent } from './login';
import { AuthService } from '../../../core/auth/auth.service';
import { ToastService } from '../../../shared/forms';
import { provideI18n } from '../../../core/i18n';
import type { AuthUser } from '../../../core/auth/auth.types';

/**
 * Tests for the LoginComponent.
 *
 * We stub AuthService + Router + ToastService and observe what the
 * component does in response. The form itself is a real Reactive
 * Forms group so validators behave as in production.
 */

class StubAuthService {
  loginCalls: Array<{ email: string; password: string }> = [];
  outcome: 'success' | 'unverified' | 'invalid-credentials' | 'network' = 'success';
  user: AuthUser = {
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
  };

  async login(input: { email: string; password: string }): Promise<AuthUser> {
    this.loginCalls.push(input);
    if (this.outcome === 'success') return this.user;
    if (this.outcome === 'unverified') return { ...this.user, is_phone_verified: false };
    if (this.outcome === 'invalid-credentials') {
      throw new HttpErrorResponse({
        status: 401,
        error: { error_code: 'AUTH_INVALID_CREDENTIALS', message: 'Wrong.' },
      });
    }
    throw new TypeError('fetch failed');
  }
}

class StubToastService {
  errors: string[] = [];
  error(msg: string): string {
    this.errors.push(msg);
    return msg;
  }
  /* Methods that LoginComponent's typing requires but tests don't drive. */
  show(input: { message: string }): string {
    return input.message;
  }
  success(): string { return ''; }
  warning(): string { return ''; }
  info(): string { return ''; }
  dismiss(): void { /* no-op */ }
  clearAll(): void { /* no-op */ }
  toasts = signal<unknown[]>([]).asReadonly();
  hasToasts = signal(false).asReadonly();
}

function makeRoute(queryParams: Record<string, string> = {}): ActivatedRoute {
  return {
    snapshot: {
      queryParamMap: convertToParamMap(queryParams),
    },
  } as ActivatedRoute;
}

function setup(opts: {
  queryParams?: Record<string, string>;
} = {}): {
  fixture: ComponentFixture<LoginComponent>;
  component: LoginComponent;
  auth: StubAuthService;
  toast: StubToastService;
  router: Router;
  routerNavigateSpy: ReturnType<typeof vi.fn>;
  routerNavigateByUrlSpy: ReturnType<typeof vi.fn>;
} {
  const auth = new StubAuthService();
  const toast = new StubToastService();

  TestBed.configureTestingModule({
    imports: [LoginComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: AuthService, useValue: auth },
      { provide: ToastService, useValue: toast },
      { provide: ActivatedRoute, useValue: makeRoute(opts.queryParams) },
    ],
  });

  const router = TestBed.inject(Router);
  const navigateSpy = vi.spyOn(router, 'navigate').mockResolvedValue(true);
  const navigateByUrlSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);

  const fixture = TestBed.createComponent(LoginComponent);
  fixture.detectChanges();

  return {
    fixture,
    component: fixture.componentInstance,
    auth,
    toast,
    router,
    routerNavigateSpy: navigateSpy as unknown as ReturnType<typeof vi.fn>,
    routerNavigateByUrlSpy: navigateByUrlSpy as unknown as ReturnType<typeof vi.fn>,
  };
}

describe('LoginComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  /* -----------------------------------------------------------------
     Initial render
     ----------------------------------------------------------------- */
  describe('initial render', () => {
    it('renders the page title, both inputs, and the submit button', () => {
      const { fixture } = setup();
      const root: HTMLElement = fixture.nativeElement;
      expect(root.querySelector('[data-testid="login-page"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="login-email"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="login-password"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="login-submit"]')).not.toBeNull();
    });

    it('starts with an invalid form (both fields required)', () => {
      const { component } = setup();
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      expect((component as any).form.invalid).toBe(true);
    });
  });

  /* -----------------------------------------------------------------
     Validation
     ----------------------------------------------------------------- */
  describe('validation', () => {
    it('does not call AuthService.login when the form is empty (submit is a no-op)', async () => {
      const { component, auth, fixture } = setup();
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();
      expect(auth.loginCalls).toHaveLength(0);
    });

    it('marks all fields touched on invalid submit so errors render', async () => {
      const { component, fixture } = setup();
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const form = (component as any).form;
      expect(form.controls.email.touched).toBe(true);
      expect(form.controls.password.touched).toBe(true);
    });
  });

  /* -----------------------------------------------------------------
     Successful login
     ----------------------------------------------------------------- */
  describe('successful login', () => {
    it('calls AuthService.login with the form values', async () => {
      const { component, auth, fixture } = setup();
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const form = (component as any).form;
      form.controls.email.setValue('jane@example.com');
      form.controls.password.setValue('secret');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(auth.loginCalls).toEqual([{ email: 'jane@example.com', password: 'secret' }]);
    });

    it('navigates to / by default when no returnUrl is present', async () => {
      const { component, routerNavigateByUrlSpy, fixture } = setup();
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const form = (component as any).form;
      form.controls.email.setValue('jane@example.com');
      form.controls.password.setValue('secret');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(routerNavigateByUrlSpy).toHaveBeenCalledWith('/');
    });

    it('navigates to a safe in-app returnUrl when supplied', async () => {
      const { component, routerNavigateByUrlSpy, fixture } = setup({
        queryParams: { returnUrl: '/account/orders' },
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const form = (component as any).form;
      form.controls.email.setValue('jane@example.com');
      form.controls.password.setValue('secret');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(routerNavigateByUrlSpy).toHaveBeenCalledWith('/account/orders');
    });

    it('REFUSES to honour a protocol-relative returnUrl (open-redirect defense)', async () => {
      const { component, routerNavigateByUrlSpy, fixture } = setup({
        queryParams: { returnUrl: '//evil.example/' },
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const form = (component as any).form;
      form.controls.email.setValue('jane@example.com');
      form.controls.password.setValue('secret');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(routerNavigateByUrlSpy).toHaveBeenCalledWith('/');
    });

    it('REFUSES to honour an absolute-URL returnUrl', async () => {
      const { component, routerNavigateByUrlSpy, fixture } = setup({
        queryParams: { returnUrl: 'https://evil.example/' },
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const form = (component as any).form;
      form.controls.email.setValue('jane@example.com');
      form.controls.password.setValue('secret');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(routerNavigateByUrlSpy).toHaveBeenCalledWith('/');
    });
  });

  /* -----------------------------------------------------------------
     Phone-unverified user (auto-routed to /verify-phone)
     ----------------------------------------------------------------- */
  describe('phone-unverified user', () => {
    it('routes to /verify-phone instead of returnUrl', async () => {
      const { component, auth, routerNavigateSpy, routerNavigateByUrlSpy, fixture } = setup({
        queryParams: { returnUrl: '/account/orders' },
      });
      auth.outcome = 'unverified';
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const form = (component as any).form;
      form.controls.email.setValue('jane@example.com');
      form.controls.password.setValue('secret');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(routerNavigateSpy).toHaveBeenCalledWith(['/verify-phone'], {
        queryParams: { from: 'login' },
      });
      /* Did NOT navigate to returnUrl. */
      expect(routerNavigateByUrlSpy).not.toHaveBeenCalled();
    });
  });

  /* -----------------------------------------------------------------
     Error paths
     ----------------------------------------------------------------- */
  describe('error paths', () => {
    it('sets per-field error for AUTH_INVALID_CREDENTIALS', async () => {
      const { component, auth, fixture } = setup();
      auth.outcome = 'invalid-credentials';
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const form = (component as any).form;
      form.controls.email.setValue('jane@example.com');
      form.controls.password.setValue('wrong');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(form.controls.email.errors).toEqual({ invalidCredentials: true });
    });

    it('shows a network toast on TypeError (fetch failed)', async () => {
      const { component, auth, toast, fixture } = setup();
      auth.outcome = 'network';
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const form = (component as any).form;
      form.controls.email.setValue('jane@example.com');
      form.controls.password.setValue('secret');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(toast.errors).toContain('auth.login.errors.network');
    });
  });

  /* -----------------------------------------------------------------
     Submitting state
     ----------------------------------------------------------------- */
  describe('submitting state', () => {
    it('disables the submit button while a login is in flight', async () => {
      const { component, fixture, auth } = setup();
      let resolveLogin: (user: AuthUser) => void = () => undefined;
      const slowPromise = new Promise<AuthUser>(resolve => {
        resolveLogin = resolve;
      });
      auth.login = (_input) => {
        auth.loginCalls.push(_input);
        return slowPromise;
      };

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const form = (component as any).form;
      form.controls.email.setValue('jane@example.com');
      form.controls.password.setValue('secret');

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const submitPromise = (component as any).onSubmit();
      fixture.detectChanges();
      const button: HTMLButtonElement = fixture.nativeElement.querySelector('[data-testid="login-submit"]');
      expect(button.disabled).toBe(true);

      /* Resolve and let the component re-render. */
      resolveLogin(auth.user);
      await submitPromise;
      fixture.detectChanges();
      expect(button.disabled).toBe(false);
    });
  });
});
