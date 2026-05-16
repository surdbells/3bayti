import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright e2e config for apps/mobile.
 *
 * M3.2.0-B — Capacitor mobile app testing via the Ionic web preview.
 *
 * Locked decisions (per m3.2.0-implementation-plan.md §3):
 *   Q3 = A: Mobile e2e in CI via Ionic serve (web preview) with
 *           continue-on-error initially. Native plugin testing
 *           remains in M3.1.7 device-test pattern.
 *   Q4 = A: Chromium only.
 *
 * What this tests:
 *   - The Angular/Ionic web bundle that runs INSIDE the Capacitor
 *     WebView. Catches ~80% of regressions: UI state, routing,
 *     forms, validation, list rendering, navigation.
 *
 * What this does NOT test:
 *   - Native plugins (Camera, Preferences, Push Notifications,
 *     Haptics, etc.) — those need real device runs per the
 *     M3.1.7 device-test-checklist pattern.
 *   - Platform-specific UI (iOS bottom-sheet vs Android material).
 *   - Hardware interactions (gestures, biometric, background apps).
 *
 * Local development:
 *   pnpm --filter @3bayti/mobile test:e2e            # headless
 *   pnpm --filter @3bayti/mobile test:e2e:ui         # interactive
 *   pnpm --filter @3bayti/mobile test:e2e:headed     # see the browser
 *
 * webServer:
 *   - Auto-starts `pnpm start` (ng serve at :4200)
 *   - 180s timeout (Ionic cold start is slower than vanilla Angular)
 *
 * Default viewport: 390×844 (iPhone 14 Pro) — most-common mobile size
 * in our analytics. Tests can override per case.
 */

const BASE_URL = process.env['PLAYWRIGHT_BASE_URL'] ?? 'http://localhost:4200';

export default defineConfig({
  testDir: './e2e',

  fullyParallel: true,
  forbidOnly: !!process.env['CI'],
  retries: process.env['CI'] ? 2 : 0,
  workers: process.env['CI'] ? 1 : undefined,

  reporter: [
    ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ['github'],
    ['list'],
  ],

  use: {
    baseURL: BASE_URL,
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',
    actionTimeout: 10_000,
    navigationTimeout: 30_000,
  },

  projects: [
    {
      name: 'chromium-mobile',
      use: {
        ...devices['iPhone 14 Pro'],
        // Override the device's WebKit engine with Chromium per
        // locked Q4. iPhone 14 Pro device profile gives us the
        // viewport (390×844) + touch + mobile UA, but we run in
        // Chromium for engine consistency with apps/web.
        defaultBrowserType: 'chromium',
      },
    },
  ],

  webServer: {
    command: 'pnpm start',
    url: BASE_URL,
    reuseExistingServer: !process.env['CI'],
    timeout: 180_000, // Ionic cold start can be slow
    stdout: 'pipe',
    stderr: 'pipe',
  },

  outputDir: 'test-results',
});
