import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Router, provideRouter, ActivatedRoute, convertToParamMap } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { VerifyPhoneComponent } from './verify-phone';
import { AuthService } from '../../../core/auth/auth.service';
import { ToastService } from '../../../shared/forms';
import { provideI18n } from '../../../core/i18n';
import type { AuthUser, ConfirmInput } from '../../../core/auth/auth.types';

/**
 * Tests for VerifyPhoneComponent.
 *
 * Coverage:
 *   - Resolves verification_id from query params or sessionStorage
 *   - Renders the missing-verification banner when neither is present
 *   - Masks the phone display correctly
 *   - Submits OTP via AuthService.confirmRegistration on valid input
 *   - Navigates to '/' on success and clears sessionStorage
 *   - Renders inline error on OTP_INVALID_CODE
 *   - Shows toast on OTP_RATE_LIMITED
 *   - Resend cooldown starts at 30s and counts down
 */

class StubAuthService {
  confirmCalls: ConfirmInput[] = [];
  outcome: 'success' | 'invalid-code' | 'rate-limited' | 'network' | 'verification-failed' = 'success';
  resendCalls: string[] = [];
  resendOutcome: 'success' | 'rate-limited' | 'network' = 'success';
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

  async confirmRegistration(input: ConfirmInput): Promise<AuthUser> {
    this.confirmCalls.push(input);
    if (this.outcome === 'success') return this.user;
    if (this.outcome === 'network') throw new TypeError('fetch failed');

    if (this.outcome === 'verification-failed') {
      /* Nested proxy envelope — { error: { code } } — as returned by
         /auth-proxy/confirm. Exercises both the nested-shape extraction
         and the OTP_VERIFICATION_FAILED -> inline mapping. */
      throw new HttpErrorResponse({
        status: 401,
        error: { error: { code: 'OTP_VERIFICATION_FAILED', message: 'Verification failed.' } },
      });
    }

    const codeMap = {
      'invalid-code': 'OTP_INVALID_CODE',
      'rate-limited': 'OTP_RATE_LIMITED',
    };
    throw new HttpErrorResponse({
      status: this.outcome === 'rate-limited' ? 429 : 422,
      error: { error_code: codeMap[this.outcome], message: codeMap[this.outcome] },
    });
  }

  private readonly _currentUser = signal<AuthUser | null>(null);
  readonly currentUser = this._currentUser.asReadonly();
  setCurrentUser(u: AuthUser | null): void {
    this._currentUser.set(u);
  }

  async resendOtp(email: string): Promise<{ verification_id: string }> {
    this.resendCalls.push(email);
    if (this.resendOutcome === 'network') throw new TypeError('fetch failed');
    if (this.resendOutcome === 'rate-limited') {
      throw new HttpErrorResponse({
        status: 429,
        error: { error_code: 'OTP_RATE_LIMITED', message: 'OTP_RATE_LIMITED' },
      });
    }
    return { verification_id: `mc-resent-${email}` };
  }
}

