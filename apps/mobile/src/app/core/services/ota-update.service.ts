import { Injectable, InjectionToken, Signal, computed, effect, inject, signal, untracked } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { CapacitorUpdater, type CapacitorUpdaterPlugin, type LatestVersion } from '@capgo/capacitor-updater';
import { CheckoutActivityService } from './checkout-activity.service';

/** The slice of @capgo/capacitor-updater this service uses. */
export type OtaUpdater = Pick<
  CapacitorUpdaterPlugin,
  | 'addListener'
  | 'getLatest'
  | 'current'
  | 'download'
  | 'next'
  | 'reload'
  | 'setMultiDelay'
  | 'cancelDelay'
  | 'getFailedUpdate'
>;

/**
 * The updater plugin; a token so specs can swap in a fake.
 *
 * A plain object of bound plugin calls, NOT the Capacitor plugin itself: that
 * is a Proxy answering EVERY property with a native-call wrapper, so Angular's
 * injector would see a function-valued `ngOnDestroy` on it, register it as a
 * destroy hook and fire a bogus native call on root-injector teardown.
 */
export const OTA_UPDATER = new InjectionToken<OtaUpdater>('OTA_UPDATER', {
  providedIn: 'root',
  factory: (): OtaUpdater => ({
    addListener: CapacitorUpdater.addListener.bind(CapacitorUpdater),
    getLatest: CapacitorUpdater.getLatest.bind(CapacitorUpdater),
    current: CapacitorUpdater.current.bind(CapacitorUpdater),
    download: CapacitorUpdater.download.bind(CapacitorUpdater),
    next: CapacitorUpdater.next.bind(CapacitorUpdater),
    reload: CapacitorUpdater.reload.bind(CapacitorUpdater),
    setMultiDelay: CapacitorUpdater.setMultiDelay.bind(CapacitorUpdater),
    cancelDelay: CapacitorUpdater.cancelDelay.bind(CapacitorUpdater),
    getFailedUpdate: CapacitorUpdater.getFailedUpdate.bind(CapacitorUpdater),
  }),
});

/** Whether we run in the native shell (the plugin is a no-op on the web). */
export const OTA_IS_NATIVE = new InjectionToken<() => boolean>('OTA_IS_NATIVE', {
  providedIn: 'root',
  factory: () => () => Capacitor.isNativePlatform(),
});

/** Minimal persistent key-value store for the OTA skip list. */
export interface OtaStore {
  getItem(key: string): string | null;
  setItem(key: string, value: string): void;
}

/**
 * Where the skip list persists: the web view's localStorage, which survives
 * bundle reloads and rollbacks (same origin). Best effort: any storage error
 * degrades to "nothing remembered", never to a broken screen.
 */
export const OTA_STORE = new InjectionToken<OtaStore>('OTA_STORE', {
  providedIn: 'root',
  factory: (): OtaStore => ({
    getItem: (key) => {
      try {
        return globalThis.localStorage?.getItem(key) ?? null;
      } catch {
        return null;
      }
    },
    setItem: (key, value) => {
      try {
        globalThis.localStorage?.setItem(key, value);
      } catch {
        /* best effort */
      }
    },
  }),
});

/**
 * Update lifecycle surfaced to the UI:
 *   idle       , nothing happening
 *   checking   , an on-demand getLatest() is in flight (no sheet yet)
 *   downloading, a bundle is streaming; `percent` is 0-100
 *   ready      , a bundle finished downloading and is STAGED. The update
 *                 sheet applies it via restartToApply() ("Restart now" or its
 *                 countdown, never during a checkout); otherwise the plugin
 *                 installs it on the next background (held during a checkout)
 *                 or cold start
 *   failed     , a check/download/restart failed (self-resets to idle)
 */
export type OtaStatus = 'idle' | 'checking' | 'downloading' | 'ready' | 'failed';

/** Persisted record of versions this device must not be pushed into again. */
interface OtaSkipList {
  /** Versions that failed to boot here (rolled back by the plugin). */
  boot: string[];
  /** Download/verification failure count per version. */
  download: Record<string, number>;
}

