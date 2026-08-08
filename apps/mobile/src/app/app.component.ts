import { Component } from '@angular/core';
import {IonApp, IonRouterOutlet, Platform} from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { Capacitor } from '@capacitor/core';
import { App as CapacitorApp } from '@capacitor/app';
import { CapacitorUpdater } from '@capgo/capacitor-updater';
import { ScreenOrientation } from '@capacitor/screen-orientation';
import { SplashScreen } from '@capacitor/splash-screen';
import {ToastController} from "@ionic/angular";
import {fadeTransition} from "../fade.transition";
import {AxNotificationHostComponent} from "./shared/ax-mobile/notification";
import {AxUpdatePromptComponent} from "./shared/ax-mobile/update-prompt";
import {AxOtaIndicatorComponent} from "./shared/ax-mobile/ota-indicator";
import {AppUpdateService} from "./service/app-update.service";
import {I18nService} from "./i18n.service";
import {OtaUpdateService} from "./core/services/ota-update.service";
import {PushManager, resolvePushDeepLink} from "./core/services/push-manager.service";

@Component({
  selector: 'app-root',
  templateUrl: 'app.component.html',
  imports: [IonApp, IonRouterOutlet, AxNotificationHostComponent, AxUpdatePromptComponent, AxOtaIndicatorComponent],
  standalone: true,
  styles: [`
    /* Debug overlay (visible only when ?debug=update or localStorage flag).
       Pinned to the bottom-left so it doesn't obscure the force-update
       modal (centered) or the notification host (top-center). Tap-through
       enabled so it can never block the app. */
    .update-debug-overlay {
      position: fixed;
      left: 8px;
      bottom: 8px;
      max-width: 90vw;
      max-height: 50vh;
      z-index: 10000;  /* above the update prompt itself */
      background: rgba(20, 16, 14, 0.92);
      color: #f5e9d4;
      border: 1px solid rgba(245, 233, 212, 0.2);
      border-radius: 8px;
      padding: 8px 10px;
      font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
      font-size: 11px;
      line-height: 1.35;
      pointer-events: none;
      overflow: auto;
    }
    .update-debug-overlay__header {
      font-weight: 700;
      margin-bottom: 4px;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #ffd789;
    }
    .update-debug-overlay__log {
      margin: 0;
      white-space: pre-wrap;
      word-break: break-all;
    }
  `]
})
export class AppComponent {
  fadeTransition = fadeTransition;

  /* Force-update prompt state. Driven by AppUpdateService.check() — the
     service runs on app launch and on every resume from background, so
     a kill-switch flip will activate the prompt within at most 5 minutes
     (the in-memory remote-config cache window) for already-running apps,
     or immediately for cold launches. */
  showForceUpdate = false;
  forceUpdateTitle = '';
  forceUpdateMessage = '';
  forceUpdateVersionLabel = '';
  forceUpdateButton = '';
  /* Soft-prompt mode: shows a Later button, dismisses for 24h per
     version. Driven by remote config force_mode. Default false (hard). */
  forceUpdateCanDismiss = false;
  forceUpdateLaterLabel = '';
  /* Tracked so onDismissed() knows which version's dismissal to record. */
  private currentPromptVersion: string | null = null;

  /* Last update-check result, exposed for the optional debug overlay.
     Populated by runUpdateCheck(); used only by the diagnostic UI in
     app.component.html when ?debug=update is in the URL. */
  lastUpdateDiag: string = '(check has not run yet)';
  showUpdateDebug = false;

  constructor(
    private platform: Platform,
    private toastCtrl: ToastController,
    private appUpdate: AppUpdateService,
    private i18n: I18nService,
    private pushManager: PushManager,
    private router: Router,
    private ota: OtaUpdateService,
  ) {
      /* OTA (self-hosted Capgo) — confirm the freshly-booted web bundle is
         healthy. MUST run on EVERY native cold start as early as possible: if
         notifyAppReady() doesn't fire within appReadyTimeout (10s, see
         capacitor.config.ts) the plugin AUTO-ROLLS BACK to the previous bundle.
         Called synchronously here — not behind platform.ready() — so nothing
         can delay it past the timeout. Native-guarded + try/caught so a plugin
         error can never block boot. (OTA = web/JS bundles only; native shell
         updates remain handled by AppUpdateService — the store gate.) */
      this.initOtaUpdater();

      this.initializeApp();

      /* Enable the update-debug overlay when ?debug=update appears in the
         URL OR localStorage flag 'ax_debug_update' is set. Devs can toggle
         from a browser dev console:
            localStorage.setItem('ax_debug_update', '1')   // turn on
            localStorage.removeItem('ax_debug_update')      // turn off
         The URL param takes precedence and persists for the session. */
      try {
        const url = new URL(window.location.href);
        const param = url.searchParams.get('debug');
        const stored = window.localStorage.getItem('ax_debug_update');
        this.showUpdateDebug = (param === 'update') || (stored === '1');
      } catch {
        /* SSR / sandboxed contexts may not have localStorage. Stay quiet. */
      }

      this.platform.ready().then(async () => {
          // Dismiss the native splash now that Angular has bootstrapped
          // and platform.ready() has resolved. The 200ms fade hands off
          // to the canvas-color #faf8f5 body bg (set inline in
          // src/index.html — see M29) so there is no visible flash.
          try {
            await SplashScreen.hide({ fadeOutDuration: 200 });
          } catch (e) {
            // Plugin not available (e.g. web build) — safe to ignore.
            console.warn('SplashScreen.hide failed:', e);
          }

          /* Run an update check on cold launch. Failing-safe — if the
             service fails for any reason, no prompt shows. */
          this.runUpdateCheck();

          /* Re-check whenever the app resumes from background. Important
             for users who keep the app suspended for hours/days while a
             new release ships. The plugin's internal cache + our 5-minute
             in-memory cache prevent excessive API hits on rapid switches. */
          CapacitorApp.addListener('appStateChange', (state) => {
            if (state.isActive) {
              this.runUpdateCheck();
            }
          });
      });
      /* Native-only: attach push listeners once. Permission is NOT
         requested here — it's requested after sign-in (Q-Z5.1=A), see
         PushManager.onSignedIn called from the login flow. */
      if (Capacitor.isNativePlatform()) {
        /* Z.5-C — route a tapped notification to its target. All order
           pushes carry order_id and deep-link to /orders/:id. The
           Router lives here (the app shell), so the routing concern is
           injected into PushManager via a tap handler rather than the
           service depending on the Router itself. */
        this.pushManager.setTapHandler((data) => {
          const path = resolvePushDeepLink(data);
          if (path !== null) {
            void this.router.navigateByUrl(path);
          }
        });
        void this.pushManager.initListeners();
      }
  }

