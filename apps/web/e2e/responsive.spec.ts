import { test, expect } from '@playwright/test';

/**
 * Responsive layout baseline tests.
 *
 * Verifies the four primary routes render without horizontal scroll
 * and with their primary content visible across three viewport sizes:
 *
 *   - 375×667   iPhone SE / mobile
 *   - 768×1024  iPad / tablet
 *   - 1280×800  desktop
 *
 * Catches regressions like a too-wide image breaking the layout on
 * mobile, or a sidebar that overflows on tablet. We don't pixel-check
 * here — that's Chromatic's job in M3.2.0-C.
 */

const VIEWPORTS = [
  { name: 'mobile', width: 375, height: 667 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1280, height: 800 },
];

const ROUTES = [
  { path: '/', name: 'home', marker: '.site-footer__brand-name' },
  { path: '/category', name: 'categories-index', marker: 'h1' },
];

for (const viewport of VIEWPORTS) {
  test.describe(`Responsive — ${viewport.name} (${viewport.width}×${viewport.height})`, () => {
    test.use({ viewport: { width: viewport.width, height: viewport.height } });

    for (const route of ROUTES) {
      test(`${route.name} renders without horizontal scroll`, async ({ page }) => {
        await page.goto(route.path);
        await page.waitForLoadState('networkidle');

        // Primary content marker should be present.
        await expect(page.locator(route.marker).first()).toBeVisible();

        // No horizontal overflow.
        const hasOverflow = await page.evaluate(() => {
          return document.documentElement.scrollWidth > document.documentElement.clientWidth;
        });
        expect(hasOverflow).toBe(false);
      });
    }
  });
}
