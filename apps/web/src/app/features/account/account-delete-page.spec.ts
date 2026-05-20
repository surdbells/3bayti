import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { provideHttpClient, HttpErrorResponse } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AccountDeletePageComponent } from './account-delete-page';
import { ProfileService } from './profile.service';
import { AuthService } from '../../core/auth/auth.service';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';

class StubProfileService {
  private _saving = signal(false);
  isSaving = this._saving.asReadonly();
  deleteCalls: string[] = [];
  deleteThrows: unknown = null;
  async deleteAccount(currentPassword: string): Promise<void> {
    this.deleteCalls.push(currentPassword);
    if (this.deleteThrows !== null) throw this.deleteThrows;
  }
}

class StubAuthService {
  logoutCalled = false;
  async logout(): Promise<void> { this.logoutCalled = true; }
}

class StubToast {
  calls: Array<{ kind: string; msg: string }> = [];
  success(m: string): string { this.calls.push({ kind: 'success', msg: m }); return ''; }
  error(m: string): string { this.calls.push({ kind: 'error', msg: m }); return ''; }
  info(m: string): string { this.calls.push({ kind: 'info', msg: m }); return ''; }
  warning(m: string): string { this.calls.push({ kind: 'warning', msg: m }); return ''; }
}

function setup(opts: { deleteThrows?: unknown } = {}): {
  fixture: ComponentFixture<AccountDeletePageComponent>;
  profile: StubProfileService;
  auth: StubAuthService;
  toast: StubToast;
  navSpy: ReturnType<typeof vi.fn>;
} {
  const profile = new StubProfileService();
  if (opts.deleteThrows !== undefined) profile.deleteThrows = opts.deleteThrows;
  const auth = new StubAuthService();
  const toast = new StubToast();

  TestBed.configureTestingModule({
    imports: [AccountDeletePageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: ProfileService, useValue: profile },
      { provide: AuthService, useValue: auth },
      { provide: ToastService, useValue: toast },
    ],
  });
  const router = TestBed.inject(Router);
  const navSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;
  const fixture = TestBed.createComponent(AccountDeletePageComponent);
  fixture.detectChanges();
  return { fixture, profile, auth, toast, navSpy };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

function q(fixture: ComponentFixture<AccountDeletePageComponent>, testid: string): HTMLElement | null {
  return fixture.nativeElement.querySelector(`[data-testid="${testid}"]`);
}

function setPassword(fixture: ComponentFixture<AccountDeletePageComponent>, value: string): void {
  const el = q(fixture, 'del-password') as HTMLInputElement;
  el.value = value;
  el.dispatchEvent(new Event('input'));
}

describe('AccountDeletePageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders the warning and does not open the modal initially', () => {
    const { fixture } = setup();
    expect(q(fixture, 'delete-warning')).not.toBeNull();
    expect(q(fixture, 'confirm-modal')).toBeNull();
  });

  it('does not open the modal when the password is empty', async () => {
    const { fixture } = setup();
    (q(fixture, 'del-submit') as HTMLButtonElement).click();
    await flush();
    fixture.detectChanges();
    expect(q(fixture, 'confirm-modal')).toBeNull();
  });

  it('opens the confirm modal once a password is entered + submitted', async () => {
    const { fixture } = setup();
    setPassword(fixture, 'MyPass123');
    fixture.detectChanges();
    (q(fixture, 'del-submit') as HTMLButtonElement).click();
    fixture.detectChanges();
    expect(q(fixture, 'confirm-modal')).not.toBeNull();
  });

  it('deletes, logs out, toasts and redirects home on confirm', async () => {
    const { fixture, profile, auth, toast, navSpy } = setup();
    setPassword(fixture, 'MyPass123');
    fixture.detectChanges();
    (q(fixture, 'del-submit') as HTMLButtonElement).click();
    fixture.detectChanges();
    (q(fixture, 'confirm-modal-confirm') as HTMLButtonElement).click();
    await flush();
    expect(profile.deleteCalls).toEqual(['MyPass123']);
    expect(auth.logoutCalled).toBe(true);
    expect(toast.calls.some(c => c.kind === 'success')).toBe(true);
    expect(navSpy).toHaveBeenCalledWith('/');
  });

  it('does not delete when the modal is dismissed', async () => {
    const { fixture, profile, auth } = setup();
    setPassword(fixture, 'MyPass123');
    fixture.detectChanges();
    (q(fixture, 'del-submit') as HTMLButtonElement).click();
    fixture.detectChanges();
    (q(fixture, 'confirm-modal-cancel') as HTMLButtonElement).click();
    await flush();
    fixture.detectChanges();
    expect(profile.deleteCalls).toEqual([]);
    expect(auth.logoutCalled).toBe(false);
    expect(q(fixture, 'confirm-modal')).toBeNull();
  });

  it('on wrong-password 401, closes the modal and does not log out / redirect', async () => {
    const authErr = new HttpErrorResponse({
      status: 401, statusText: 'Unauthorized',
      error: { error_code: 'AUTH_INVALID_CREDENTIALS' },
    });
    const { fixture, auth, navSpy, toast } = setup({ deleteThrows: authErr });
    setPassword(fixture, 'wrong');
    fixture.detectChanges();
    (q(fixture, 'del-submit') as HTMLButtonElement).click();
    fixture.detectChanges();
    (q(fixture, 'confirm-modal-confirm') as HTMLButtonElement).click();
    await flush();
    fixture.detectChanges();
    expect(auth.logoutCalled).toBe(false);
    expect(navSpy).not.toHaveBeenCalled();
    expect(toast.calls.some(c => c.kind === 'success')).toBe(false);
    /* Modal closed so the field error is visible. */
    expect(q(fixture, 'confirm-modal')).toBeNull();
  });

  it('toasts a network error on a connection failure', async () => {
    const netErr = new HttpErrorResponse({ status: 0, statusText: 'Unknown Error' });
    const { fixture, toast, auth } = setup({ deleteThrows: netErr });
    setPassword(fixture, 'MyPass123');
    fixture.detectChanges();
    (q(fixture, 'del-submit') as HTMLButtonElement).click();
    fixture.detectChanges();
    (q(fixture, 'confirm-modal-confirm') as HTMLButtonElement).click();
    await flush();
    expect(toast.calls.some(c => c.kind === 'error')).toBe(true);
    expect(auth.logoutCalled).toBe(false);
  });
});