  initializeApp() {
    /* Native-only: ScreenOrientation has no web implementation and
       throws "not available" on web/headless test platforms. Guard on
       the platform so component specs (and any web build) don't crash;
       also fail-safe with a catch in case the plugin is missing. */
    if (Capacitor.isNativePlatform()) {
      ScreenOrientation.lock({ orientation: 'portrait' })
        .then(() => console.log('ScreenOrientation loaded'))
        .catch((e) => console.warn('ScreenOrientation.lock failed:', e));
    }
  }

  /* OTA bootstrap (self-hosted Capgo, @capgo/capacitor-updater → our
     /v3/ota/updates endpoint).
       - notifyAppReady() validates the running bundle so the plugin doesn't
         auto-roll-back a healthy update.
       - The listeners make OTA observable in chrome://inspect / Safari Web
         Inspector.
       - AppUpdateService.check() (runUpdateCheck) still governs mandatory
         NATIVE shell updates via the store gate — the two never conflict: OTA
         swaps the JS bundle inside the SAME native version; the store gate
         forces a new native version when native code/permissions change.
     Native-guarded + fully wrapped so a plugin error can never block boot. */
  private initOtaUpdater(): void {
    if (!Capacitor.isNativePlatform()) {
      return;
    }
    try {
      CapacitorUpdater.notifyAppReady()
        .then(() => console.log('[OTA] notifyAppReady() ok'))
        .catch((e) => console.warn('[OTA] notifyAppReady() failed:', e));

      // Reactive listeners (download percent / complete / failed) live in
      // OtaUpdateService, which drives the silent top progress bar. Safe to
      // await-less; the service is native-guarded + fully wrapped.
      void this.ota.init();
    } catch (e) {
      console.warn('[OTA] updater init failed:', e);
    }
  }


  /* Run AppUpdateService.check() and update local UI state.
     Always async, never throws — the service handles errors internally. */
  private async runUpdateCheck(): Promise<void> {
    try {
      const result = await this.appUpdate.check();
      /* Always update diagnostic snapshot for the optional debug overlay,
         regardless of whether shouldForceUpdate is true or false. */
      this.lastUpdateDiag = this.appUpdate.lastDiagnostic
        + '\n---\nresult.shouldForceUpdate=' + result.shouldForceUpdate
        + '\nresult.canDismiss=' + result.canDismiss
        + '\nresult.currentVersion=' + result.currentVersion
        + '\nresult.availableVersion=' + result.availableVersion
        + '\nresult.updateAvailable=' + result.updateAvailable;

      if (result.shouldForceUpdate) {
        const isArabic = this.i18n.lang === 'ar';
        this.forceUpdateTitle = this.i18n.t('update_available_title');
        this.forceUpdateMessage = isArabic ? result.messageAr : result.messageEn;
        this.forceUpdateButton = this.i18n.t('update_now');
        this.forceUpdateVersionLabel = result.availableVersion
          ? this.i18n.t('update_available_version', { version: result.availableVersion })
          : '';
        this.forceUpdateCanDismiss = result.canDismiss;
        this.forceUpdateLaterLabel = result.canDismiss ? this.i18n.t('update_later') : '';
        this.currentPromptVersion = result.availableVersion;
        this.showForceUpdate = true;
      }
      /* Note: we don't auto-dismiss here when shouldForceUpdate becomes
         false. Once the prompt is up, it stays until the user updates
         (which restarts the app from scratch). If the kill-switch is
         flipped off remotely, the next cold launch won't show it — which
         is the right behavior. */
    } catch {
      /* Failing safe: any unexpected error keeps the prompt hidden. */
    }
  }

  /* Tapped "Update now" in the prompt. Hand off to the service which
     either triggers in-app update flow (Android, when supported) or
     opens the store listing (iOS or Android fallback). */
  async onUpdateClicked(): Promise<void> {
    await this.appUpdate.startUpdate();
  }

  /* Tapped "Later" in the prompt (only emitted in soft-prompt mode).
     Record the dismissal so the user gets a 24h grace window for this
     specific version, then hide the prompt. */
  async onDismissed(): Promise<void> {
    if (this.currentPromptVersion) {
      await this.appUpdate.markDismissed(this.currentPromptVersion);
    }
    this.showForceUpdate = false;
  }
}
