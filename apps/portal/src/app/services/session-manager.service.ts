import { Injectable, NgZone, signal, inject } from '@angular/core';
import { Router } from '@angular/router';

import { PortalCrudAdapter } from './portal-crud-adapter';
import { PortalAuthService } from './portal-auth.service';
import { isSessionChanged, isSessionOver } from './session-errors';
import { GlobalComponent } from '../global-component';

/**
 * SessionManager, keeps a signed-in portal session alive while the user is
 * working, and ends it gracefully (with a warning) only when they've gone
 * idle. Two jobs:
 *
 *  1. Proactive silent refresh, before the short-lived access token expires,
 *     swap it for a fresh one using the refresh token, in the background. So a
 *     navigation never lands on an expired token → the user is never bounced
 *     to login mid-session (the bug this fixes). Only the API's definitive
 *     "session is over" answer ends the session; a transient failure (offline,
 *     timeout, 403, 429, 5xx) keeps it and retries with exponential backoff.
 *
 *  2. Idle guard, track real activity; after IDLE_LIMIT of no interaction,
 *     show a countdown modal ("Stay signed in?"). "Stay" refreshes + resets;
 *     if the countdown runs out, sign out cleanly. Nothing ends a session
 *     abruptly while the user is active.
 *
 * Started once from the admin/vendor shell (idempotent). Activity listeners
 * run outside Angular's zone so mousemove doesn't thrash change detection;
 * the periodic tick runs in-zone so the modal signals repaint.
 */
@Injectable({ providedIn: 'root' })
export class SessionManager {
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly auth = inject(PortalAuthService);
  private readonly router = inject(Router);
  private readonly zone = inject(NgZone);

  /** Idle time before the warning modal appears. */
  private readonly IDLE_LIMIT_MS = 25 * 60 * 1000;
  /** Countdown shown in the warning modal before auto sign-out. */
  private readonly WARN_SECONDS = 60;
  /** Refresh the access token this long before it expires. */
  private readonly REFRESH_LEAD_MS = 2 * 60 * 1000;
  /** How often the housekeeping tick runs. */
  private readonly TICK_MS = 15 * 1000;
  /** Wait after the first transient silent-refresh failure; doubles per failure. */
  private readonly RETRY_BASE_MS = 30 * 1000;
  /** Backoff ceiling, so a recovered API is picked up within this long. */
  private readonly RETRY_MAX_MS = 5 * 60 * 1000;

  /** Modal visibility + live countdown (read by the idle modal component). */
  readonly warningVisible = signal(false);
  readonly countdown = signal(this.WARN_SECONDS);

  private started = false;
  private lastActivity = Date.now();
  private tickTimer: ReturnType<typeof setInterval> | null = null;
  private countdownTimer: ReturnType<typeof setInterval> | null = null;
  private refreshing = false;
  /** Consecutive transient silent-refresh failures, and when to try again. */
  private refreshFailures = 0;
  private retryAt = 0;
  private readonly boundActivity = () => this.onActivity();

  /** Begin monitoring. Safe to call from every shell on every load. */
  start(): void {
    if (this.started || typeof window === 'undefined') return;
    if (!this.hasSession()) return;
    this.started = true;
    this.lastActivity = Date.now();

    this.zone.runOutsideAngular(() => {
      for (const ev of ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click']) {
        window.addEventListener(ev, this.boundActivity, { passive: true });
      }
      this.tickTimer = setInterval(() => this.zone.run(() => this.tick()), this.TICK_MS);
    });
  }

  stop(): void {
    this.started = false;
    for (const ev of ['mousemove', 'mousedown', 'keydown', 'scroll', 'touchstart', 'click']) {
      window.removeEventListener(ev, this.boundActivity);
    }
    if (this.tickTimer) { clearInterval(this.tickTimer); this.tickTimer = null; }
    this.clearCountdown();
    this.warningVisible.set(false);
    this.resetBackoff();
  }

  /** "Stay signed in", dismiss the warning, reset idle, refresh now (even
   *  while backing off, it's an explicit user action). */
  stayActive(): void {
    this.lastActivity = Date.now();
    this.warningVisible.set(false);
    this.clearCountdown();
    this.silentRefresh(true);
  }

