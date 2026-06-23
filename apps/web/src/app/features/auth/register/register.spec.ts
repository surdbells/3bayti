import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { RegisterComponent } from './register';
import { AuthService } from '../../../core/auth/auth.service';
import { ToastService } from '../../../shared/forms';
import { provideI18n } from '../../../core/i18n';
import type {
  AuthUser,
  RegisterInitiateInput,
  RegisterVerifyPhoneInput,
  RegisterSubmitInput,
  RegisterConfirmEmailInput,
} from '../../../core/auth/auth.types';

/**
 * Tests for the phone-first 4-step RegisterComponent (#8).
 *
 * Step 1 initiate → step 2 verify-phone → step 3 submit → step 4
 * confirm-email (auto-login). AuthService + Router + Toast are stubbed;
 * forms are real.
 */

const USER: AuthUser = {
  id: 1,
  email: 'new@example.com',
  phone: '+971501234567',
  country_code: 'AE',
  first_name: 'Jane',
  last_name: 'Doe',
  gender: null,
  dob: null,
  locale: null,
  timezone: null,
  is_phone_verified: true,
  is_email_verified: true,
  roles: ['customer'],
  is_store_approved: false,
  is_store_active: false,
  last_login_at: null,
  avatar_url: null,
};

class StubAuthService {
  initiateCalls: RegisterInitiateInput[] = [];
  verifyPhoneCalls: RegisterVerifyPhoneInput[] = [];
  submitCalls: RegisterSubmitInput[] = [];
  confirmEmailCalls: RegisterConfirmEmailInput[] = [];

  initiateOutcome: 'success' | 'phone-taken' | 'network' = 'success';
  submitOutcome: 'success' | 'email-taken' | 'expired-token' = 'success';

  async validatePhone(): Promise<{ phone: string; available: boolean }> {
    return { phone: '+971501234567', available: true };
  }

  async registerInitiate(input: RegisterInitiateInput): Promise<{ verification_id: string }> {
    this.initiateCalls.push(input);
    if (this.initiateOutcome === 'phone-taken') {
      throw new HttpErrorResponse({
        status: 409,
        error: { error_code: 'CONFLICT_PHONE_TAKEN', message: 'Taken.' },
      });
    }
    if (this.initiateOutcome === 'network') throw new TypeError('fetch failed');
    return { verification_id: 'phone-vid' };
  }

  async registerVerifyPhone(input: RegisterVerifyPhoneInput): Promise<{ registration_token: string }> {
    this.verifyPhoneCalls.push(input);
    return { registration_token: 'reg-token' };
  }

  async registerSubmit(input: RegisterSubmitInput): Promise<{ verification_id: string }> {
    this.submitCalls.push(input);
    if (this.submitOutcome === 'email-taken') {
      throw new HttpErrorResponse({
        status: 409,
        error: { error_code: 'CONFLICT_EMAIL_TAKEN', message: 'Taken.' },
      });
    }
    if (this.submitOutcome === 'expired-token') {
      throw new HttpErrorResponse({
        status: 401,
        error: { error_code: 'AUTH_INVALID_TOKEN', message: 'Expired.' },
      });
    }
    return { verification_id: 'email-vid' };
  }

  async registerConfirmEmail(input: RegisterConfirmEmailInput): Promise<AuthUser> {
    this.confirmEmailCalls.push(input);
    return USER;
  }
}

class StubToastService {
  errors: string[] = [];
  successes: string[] = [];
  error(msg: string): string { this.errors.push(msg); return msg; }
  success(msg: string): string { this.successes.push(msg); return msg; }
  show(input: { message: string }): string { return input.message; }
  warning(): string { return ''; }
  info(): string { return ''; }
  dismiss(): void { /* no-op */ }
  clearAll(): void { /* no-op */ }
  toasts = signal<unknown[]>([]).asReadonly();
  hasToasts = signal(false).asReadonly();
}

function setup(): {
  fixture: ComponentFixture<RegisterComponent>;
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  component: any;
  auth: StubAuthService;
  toast: StubToastService;
  navigateByUrlSpy: ReturnType<typeof vi.fn>;
} {
  const auth = new StubAuthService();
  const toast = new StubToastService();

  TestBed.configureTestingModule({
    imports: [RegisterComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: AuthService, useValue: auth },
      { provide: ToastService, useValue: toast },
    ],
  });

  const router = TestBed.inject(Router);
  const navigateByUrlSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;

  const fixture = TestBed.createComponent(RegisterComponent);
  fixture.detectChanges();

  return { fixture, component: fixture.componentInstance, auth, toast, navigateByUrlSpy };
}

