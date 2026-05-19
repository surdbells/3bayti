import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { RegisterComponent } from './register';
import { AuthService } from '../../../core/auth/auth.service';
import { ToastService } from '../../../shared/forms';
import { provideI18n } from '../../../core/i18n';
import type { RegisterInput, RegisterResponse } from '../../../core/auth/auth.types';

/**
 * Tests for RegisterComponent.
 *
 * Coverage
 * --------
 *   - Form starts invalid; submit no-ops
 *   - Successful submit calls AuthService.register with the exact
 *     payload shape RegisterInput requires (including country_code
 *     derived from the phone)
 *   - Successful submit navigates to /verify-phone with vid + phone
 *     query params
 *   - Per-field errors for CONFLICT_EMAIL_TAKEN / CONFLICT_PHONE_TAKEN
 *   - Toasts for OTP_RATE_LIMITED / OTP_PROVIDER_ERROR
 *   - Network toast on fetch failure
 *   - Submit disabled while in flight
 */

class StubAuthService {
  registerCalls: RegisterInput[] = [];
  outcome:
    | 'success'
    | 'email-taken'
    | 'phone-taken'
    | 'rate-limited'
    | 'provider-error'
    | 'network' = 'success';
  response: RegisterResponse = {
    verification_id: 'mc-abc123',
    user: {
      email: 'new@example.com',
      phone: '+971501234567',
      country_code: 'AE',
      is_phone_verified: false,
    },
  };

  async register(input: RegisterInput): Promise<RegisterResponse> {
    this.registerCalls.push(input);
    if (this.outcome === 'success') return this.response;
    if (this.outcome === 'network') throw new TypeError('fetch failed');

    const codeMap = {
      'email-taken': 'CONFLICT_EMAIL_TAKEN',
      'phone-taken': 'CONFLICT_PHONE_TAKEN',
      'rate-limited': 'OTP_RATE_LIMITED',
      'provider-error': 'OTP_PROVIDER_ERROR',
    };
    const code = codeMap[this.outcome];
    const status = this.outcome.includes('taken')
      ? 409
      : this.outcome === 'rate-limited'
        ? 429
        : 502;
    throw new HttpErrorResponse({
      status,
      error: { error_code: code, message: code },
    });
  }
}

class StubToastService {
  errors: string[] = [];
  error(msg: string): string {
    this.errors.push(msg);
    return msg;
  }
  show(input: { message: string }): string { return input.message; }
  success(): string { return ''; }
  warning(): string { return ''; }
  info(): string { return ''; }
  dismiss(): void { /* no-op */ }
  clearAll(): void { /* no-op */ }
  toasts = signal<unknown[]>([]).asReadonly();
  hasToasts = signal(false).asReadonly();
}

function setup(): {
  fixture: ComponentFixture<RegisterComponent>;
  component: RegisterComponent;
  auth: StubAuthService;
  toast: StubToastService;
  router: Router;
  navigateSpy: ReturnType<typeof vi.fn>;
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
  const navigateSpy = vi.spyOn(router, 'navigate').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;

  const fixture = TestBed.createComponent(RegisterComponent);
  fixture.detectChanges();

  return { fixture, component: fixture.componentInstance, auth, toast, router, navigateSpy };
}

/* Helper — fill the form to a valid state. */
function fillValid(component: RegisterComponent): void {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const form = (component as any).form;
  form.controls.email.setValue('new@example.com');
  form.controls.phone.setValue('+971501234567');
  form.controls.password.setValue('strongpass1234');
  form.controls.first_name.setValue('Jane');
  form.controls.last_name.setValue('Doe');
}

