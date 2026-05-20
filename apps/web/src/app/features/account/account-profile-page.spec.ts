import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AccountProfilePageComponent } from './account-profile-page';
import { ProfileService, ProfileUpdate } from './profile.service';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { AuthUser } from '../../core/auth/auth.types';
import { HttpErrorResponse } from '@angular/common/http';

function makeUser(o: Partial<AuthUser> = {}): AuthUser {
  return {
    id: 1, email: 'sara@example.com', phone: '+971500000000', country_code: 'AE',
    first_name: 'Sara', last_name: 'Khan', gender: 'female', dob: '1990-05-01',
    locale: 'en', timezone: 'Asia/Dubai', is_phone_verified: true,
    is_email_verified: false, roles: [], is_store_approved: false,
    is_store_active: false, last_login_at: null, ...o,
  } as AuthUser;
}

class StubProfileService {
  private _loading = signal(false);
  private _saving = signal(false);
  isLoading = this._loading.asReadonly();
  isSaving = this._saving.asReadonly();

  getResult: AuthUser = makeUser();
  getThrows = false;
  updateThrows: unknown = null;
  updateCalls: ProfileUpdate[] = [];
  updateResult: AuthUser = makeUser();

  async getProfile(): Promise<AuthUser> {
    if (this.getThrows) throw new Error('load failed');
    return this.getResult;
  }
  async updateProfile(patch: ProfileUpdate): Promise<AuthUser> {
    this.updateCalls.push(patch);
    if (this.updateThrows !== null) throw this.updateThrows;
    return this.updateResult;
  }
}

class StubToast {
  calls: Array<{ kind: string; msg: string }> = [];
  success(m: string): string { this.calls.push({ kind: 'success', msg: m }); return ''; }
  error(m: string): string { this.calls.push({ kind: 'error', msg: m }); return ''; }
  info(m: string): string { this.calls.push({ kind: 'info', msg: m }); return ''; }
  warning(m: string): string { this.calls.push({ kind: 'warning', msg: m }); return ''; }
}

function setup(opts: {
  user?: AuthUser;
  getThrows?: boolean;
  updateThrows?: unknown;
  updateResult?: AuthUser;
} = {}): {
  fixture: ComponentFixture<AccountProfilePageComponent>;
  profile: StubProfileService;
  toast: StubToast;
} {
  const profile = new StubProfileService();
  if (opts.user !== undefined) profile.getResult = opts.user;
  if (opts.getThrows === true) profile.getThrows = true;
  if (opts.updateThrows !== undefined) profile.updateThrows = opts.updateThrows;
  profile.updateResult = opts.updateResult ?? opts.user ?? makeUser();
  const toast = new StubToast();

  TestBed.configureTestingModule({
    imports: [AccountProfilePageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: ProfileService, useValue: profile },
      { provide: ToastService, useValue: toast },
    ],
  });
  const fixture = TestBed.createComponent(AccountProfilePageComponent);
  fixture.detectChanges();
  return { fixture, profile, toast };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

function getControl(fixture: ComponentFixture<AccountProfilePageComponent>, testid: string): HTMLInputElement | HTMLSelectElement {
  return fixture.nativeElement.querySelector(`[data-testid="${testid}"]`);
}

function setInput(el: HTMLInputElement | HTMLSelectElement, value: string): void {
  el.value = value;
  el.dispatchEvent(new Event('input'));
  el.dispatchEvent(new Event('change'));
}

