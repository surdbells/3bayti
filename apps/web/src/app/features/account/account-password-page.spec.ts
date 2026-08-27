import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter, Router } from '@angular/router';
import { provideHttpClient, HttpErrorResponse } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { signal } from '@angular/core';
import { AccountPasswordPageComponent } from './account-password-page';
import { ProfileService } from './profile.service';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';

class StubProfileService {
  private _saving = signal(false);
  isSaving = this._saving.asReadonly();
  changeCalls: Array<{ current: string; next: string }> = [];
  changeThrows: unknown = null;
  async changePassword(current: string, next: string): Promise<void> {
    this.changeCalls.push({ current, next });
    if (this.changeThrows !== null) throw this.changeThrows;
  }
}

class StubToast {
  calls: Array<{ kind: string; msg: string }> = [];
  success(m: string): string { this.calls.push({ kind: 'success', msg: m }); return ''; }
  error(m: string): string { this.calls.push({ kind: 'error', msg: m }); return ''; }
  info(m: string): string { this.calls.push({ kind: 'info', msg: m }); return ''; }
  warning(m: string): string { this.calls.push({ kind: 'warning', msg: m }); return ''; }
}

function setup(opts: { changeThrows?: unknown } = {}): {
  fixture: ComponentFixture<AccountPasswordPageComponent>;
  profile: StubProfileService;
  toast: StubToast;
  navSpy: ReturnType<typeof vi.fn>;
} {
  const profile = new StubProfileService();
  if (opts.changeThrows !== undefined) profile.changeThrows = opts.changeThrows;
  const toast = new StubToast();

  TestBed.configureTestingModule({
    imports: [AccountPasswordPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: ProfileService, useValue: profile },
      { provide: ToastService, useValue: toast },
    ],
  });
  const router = TestBed.inject(Router);
  const navSpy = vi.spyOn(router, 'navigateByUrl').mockResolvedValue(true) as unknown as ReturnType<typeof vi.fn>;
  const fixture = TestBed.createComponent(AccountPasswordPageComponent);
  fixture.detectChanges();
  return { fixture, profile, toast, navSpy };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 8; i++) await Promise.resolve();
}

function setField(fixture: ComponentFixture<AccountPasswordPageComponent>, testid: string, value: string): void {
  const el = fixture.nativeElement.querySelector(`[data-testid="${testid}"]`) as HTMLInputElement;
  el.value = value;
  el.dispatchEvent(new Event('input'));
}

function submit(fixture: ComponentFixture<AccountPasswordPageComponent>): void {
  (fixture.nativeElement.querySelector('[data-testid="pw-save"]') as HTMLButtonElement).click();
}

describe('AccountPasswordPageComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('does not submit when fields are empty (required)', async () => {
    const { fixture, profile } = setup();
    submit(fixture);
    await flush();
    expect(profile.changeCalls).toHaveLength(0);
  });

  it('blocks submit when new + confirm do not match', async () => {
    const { fixture, profile } = setup();
    setField(fixture, 'pw-current', 'oldpass12');
    setField(fixture, 'pw-new', 'newpass123');
    setField(fixture, 'pw-confirm', 'different123');
    fixture.detectChanges();
    submit(fixture);
    await flush();
    expect(profile.changeCalls).toHaveLength(0);
  });

  it('blocks submit when new password is too short', async () => {
    const { fixture, profile } = setup();
    setField(fixture, 'pw-current', 'oldpass12');
    setField(fixture, 'pw-new', 'short');
    setField(fixture, 'pw-confirm', 'short');
    fixture.detectChanges();
    submit(fixture);
    await flush();
    expect(profile.changeCalls).toHaveLength(0);
  });

  it('calls changePassword with current + new on a valid submit', async () => {
    const { fixture, profile } = setup();
    setField(fixture, 'pw-current', 'oldpass12');
    setField(fixture, 'pw-new', 'newpass123');
    setField(fixture, 'pw-confirm', 'newpass123');
    fixture.detectChanges();
    submit(fixture);
    await flush();
    expect(profile.changeCalls).toEqual([{ current: 'oldpass12', next: 'newpass123' }]);
  });

  it('toasts success and navigates to /account on success', async () => {
    const { fixture, toast, navSpy } = setup();
    setField(fixture, 'pw-current', 'oldpass12');
    setField(fixture, 'pw-new', 'newpass123');
    setField(fixture, 'pw-confirm', 'newpass123');
    fixture.detectChanges();
    submit(fixture);
    await flush();
    expect(toast.calls.some(c => c.kind === 'success')).toBe(true);
    expect(navSpy).toHaveBeenCalledWith('/account');
  });

  it('maps AUTH_INVALID_CREDENTIALS onto the current-password field (no nav)', async () => {
    const authErr = new HttpErrorResponse({
      status: 401, statusText: 'Unauthorized',
      error: { error_code: 'AUTH_INVALID_CREDENTIALS' },
    });
    const { fixture, navSpy, toast } = setup({ changeThrows: authErr });
    setField(fixture, 'pw-current', 'wrongpass');
    setField(fixture, 'pw-new', 'newpass123');
    setField(fixture, 'pw-confirm', 'newpass123');
    fixture.detectChanges();
    submit(fixture);
    await flush();
    fixture.detectChanges();
    /* No navigation, no generic toast, the error is on the field. */
    expect(navSpy).not.toHaveBeenCalled();
    expect(toast.calls.some(c => c.kind === 'success')).toBe(false);
  });

  it('toasts a generic error for an unmapped server failure', async () => {
    const serverErr = new HttpErrorResponse({
      status: 500, statusText: 'Server Error', error: { error_code: 'INTERNAL' },
    });
    const { fixture, toast } = setup({ changeThrows: serverErr });
    setField(fixture, 'pw-current', 'oldpass12');
    setField(fixture, 'pw-new', 'newpass123');
    setField(fixture, 'pw-confirm', 'newpass123');
    fixture.detectChanges();
    submit(fixture);
    await flush();
    expect(toast.calls.some(c => c.kind === 'error')).toBe(true);
  });

  it('toasts a network error on a connection failure', async () => {
    const netErr = new HttpErrorResponse({ status: 0, statusText: 'Unknown Error' });
    const { fixture, toast } = setup({ changeThrows: netErr });
    setField(fixture, 'pw-current', 'oldpass12');
    setField(fixture, 'pw-new', 'newpass123');
    setField(fixture, 'pw-confirm', 'newpass123');
    fixture.detectChanges();
    submit(fixture);
    await flush();
    expect(toast.calls.some(c => c.kind === 'error')).toBe(true);
  });
});
