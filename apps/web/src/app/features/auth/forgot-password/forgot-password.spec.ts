import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ForgotPasswordComponent } from './forgot-password';
import { AuthService } from '../../../core/auth/auth.service';
import { ToastService } from '../../../shared/forms';
import { provideI18n } from '../../../core/i18n';

class StubAuthService {
  resetCalls: string[] = [];
  outcome: 'success' | 'success-fake' | 'rate-limited' | 'network' = 'success';

  async requestPasswordReset(email: string): Promise<{ verification_id: string }> {
    this.resetCalls.push(email);
    if (this.outcome === 'success') return { verification_id: 'mc-real' };
    if (this.outcome === 'success-fake') return { verification_id: 'fake-aaa' };
    if (this.outcome === 'network') throw new TypeError('fetch failed');
    throw new HttpErrorResponse({
      status: 429,
      error: { error_code: 'OTP_RATE_LIMITED', message: 'Too many.' },
    });
  }
}

class StubToastService {
  errors: string[] = [];
  error(msg: string): string { this.errors.push(msg); return msg; }
  show(): string { return ''; }
  success(): string { return ''; }
  warning(): string { return ''; }
  info(): string { return ''; }
  dismiss(): void { /* no-op */ }
  clearAll(): void { /* no-op */ }
  toasts = signal<unknown[]>([]).asReadonly();
  hasToasts = signal(false).asReadonly();
}

function setup(): {
  fixture: ComponentFixture<ForgotPasswordComponent>;
  component: ForgotPasswordComponent;
  auth: StubAuthService;
  toast: StubToastService;
  navigateSpy: ReturnType<typeof vi.fn>;
} {
  const auth = new StubAuthService();
  const toast = new StubToastService();
  TestBed.configureTestingModule({
    imports: [ForgotPasswordComponent],
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
  const fixture = TestBed.createComponent(ForgotPasswordComponent);
  fixture.detectChanges();
  return { fixture, component: fixture.componentInstance, auth, toast, navigateSpy };
}

describe('ForgotPasswordComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders the email field and submit button', () => {
    const { fixture } = setup();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('[data-testid="forgot-email"]')).not.toBeNull();
    expect(root.querySelector('[data-testid="forgot-submit"]')).not.toBeNull();
  });

  it('does not call requestPasswordReset on empty submit', async () => {
    const { component, auth, fixture } = setup();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (component as any).onSubmit();
    fixture.detectChanges();
    expect(auth.resetCalls).toHaveLength(0);
  });

  it('navigates to /reset-password with vid + email on success', async () => {
    const { component, navigateSpy, fixture } = setup();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (component as any).form.controls.email.setValue('jane@example.com');
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (component as any).onSubmit();
    fixture.detectChanges();
    expect(navigateSpy).toHaveBeenCalledWith(['/reset-password'], {
      queryParams: { verification_id: 'mc-real', email: 'jane@example.com' },
    });
  });

  it('navigates to /reset-password ALSO when the API returns a fake- prefix vid (anti-enumeration)', async () => {
    /* Anti-enumeration: the frontend must NOT branch on the
       verification_id shape. The reset-confirm endpoint will reject
       the fake- prefixed vid with OTP_INVALID_CODE, but that's
       indistinguishable from a wrong code attempt on a real vid. */
    const { component, auth, navigateSpy, fixture } = setup();
    auth.outcome = 'success-fake';
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (component as any).form.controls.email.setValue('nobody@example.com');
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (component as any).onSubmit();
    fixture.detectChanges();
    expect(navigateSpy).toHaveBeenCalledWith(['/reset-password'], {
      queryParams: { verification_id: 'fake-aaa', email: 'nobody@example.com' },
    });
  });

  it('shows rateLimited toast on OTP_RATE_LIMITED', async () => {
    const { component, auth, toast, fixture } = setup();
    auth.outcome = 'rate-limited';
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (component as any).form.controls.email.setValue('jane@example.com');
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (component as any).onSubmit();
    fixture.detectChanges();
    expect(toast.errors).toContain('auth.forgot.errors.rateLimited');
  });

  it('shows network toast on fetch failure', async () => {
    const { component, auth, toast, fixture } = setup();
    auth.outcome = 'network';
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (component as any).form.controls.email.setValue('jane@example.com');
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (component as any).onSubmit();
    fixture.detectChanges();
    expect(toast.errors).toContain('auth.login.errors.network');
  });
});
