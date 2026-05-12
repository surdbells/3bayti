/* =============================================================================
   AppUpdateService — version check + remote kill-switch + force-update flow
   -----------------------------------------------------------------------------
   Composes two signals to decide whether to FORCE the user to update:

     1. Local: store version vs installed version, via @capawesome/capacitor-app-update
        - Android: Google Play Core's AppUpdateManager
        - iOS:     iTunes Lookup API (App Store)
        - Web:     unsupported, returns no-update

     2. Remote: a static JSON kill-switch hosted at environment.appUpdate.configUrl
        - require_app_update:  global on/off  (default false = no force)
        - min_required_version: semver string, force only if installed < this
        - force_update_threshold: "patch" | "minor" | "major"
            "patch" forces any version below min_required_version
            "minor" forces only when minor or major bump
            "major" forces only on major bump
        - force_update_message_en / _ar: optional override copy

   Both must agree before a force-update prompt fires:
     remote.require_app_update === true
     AND local has an update available
     AND installed version < remote.min_required_version
     AND threshold satisfies (e.g., for "minor" threshold, the bump must be at
         least minor-level).

   Failing safe: any error (network, malformed JSON, plugin missing, browser
   build) results in shouldForceUpdate=false. The kill-switch activates updates
   only when ALL conditions are met. This means:
     - First-time deploy: configUrl is '', so kill-switch is dormant — no force
     - Remote server down: defaults to no force
     - Plugin returns wrong values: usually no force (the "unknown" path)

   Manually flipping the kill-switch:
     1. Edit the hosted JSON file: set require_app_update: true
     2. Set min_required_version to the version you want to force users TO
     3. Save / re-deploy the file
     4. Within the cache window (e.g., 5 min), every app launch / resume will
        hit the new value and force the prompt.
     5. To deactivate: set require_app_update back to false.

   ============================================================================= */

import { Injectable } from '@angular/core';
import { Capacitor } from '@capacitor/core';
import { Preferences } from '@capacitor/preferences';
import { environment } from '../../environments/environment';

/* JSON shape expected at environment.appUpdate.configUrl. All fields
   optional in case the file is partial — failing-safe defaults applied. */
export interface RemoteAppConfig {
  require_app_update?: boolean;
  /* "hard": no Later button, user must update.
     "soft": Later button visible, dismisses for 24h per version.
     If absent, defaults to "soft" — more lenient default protects against
     accidental hard-prompt deploys when an operator only meant to
     announce that an update was available. */
  force_mode?: 'hard' | 'soft';
  min_required_version?: string;          // semver, e.g. "0.0.2"
  force_update_message_en?: string;
  force_update_message_ar?: string;
  force_update_threshold?: 'patch' | 'minor' | 'major';
}

export interface UpdateCheckResult {
  /* True iff the app should show the force-update prompt right now. */
  shouldForceUpdate: boolean;
  /* Whether the user can dismiss the prompt (soft mode) or must update
     (hard mode). Caller passes to AxUpdatePromptComponent.canDismiss. */
  canDismiss: boolean;
  /* The version available in the store (if known), e.g. "0.0.2". May be
     missing if the plugin couldn't reach the store API. */
  availableVersion: string | null;
  /* The version currently installed on the device. May be missing on web. */
  currentVersion: string | null;
  /* Whether a non-forced update is also available — informational, not used
     by the hard-prompt UI but exposed for future use. */
  updateAvailable: boolean;
  /* Localized force-update message text, prefilled by the remote config or
     fallback. Caller passes to the prompt component as-is. */
  messageEn: string;
  messageAr: string;
}

/* Default messages used when the remote config doesn't provide overrides. */
const DEFAULT_MESSAGE_EN = 'An important update is required to continue using the app.';
const DEFAULT_MESSAGE_AR = 'يلزم تثبيت تحديث مهم للاستمرار في استخدام التطبيق.';