/**
 * OtaUpdateService, a thin, reactive lens over @capgo/capacitor-updater so the
 * app can show the mandatory update sheet (ax-ota-indicator) and proactively
 * check for a freshly-published bundle (e.g. when the customer lands on the
 * home dashboard) without waiting for the next full app resume.
 *
 * Who applies an update, and when (OTA updates are MANDATORY):
 *   - `autoUpdate: true` (capacitor.config) owns the automatic resume-time
 *     check + download, and stages the result. This service OBSERVES its
 *     events (`download` percent, `downloadComplete`/`updateAvailable`) and
 *     adds an on-demand check (checkNow(), which only STAGES via `next()`).
 *   - A staged bundle is applied in exactly two ways:
 *       1. restartToApply(), called by ax-ota-indicator ("Restart now" or its
 *          6-second countdown). It refuses during a checkout/payment.
 *       2. The PLUGIN itself, which installs a staged bundle (and reloads the
 *          web view) whenever the app goes to the background, and on a cold
 *          start. A customer leaving for their bank / SMS app mid-3DS is such
 *          a background, so while CheckoutActivityService is active this
 *          service sets a `kill` delay condition (setMultiDelay), which holds
 *          background installs until the checkout window closes
 *          (cancelDelay) or the app is killed.
 *   - Versions that failed on THIS device are remembered (OTA_STORE) and never
 *     presented or auto-restarted into again: one that failed to boot (the
 *     plugin rolled it back; read via getFailedUpdate()/`updateFailed`), or
 *     one whose download/verification failed repeatedly. Without this, a
 *     bundle that crashes on boot is re-downloaded and auto-restarted into on
 *     every launch, a loop that makes the app unusable.
 *   - Version + "what's new" notes are populated for BOTH paths: checkNow()
 *     reads them from getLatest() (`comment`); for the automatic path the
 *     version comes from the event's bundle and the notes from the bundle's
 *     `comment` (iOS) or one getLatest() lookup.
 *   - Everything is native-only and fully wrapped: a plugin error degrades to
 *     "no sheet", never to a broken screen.
 */
@Injectable({ providedIn: 'root' })
export class OtaUpdateService {
  private readonly checkout = inject(CheckoutActivityService);
  private readonly updater = inject(OTA_UPDATER);
  private readonly isNative = inject(OTA_IS_NATIVE);
  private readonly store = inject(OTA_STORE);

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
  /** True while a bundle is streaming or staged, drives the update sheet. */
  readonly isActive = computed(() => this._status() === 'downloading' || this._status() === 'ready');
  /** "What's new" summary for the staged bundle, from the OTA release notes;
   *  null when the release carried none. */
  readonly summary: Signal<string | null> = this._summary.asReadonly();
  /** Version string of the downloading/staged bundle, for the sheet header. */
  readonly version: Signal<string | null> = this._version.asReadonly();

  /** Wall-clock + percent at the start of the current download, for the ETA. */
  private dlStartMs: number | null = null;
  private dlStartPct = 0;

  private listenersReady = false;
  private inFlight = false;               // guards concurrent checkNow()
  private handledVersion: string | null = null; // dedup: don't re-stage the same version
  private notesLookedUpFor: string | null = null; // dedup: one notes lookup per version
  private reloadFailedFor: string | null = null;  // don't re-present a version whose restart failed
  private delayHeld = false;              // a checkout `kill` delay condition is set
  private lastCheckAt = 0;                // throttle on-demand checks
  private resetTimer: ReturnType<typeof setTimeout> | null = null;

  /** Don't hammer the endpoint if the customer bounces around the dashboard. */
  private static readonly MIN_CHECK_INTERVAL_MS = 5 * 60 * 1000;
  private static readonly SKIP_KEY = 'ota.skip.v1';
  /** Failed downloads of one version before it's skipped (one blip is not enough). */
  private static readonly DOWNLOAD_FAILURES_TO_SKIP = 2;
  /** Cap on remembered versions, so the list can't grow without bound. */
  private static readonly MAX_REMEMBERED = 20;

