import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Router, provideRouter, ActivatedRoute, convertToParamMap } from '@angular/router';
import { HttpErrorResponse, provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { ResetPasswordComponent } from './reset-password';
import { AuthService } from '../../../core/auth/auth.service';
import { ToastService } from '../../../shared/forms';
import { provideI18n } from '../../../core/i18n';
import type { AuthUser, ResetConfirmInput } from '../../../core/auth/auth.types';

class StubAuthService {
  resetConfirmCalls: ResetConfirmInput[] = [];
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

  async confirmPasswordReset(input: ResetConfirmInput): Promise<AuthUser> {
    this.resetConfirmCalls.push(input);
    if (this.outcome === 'success') return this.user;
    if (this.outcome === 'network') throw new TypeError('fetch failed');
    const map = { 'invalid-code': 'OTP_INVALID_CODE', 'rate-limited': 'OTP_RATE_LIMITED' };
    throw new HttpErrorResponse({
      status: this.outcome === 'rate-limited' ? 429 : 422,
      error: { error_code: map[this.outcome], message: map[this.outcome] },
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

function makeRoute(queryParams: Record<string, string> = {}): ActivatedRoute {
  return { snapshot: { queryParamMap: convertToParamMap(queryParams) } } as ActivatedRoute;
}

function setup(opts: { queryParams?: Record<string, string> } = {}): {
  fixture: ComponentFixture<ResetPasswordComponent>;
  component: ResetPasswordComponent;
  auth: StubAuthService;
  toast: StubToastService;
  navigateByUrlSpy: ReturnType<typeof vi.fn>;
} {
  const auth = new StubAuthService();
  const toast = new StubToastService();
  TestBed.configureTestingModule({
    imports: [ResetPasswordComponent],
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
  const fixture = TestBed.createComponent(ResetPasswordComponent);
  fixture.detectChanges();
  return { fixture, component: fixture.componentInstance, auth, toast, navigateByUrlSpy };
}

function fillValid(component: ResetPasswordComponent, opts: { code?: string; password?: string; confirm?: string } = {}): void {
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const form = (component as any).form;
  form.controls.code.setValue(opts.code ?? '123456');
  form.controls.new_password.setValue(opts.password ?? 'strongpass1234');
  form.controls.confirm_password.setValue(opts.confirm ?? 'strongpass1234');
  form.updateValueAndValidity();
}

describe('ResetPasswordComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders the missing-vid banner when no verification_id is in the URL', () => {
    const { fixture } = setup();
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('[data-testid="reset-missing-vid"]')).not.toBeNull();
    expect(root.querySelector('[data-testid="reset-form"]')).toBeNull();
  });

  it('renders the form when verification_id is present', () => {
    const { fixture } = setup({
      queryParams: { verification_id: 'mc-abc', email: 'jane@example.com' },
    });
    const root: HTMLElement = fixture.nativeElement;
    expect(root.querySelector('[data-testid="reset-form"]')).not.toBeNull();
    expect(root.querySelector('[data-testid="reset-missing-vid"]')).toBeNull();
  });

  it('blocks submit when passwords do not match (passwordsMismatch validator)', async () => {
    const { component, auth, fixture } = setup({
      queryParams: { verification_id: 'mc-abc' },
    });
    fillValid(component, { confirm: 'different-from-newpass' });
    fixture.detectChanges();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    expect((component as any).form.controls.confirm_password.errors?.passwordsMismatch).toBe(true);

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (component as any).onSubmit();
    fixture.detectChanges();
    expect(auth.resetConfirmCalls).toHaveLength(0);
  });

  it('clears the passwordsMismatch error when the passwords match', async () => {
    const { component, fixture } = setup({ queryParams: { verification_id: 'mc-abc' } });
    fillValid(component, { confirm: 'different' });
    fixture.detectChanges();
    fillValid(component, { confirm: 'strongpass1234' }); /* matches now */
    fixture.detectChanges();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const errors = (component as any).form.controls.confirm_password.errors;
    /* Either null OR has no passwordsMismatch key. */
    expect(errors?.passwordsMismatch).toBeUndefined();
  });

  it('calls confirmPasswordReset with vid, code, new_password on valid submit', async () => {
    const { component, auth, fixture } = setup({ queryParams: { verification_id: 'mc-real' } });
    fillValid(component);
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (component as any).onSubmit();
    fixture.detectChanges();
    expect(auth.resetConfirmCalls).toEqual([
      { verification_id: 'mc-real', code: '123456', new_password: 'strongpass1234' },
    ]);
  });

  it('navigates to / on success (auto-login)', async () => {
    const { component, navigateByUrlSpy, fixture } = setup({ queryParams: { verification_id: 'mc-real' } });
    fillValid(component);
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (component as any).onSubmit();
    fixture.detectChanges();
    expect(navigateByUrlSpy).toHaveBeenCalledWith('/');
  });

  it('attaches invalidCode error on OTP_INVALID_CODE', async () => {
    const { component, auth, fixture } = setup({ queryParams: { verification_id: 'mc-real' } });
    auth.outcome = 'invalid-code';
    fillValid(component);
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (component as any).onSubmit();
    fixture.detectChanges();
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const errors = (component as any).form.controls.code.errors;
    expect(errors).toEqual({ invalidCode: true });
  });

  it('shows rateLimited toast on OTP_RATE_LIMITED', async () => {
    const { component, auth, toast, fixture } = setup({ queryParams: { verification_id: 'mc-real' } });
    auth.outcome = 'rate-limited';
    fillValid(component);
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    await (component as any).onSubmit();
    fixture.detectChanges();
    expect(toast.errors).toContain('auth.reset.errors.rateLimited');
  });
});