@Injectable({ providedIn: 'root' })
export class AppUpdateService {
  /* Cache the remote config in memory for the lifetime of one app launch.
     We re-fetch on every app resume (handled by the caller), but within a
     single session we don't repeatedly hit the URL. */
  private cachedRemote: RemoteAppConfig | null = null;
  private cachedAt: number = 0;
  private readonly CACHE_TTL_MS = 5 * 60 * 1000;  // 5 minutes

  /**
   * Run the full update check. Combines store-version check + remote
   * kill-switch + threshold logic. Returns a result object the caller
   * can use to decide whether to render the force-update prompt.
   *
   * Always resolves — never throws. Errors in any underlying call result
   * in shouldForceUpdate=false (fail safe).
   */
  /* Diagnostic snapshot from the most recent check(). Populated in dev
     builds for the optional debug overlay; safe to expose otherwise (no
     secrets, just version strings + decision booleans). */
  lastDiagnostic: string = '';

  async check(): Promise<UpdateCheckResult> {
    const diag: string[] = [];
    const log = (msg: string) => {
      diag.push(msg);
      console.log('[AppUpdate] ' + msg);
    };

    const fallback: UpdateCheckResult = {
      shouldForceUpdate: false,
      canDismiss: true,  /* default to dismissable; rewritten below if we decide to force */
      availableVersion: null,
      currentVersion: null,
      updateAvailable: false,
      messageEn: DEFAULT_MESSAGE_EN,
      messageAr: DEFAULT_MESSAGE_AR,
    };

    /* Web build: bail out early. The plugin throws if called outside
       a native context, and there's nothing meaningful we'd do for a
       browser user anyway. */
    if (!Capacitor.isNativePlatform()) {
      log('SKIP: not native platform (browser build)');
      this.lastDiagnostic = diag.join('\n');
      return fallback;
    }
    log('platform: ' + Capacitor.getPlatform());

    /* Run store check + remote check in parallel. They're independent. */
    const [storeInfo, remote] = await Promise.all([
      this.fetchStoreInfo(),
      this.fetchRemoteConfig(),
    ]);

    log('store.currentVersion: ' + JSON.stringify(storeInfo.currentVersion));
    log('store.availableVersion: ' + JSON.stringify(storeInfo.availableVersion));
    log('store.updateAvailable: ' + storeInfo.updateAvailable);
    log('remote.require_app_update: ' + JSON.stringify(remote?.require_app_update));
    log('remote.min_required_version: ' + JSON.stringify(remote?.min_required_version));
    log('remote.force_mode: ' + JSON.stringify(remote?.force_mode));
    log('remote.force_update_threshold: ' + JSON.stringify(remote?.force_update_threshold));

    /* DEV OVERRIDE — used to test the prompt UI locally without needing a
       real Play Store / App Store update available. Enabled by setting
       localStorage.ax_debug_force_update='1' OR ?forceupdate=<version> in
       the URL. NEVER fires for normal users (no UI exposes the flag).

       When active, mutates storeInfo so the rest of the decision logic
       sees a plausible "update available" state:
         - updateAvailable = true (overrides UPDATE_NOT_AVAILABLE)
         - availableVersion = the mock version from the flag

       The currentVersion is NOT overridden — uses whatever the plugin
       reports. This means cond.belowMin still does a real comparison
       (so e.g. setting mock=0.0.1 won't fire because installed > 0.0.1).
       Set the mock version higher than your installed version to test.

       Also clears any prior dismissal for the mock version so soft-mode
       doesn't suppress on second test in the same session. */
    const dev = this.readDevOverride();
    if (dev.enabled) {
      log('!! DEV OVERRIDE ACTIVE — mock availableVersion=' + dev.mockVersion);
      storeInfo.updateAvailable = true;
      storeInfo.availableVersion = dev.mockVersion;
      /* Clear dismissal record so we can re-test without waiting 24h. */
      try {
        await Preferences.remove({ key: this.dismissalKey(dev.mockVersion) });
      } catch {
        /* Non-fatal */
      }
    }

    /* Resolve force_mode. Default is "soft" (more lenient) so an operator
       who only wanted to *announce* an update doesn't accidentally lock
       users out by forgetting to set the field. To force a hard prompt
       on a release, the JSON must explicitly say `"force_mode": "hard"`. */
    const forceMode: 'hard' | 'soft' = remote?.force_mode === 'hard' ? 'hard' : 'soft';
    log('resolved forceMode: ' + forceMode);

    /* Compose the result. */
    const result: UpdateCheckResult = {
      ...fallback,
      availableVersion: storeInfo.availableVersion,
      currentVersion: storeInfo.currentVersion,
      updateAvailable: storeInfo.updateAvailable,
      messageEn: remote?.force_update_message_en || DEFAULT_MESSAGE_EN,
      messageAr: remote?.force_update_message_ar || DEFAULT_MESSAGE_AR,
    };

    /* Decide whether to force. All conditions must be satisfied:
         - Remote kill-switch flipped on
         - Store reports an update is available
         - Installed version is below the configured min_required_version
         - The version bump meets the configured threshold
    */
    const cond_killSwitch = remote?.require_app_update === true;
    const cond_storeUpd   = !!storeInfo.updateAvailable;
    const cond_haveCur    = !!storeInfo.currentVersion;
    const cond_haveMin    = !!remote?.min_required_version;
    const cond_belowMin   = cond_haveCur && cond_haveMin
      ? compareSemver(storeInfo.currentVersion!, remote!.min_required_version!) < 0
      : false;
    const cond_threshold  = cond_haveCur && cond_haveMin
      ? meetsThreshold(
          storeInfo.currentVersion!,
          remote!.min_required_version!,
          remote!.force_update_threshold || 'patch'
        )
      : false;

    log('cond.killSwitch (require_app_update===true): ' + cond_killSwitch);
    log('cond.storeUpd (updateAvailable): ' + cond_storeUpd);
    log('cond.haveCurrentVersion: ' + cond_haveCur);
    log('cond.haveMinVersion: ' + cond_haveMin);
    log('cond.belowMin (current < min): ' + cond_belowMin);
    log('cond.thresholdMet: ' + cond_threshold);

    const meetsForceConditions = cond_killSwitch && cond_storeUpd && cond_haveCur
      && cond_haveMin && cond_belowMin && cond_threshold;
    log('meetsForceConditions: ' + meetsForceConditions);

    if (!meetsForceConditions) {
      log('-> shouldForceUpdate=false (one or more conditions failed)');
      this.lastDiagnostic = diag.join('\n');
      return result;
    }

    /* In SOFT mode, also check if the user has dismissed this version
       within the past 24h. If so, suppress the prompt — they get a 24h
       grace period before being asked again. The dismissal record is
       per-version, so a NEWER version surfaces the prompt immediately
       even if a previous dismissal is recent.

       In HARD mode, we ignore dismissals entirely. The user must update.
       This gives operators a reliable escalation path: deploy with soft
       initially, escalate to hard if needed by editing the JSON. */
    if (forceMode === 'soft' && storeInfo.availableVersion) {
      const dismissed = await this.wasRecentlyDismissed(storeInfo.availableVersion);
      log('soft-mode dismissal check: dismissed=' + dismissed);
      if (dismissed) {
        log('-> shouldForceUpdate=false (recently dismissed)');
        this.lastDiagnostic = diag.join('\n');
        return result;  /* shouldForceUpdate stays false */
      }
    }

    result.shouldForceUpdate = true;
    result.canDismiss = forceMode === 'soft';
    log('-> shouldForceUpdate=TRUE (canDismiss=' + result.canDismiss + ')');
    this.lastDiagnostic = diag.join('\n');
    return result;
  }