/** Drive steps 1→2→3 so the form is parked on the details step. */
// eslint-disable-next-line @typescript-eslint/no-explicit-any
async function reachDetails(component: any): Promise<void> {
  component.phoneForm.controls.phone.setValue('+971501234567');
  await component.onInitiate();
  component.otpForm.controls.code.setValue('123456');
  await component.onVerifyPhone();
}

// eslint-disable-next-line @typescript-eslint/no-explicit-any
function fillDetails(component: any): void {
  component.detailsForm.controls.first_name.setValue('Jane');
  component.detailsForm.controls.last_name.setValue('Doe');
  component.detailsForm.controls.email.setValue('new@example.com');
  component.detailsForm.controls.password.setValue('strongpass1234');
  component.detailsForm.controls.confirm_password.setValue('strongpass1234');
}

describe('RegisterComponent (phone-first 4-step)', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('starts on step 1 with the phone field', () => {
    const { component, fixture } = setup();
    expect(component.step()).toBe(1);
    expect(fixture.nativeElement.querySelector('[data-testid="register-phone"]')).not.toBeNull();
  });

  describe('step 1 → 2 (initiate)', () => {
    it('calls registerInitiate with a derived country_code and advances', async () => {
      const { component, auth } = setup();
      component.phoneForm.controls.phone.setValue('+971501234567');
      await component.onInitiate();

      expect(auth.initiateCalls).toEqual([{ phone: '+971501234567', country_code: 'AE' }]);
      expect(component.step()).toBe(2);
    });

    it('derives SA for +966', async () => {
      const { component, auth } = setup();
      component.phoneForm.controls.phone.setValue('+966512345678');
      await component.onInitiate();
      expect(auth.initiateCalls[0].country_code).toBe('SA');
    });

    it('attaches phoneTaken error on CONFLICT_PHONE_TAKEN', async () => {
      const { component, auth } = setup();
      auth.initiateOutcome = 'phone-taken';
      component.phoneForm.controls.phone.setValue('+971501234567');
      await component.onInitiate();
      expect(component.phoneForm.controls.phone.errors).toEqual({ phoneTaken: true });
    });

    it('does not call initiate on empty phone', async () => {
      const { component, auth } = setup();
      await component.onInitiate();
      expect(auth.initiateCalls).toHaveLength(0);
    });
  });

  describe('step 2 → 3 (verify phone)', () => {
    it('exchanges the OTP for a registration_token and advances', async () => {
      const { component, auth } = setup();
      component.phoneForm.controls.phone.setValue('+971501234567');
      await component.onInitiate();
      component.otpForm.controls.code.setValue('123456');
      await component.onVerifyPhone();

      expect(auth.verifyPhoneCalls).toEqual([{ verification_id: 'phone-vid', code: '123456' }]);
      expect(component.step()).toBe(3);
    });
  });

  describe('step 3 → 4 (submit details)', () => {
    it('submits the details and advances to email verification', async () => {
      const { component, auth } = setup();
      await reachDetails(component);
      fillDetails(component);
      await component.onSubmitDetails();

      expect(auth.submitCalls).toEqual([
        {
          registration_token: 'reg-token',
          email: 'new@example.com',
          password: 'strongpass1234',
          first_name: 'Jane',
          last_name: 'Doe',
        },
      ]);
      expect(component.step()).toBe(4);
    });

    it('attaches emailTaken error on CONFLICT_EMAIL_TAKEN', async () => {
      const { component, auth } = setup();
      auth.submitOutcome = 'email-taken';
      await reachDetails(component);
      fillDetails(component);
      await component.onSubmitDetails();
      expect(component.detailsForm.controls.email.errors).toEqual({ emailTaken: true });
    });

    it('restarts at step 1 when the registration_token is expired', async () => {
      const { component, auth, toast } = setup();
      auth.submitOutcome = 'expired-token';
      await reachDetails(component);
      fillDetails(component);
      await component.onSubmitDetails();
      expect(component.step()).toBe(1);
      expect(toast.errors).toContain('auth.register.errors.sessionExpired');
    });

    it('blocks submit when passwords do not match', async () => {
      const { component, auth } = setup();
      await reachDetails(component);
      fillDetails(component);
      component.detailsForm.controls.confirm_password.setValue('different');
      await component.onSubmitDetails();
      expect(auth.submitCalls).toHaveLength(0);
    });
  });

  describe('step 4 (confirm email → auto-login)', () => {
    it('confirms the email OTP and navigates home', async () => {
      const { component, auth, navigateByUrlSpy } = setup();
      await reachDetails(component);
      fillDetails(component);
      await component.onSubmitDetails();
      component.otpForm.controls.code.setValue('654321');
      await component.onConfirmEmail();

      expect(auth.confirmEmailCalls).toEqual([{ verification_id: 'email-vid', code: '654321' }]);
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/');
    });
  });
});
