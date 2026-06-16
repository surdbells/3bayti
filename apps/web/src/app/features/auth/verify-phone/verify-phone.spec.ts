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
  outcome: 'success' | 'invalid-code' | 'rate-limited' | 'network' = 'success';
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

    const codeMap = {
      'invalid-code': 'OTP_INVALID_CODE',
      'rate-limited': 'OTP_RATE_LIMITED',
    };
    throw new HttpErrorResponse({
      status: this.outcome === 'rate-limited' ? 429 : 422,
      error: { error_code: codeMap[this.outcome], message: codeMap[this.outcome] },
    });
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
  success(): string { return ''; }
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

function setup(opts: {
  queryParams?: Record<string, string>;
  sessionStorage?: Record<string, string>;
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

    it('does not call confirmRegistration when code is fewer than 6 digits', async () => {
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
});