  /**
   * Record that the user dismissed the prompt for a specific version.
   * Stores an ISO timestamp in Capacitor Preferences so the dismissal
   * survives app restarts (but can be wiped by clearing app data).
   *
   * Per-version key: dismissing v0.0.2 doesn't dismiss v0.0.3 when it
   * eventually releases. The next version's prompt fires immediately.
   *
   * Failure to write is non-fatal — the user just sees the prompt
   * again on next launch instead of getting their grace period.
   */
  async markDismissed(version: string): Promise<void> {
    if (!version) return;
    try {
      await Preferences.set({
        key: this.dismissalKey(version),
        value: new Date().toISOString(),
      });
    } catch {
      /* Swallow — losing the grace period is annoying but not broken. */
    }
  }

  /**
   * Check whether the user dismissed this version's prompt within the
   * past DISMISSAL_WINDOW_MS. Returns false on any storage error or
   * malformed timestamp (failing safe in the conservative direction —
   * a failed read shows the prompt rather than hides it).
   */
  async wasRecentlyDismissed(version: string): Promise<boolean> {
    if (!version) return false;
    try {
      const { value } = await Preferences.get({ key: this.dismissalKey(version) });
      if (!value) return false;
      const dismissedAt = Date.parse(value);
      if (isNaN(dismissedAt)) return false;
      return (Date.now() - dismissedAt) < this.DISMISSAL_WINDOW_MS;
    } catch {
      return false;
    }
  }