  /**
   * Sign out cleanly (idle timeout or the modal's "Log out"). The explicit
   * "Log out" also ends this session server-side (fire-and-forget, only this
   * session, never the user's other devices), and mid-impersonation the
   * stashed admin session too; an idle timeout only signs this tab out
   * locally, as before.
   */
  logoutNow(reason: 'idle' | 'user' = 'user'): void {
    this.stop();
    if (reason === 'user') {
      void this.auth.revokeCurrentSession();
      this.auth.discardStashedAdminSession();
    }
    try { sessionStorage.removeItem('SESSION'); } catch { /* noop */ }
    this.router.navigate(['/login'], { queryParams: reason === 'idle' ? { reason: 'idle' } : {} }).catch(() => {});
  }

  // ── Internals ────────────────────────────────────────────────────────
  private onActivity(): void {
    // Ignore activity while the warning modal is up, the user must make an
    // explicit choice there. Otherwise a stray mousemove would silently keep
    // an abandoned session alive.
    if (this.warningVisible()) return;
    this.lastActivity = Date.now();
  }

  private tick(): void {
    if (!this.hasSession()) { this.stop(); return; }
    if (this.warningVisible()) return; // countdown timer owns this phase

    const idleMs = Date.now() - this.lastActivity;
    if (idleMs >= this.IDLE_LIMIT_MS) {
      this.showWarning();
      return;
    }

    // Active, keep the access token fresh so navigation never 401s.
    const expiresInMs = this.accessExpiresInMs();
    if (expiresInMs !== null && expiresInMs <= this.REFRESH_LEAD_MS) {
      this.silentRefresh();
    } else {
      // Token is fresh (a request's own refresh may have recovered it), so a
      // later failure starts backing off from the base delay again.
      this.resetBackoff();
    }
  }

  private showWarning(): void {
    this.countdown.set(this.WARN_SECONDS);
    this.warningVisible.set(true);
    this.clearCountdown();
    this.zone.runOutsideAngular(() => {
      this.countdownTimer = setInterval(() => this.zone.run(() => {
        const next = this.countdown() - 1;
        if (next <= 0) {
          this.logoutNow('idle');
          return;
        }
        this.countdown.set(next);
      }), 1000);
    });
  }

  private clearCountdown(): void {
    if (this.countdownTimer) { clearInterval(this.countdownTimer); this.countdownTimer = null; }
  }

  private silentRefresh(force = false): void {
    if (this.refreshing) return;
    if (!force && Date.now() < this.retryAt) return; // backing off
    this.refreshing = true;
    this.adapter.refreshSession().subscribe({
      next: () => {
        this.refreshing = false;
        this.resetBackoff();
      },
      error: (err: unknown) => {
        this.refreshing = false;
        if (isSessionChanged(err)) {
          // The tab switched sessions mid-refresh (impersonation exit,
          // re-login); that says nothing about the current session, so no
          // backoff, the next tick checks it afresh.
          return;
        }
        if (isSessionOver(err)) {
          // The API ended this session for good: stop ticking, sign out once.
          this.stop();
          this.adapter.terminate();
          return;
        }
        // Transient (offline, timeout, 403, 429, 5xx): keep the session and
        // back off exponentially instead of retrying every tick; the first
        // retry that succeeds recovers silently.
        this.refreshFailures++;
        const delay = Math.min(this.RETRY_MAX_MS, this.RETRY_BASE_MS * 2 ** (this.refreshFailures - 1));
        this.retryAt = Date.now() + delay;
      },
    });
  }

  private resetBackoff(): void {
    this.refreshFailures = 0;
    this.retryAt = 0;
  }

  private hasSession(): boolean {
    try { return !!sessionStorage.getItem('SESSION'); } catch { return false; }
  }

  /** Ms until the access token expires, or null if unknown. */
  private accessExpiresInMs(): number | null {
    try {
      const raw = sessionStorage.getItem('SESSION');
      if (!raw) return null;
      const s = GlobalComponent.decodeBase64<any>(raw);
      const exp = s?.access_token_expires_at;
      if (!exp) return null;
      const t = new Date(exp).getTime();
      return isNaN(t) ? null : t - Date.now();
    } catch {
      return null;
    }
  }
}
