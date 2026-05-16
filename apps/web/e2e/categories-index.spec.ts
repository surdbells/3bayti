import { test, expect } from '@playwright/test';

/**
 * Categories index (`/category`) baseline e2e tests.
 *
 * Server-rendered listing of all top-level categories with
 * TransferState hydration. Build-time verification asserts the
 * page renders with "Shop by Category" heading.
 */

test.describe('Categories index', () => {
  test('loads and displays category grid', async ({ page }) => {
    await page.goto('/category');

    // The heading is asserted server-side in web.yml smoke tests.
    await expect(page.getByRole('heading', { name: /Shop by Category/i })).toBeVisible();
  });

  test('has correct title', async ({ page }) => {
    await page.goto('/category');
    await expect(page).toHaveTitle(/Shop by Category.*3bayti/i);
  });

  test('renders at least one category', async ({ page }) => {
    await page.goto('/category');
    await page.waitForLoadState('networkidle');

    // Each category link routes to /category/:slug. We don't pin a
    // specific selector class because shared-ui category-card markup
    // may evolve; instead we assert on the link pattern.
    const categoryLinks = page.locator('a[href^="/category/"]').filter({
      hasNot: page.locator('[href="/category"]'), // exclude the index link itself
    });
    const linkCount = await categoryLinks.count();
    expect(linkCount).toBeGreaterThanOrEqual(1);
  });

  test('has no horizontal scroll', async ({ page }) => {
    await page.goto('/category');
    await page.waitForLoadState('networkidle');

    const hasOverflow = await page.evaluate(() => {
      return document.documentElement.scrollWidth > document.documentElement.clientWidth;
    });
    expect(hasOverflow).toBe(false);
  });
});