describe('RegisterComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders the form with all expected fields', () => {
    const { fixture } = setup();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('[data-testid="register-email"]')).not.toBeNull();
    expect(root.querySelector('[data-testid="register-phone"]')).not.toBeNull();
    expect(root.querySelector('[data-testid="register-password"]')).not.toBeNull();
    expect(root.querySelector('[data-testid="register-first-name"]')).not.toBeNull();
    expect(root.querySelector('[data-testid="register-last-name"]')).not.toBeNull();
    expect(root.querySelector('[data-testid="register-submit"]')).not.toBeNull();
  });

  it('does not call AuthService.register on empty submit', async () => {
    const { component, auth, fixture } = setup();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (component as any).onSubmit();
    fixture.detectChanges();
    expect(auth.registerCalls).toHaveLength(0);
  });

  describe('successful registration', () => {
    it('calls AuthService.register with the correct payload including derived country_code', async () => {
      const { component, auth, fixture } = setup();
      fillValid(component);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(auth.registerCalls).toHaveLength(1);
      expect(auth.registerCalls[0]).toEqual({
        email: 'new@example.com',
        phone: '+971501234567',
        password: 'strongpass1234',
        country_code: 'AE',
        first_name: 'Jane',
        last_name: 'Doe',
      });
    });

    it('derives Saudi country_code from +966 phone', async () => {
      const { component, auth, fixture } = setup();
      fillValid(component);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.phone.setValue('+966501234567');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(auth.registerCalls[0].country_code).toBe('SA');
    });

    it('passes null for empty first_name and last_name', async () => {
      const { component, auth, fixture } = setup();
      fillValid(component);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.first_name.setValue('');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.last_name.setValue('');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(auth.registerCalls[0].first_name).toBeNull();
      expect(auth.registerCalls[0].last_name).toBeNull();
    });

    it('navigates to /verify-phone with verification_id, phone, and from=register query params', async () => {
      const { component, navigateSpy, fixture } = setup();
      fillValid(component);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(navigateSpy).toHaveBeenCalledWith(['/verify-phone'], {
        queryParams: {
          verification_id: 'mc-abc123',
          phone: '+971501234567',
          from: 'register',
        },
      });
    });
  });

  describe('error paths', () => {
    it('attaches emailTaken error on CONFLICT_EMAIL_TAKEN', async () => {
      const { component, auth, fixture } = setup();
      auth.outcome = 'email-taken';
      fillValid(component);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const emailErrors = (component as any).form.controls.email.errors;
      expect(emailErrors).toEqual({ emailTaken: true });
    });

    it('attaches phoneTaken error on CONFLICT_PHONE_TAKEN', async () => {
      const { component, auth, fixture } = setup();
      auth.outcome = 'phone-taken';
      fillValid(component);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const phoneErrors = (component as any).form.controls.phone.errors;
      expect(phoneErrors).toEqual({ phoneTaken: true });
    });

    it('shows rateLimited toast on OTP_RATE_LIMITED', async () => {
      const { component, auth, toast, fixture } = setup();
      auth.outcome = 'rate-limited';
      fillValid(component);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();
      expect(toast.errors).toContain('auth.register.errors.rateLimited');
    });

    it('shows providerError toast on OTP_PROVIDER_ERROR', async () => {
      const { component, auth, toast, fixture } = setup();
      auth.outcome = 'provider-error';
      fillValid(component);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();
      expect(toast.errors).toContain('auth.register.errors.providerError');
    });

    it('shows network toast on fetch failure', async () => {
      const { component, auth, toast, fixture } = setup();
      auth.outcome = 'network';
      fillValid(component);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();
      expect(toast.errors).toContain('auth.login.errors.network');
    });
  });

  describe('submitting state', () => {
    it('disables the submit button while register is in flight', async () => {
      const { component, fixture, auth } = setup();
      let resolveCall: (v: RegisterResponse) => void = () => undefined;
      auth.register = (_input) => {
        auth.registerCalls.push(_input);
        return new Promise(resolve => { resolveCall = resolve; });
      };
      fillValid(component);
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const submitPromise = (component as any).onSubmit();
      fixture.detectChanges();

      const button: HTMLButtonElement = fixture.nativeElement.querySelector('[data-testid="register-submit"]');
      expect(button.disabled).toBe(true);

      resolveCall(auth.response);
      await submitPromise;
      fixture.detectChanges();
      expect(button.disabled).toBe(false);
    });
  });
});