describe('AccountProfilePageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  describe('load', () => {
    it('populates the form from the loaded profile', async () => {
      const { fixture } = setup({ user: makeUser({ first_name: 'Layla', last_name: 'Noor' }) });
      await flush();
      fixture.detectChanges();
      expect((getControl(fixture, 'prof-first') as HTMLInputElement).value).toBe('Layla');
      expect((getControl(fixture, 'prof-last') as HTMLInputElement).value).toBe('Noor');
    });

    it('shows read-only email + phone with verified badges', async () => {
      const { fixture } = setup({ user: makeUser({ is_email_verified: true, is_phone_verified: true }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="prof-readonly"]').textContent).toContain('sara@example.com');
      expect(fixture.nativeElement.querySelector('[data-testid="prof-email-verified"]')).not.toBeNull();
      expect(fixture.nativeElement.querySelector('[data-testid="prof-phone-verified"]')).not.toBeNull();
    });

    it('omits the email verified badge when not verified', async () => {
      const { fixture } = setup({ user: makeUser({ is_email_verified: false }) });
      await flush();
      fixture.detectChanges();
      expect(fixture.nativeElement.querySelector('[data-testid="prof-email-verified"]')).toBeNull();
    });

    it('toasts on load failure', async () => {
      const { toast } = setup({ getThrows: true });
      await flush();
      expect(toast.calls.some(c => c.kind === 'error')).toBe(true);
    });
  });

  describe('submit — diff', () => {
    it('sends only the changed fields', async () => {
      const { fixture, profile } = setup({ user: makeUser({ first_name: 'Sara', last_name: 'Khan' }) });
      await flush();
      fixture.detectChanges();
      setInput(getControl(fixture, 'prof-first'), 'Sarah');
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="prof-save"]') as HTMLButtonElement).click();
      await flush();
      expect(profile.updateCalls).toHaveLength(1);
      expect(profile.updateCalls[0]).toEqual({ first_name: 'Sarah' });
    });

    it('sends null to clear a field emptied in the form', async () => {
      const { fixture, profile } = setup({ user: makeUser({ last_name: 'Khan' }) });
      await flush();
      fixture.detectChanges();
      setInput(getControl(fixture, 'prof-last'), '');
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="prof-save"]') as HTMLButtonElement).click();
      await flush();
      expect(profile.updateCalls[0]).toEqual({ last_name: null });
    });

    it('does not call the API and toasts info when nothing changed', async () => {
      const { fixture, profile, toast } = setup({ user: makeUser() });
      await flush();
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="prof-save"]') as HTMLButtonElement).click();
      await flush();
      expect(profile.updateCalls).toHaveLength(0);
      expect(toast.calls.some(c => c.kind === 'info')).toBe(true);
    });

    it('sends a changed gender + dob + locale', async () => {
      const { fixture, profile } = setup({ user: makeUser({ gender: 'female', dob: '1990-05-01', locale: 'en' }) });
      await flush();
      fixture.detectChanges();
      setInput(getControl(fixture, 'prof-gender'), 'other');
      setInput(getControl(fixture, 'prof-dob'), '1991-06-02');
      setInput(getControl(fixture, 'prof-locale'), 'ar');
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="prof-save"]') as HTMLButtonElement).click();
      await flush();
      expect(profile.updateCalls[0]).toEqual({ gender: 'other', dob: '1991-06-02', locale: 'ar' });
    });
  });

  describe('submit — outcomes', () => {
    it('toasts success on a successful save', async () => {
      const { fixture, toast } = setup({
        user: makeUser({ first_name: 'Sara' }),
        updateResult: makeUser({ first_name: 'Mariam' }),
      });
      await flush();
      fixture.detectChanges();
      setInput(getControl(fixture, 'prof-first'), 'Mariam');
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="prof-save"]') as HTMLButtonElement).click();
      await flush();
      expect(toast.calls.some(c => c.kind === 'success')).toBe(true);
    });

    it('surfaces a network error as a toast', async () => {
      const netErr = new HttpErrorResponse({ status: 0, statusText: 'Unknown Error' });
      const { fixture, toast } = setup({ user: makeUser({ first_name: 'Sara' }), updateThrows: netErr });
      await flush();
      fixture.detectChanges();
      setInput(getControl(fixture, 'prof-first'), 'Changed');
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="prof-save"]') as HTMLButtonElement).click();
      await flush();
      expect(toast.calls.some(c => c.kind === 'error')).toBe(true);
    });

    it('surfaces an unmapped server error as a toast', async () => {
      const serverErr = new HttpErrorResponse({
        status: 500, statusText: 'Server Error', error: { error_code: 'INTERNAL' },
      });
      const { fixture, toast } = setup({ user: makeUser({ first_name: 'Sara' }), updateThrows: serverErr });
      await flush();
      fixture.detectChanges();
      setInput(getControl(fixture, 'prof-first'), 'Changed');
      fixture.detectChanges();
      (fixture.nativeElement.querySelector('[data-testid="prof-save"]') as HTMLButtonElement).click();
      await flush();
      expect(toast.calls.some(c => c.kind === 'error')).toBe(true);
    });
  });
});
