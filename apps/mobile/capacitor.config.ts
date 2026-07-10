import type { CapacitorConfig } from '@capacitor/cli';
const config: CapacitorConfig = {
  appId: 'com.threebayti.app',
  appName: '3bayti',
  webDir: 'www',
  plugins: {
    PushNotifications: {
      presentationOptions: ["badge", "sound", "alert"],
    },
    // Native Google + Apple sign-in via Firebase Auth
    // (@capacitor-firebase/authentication). skipNativeAuth:false means the
    // plugin performs the full native Firebase sign-in and we read the
    // resulting Firebase ID token via getIdToken() — that token is what our
    // API verifies at POST /v3/auth/social. providers lists the only two
    // social providers we enable on mobile.
    FirebaseAuthentication: {
      skipNativeAuth: false,
      providers: ["google.com", "apple.com"],
    },
    // OTA web-bundle updates via Capgo Cloud (@capgo/capacitor-updater).
    // Division of responsibility (see app.component.ts + AppUpdateService):
    //   - CapacitorUpdater (this) = WEB/JS bundle OTA. Ships new Angular
    //     builds into the SAME installed native shell, instantly, without
    //     going through the app store. CANNOT change native code/plugins.
    //   - AppUpdateService / AxUpdatePromptComponent = mandatory NATIVE
    //     updates (new shell version) via the store gate. Unchanged.
    // autoUpdate=true: on every resume the plugin checks Capgo Cloud,
    //   downloads any new bundle in the background, and applies it on the
    //   NEXT cold start. directUpdate=false: never swap the running bundle
    //   mid-session (avoids a jarring in-place reload). resetWhenUpdate=true:
    //   when the NATIVE shell is updated via the store, drop stale OTA
    //   bundles so the fresh builtin wins. appReadyTimeout=10000: if
    //   notifyAppReady() (called in app.component.ts) does not fire within
    //   10s of a new bundle booting, AUTO-ROLLBACK to the last good bundle.
    // Capgo CLOUD: leave updateUrl/statsUrl/channelUrl unset so the plugin
    //   uses Capgo's hosted endpoints. Do NOT set custom URLs.
    CapacitorUpdater: {
      autoUpdate: true,
      directUpdate: false,
      resetWhenUpdate: true,
      appReadyTimeout: 10000,
    },
    // M32: native splash. Logo centered on canvas-color background.
    // Programmatically dismissed from app.component.ts after Angular
    // bootstrap completes (see SplashScreen.hide() call there), so
    // launchAutoHide is false and launchShowDuration is 0.
    // Asset generation: run `npx @capacitor/assets generate
    // --splashBackgroundColor "#faf8f5" --logoSplashScale 0.2` after
    // copying the logo to project-root `assets/logo.png`. Then run
    // `npx cap sync` to push config + assets to native projects.
    SplashScreen: {
      launchShowDuration: 0,
      launchAutoHide: false,
      backgroundColor: "#faf8f5",
      androidScaleType: "CENTER",
      showSpinner: false,
      splashImmersive: false,
      splashFullScreen: false,
    },
  },
  ios: {
  scrollEnabled: false,
  webContentsDebuggingEnabled: true,
}
};

export default config;
