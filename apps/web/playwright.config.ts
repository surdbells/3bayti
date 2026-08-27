import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright e2e config for apps/web.
 *
 * M3.2.0-A, first phase of M3.2 quality infrastructure.
 *
 * Locked decisions (per m3.2.0-implementation-plan.md §3):
 *   Q4 = A: Chromium only. WebKit/Firefox deferred to M4 unless
 *   we see browser-specific bugs.
 *
 * Local development:
 *   pnpm --filter @3bayti/web test:e2e          # headless
 *   pnpm --filter @3bayti/web test:e2e:ui       # UI mode
 *   pnpm --filter @3bayti/web test:e2e:headed   # see the browser
 *   pnpm --filter @3bayti/web test:e2e:report   # open last HTML report
 *
 * CI behaviour:
 *   - forbidOnly: prevents accidental `.only` from being merged
 *   - retries: 2 (CI is noisy; local default 0)
 *   - workers: 2 (CI runner is bounded; local default = unbounded)
 *   - reporters: HTML for artifact + github for inline annotations
 *
 * webServer:
 *   - Auto-starts `pnpm dev` (ng serve at :4200) if not already running
 *   - In CI, `reuseExistingServer: false` ensures a clean start
 *   - 120s timeout because Angular cold-start can be slow on first run
 */

const BASE_URL = process.env['PLAYWRIGHT_BASE_URL'] ?? 'http://localhost:4200';

export default defineConfig({
  testDir: './e2e',

  // Run files in parallel; tests within a file run sequentially by default.
  fullyParallel: true,

  // Prevent .only from being merged into main.
  forbidOnly: !!process.env['CI'],

  // CI is flaky; local should fail fast.
  retries: process.env['CI'] ? 2 : 0,

  // Bounded parallelism in CI to avoid OOM on small runners.
  workers: process.env['CI'] ? 2 : undefined,

  // HTML for the report artifact, github for inline PR annotations.
  reporter: [
    ['html', { open: 'never', outputFolder: 'playwright-report' }],
    ['github'],
    ['list'],
  ],

  use: {
    baseURL: BASE_URL,

    // trace + screenshot on first retry, diagnoses flakes without
    // bloating green-run artifacts.
    trace: 'on-first-retry',
    screenshot: 'only-on-failure',
    video: 'retain-on-failure',

    // Generous action timeout; SSR + first-paint can be slow under
    // dev-server cold start.
    actionTimeout: 10_000,
    navigationTimeout: 30_000,
  },

  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],

  webServer: {
    command: 'pnpm dev',
    url: BASE_URL,
    reuseExistingServer: !process.env['CI'],
    timeout: 120_000,
    stdout: 'pipe',
    stderr: 'pipe',
  },

  // Where Playwright writes test outputs (cleaned per turbo config).
  outputDir: 'test-results',
});
