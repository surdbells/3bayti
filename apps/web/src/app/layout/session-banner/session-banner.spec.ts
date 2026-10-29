import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { signal } from '@angular/core';
import { provideHttpClient } from '@angular/common/http';
import { provideHttpClientTesting } from '@angular/common/http/testing';
import { AuthService, type SessionStatus } from '../../core/auth/auth.service';
import { provideI18n } from '../../core/i18n';
import {
  SessionBannerComponent,
  SESSION_BANNER_DELAY_MS,
  SESSION_BANNER_RETRY_WAIT_MS,
} from './session-banner';

/**
 * Tests for the session reconnect banner (W-4): while the session stays
 * 'unknown' after a transient failure (protected routes are held, never
 * bounced to /login) the shell must show a visible "can't reach 3bayti"
 * notice with a retry, not a silent blank page.
 */

class StubAuthService {
  readonly status = signal<SessionStatus>('unknown');
  readonly sessionStatus = this.status.asReadonly();
  readonly waits: Array<number | undefined> = [];
  private resolveWait: ((s: SessionStatus) => void) | null = null;

  whenSessionKnown(timeoutMs?: number): Promise<SessionStatus> {
    this.waits.push(timeoutMs);
    return new Promise(resolve => {
      this.resolveWait = resolve;
    });
  }

  settle(s: SessionStatus): void {
    this.status.set(s);
    this.resolveWait?.(s);
  }
}

function setup(status: SessionStatus): {
  fixture: ComponentFixture<SessionBannerComponent>;
  auth: StubAuthService;
  banner: () => HTMLElement | null;
} {
  const auth = new StubAuthService();
  auth.status.set(status);
  TestBed.configureTestingModule({
    imports: [SessionBannerComponent],
    providers: [
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: AuthService, useValue: auth },
    ],
  });
  const fixture = TestBed.createComponent(SessionBannerComponent);
  fixture.detectChanges();
  const banner = (): HTMLElement | null =>
    (fixture.nativeElement as HTMLElement).querySelector('[data-testid="session-banner"]');
  return { fixture, auth, banner };
}

describe('SessionBannerComponent', () => {
  afterEach(() => {
    TestBed.resetTestingModule();
    vi.useRealTimers();
  });

  it("shows nothing while the session is known (signed in or out)", () => {
    vi.useFakeTimers();
    for (const status of ['authenticated', 'anonymous'] as const) {
      const { fixture, banner } = setup(status);
      vi.advanceTimersByTime(SESSION_BANNER_DELAY_MS * 10);
      fixture.detectChanges();
      expect(banner()).toBeNull();
      TestBed.resetTestingModule();
    }
  });

  it("appears only after the session has stayed 'unknown' for a moment (no flash on a single blip)", () => {
    vi.useFakeTimers();
    const { fixture, banner } = setup('unknown');

    vi.advanceTimersByTime(SESSION_BANNER_DELAY_MS - 1);
    fixture.detectChanges();
    expect(banner()).toBeNull();

    vi.advanceTimersByTime(1);
    fixture.detectChanges();
    expect(banner()).not.toBeNull();
    expect(banner()!.getAttribute('role')).toBe('status');
  });

  it('never appears if the session resolves before the delay', () => {
    vi.useFakeTimers();
    const { fixture, auth, banner } = setup('unknown');
    vi.advanceTimersByTime(SESSION_BANNER_DELAY_MS / 2);
    auth.status.set('authenticated');
    fixture.detectChanges();
    vi.advanceTimersByTime(SESSION_BANNER_DELAY_MS * 2);
    fixture.detectChanges();
    expect(banner()).toBeNull();
  });

  it('"Try again" kicks an immediate retry and the banner goes away once the session resolves', async () => {
    vi.useFakeTimers();
    const { fixture, auth, banner } = setup('unknown');
    vi.advanceTimersByTime(SESSION_BANNER_DELAY_MS);
    fixture.detectChanges();

    const retry = (fixture.nativeElement as HTMLElement).querySelector(
      '[data-testid="session-banner-retry"]',
    ) as HTMLButtonElement;
    retry.click();
    fixture.detectChanges();
    expect(auth.waits).toEqual([SESSION_BANNER_RETRY_WAIT_MS]);
    expect(retry.disabled).toBe(true);

    auth.settle('authenticated');
    await Promise.resolve();
    await Promise.resolve();
    fixture.detectChanges();
    expect(banner()).toBeNull();
  });
});