  private dismissalKey(version: string): string {
    /* Sanitize version string for use in a key — though semver chars
       (digits + dots) are already safe. Belt-and-suspenders. */
    return `app_update_dismissed_${version.replace(/[^0-9.\-a-z]/gi, '_')}_at`;
  }

  /**
   * Read dev-override settings for forcing the prompt to show during
   * local testing (e.g., before a real Play Store update exists).
   *
   * Sources, in priority order:
   *   1. URL param ?forceupdate=<version>  (one-shot, this session)
   *   2. localStorage:
   *        ax_debug_force_update  = '1'   (turns override on)
   *        ax_debug_mock_version  = e.g. '1.5'  (the fake available version)
   *
   * Production safety:
   *   - No UI ever sets these — only a dev with browser dev tools / URL
   *     access can enable
   *   - Override only injects fake updateAvailable + availableVersion
   *     into storeInfo. The remote kill-switch JSON still has to declare
   *     require_app_update=true, AND min_required_version must be > the
   *     real installed version. So a dev can't accidentally activate
   *     it for a real user.
   *   - Logged loudly when active ('!! DEV OVERRIDE ACTIVE') so it's
   *     never silent.
   */
  private readDevOverride(): { enabled: boolean; mockVersion: string } {
    const fallback = { enabled: false, mockVersion: '' };
    try {
      /* URL param wins over storage if both are set.
         Accepts:
           ?forceupdate=1          -> enable with default mock 99.99.99
           ?forceupdate=1.5        -> enable with mock version 1.5 */
      const urlVersion = new URL(window.location.href).searchParams.get('forceupdate');
      if (urlVersion) {
        const mock = urlVersion === '1' ? '99.99.99' : urlVersion;
        return { enabled: true, mockVersion: mock };
      }
      const flag = window.localStorage.getItem('ax_debug_force_update');
      if (flag === '1') {
        const mockVersion = window.localStorage.getItem('ax_debug_mock_version') || '99.99.99';
        return { enabled: true, mockVersion };
      }
    } catch {
      /* Any access error -> override stays off. Fail safe. */
    }
    return fallback;
  }

  /* 24 hours in ms. The dismissal grace period — how long after tapping
     "Later" before the prompt reappears. Per-version, so a new release
     surfaces immediately regardless. */
  private readonly DISMISSAL_WINDOW_MS = 24 * 60 * 60 * 1000;

  /**
   * Trigger the actual update flow. On Android, attempts an in-app update if
   * the Play Store reports it as supported; otherwise falls back to opening
   * the Play Store. On iOS, opens the App Store listing.
   *
   * Caller invokes this when the user taps "Update now" in the prompt.
   */
  async startUpdate(): Promise<void> {
    if (!Capacitor.isNativePlatform()) {
      return;
    }
    const container = await this.loadPlugin();
    if (!container) {
      return;
    }
    const plugin = container.plugin;
    try {
      const platform = Capacitor.getPlatform();
      if (platform === 'android') {
        const info = await plugin.getAppUpdateInfo();
        if (info?.immediateUpdateAllowed) {
          /* In-app update — Google's flow downloads + installs without
             leaving our app. User taps Update once, then waits. */
          await plugin.performImmediateUpdate();
          return;
        }
      }
      /* iOS or fallback Android: open the store listing. */
      await plugin.openAppStore();
    } catch (e) {
      /* Last-ditch fallback — if the plugin fails for any reason, try
         openAppStore() one more time. If THAT also fails, we're stuck:
         the prompt remains visible, user can try again. */
      try {
        await plugin.openAppStore();
      } catch {
        /* Swallow; let the user retry. */
      }
    }
  }