  constructor() {
    // Hold the plugin's background install for as long as a checkout/payment
    // window is open (see the class doc). Native-only, fully wrapped.
    effect(() => {
      const busy = this.checkout.isActive();
      untracked(() => void this.holdInstallsDuringCheckout(busy));
    });
  }

  /**
   * Register the reactive OTA event listeners and pick up a boot failure the
   * plugin recorded before this (rolled-back) bundle started. Native-only,
   * idempotent, fully wrapped. Call once from app bootstrap (after
   * notifyAppReady()). These listeners fire for BOTH the automatic
   * (resume-time) download and any on-demand checkNow().
   */
  async init(): Promise<void> {
    if (!this.isNative() || this.listenersReady) {
      return;
    }
    this.listenersReady = true;
    try {
      // A bundle that failed to boot was rolled back before this JS started,
      // so its `updateFailed` event went to the dead context; the plugin
      // keeps it for one getFailedUpdate() call.
      const failed = await this.updater.getFailedUpdate().catch(() => null);
      this.recordBootFailure(failed?.bundle?.version);
      // No checkout is open at boot, so no delay should be held. Clears one
      // a previous JS context may have left behind (the plugin drops `kill`
      // conditions on a real relaunch, not on a web-view reload).
      if (!this.checkout.isActive()) {
        await this.updater.cancelDelay().catch(() => undefined);
      }

      await this.updater.addListener('download', (state) => {
        const v = (state?.bundle?.version ?? '').trim();
        if (v && this.isSkipped(v)) {
          return; // a version that already failed here: no blocking sheet for it
        }
        this.adoptVersion(v);
        const p = Math.max(0, Math.min(100, Math.round(Number(state?.percent ?? 0))));
        this._percent.set(p);
        this._status.set('downloading');
        this.updateEta(p);
      });
      await this.updater.addListener('downloadComplete', (e) => this.onBundleReady(e?.bundle));
      await this.updater.addListener('updateAvailable', (e) => this.onBundleReady(e?.bundle));
      await this.updater.addListener('updateFailed', (e) => {
        this.recordBootFailure(e?.bundle?.version);
        this.markFailed();
      });
      await this.updater.addListener('downloadFailed', (e) => {
        this.recordDownloadFailure(e?.version);
        // A failure can't concern a bundle that is already staged; don't let
        // it hide that bundle's sheet.
        if (this._status() !== 'ready') {
          this.markFailed();
        }
      });
    } catch {
      /* observability only, never block boot */
    }
  }

  /**
   * On-demand check, call when the customer lands on the dashboard so a
   * freshly-published bundle is caught without waiting for the next resume.
   * Throttled (5 min), de-duped by version, single-flight, native-only, fully
   * wrapped. Only STAGES the bundle (next()). No-op while a bundle is already
   * downloading or staged, and never fetches a version that failed here.
   */
  async checkNow(): Promise<void> {
    const status = this._status();
    if (!this.isNative() || this.inFlight || status === 'downloading' || status === 'ready') {
      return;
    }
    const now = Date.now();
    if (now - this.lastCheckAt < OtaUpdateService.MIN_CHECK_INTERVAL_MS) {
      return;
    }
    this.lastCheckAt = now;
    this.inFlight = true;
    let downloading = false;
    try {
      this._status.set('checking');
      const latest = await this.updater.getLatest();
      // The automatic path's events took over while we waited: leave it be.
      if (this._status() !== 'checking') {
        return;
      }
      const version = (latest?.version ?? '').trim();
      const url = (latest?.url ?? '').trim();

      // Up to date (kind "up_to_date": no url) / server error / already
      // staged / failed on this device → stop.
      if (!version || !url || latest?.error || version === this.handledVersion || this.isSkipped(version)) {
        this._status.set('idle');
        return;
      }
      // Already the running bundle → nothing to do.
      const current = await this.updater.current().catch(() => null);
      if (this._status() !== 'checking') {
        return;
      }
      if (current?.bundle?.version === version) {
        this._status.set('idle');
        return;
      }

      // Capture the "what's new" summary from the release notes; shown by the
      // update sheet once the bundle is ready.
      this._version.set(version);
      this._summary.set(OtaUpdateService.notesOf(latest));
      this.notesLookedUpFor = version;

      // Download now (fires 'download' percent events for the sheet), then
      // STAGE it. No reload()/set() here.
      this._percent.set(0);
      this._status.set('downloading');
      downloading = true;
      const bundle = await this.updater.download({
        version,
        url,
        sessionKey: latest?.sessionKey,
        checksum: latest?.checksum,
      });
      this.handledVersion = version;
      await this.updater.next({ id: bundle.id });
      this.markReady();
    } catch {
      // Only reset what this check owns, never a download the automatic path
      // started meanwhile.
      if (downloading || this._status() === 'checking') {
        this.markFailed();
      }
    } finally {
      this.inFlight = false;
    }
  }