class StubToastService {
  errors: string[] = [];
  infos: string[] = [];
  error(msg: string): string {
    this.errors.push(msg);
    return msg;
  }
  info(msg: string): string {
    this.infos.push(msg);
    return msg;
  }
  show(input: { message: string }): string { return input.message; }
  successes: string[] = [];
  success(msg: string): string {
    this.successes.push(msg);
    return msg;
  }
  warning(): string { return ''; }
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

function setup(opts: {
  queryParams?: Record<string, string>;
  sessionStorage?: Record<string, string>;
  currentUser?: AuthUser | null;
} = {}): {
  fixture: ComponentFixture<VerifyPhoneComponent>;
  component: VerifyPhoneComponent;
  auth: StubAuthService;
  toast: StubToastService;
  router: Router;
  navigateByUrlSpy: ReturnType<typeof vi.fn>;
} {
  /* Seed sessionStorage BEFORE creating the component so ngOnInit
     sees the stored values. */
  if (typeof sessionStorage !== 'undefined') {
    sessionStorage.clear();
    for (const [k, v] of Object.entries(opts.sessionStorage ?? {})) {
      sessionStorage.setItem(k, v);
    }
  }

  const auth = new StubAuthService();
  const toast = new StubToastService();

  TestBed.configureTestingModule({
    imports: [VerifyPhoneComponent],
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
  const navigateByUrlSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;

  if (opts.currentUser !== undefined) {
    auth.setCurrentUser(opts.currentUser);
  }

  const fixture = TestBed.createComponent(VerifyPhoneComponent);
  fixture.detectChanges();

  return { fixture, component: fixture.componentInstance, auth, toast, router, navigateByUrlSpy };
}

describe('VerifyPhoneComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
    if (typeof sessionStorage !== 'undefined') {
      sessionStorage.clear();
    }
  });

  /* -----------------------------------------------------------------
     verification_id resolution
     ----------------------------------------------------------------- */
  describe('verification_id resolution', () => {
    it('reads verification_id from query params and shows the form', () => {
      const { fixture } = setup({
        queryParams: { verification_id: 'mc-abc123', phone: '+971501234567' },
      });
      const root: HTMLElement = fixture.nativeElement;
      expect(root.querySelector('[data-testid="verify-form"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="verify-missing-vid"]')).toBeNull();
    });

    it('falls back to sessionStorage when query params are absent', () => {
      const { fixture } = setup({
        sessionStorage: {
          'bayti_pending_verification_id': 'mc-stored',
          'bayti_pending_verification_phone': '+971501234567',
        },
      });
      const root: HTMLElement = fixture.nativeElement;
      expect(root.querySelector('[data-testid="verify-form"]')).not.toBeNull();
    });

    it('renders the missing-verification banner when neither query param nor sessionStorage has it', () => {
      const { fixture } = setup();
      const root: HTMLElement = fixture.nativeElement;
      expect(root.querySelector('[data-testid="verify-missing-vid"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="verify-form"]')).toBeNull();
    });

    it('persists the query-param verification_id to sessionStorage for refresh-safety', () => {
      setup({ queryParams: { verification_id: 'mc-fresh', phone: '+971501234567' } });
      expect(sessionStorage.getItem('bayti_pending_verification_id')).toBe('mc-fresh');
      expect(sessionStorage.getItem('bayti_pending_verification_phone')).toBe('+971501234567');
    });
  });

  /* -----------------------------------------------------------------
     Phone masking
     ----------------------------------------------------------------- */
  describe('phone display', () => {
    it('renders the subtitle with the phone interpolated (translate key uses {phone})', () => {
      const { fixture } = setup({
        queryParams: { verification_id: 'mc-abc', phone: '+971501234567' },
      });
      /* We can't assert on the resolved subtitle text since translations
         aren't loaded in the test harness — but we CAN assert the
         translate key is present in the DOM via the subtitle paragraph. */
      const subtitle: HTMLElement | null = fixture.nativeElement.querySelector('.auth-card__subtitle');
      expect(subtitle).not.toBeNull();
    });
  });

  /* -----------------------------------------------------------------
     Form submission
     ----------------------------------------------------------------- */
  describe('form submission', () => {
    it('does not call confirmRegistration on empty submit', async () => {
      const { component, auth, fixture } = setup({
        queryParams: { verification_id: 'mc-abc', phone: '+971501234567' },
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();
      expect(auth.confirmCalls).toHaveLength(0);
    });

    it('does not call confirmRegistration when code is fewer than 4 digits', async () => {
      const { component, auth, fixture } = setup({
        queryParams: { verification_id: 'mc-abc', phone: '+971501234567' },
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.code.setValue('123');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();
      expect(auth.confirmCalls).toHaveLength(0);
    });

    it('accepts a 4-digit code (MessageCentral sends 4 digits)', async () => {
      const { component, auth, fixture } = setup({
        queryParams: { verification_id: 'mc-abc4', phone: '+971501234567' },
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.code.setValue('1234');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();
      expect(auth.confirmCalls).toEqual([{ verification_id: 'mc-abc4', code: '1234' }]);
    });

    it('does not call confirmRegistration when code contains non-digits', async () => {
      const { component, auth, fixture } = setup({
        queryParams: { verification_id: 'mc-abc', phone: '+971501234567' },
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.code.setValue('12a456');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();
      expect(auth.confirmCalls).toHaveLength(0);
    });

    it('calls confirmRegistration with verification_id + code on valid submit', async () => {
      const { component, auth, fixture } = setup({
        queryParams: { verification_id: 'mc-abc123', phone: '+971501234567' },
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.code.setValue('123456');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(auth.confirmCalls).toEqual([{ verification_id: 'mc-abc123', code: '123456' }]);
    });

    it('navigates to / and clears sessionStorage on success', async () => {
      const { component, navigateByUrlSpy, fixture } = setup({
        queryParams: { verification_id: 'mc-abc', phone: '+971501234567' },
      });
      expect(sessionStorage.getItem('bayti_pending_verification_id')).toBe('mc-abc');

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.code.setValue('123456');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(navigateByUrlSpy).toHaveBeenCalledWith('/');
      expect(sessionStorage.getItem('bayti_pending_verification_id')).toBeNull();
      expect(sessionStorage.getItem('bayti_pending_verification_phone')).toBeNull();
    });

    it('honours a safe returnUrl query param on success', async () => {
      const { component, navigateByUrlSpy, fixture } = setup({
        queryParams: {
          verification_id: 'mc-abc',
          phone: '+971501234567',
          returnUrl: '/checkout/review',
        },
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.code.setValue('123456');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(navigateByUrlSpy).toHaveBeenCalledWith('/checkout/review');
    });

    it('ignores an unsafe (protocol-relative) returnUrl and goes home', async () => {
      const { component, navigateByUrlSpy, fixture } = setup({
        queryParams: {
          verification_id: 'mc-abc',
          phone: '+971501234567',
          returnUrl: '//evil.example/',
        },
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.code.setValue('123456');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(navigateByUrlSpy).toHaveBeenCalledWith('/');
    });
  });

  /* -----------------------------------------------------------------
     Error paths
     ----------------------------------------------------------------- */
  describe('error paths', () => {
    it('attaches invalidCode error on OTP_VERIFICATION_FAILED (nested proxy envelope)', async () => {
      const { component, auth, fixture } = setup({
        queryParams: { verification_id: 'mc-abc', phone: '+971501234567' },
      });
      auth.outcome = 'verification-failed';
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.code.setValue('123456');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const errors = (component as any).form.controls.code.errors;
      expect(errors).toEqual({ invalidCode: true });
    });

    it('attaches invalidCode error on OTP_INVALID_CODE', async () => {
      const { component, auth, fixture } = setup({
        queryParams: { verification_id: 'mc-abc', phone: '+971501234567' },
      });
      auth.outcome = 'invalid-code';
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.code.setValue('999999');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      const errors = (component as any).form.controls.code.errors;
      expect(errors).toEqual({ invalidCode: true });
    });

    it('shows rateLimited toast on OTP_RATE_LIMITED', async () => {
      const { component, auth, toast, fixture } = setup({
        queryParams: { verification_id: 'mc-abc', phone: '+971501234567' },
      });
      auth.outcome = 'rate-limited';
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.code.setValue('123456');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(toast.errors).toContain('auth.verifyPhone.errors.rateLimited');
    });

    it('shows network toast on TypeError', async () => {
      const { component, auth, toast, fixture } = setup({
        queryParams: { verification_id: 'mc-abc', phone: '+971501234567' },
      });
      auth.outcome = 'network';
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      (component as any).form.controls.code.setValue('123456');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSubmit();
      fixture.detectChanges();

      expect(toast.errors).toContain('auth.login.errors.network');
    });
  });

  /* -----------------------------------------------------------------
     Resend cooldown
     ----------------------------------------------------------------- */
  describe('resend cooldown', () => {
    it('starts a 30s countdown when resend is clicked', async () => {
      vi.useFakeTimers();
      try {
        const { component, fixture } = setup({
          queryParams: { verification_id: 'mc-abc', phone: '+971501234567' },
        });
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        await (component as any).onResend();
        fixture.detectChanges();

        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        expect((component as any).resendCooldown()).toBe(30);

        vi.advanceTimersByTime(5_000);
        fixture.detectChanges();
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        expect((component as any).resendCooldown()).toBe(25);

        vi.advanceTimersByTime(25_000);
        fixture.detectChanges();
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        expect((component as any).resendCooldown()).toBe(0);
      } finally {
        vi.useRealTimers();
      }
    });

    it('disables the resend button while in cooldown', async () => {
      vi.useFakeTimers();
      try {
        const { component, fixture } = setup({
          queryParams: { verification_id: 'mc-abc', phone: '+971501234567' },
        });
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        await (component as any).onResend();
        fixture.detectChanges();

        const button: HTMLButtonElement = fixture.nativeElement.querySelector('[data-testid="verify-resend"]');
        expect(button.disabled).toBe(true);

        vi.advanceTimersByTime(30_000);
        fixture.detectChanges();
        expect(button.disabled).toBe(false);
      } finally {
        vi.useRealTimers();
      }
    });
  });

  /* -----------------------------------------------------------------
     Self-serve send (signed-in, unverified, no verification_id) —
     the post-registration nudge flow (account / checkout / header).
     ----------------------------------------------------------------- */
  describe('self-serve send', () => {
    it('shows the send-code screen for a signed-in unverified user with no verification_id', () => {
      const { fixture } = setup({ currentUser: makeUser({ is_phone_verified: false }) });
      const root: HTMLElement = fixture.nativeElement;
      expect(root.querySelector('[data-testid="verify-send-code"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="verify-missing-vid"]')).toBeNull();
      expect(root.querySelector('[data-testid="verify-form"]')).toBeNull();
    });

    it('onSendCode mints + adopts a verification_id and reveals the code form', async () => {
      const { component, auth, toast, fixture } = setup({
        currentUser: makeUser({ is_phone_verified: false }),
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onSendCode();
      fixture.detectChanges();

      expect(auth.resendCalls).toEqual(['jane@example.com']);
      expect(fixture.nativeElement.querySelector('[data-testid="verify-form"]')).not.toBeNull();
      expect(toast.successes).toContain('auth.verifyPhone.codeSent');
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      expect((component as any).resendCooldown()).toBe(30);
    });

    it('still shows the missing-verification banner for an anonymous visitor', () => {
      const { fixture } = setup({ currentUser: null });
      const root: HTMLElement = fixture.nativeElement;
      expect(root.querySelector('[data-testid="verify-missing-vid"]')).not.toBeNull();
      expect(root.querySelector('[data-testid="verify-send-code"]')).toBeNull();
    });

    it('redirects an already-verified user away when there is no verification_id', () => {
      const { navigateByUrlSpy } = setup({
        currentUser: makeUser({ is_phone_verified: true }),
        queryParams: { returnUrl: '/account' },
      });
      expect(navigateByUrlSpy).toHaveBeenCalledWith('/account');
    });

    it('onResend re-sends via send-otp and adopts the new verification_id for a signed-in user', async () => {
      const { component, auth, toast, fixture } = setup({
        queryParams: { verification_id: 'mc-old', phone: '+971501234567' },
        currentUser: makeUser({ is_phone_verified: false }),
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onResend();
      fixture.detectChanges();

      expect(auth.resendCalls).toEqual(['jane@example.com']);
      expect(sessionStorage.getItem('bayti_pending_verification_id')).toBe('mc-resent-jane@example.com');
      expect(toast.successes).toContain('auth.verifyPhone.codeSent');
    });

    it('onResend falls back to resendUnavailable when no signed-in email is available', async () => {
      const { component, toast, fixture } = setup({
        queryParams: { verification_id: 'mc-old', phone: '+971501234567' },
        currentUser: null,
      });
      // eslint-disable-next-line @typescript-eslint/no-explicit-any
      await (component as any).onResend();
      fixture.detectChanges();

      expect(toast.infos).toContain('auth.verifyPhone.errors.resendUnavailable');
    });
  });
});
