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
import type { ResetRequestInput } from '../../../core/auth/auth.types';

/**
 * Tests for the two-channel ForgotPasswordComponent (#8).
 */

class StubAuthService {
  resetCalls: ResetRequestInput[] = [];
  outcome: 'success' | 'success-fake' | 'rate-limited' | 'network' = 'success';

  async requestPasswordResetChannel(input: ResetRequestInput): Promise<{ verification_id: string }> {
    this.resetCalls.push(input);
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
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  component: any;
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

describe('ForgotPasswordComponent (two-channel)', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders the channel toggle and email field by default', () => {
    const { fixture } = setup();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('[data-testid="forgot-channel"]')).not.toBeNull();
    expect(root.querySelector('[data-testid="forgot-email"]')).not.toBeNull();
  });

  it('switches to the phone field when the phone channel is selected', () => {
    const { component, fixture } = setup();
    component.setChannel('phone');
    fixture.detectChanges();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('[data-testid="forgot-phone"]')).not.toBeNull();
    expect(root.querySelector('[data-testid="forgot-email"]')).toBeNull();
  });

  it('does not call the reset endpoint on empty submit', async () => {
    const { component, auth } = setup();
    await component.onSubmit();
    expect(auth.resetCalls).toHaveLength(0);
  });

  it('sends an email reset and navigates with channel + destination', async () => {
    const { component, auth, navigateSpy } = setup();
    component.form.controls.email.setValue('jane@example.com');
    await component.onSubmit();

    expect(auth.resetCalls).toEqual([{ channel: 'email', email: 'jane@example.com' }]);
    expect(navigateSpy).toHaveBeenCalledWith(['/reset-password'], {
      queryParams: {
        verification_id: 'mc-real',
        channel: 'email',
        destination: 'jane@example.com',
      },
    });
  });

  it('sends a phone reset on the phone channel', async () => {
    const { component, auth, navigateSpy } = setup();
    component.setChannel('phone');
    component.form.controls.phone.setValue('+971501234567');
    await component.onSubmit();

    expect(auth.resetCalls).toEqual([{ channel: 'phone', phone: '+971501234567' }]);
    expect(navigateSpy).toHaveBeenCalledWith(['/reset-password'], {
      queryParams: {
        verification_id: 'mc-real',
        channel: 'phone',
        destination: '+971501234567',
      },
    });
  });

  it('navigates ALSO on a fake- prefix vid (anti-enumeration)', async () => {
    const { component, auth, navigateSpy } = setup();
    auth.outcome = 'success-fake';
    component.form.controls.email.setValue('nobody@example.com');
    await component.onSubmit();
    expect(navigateSpy).toHaveBeenCalledWith(['/reset-password'], {
      queryParams: {
        verification_id: 'fake-aaa',
        channel: 'email',
        destination: 'nobody@example.com',
      },
    });
  });

  it('shows rateLimited toast on OTP_RATE_LIMITED', async () => {
    const { component, auth, toast } = setup();
    auth.outcome = 'rate-limited';
    component.form.controls.email.setValue('jane@example.com');
    await component.onSubmit();
    expect(toast.errors).toContain('auth.forgot.errors.rateLimited');
  });

  it('shows network toast on fetch failure', async () => {
    const { component, auth, toast } = setup();
    auth.outcome = 'network';
    component.form.controls.email.setValue('jane@example.com');
    await component.onSubmit();
    expect(toast.errors).toContain('auth.login.errors.network');
  });
});
