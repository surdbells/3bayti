import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Router, provideRouter, ActivatedRoute, convertToParamMap } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { LoginComponent } from './login';
import { AuthService } from '../../../core/auth/auth.service';
import { ToastService } from '../../../shared/forms';
import { provideI18n } from '../../../core/i18n';
import type {
  AuthUser,
  OtpLoginSendInput,
  OtpLoginVerifyInput,
} from '../../../core/auth/auth.types';

/**
 * Tests for the passwordless-first LoginComponent (#8).
 *
 * Covers: the choice → phone/email → OTP send + verify flow, plus the
 * preserved email + password fallback. AuthService + Router + ToastService
 * are stubbed; the Reactive Forms groups are real so validators behave as
 * in production.
 */

const USER: AuthUser = {
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
  avatar_url: null,
};

class StubAuthService {
  loginCalls: Array<{ email: string; password: string }> = [];
  sendCalls: OtpLoginSendInput[] = [];
  verifyCalls: OtpLoginVerifyInput[] = [];
  loginOutcome: 'success' | 'invalid-credentials' | 'network' = 'success';
  verifyOutcome: 'success' | 'invalid-code' | 'network' = 'success';

  async login(input: { email: string; password: string }): Promise<AuthUser> {
    this.loginCalls.push(input);
    if (this.loginOutcome === 'invalid-credentials') {
      throw new HttpErrorResponse({
        status: 401,
        error: { error_code: 'AUTH_INVALID_CREDENTIALS', message: 'Wrong.' },
      });
    }
    if (this.loginOutcome === 'network') throw new TypeError('fetch failed');
    return USER;
  }

  async sendOtpLogin(input: OtpLoginSendInput): Promise<{ verification_id: string }> {
    this.sendCalls.push(input);
    return { verification_id: 'vid-123' };
  }

  async verifyOtpLogin(input: OtpLoginVerifyInput): Promise<AuthUser> {
    this.verifyCalls.push(input);
    if (this.verifyOutcome === 'invalid-code') {
      throw new HttpErrorResponse({
        status: 401,
        error: { error_code: 'OTP_VERIFICATION_FAILED', message: 'Bad code.' },
      });
    }
    if (this.verifyOutcome === 'network') throw new TypeError('fetch failed');
    return USER;
  }
}

class StubToastService {
  errors: string[] = [];
  successes: string[] = [];
  error(msg: string): string {
    this.errors.push(msg);
    return msg;
  }
  success(msg: string): string {
    this.successes.push(msg);
    return msg;
  }
  show(input: { message: string }): string {
    return input.message;
  }
  warning(): string { return ''; }
  info(): string { return ''; }
  dismiss(): void { /* no-op */ }
  clearAll(): void { /* no-op */ }
  toasts = signal<unknown[]>([]).asReadonly();
  hasToasts = signal(false).asReadonly();
}

function makeRoute(queryParams: Record<string, string> = {}): ActivatedRoute {
  return {
    snapshot: { queryParamMap: convertToParamMap(queryParams) },
  } as ActivatedRoute;
}

function setup(opts: { queryParams?: Record<string, string> } = {}): {
  fixture: ComponentFixture<LoginComponent>;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  component: any;
  auth: StubAuthService;
  toast: StubToastService;
  navigateByUrlSpy: ReturnType<typeof vi.fn>;
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
  const navigateByUrlSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true);

  const fixture = TestBed.createComponent(LoginComponent);
  fixture.detectChanges();

  return {
    fixture,
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    component: fixture.componentInstance as any,
    auth,
    toast,
    navigateByUrlSpy: navigateByUrlSpy as unknown as ReturnType<typeof vi.fn>,
  };
}

