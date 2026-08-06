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
    // Self-hosted OTA web-bundle updates (@capgo/capacitor-updater), pointed at
    // OUR endpoint instead of Capgo Cloud (POST /v3/ota/updates → ota_bundles).
    //   - autoUpdate=true: check on every resume, download in the background,
    //     apply on the NEXT cold start.
    //   - directUpdate=false: never swap the running bundle mid-session.
    //   - resetWhenUpdate=true: when the NATIVE shell is updated via the store,
    //     drop stale OTA bundles so the fresh builtin wins.
    //   - appReadyTimeout=10000: if notifyAppReady() (app.component.ts) doesn't
    //     fire within 10s of a new bundle booting, AUTO-ROLLBACK to the last
    //     good bundle.
    //   - updateUrl: our v3 API (same host as every other call). statsUrl /
    //     channelUrl are omitted until those endpoints exist.
    //   - publicKey: `npx @capgo/cli key create` writes it here to enable
    //     end-to-end encryption / code signing — see OTA-SIGNING.md. Do this
    //     BEFORE opening production OTA. Until then bundles are unsigned
    //     (SHA256-verified only).
    // NOTE: OTA ships JS/CSS only. Anything needing new native code must go via
    // the store; the server's min_native_version gate enforces this.
    CapacitorUpdater: {
      autoUpdate: true,
      directUpdate: false,
      resetWhenUpdate: true,
      appReadyTimeout: 10000,
      updateUrl: "https://api-v3.3bayti.ae/v3/ota/updates",
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
