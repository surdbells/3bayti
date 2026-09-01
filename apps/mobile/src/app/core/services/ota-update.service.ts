import { Injectable, Signal, computed, inject, signal } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { CapacitorUpdater } from '@capgo/capacitor-updater';
import { CheckoutActivityService } from './checkout-activity.service';

/**
 * Update lifecycle surfaced to the UI:
 *   idle       , nothing happening
 *   checking   , an on-demand getLatest() is in flight (no bar yet)
 *   downloading, a bundle is streaming; `percent` is 0-100
 *   ready      , a bundle finished downloading and is STAGED for the next
 *                 cold start (nothing is hot-swapped mid-session)
 *   failed     , a check/download failed (self-resets to idle)
 */
export type OtaStatus = 'idle' | 'checking' | 'downloading' | 'ready' | 'failed';

/**
 * OtaUpdateService, a thin, reactive lens over @capgo/capacitor-updater so the
 * app can show a SILENT, non-intrusive progress indicator and proactively check
 * for a freshly-published bundle (e.g. when the customer lands on the home
 * dashboard) without waiting for the next full app resume.
 *
 * Boundaries (deliberately conservative for a live checkout app):
 *   - `autoUpdate: true` (capacitor.config) still owns the automatic
 *     resume-time check/download/apply. This service only OBSERVES its events
 *     (`download` percent, etc.) and adds an on-demand check, it never changes
 *     that mechanism.
 *   - checkNow() only ever STAGES an update via `next()` (applies on the next
 *     cold start). It never calls `set()`/`reload()`, so it can't hot-swap the
 *     bundle mid-session and interrupt a checkout in progress.
 *   - Everything is native-only and fully wrapped: a plugin error degrades to
 *     "no indicator", never to a broken screen.
 */
@Injectable({ providedIn: 'root' })
export class OtaUpdateService {
  private readonly checkout = inject(CheckoutActivityService);

  private readonly _status = signal<OtaStatus>('idle');
  private readonly _percent = signal<number>(0);
  private readonly _etaSeconds = signal<number | null>(null);
  private readonly _summary = signal<string | null>(null);
  private readonly _version = signal<string | null>(null);

  /** Current update lifecycle status (read-only signal). */
  readonly status: Signal<OtaStatus> = this._status.asReadonly();
  /** Download progress 0-100 (read-only signal). */
  readonly percent: Signal<number> = this._percent.asReadonly();
  /** Rough estimated seconds remaining in the current download, null until it
   *  can be estimated. Derived from the percent-over-time rate (the plugin's
   *  download event carries no byte totals). */
  readonly etaSeconds: Signal<number | null> = this._etaSeconds.asReadonly();
  /** True while a bundle is actively streaming, drives the top progress bar. */
  readonly isActive = computed(() => this._status() === 'downloading' || this._status() === 'ready');
  /** "What's new" summary for the staged bundle, from the OTA release notes;
   *  null when the release carried none. */
  readonly summary: Signal<string | null> = this._summary.asReadonly();
  /** Version string of the staged bundle, for the update modal header. */
  readonly version: Signal<string | null> = this._version.asReadonly();

  /** Wall-clock + percent at the start of the current download, for the ETA. */
  private dlStartMs: number | null = null;
  private dlStartPct = 0;

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
        const p = Math.max(0, Math.min(100, Math.round(Number(state?.percent ?? 0))));
        this._percent.set(p);
        this._status.set('downloading');
        this.updateEta(p);
      });
      await CapacitorUpdater.addListener('downloadComplete', () => this.markReady());
      await CapacitorUpdater.addListener('updateAvailable', () => this.markReady());
      await CapacitorUpdater.addListener('updateFailed', () => this.markFailed());
      await CapacitorUpdater.addListener('downloadFailed', () => this.markFailed());
    } catch {
      /* observability only, never block boot */
    }
  }

  /**
   * On-demand check, call when the customer lands on the dashboard so a
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

      // Capture the "what's new" summary from the release notes (the API sends
      // it as `message`/`notes`; the plugin surfaces it on getLatest()). Shown
      // by the update modal once the bundle is ready.
      const l = latest as unknown as Record<string, unknown>;
      const note = [l['message'], l['comment'], l['notes'], l['link']]
        .map((v) => (typeof v === 'string' ? v.trim() : ''))
        .find((v) => v.length > 0);
      this._version.set(version);
      this._summary.set(note ?? null);

      // Download now, this fires 'download' percent events (the indicator) -
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
    this.resetEta();
  }

  private markFailed(): void {
    this._status.set('failed');
    this.resetEta();
    // Reset quietly so a failed check doesn't leave a stuck indicator.
    if (this.resetTimer) {
      clearTimeout(this.resetTimer);
    }
    this.resetTimer = setTimeout(() => this._status.set('idle'), 4000);
  }

  /** Estimate seconds-remaining from the download rate so far (linear). */
  private updateEta(percent: number): void {
    const now = Date.now();
    if (this.dlStartMs === null || percent < this.dlStartPct) {
      // New (or restarted) download, anchor the rate baseline.
      this.dlStartMs = now;
      this.dlStartPct = percent;
      this._etaSeconds.set(null);
      return;
    }
    const elapsed = (now - this.dlStartMs) / 1000;
    const gained = percent - this.dlStartPct;
    if (elapsed < 0.5 || gained <= 0 || percent >= 100) {
      return;
    }
    const rate = gained / elapsed; // percent per second
    this._etaSeconds.set(Math.max(0, Math.round((100 - percent) / rate)));
  }

  private resetEta(): void {
    this.dlStartMs = null;
    this.dlStartPct = 0;
    this._etaSeconds.set(null);
  }

  /**
   * Whether applying a staged update right now is blocked because a checkout /
   * payment flow is in progress. The staged bundle still applies on the next
   * natural cold start, so nothing is lost, just deferred.
   */
  get deferredForCheckout(): boolean {
    return this.checkout.isActive();
  }

  /**
   * Apply a downloaded (staged) bundle immediately by reloading the web view.
   * NEVER reloads while a checkout / payment is in progress (that would tear
   * down in-flight payment state) — it silently defers, and the bundle applies
   * on the next cold start. Native-only, fully wrapped.
   */
  async restartToApply(): Promise<void> {
    if (!Capacitor.isNativePlatform()) {
      return;
    }
    // Hard guard: an OTA reload must never interrupt an active checkout.
    if (this.checkout.isActive()) {
      return;
    }
    try {
      await CapacitorUpdater.reload();
    } catch {
      /* falls back to applying on the next cold start */
    }
  }
}