describe('LoginComponent (passwordless-first)', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('initial render', () => {
    it('renders the choice screen (Phone / Email)', () => {
      const { fixture } = setup();
      const root: HTMLElement = fixture.nativeElement;
      expect(root.querySelector('[data-testid="login-choice"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="login-choice-phone"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="login-choice-email"]')).not.toBeNull();
    });
  });

  describe('email OTP flow', () => {
    it('sends an email OTP and advances to the OTP view', async () => {
      const { component, auth, fixture } = setup();
      component.goEmail();
      component.emailForm.controls.email.setValue('jane@example.com');
      await component.onSendEmailOtp();
      fixture.detectChanges();

      expect(auth.sendCalls).toEqual([{ channel: 'email', email: 'jane@example.com' }]);
      expect(component.view()).toBe('otp');
    });

    it('verifies the OTP and navigates home', async () => {
      const { component, auth, navigateByUrlSpy, fixture } = setup();
      component.goEmail();
      component.emailForm.controls.email.setValue('jane@example.com');
      await component.onSendEmailOtp();
      component.otpForm.controls.code.setValue('123456');
      await component.onVerifyOtp();
      fixture.detectChanges();

      expect(auth.verifyCalls).toEqual([{ verification_id: 'vid-123', code: '123456' }]);
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/');
    });

    it('sets a per-field code error on OTP_VERIFICATION_FAILED', async () => {
      const { component, auth } = setup();
      auth.verifyOutcome = 'invalid-code';
      component.goEmail();
      component.emailForm.controls.email.setValue('jane@example.com');
      await component.onSendEmailOtp();
      component.otpForm.controls.code.setValue('000000');
      await component.onVerifyOtp();

      expect(component.otpForm.controls.code.errors).toEqual({ invalidCode: true });
    });
  });

  describe('phone OTP flow', () => {
    it('sends a phone OTP with a derived country_code', async () => {
      const { component, auth } = setup();
      component.goPhone();
      component.phoneForm.controls.phone.setValue('+971501234567');
      await component.onSendPhoneOtp();

      expect(auth.sendCalls).toEqual([
        { channel: 'phone', country_code: 'AE', phone: '+971501234567' },
      ]);
      expect(component.view()).toBe('otp');
    });
  });

  describe('password fallback (preserved)', () => {
    it('renders the password form via "use password instead"', () => {
      const { component, fixture } = setup();
      component.goPassword();
      fixture.detectChanges();
      const root: HTMLElement = fixture.nativeElement;
      expect(root.querySelector('[data-testid="login-form"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="login-password"]')).not.toBeNull();
    });

    it('calls AuthService.login with the form values', async () => {
      const { component, auth, navigateByUrlSpy } = setup();
      component.goPassword();
      component.passwordForm.controls.email.setValue('jane@example.com');
      component.passwordForm.controls.password.setValue('secret');
      await component.onSubmitPassword();

      expect(auth.loginCalls).toEqual([{ email: 'jane@example.com', password: 'secret' }]);
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/');
    });

    it('honours a safe in-app returnUrl', async () => {
      const { component, navigateByUrlSpy } = setup({
        queryParams: { returnUrl: '/account/orders' },
      });
      component.goPassword();
      component.passwordForm.controls.email.setValue('jane@example.com');
      component.passwordForm.controls.password.setValue('secret');
      await component.onSubmitPassword();

      expect(navigateByUrlSpy).toHaveBeenCalledWith('/account/orders');
    });

    it('REFUSES a protocol-relative returnUrl (open-redirect defense)', async () => {
      const { component, navigateByUrlSpy } = setup({
        queryParams: { returnUrl: '//evil.example/' },
      });
      component.goPassword();
      component.passwordForm.controls.email.setValue('jane@example.com');
      component.passwordForm.controls.password.setValue('secret');
      await component.onSubmitPassword();

      expect(navigateByUrlSpy).toHaveBeenCalledWith('/');
    });

    it('sets a per-field error for AUTH_INVALID_CREDENTIALS', async () => {
      const { component, auth } = setup();
      auth.loginOutcome = 'invalid-credentials';
      component.goPassword();
      component.passwordForm.controls.email.setValue('jane@example.com');
      component.passwordForm.controls.password.setValue('wrong');
      await component.onSubmitPassword();

      expect(component.passwordForm.controls.email.errors).toEqual({ invalidCredentials: true });
    });

    it('shows a network toast on TypeError', async () => {
      const { component, auth, toast } = setup();
      auth.loginOutcome = 'network';
      component.goPassword();
      component.passwordForm.controls.email.setValue('jane@example.com');
      component.passwordForm.controls.password.setValue('secret');
      await component.onSubmitPassword();

      expect(toast.errors).toContain('auth.login.errors.network');
    });
  });

  describe('validation guards', () => {
    it('does not send an OTP when the email is empty', async () => {
      const { component, auth } = setup();
      component.goEmail();
      await component.onSendEmailOtp();
      expect(auth.sendCalls).toHaveLength(0);
    });
  });
});
