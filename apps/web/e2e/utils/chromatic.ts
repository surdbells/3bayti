/**
 * Chromatic visual regression helper for apps/web e2e tests.
 *
 * M3.2.0-C, wraps @chromatic-com/playwright's takeSnapshot with a
 * single import surface for our spec files, plus a no-op fallback
 * when CHROMATIC_PROJECT_TOKEN is absent (e.g. local dev).
 *
 * Locked decision (per m3.2.0-implementation-plan.md §3):
 *   Q1 = C: Code lands with continue-on-error in CI until operator
 *           adds CHROMATIC_PROJECT_TOKEN to GitHub secrets. Local
 *           dev should not require a token at all.
 *
 * Usage in a spec file:
 *   import { test, expect } from '@playwright/test';
 *   import { snapshot } from './utils/chromatic';
 *
 *   test('home page renders', async ({ page }, testInfo) => {
 *     await page.goto('/');
 *     await snapshot(page, 'home-page', testInfo);
 *   });
 *
 * What happens:
 *   - During regular `playwright test` run: no-op (no Chromatic upload)
 *   - During `chromatic` CLI run (CI): captures the page as an archive
 *     and uploads to Chromatic for visual diff against baseline
 *
 * Chromatic determines whether to capture based on the
 * CHROMATIC_PROJECT_TOKEN env var. When absent (local dev without
 * token), takeSnapshot is a no-op and our wrapper just returns.
 */

import type { Page, TestInfo } from '@playwright/test';
import { takeSnapshot as chromaticTakeSnapshot } from '@chromatic-com/playwright';

/**
 * Capture a visual snapshot at this point in the test.
 *
 * @param page    The Playwright page to snapshot
 * @param name    Snapshot name (must be unique within the test)
 * @param info    The TestInfo from the test fixture
 */
export async function snapshot(
  page: Page,
  name: string,
  info: TestInfo,
): Promise<void> {
  // @chromatic-com/playwright's takeSnapshot is a no-op when run
  // outside the chromatic CLI environment, so this is safe to call
  // unconditionally during local Playwright runs.
  try {
    await chromaticTakeSnapshot(page, name, info);
  } catch (err) {
    // Defensive: never let a snapshot failure break the test.
    // Visual regression is informational; functional tests are
    // the source of truth.
    // eslint-disable-next-line no-console
    console.warn(`[chromatic] snapshot '${name}' failed:`, err);
  }
}