  /**
   * A bundle finished downloading / was staged (automatic path, or the events
   * checkNow()'s own download also fires). Records its version and notes;
   * a version that already failed to boot here is never presented.
   */
  private onBundleReady(bundle: { version?: string; comment?: string } | undefined): void {
    const v = (bundle?.version ?? '').trim();
    if (v && (this.isBootFailed(v) || v === this.reloadFailedFor)) {
      return;
    }
    if (v) {
      this.clearDownloadFailures(v);
    }
    this.adoptVersion(v);
    const staged = this._version();
    if (staged) {
      this.handledVersion = staged;
    }
    this.markReady();
    if (!staged || this.notesLookedUpFor === staged) {
      return;
    }
    // iOS stores the release notes on the downloaded bundle; otherwise ask once.
    const comment = typeof bundle?.comment === 'string' ? bundle.comment.trim() : '';
    this.notesLookedUpFor = staged;
    if (comment && staged === v) {
      this._summary.set(comment);
    } else {
      void this.loadNotes(staged);
    }
  }

  /**
   * The automatic path's events carry the bundle but (on Android) not the
   * release notes, which only the update endpoint returns. Ask it once; only
   * accept the answer if it still describes the bundle that was staged.
   */
  private async loadNotes(version: string): Promise<void> {
    try {
      const latest = await this.updater.getLatest();
      if ((latest?.version ?? '').trim() === version && this._version() === version) {
        this._summary.set(OtaUpdateService.notesOf(latest));
      }
    } catch {
      /* no notes, the sheet falls back to the generic description */
    }
  }

  /** Track the bundle being downloaded; a different version drops stale notes. */
  private adoptVersion(version: string | undefined): void {
    const v = (version ?? '').trim();
    if (!v || v === 'builtin' || v === this._version()) {
      return;
    }
    this._version.set(v);
    this._summary.set(null);
  }

