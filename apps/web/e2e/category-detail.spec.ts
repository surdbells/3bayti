import { test, expect } from '@playwright/test';
import { snapshot } from './utils/chromatic';
import { expectNoA11yViolations } from './utils/a11y';

/**
 * Category detail (`/category/:slug`) baseline e2e tests.
 *
 * Each of 8 categories is prerendered at build time. The exact slug
 * shape depends on the backend (v2 used `abayas-1`, v3 uses `abayas`).
 * Per web.yml's "Verify SSR output" step we dynamically pick a slug
 * from the sitemap rather than hardcoding.
 *
 * M3.2.0-C: Chromatic snapshot captures the populated product grid.
 */

async function pickCategorySlug(baseURL: string): Promise<string> {
  // Use the sitemap to find a prerendered category slug. Fetch via
  // node-fetch through the page context so the same base URL applies
  // whether we're against localhost or staging.
  const sitemap = await fetch(`${baseURL}/sitemap.xml`).then((r) => r.text());
  const matches = sitemap.match(/<loc>[^<]+\/category\/[^<]+<\/loc>/g);
  if (!matches || matches.length === 0) {
    throw new Error('No category slugs found in sitemap.xml');
  }
  const first = matches[0].match(/\/category\/([^<]+)</);
  if (!first) throw new Error('Could not parse category slug from sitemap');
  return first[1];
}

test.describe('Category detail', () => {
  test('loads with product grid and JSON-LD', async ({ page, baseURL }, info) => {
    const slug = await pickCategorySlug(baseURL!);
    await page.goto(`/category/${slug}`);
    await page.waitForLoadState('networkidle');

    // ItemList JSON-LD is asserted in web.yml build verification.
    const jsonLd = await page
      .locator('script[type="application/ld+json"]')
      .allInnerTexts();
    const hasItemList = jsonLd.some((s) => {
      try {
        const obj = JSON.parse(s);
        return obj['@type'] === 'ItemList';
      } catch {
        return false;
      }
    });
    expect(hasItemList).toBe(true);

    // At least one product card.
    const productCards = page.locator('.product-card__name');
    const count = await productCards.count();
    expect(count).toBeGreaterThanOrEqual(1);

    await snapshot(page, `category-detail-${slug}`, info);

    // M3.2.0-D: WCAG AA gate
    await expectNoA11yViolations(page, info);
  });

  test('has breadcrumb or category heading', async ({ page, baseURL }) => {
    const slug = await pickCategorySlug(baseURL!);
    await page.goto(`/category/${slug}`);
    await page.waitForLoadState('networkidle');

    // Either a breadcrumb back to /category OR an h1 with the category name
    const breadcrumb = page.locator('a[href="/category"]').first();
    const h1 = page.getByRole('heading', { level: 1 });

    // At least one of them must be present.
    const hasBreadcrumb = await breadcrumb.isVisible().catch(() => false);
    const hasH1 = await h1.isVisible().catch(() => false);
    expect(hasBreadcrumb || hasH1).toBe(true);
  });

  test('product cards link to PDP', async ({ page, baseURL }) => {
    const slug = await pickCategorySlug(baseURL!);
    await page.goto(`/category/${slug}`);
    await page.waitForLoadState('networkidle');

    // First product card should link to /product/:slug
    const productLinks = page.locator('a[href^="/product/"]');
    const firstLink = productLinks.first();
    const href = await firstLink.getAttribute('href');
    expect(href).toMatch(/^\/product\/.+/);
  });
});
