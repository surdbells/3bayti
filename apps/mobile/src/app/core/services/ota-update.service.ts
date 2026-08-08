import { Injectable, Signal, computed, signal } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { CapacitorUpdater } from '@capgo/capacitor-updater';

/**
 * Update lifecycle surfaced to the UI:
 *   idle        — nothing happening
 *   checking    — an on-demand getLatest() is in flight (no bar yet)
 *   downloading — a bundle is streaming; `percent` is 0-100
 *   ready       — a bundle finished downloading and is STAGED for the next
 *                 cold start (nothing is hot-swapped mid-session)
 *   failed      — a check/download failed (self-resets to idle)
 */
export type OtaStatus = 'idle' | 'checking' | 'downloading' | 'ready' | 'failed';

/**
 * OtaUpdateService — a thin, reactive lens over @capgo/capacitor-updater so the
 * app can show a SILENT, non-intrusive progress indicator and proactively check
 * for a freshly-published bundle (e.g. when the customer lands on the home
 * dashboard) without waiting for the next full app resume.
 *
 * Boundaries (deliberately conservative for a live checkout app):
 *   - `autoUpdate: true` (capacitor.config) still owns the automatic
 *     resume-time check/download/apply. This service only OBSERVES its events
 *     (`download` percent, etc.) and adds an on-demand check — it never changes
 *     that mechanism.
 *   - checkNow() only ever STAGES an update via `next()` (applies on the next
 *     cold start). It never calls `set()`/`reload()`, so it can't hot-swap the
 *     bundle mid-session and interrupt a checkout in progress.
 *   - Everything is native-only and fully wrapped: a plugin error degrades to
 *     "no indicator", never to a broken screen.
 */
@Injectable({ providedIn: 'root' })
export class OtaUpdateService {
  private readonly _status = signal<OtaStatus>('idle');
  private readonly _percent = signal<number>(0);

  /** Current update lifecycle status (read-only signal). */
  readonly status: Signal<OtaStatus> = this._status.asReadonly();
  /** Download progress 0-100 (read-only signal). */
  readonly percent: Signal<number> = this._percent.asReadonly();
  /** True while a bundle is actively streaming — drives the top progress bar. */
  readonly isActive = computed(() => this._status() === 'downloading' || this._status() === 'ready');

  private listenersReady = false;
  private inFlight = false;               // guards concurrent checkNow()
  private handledVersion: string | null = null; // dedup: don't re-stage the same version
  private lastCheckAt = 0;                // throttle on-demand checks
  private resetTimer: ReturnType<typeof setTimeout> | null = null;

  /** Don't hammer the endpoint if the customer bounces around the dashboard. */
  private static readonly MIN_CHECK_INTERVAL_MS = 5 * 60 * 1000;

  /**
   * Register the reactive OTA event listeners. Native-only, idempotent, fully
   * wrapped. Call once from app bootstrap (after notifyAppReady()). These
   * listeners fire for BOTH the automatic (resume-time) download and any
   * on-demand checkNow(), so the indicator shows progress regardless of trigger.
   */
  async init(): Promise<void> {
    if (!Capacitor.isNativePlatform() || this.listenersReady) {
      return;
    }
    this.listenersReady = true;
    try {
      await CapacitorUpdater.addListener('download', (state) => {
        const p = Math.round(Number(state?.percent ?? 0));
        this._percent.set(Math.max(0, Math.min(100, p)));
        this._status.set('downloading');
      });
      await CapacitorUpdater.addListener('downloadComplete', () => this.markReady());
      await CapacitorUpdater.addListener('updateAvailable', () => this.markReady());
      await CapacitorUpdater.addListener('updateFailed', () => this.markFailed());
      await CapacitorUpdater.addListener('downloadFailed', () => this.markFailed());
    } catch {
      /* observability only — never block boot */
    }
  }

  /**
   * On-demand check — call when the customer lands on the dashboard so a
   * freshly-published bundle is caught without waiting for the next resume.
   * Throttled (5 min), de-duped by version, single-flight, native-only, fully
   * wrapped. Stages the bundle for the next cold start; never hot-swaps.
   */
  async checkNow(): Promise<void> {
    if (!Capacitor.isNativePlatform() || this.inFlight || this._status() === 'downloading') {
      return;
    }
    const now = Date.now();
    if (now - this.lastCheckAt < OtaUpdateService.MIN_CHECK_INTERVAL_MS) {
      return;
    }
    this.lastCheckAt = now;
    this.inFlight = true;
    const wasReady = this._status() === 'ready';
    try {
      this._status.set('checking');
      const latest = await CapacitorUpdater.getLatest();
      const version = (latest?.version ?? '').trim();
      const url = (latest?.url ?? '').trim();

      // No newer bundle / server error / already staged this version → stop.
      if (!version || !url || latest?.error || version === this.handledVersion) {
        this._status.set(wasReady ? 'ready' : 'idle');
        return;
      }
      // Already the running bundle → nothing to do.
      const current = await CapacitorUpdater.current().catch(() => null);
      if (current?.bundle?.version === version) {
        this._status.set(wasReady ? 'ready' : 'idle');
        return;
      }

      // Download now — this fires 'download' percent events (the indicator) —
      // then STAGE it for the next cold start. No reload()/set() mid-session.
      this._percent.set(0);
      this._status.set('downloading');
      const bundle = await CapacitorUpdater.download({
        version,
        url,
        sessionKey: latest?.sessionKey,
        checksum: latest?.checksum,
      });
      this.handledVersion = version;
      await CapacitorUpdater.next({ id: bundle.id });
      this.markReady();
    } catch {
      this.markFailed();
    } finally {
      this.inFlight = false;
    }
  }

  private markReady(): void {
    if (this.resetTimer) {
      clearTimeout(this.resetTimer);
      this.resetTimer = null;
    }
    this._percent.set(100);
    this._status.set('ready');
  }

  private markFailed(): void {
    this._status.set('failed');
    // Reset quietly so a failed check doesn't leave a stuck indicator.
    if (this.resetTimer) {
      clearTimeout(this.resetTimer);
    }
    this.resetTimer = setTimeout(() => this._status.set('idle'), 4000);
  }
}