  /**
   * The API sends the release notes as `comment` (+ `notes`). Never `message`:
   * on an update response the plugin would reject it, and on any other
   * response it is a server status ("No update"), not release notes.
   */
  private static notesOf(latest: LatestVersion | null | undefined): string | null {
    const l = (latest ?? {}) as unknown as Record<string, unknown>;
    const note = [l['comment'], l['notes']]
      .map((v) => (typeof v === 'string' ? v.trim() : ''))
      .find((v) => v.length > 0);
    return note ?? null;
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

  // ── checkout hold ──────────────────────────────────────────────────

  /**
   * While a checkout/payment window is open, a `kill` delay condition stops
   * the plugin from installing a staged bundle on background (which would
   * reload the web view under the payment sheet); cleared when the window
   * closes. Native-only, transitions only, fully wrapped.
   */
  private async holdInstallsDuringCheckout(busy: boolean): Promise<void> {
    if (!this.isNative() || busy === this.delayHeld) {
      return;
    }
    this.delayHeld = busy;
    try {
      if (busy) {
        await this.updater.setMultiDelay({ delayConditions: [{ kind: 'kill' }] });
      } else {
        await this.updater.cancelDelay();
      }
    } catch {
      /* best effort: restartToApply() and the sheet still honour the guard */
    }
  }

  // ── skip list (versions that failed on this device) ────────────────

  private isBootFailed(version: string): boolean {
    return this.readSkip().boot.includes(version);
  }

  private isSkipped(version: string): boolean {
    const skip = this.readSkip();
    return (
      skip.boot.includes(version) ||
      (skip.download[version] ?? 0) >= OtaUpdateService.DOWNLOAD_FAILURES_TO_SKIP
    );
  }

  private recordBootFailure(version: string | undefined): void {
    const v = (version ?? '').trim();
    if (!v || v === 'builtin') {
      return;
    }
    const skip = this.readSkip();
    if (!skip.boot.includes(v)) {
      skip.boot = [...skip.boot, v].slice(-OtaUpdateService.MAX_REMEMBERED);
      this.writeSkip(skip);
    }
  }

  private recordDownloadFailure(version: string | undefined): void {
    const v = (version ?? '').trim();
    if (!v || v === 'builtin') {
      return;
    }
    const skip = this.readSkip();
    skip.download[v] = (skip.download[v] ?? 0) + 1;
    const keys = Object.keys(skip.download);
    for (const stale of keys.slice(0, Math.max(0, keys.length - OtaUpdateService.MAX_REMEMBERED))) {
      delete skip.download[stale];
    }
    this.writeSkip(skip);
  }

  private clearDownloadFailures(version: string): void {
    const skip = this.readSkip();
    if (version in skip.download) {
      delete skip.download[version];
      this.writeSkip(skip);
    }
  }

  private readSkip(): OtaSkipList {
    try {
      const parsed = JSON.parse(this.store.getItem(OtaUpdateService.SKIP_KEY) ?? 'null') as Partial<OtaSkipList> | null;
      const boot = Array.isArray(parsed?.boot) ? parsed.boot.filter((v): v is string => typeof v === 'string') : [];
      const download: Record<string, number> = {};
      if (parsed?.download && typeof parsed.download === 'object') {
        for (const [k, n] of Object.entries(parsed.download)) {
          if (typeof n === 'number' && Number.isFinite(n)) {
            download[k] = n;
          }
        }
      }
      return { boot, download };
    } catch {
      return { boot: [], download: {} };
    }
  }

  private writeSkip(skip: OtaSkipList): void {
    try {
      this.store.setItem(OtaUpdateService.SKIP_KEY, JSON.stringify(skip));
    } catch {
      /* best effort */
    }
  }

  // ── applying ───────────────────────────────────────────────────────

  /**
   * Whether applying a staged update right now is blocked because a checkout /
   * payment flow is in progress. The staged bundle still applies once the
   * checkout window closes (next background) or on the next cold start, so
   * nothing is lost, just deferred.
   */
  get deferredForCheckout(): boolean {
    return this.checkout.isActive();
  }

  /**
   * Apply the staged bundle NOW by reloading the web view (called by
   * ax-ota-indicator). NEVER reloads while a checkout / payment is in progress
   * (that would tear down in-flight payment state): it silently defers.
   *
   * Resolves true when the reload went through. If reload() rejects, the sheet
   * is taken down (status `failed` → idle) instead of leaving the customer
   * behind a blocking sheet whose only button fails the same way; the bundle
   * stays staged for the next background/cold start and isn't presented again
   * this session. Native-only, fully wrapped.
   */
  async restartToApply(): Promise<boolean> {
    if (!this.isNative()) {
      return false;
    }
    // Hard guard: an OTA reload must never interrupt an active checkout.
    if (this.checkout.isActive()) {
      return false;
    }
    try {
      await this.updater.reload();
      return true;
    } catch {
      this.reloadFailedFor = this._version();
      this.markFailed();
      return false;
    }
  }
}