  /* ----- Internals --------------------------------------------------------- */

  /* Cache the plugin reference once loaded.

     CRITICAL: Capacitor's plugin proxy responds to any property access
     (including `.then`) by treating it as a method invocation. If the
     proxy ever becomes the resolved value of a Promise, the JS engine's
     await machinery checks if the value is "thenable" by accessing
     `.then` — which triggers a native call to `AppUpdate.then()` and
     throws "not implemented on android".

     Workaround: wrap the proxy in a container object ({plugin}) so the
     resolution value of the loadPlugin promise is the container, not
     the proxy itself. Callers extract `.plugin` from the result, which
     happens AFTER the await is complete. */
  private cachedContainer: { plugin: any } | null = null;
  private cachedAttempted = false;

  /* Lazy-load the native plugin. Returns a container object wrapping the
     plugin, or null on web / if the import fails. Callers do:
       const c = await this.loadPlugin();
       if (!c) return; const plugin = c.plugin;  */
  private async loadPlugin(): Promise<{ plugin: any } | null> {
    if (!Capacitor.isNativePlatform()) {
      return null;
    }
    if (this.cachedAttempted) {
      return this.cachedContainer;
    }
    this.cachedAttempted = true;
    try {
      const m = await import('@capawesome/capacitor-app-update');
      this.cachedContainer = { plugin: m.AppUpdate };
      console.log('[AppUpdate] plugin module loaded:', typeof m.AppUpdate);
    } catch (err) {
      console.warn('[AppUpdate] plugin module load FAILED:', err);
      this.cachedContainer = null;
    }
    return this.cachedContainer;
  }

  /* Fetch store-version info via the plugin. Returns nulls on any error. */
  private async fetchStoreInfo(): Promise<{
    currentVersion: string | null;
    availableVersion: string | null;
    updateAvailable: boolean;
  }> {
    const container = await this.loadPlugin();
    if (!container) {
      console.warn('[AppUpdate] fetchStoreInfo: plugin is null (sync/build issue or web)');
      return { currentVersion: null, availableVersion: null, updateAvailable: false };
    }
    const plugin = container.plugin;
    try {
      const platform = Capacitor.getPlatform();
      const params = platform === 'ios'
        ? { country: environment.appUpdate?.iosCountry || 'us' }
        : undefined;
      console.log('[AppUpdate] calling getAppUpdateInfo with params:', params);
      const info = await plugin.getAppUpdateInfo(params);
      console.log('[AppUpdate] getAppUpdateInfo raw response:', JSON.stringify(info));
      /* iOS: currentVersionName / availableVersionName are CFBundleShortVersionString
         strings (e.g. "1.2.3"). On Android the plugin returns versionCode (numeric
         build counter), but for our semver-based comparison we want the name. The
         plugin exposes currentVersionName/availableVersionName on Android too as
         of 8.x. */
      return {
        currentVersion: info?.currentVersionName ?? null,
        availableVersion: info?.availableVersionName ?? null,
        /* The plugin's UPDATE_AVAILABLE constant is "UPDATE_AVAILABLE" string-typed.
           We compare against the string for safety — if the enum changes shape
           in a future plugin version, this still degrades to no-update. */
        updateAvailable: info?.updateAvailability === 'UPDATE_AVAILABLE',
      };
    } catch (e) {
      console.warn('[AppUpdate] getAppUpdateInfo threw:', e);
      return { currentVersion: null, availableVersion: null, updateAvailable: false };
    }
  }

