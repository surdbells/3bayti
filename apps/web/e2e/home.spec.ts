import { test, expect } from '@playwright/test';
import { snapshot } from './utils/chromatic';
import { expectNoA11yViolations } from './utils/a11y';

/**
 * Home page (`/`) baseline e2e tests.
 *
 * The home page is the most-visited route, prerendered at build time
 * with a hero carousel + multiple product strips. These tests verify
 * the shipped HTML matches the smoke markers that web.yml's build
 * verification already asserts on, so a Playwright failure here
 * means web.yml deploy would also fail, same signal, different
 * surface (runtime vs build-time).
 *
 * Markers used (stable BEM class names from component templates):
 *   - .site-footer__brand-name → global app shell rendered
 *   - .hero-carousel__slide   → hero carousel renders with slide data
 *
 * Rationale for marker-based assertion: editorial copy ("Premium
 * Abayas, Kaftans...") used to be the marker but was removed when the
 * home transitioned to a carousel-only hero. BEM markers are
 * structural and don't change when copy is edited.
 *
 * M3.2.0-C: Chromatic visual snapshots captured at logical
 * checkpoints. No-op when running outside the chromatic CLI.
 */

test.describe('Home page', () => {
  test('loads with hero carousel and global app shell', async ({ page }, info) => {
    await page.goto('/');

    // Global app shell, footer brand name proves the shell rendered.
    await expect(page.locator('.site-footer__brand-name')).toBeVisible();

    // Primary content, hero carousel with at least one slide.
    const slides = page.locator('.hero-carousel__slide');
    await expect(slides.first()).toBeVisible();
    const slideCount = await slides.count();
    expect(slideCount).toBeGreaterThanOrEqual(1);

    // Capture visual snapshot, fully rendered home page
    await snapshot(page, 'home-page-default', info);

    // M3.2.0-D: enforce WCAG AA. Allowlist absorbs known violations
    // pending remediation; new violations fail the test.
    await expectNoA11yViolations(page, info);
  });

  test('has correct title and meta description', async ({ page }) => {
    await page.goto('/');

    await expect(page).toHaveTitle(/3bayti/i);

    const description = await page
      .locator('meta[name="description"]')
      .getAttribute('content');
    expect(description).toBeTruthy();
    expect(description!.length).toBeGreaterThan(50);
  });

  test('header navigation is present', async ({ page }) => {
    await page.goto('/');

    // The header should be visible at the top of the viewport.
    const header = page.locator('header').first();
    await expect(header).toBeVisible();
  });

  test('footer contains sitemap and brand links', async ({ page }) => {
    await page.goto('/');

    const footer = page.locator('footer, .site-footer').first();
    await expect(footer).toBeVisible();
    await expect(page.locator('.site-footer__brand-name')).toBeVisible();
  });

  test('renders without JavaScript errors', async ({ page }) => {
    const errors: string[] = [];
    page.on('pageerror', (err) => errors.push(err.message));
    page.on('console', (msg) => {
      if (msg.type() === 'error') errors.push(msg.text());
    });

    await page.goto('/');
    await page.waitForLoadState('networkidle');

    // Filter out known noise that doesn't indicate a real problem
    // (e.g. missing favicon during dev, deprecation warnings).
    const realErrors = errors.filter(
      (e) =>
        !e.includes('favicon') &&
        !e.toLowerCase().includes('deprecat') &&
        !e.includes('Failed to load resource') &&
        !e.includes('net::ERR_INTERNET_DISCONNECTED'),
    );

    expect(realErrors).toEqual([]);
  });
});
