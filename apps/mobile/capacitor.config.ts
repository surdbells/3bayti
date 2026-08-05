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