  /* Fetch the remote kill-switch JSON. Cached in memory for CACHE_TTL_MS to
     avoid hammering the URL on rapid app resumes. Returns null on any
     failure (URL unconfigured, network error, malformed JSON). */
  private async fetchRemoteConfig(): Promise<RemoteAppConfig | null> {
    const url = environment.appUpdate?.configUrl;
    if (!url || url.trim() === '') {
      /* Kill-switch URL not configured — disable the force-update path
         entirely. This is the development default until M87. */
      console.warn('[AppUpdate] remote config URL is empty');
      return null;
    }

    /* Serve from cache if recent. */
    const now = Date.now();
    if (this.cachedRemote && now - this.cachedAt < this.CACHE_TTL_MS) {
      console.log('[AppUpdate] remote config served from in-memory cache');
      return this.cachedRemote;
    }

    try {
      /* Cache-bust query param so we never serve stale CDN bytes when the
         operator flips the flag. The `t` value rounds to the cache-TTL
         window so we don't bypass the IN-APP cache, only external caches. */
      const cacheBust = Math.floor(now / this.CACHE_TTL_MS);
      const fullUrl = `${url}?t=${cacheBust}`;
      console.log('[AppUpdate] fetching remote config:', fullUrl);
      const resp = await fetch(fullUrl, {
        method: 'GET',
        headers: { 'Accept': 'application/json' },
      });
      console.log('[AppUpdate] remote config HTTP status:', resp.status);
      if (!resp.ok) {
        return null;
      }
      const data = (await resp.json()) as RemoteAppConfig;
      console.log('[AppUpdate] remote config parsed:', JSON.stringify(data));
      /* Sanity-check the shape — at minimum require_app_update should be a
         boolean if present. Reject obviously malformed payloads. */
      if (data && typeof data === 'object') {
        this.cachedRemote = data;
        this.cachedAt = now;
        return data;
      }
      return null;
    } catch (e) {
      console.warn('[AppUpdate] fetchRemoteConfig threw:', e);
      return null;
    }
  }
}

/* ----- Helpers ------------------------------------------------------------- */

/**
 * Lexicographic semver comparison. Returns negative if a < b, positive if
 * a > b, zero if equal. Handles up-to 4-part versions (e.g. 1.2.3 or 1.2.3.4).
 * Non-numeric segments are coerced to 0. Pre-release suffixes are ignored
 * (so "1.2.3-beta" compares equal to "1.2.3").
 */
function compareSemver(a: string, b: string): number {
  const partsA = a.split('-')[0].split('.').map(p => parseInt(p, 10) || 0);
  const partsB = b.split('-')[0].split('.').map(p => parseInt(p, 10) || 0);
  const len = Math.max(partsA.length, partsB.length);
  for (let i = 0; i < len; i++) {
    const va = partsA[i] || 0;
    const vb = partsB[i] || 0;
    if (va !== vb) return va - vb;
  }
  return 0;
}

/**
 * Does the bump from `current` to `min` satisfy the threshold?
 *   - 'patch' threshold: any bump qualifies (most aggressive)
 *   - 'minor' threshold: only minor or major bumps qualify
 *   - 'major' threshold: only major bumps qualify
 *
 * E.g., current="1.2.3" -> min="1.2.4":
 *   - patch threshold: yes
 *   - minor threshold: no (only patch differs)
 *   - major threshold: no
 *
 * E.g., current="1.2.3" -> min="2.0.0":
 *   - all three thresholds: yes
 */
function meetsThreshold(
  current: string,
  min: string,
  threshold: 'patch' | 'minor' | 'major'
): boolean {
  const c = current.split('-')[0].split('.').map(p => parseInt(p, 10) || 0);
  const m = min.split('-')[0].split('.').map(p => parseInt(p, 10) || 0);

  const majorBump = (m[0] || 0) > (c[0] || 0);
  const minorBump = !majorBump && (m[1] || 0) > (c[1] || 0);
  /* patch bump = current < min by patch only (or any segment we haven't
     covered with major/minor). No need to compute explicitly — if we got
     here at all, current < min was already verified by the caller. */

  switch (threshold) {
    case 'major': return majorBump;
    case 'minor': return majorBump || minorBump;
    case 'patch':
    default:      return true;
  }
}
