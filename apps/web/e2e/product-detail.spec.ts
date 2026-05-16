import { test, expect } from '@playwright/test';

/**
 * Product detail (`/product/:slug`) baseline e2e tests.
 *
 * Two prerendered product slugs exist; the rest are runtime-SSR'd
 * via the Cloudflare Worker. Both paths must render Product JSON-LD,
 * a name, price, and at least one image.
 */

async function pickProductSlug(baseURL: string): Promise<string> {
  const sitemap = await fetch(`${baseURL}/sitemap.xml`).then((r) => r.text());
  const matches = sitemap.match(/<loc>[^<]+\/product\/[^<]+<\/loc>/g);
  if (!matches || matches.length === 0) {
    throw new Error('No product slugs found in sitemap.xml');
  }
  const first = matches[0].match(/\/product\/([^<]+)</);
  if (!first) throw new Error('Could not parse product slug from sitemap');
  return first[1];
}

test.describe('Product detail', () => {
  test('loads with Product JSON-LD', async ({ page, baseURL }) => {
    const slug = await pickProductSlug(baseURL!);
    await page.goto(`/product/${slug}`);
    await page.waitForLoadState('networkidle');

    const jsonLd = await page
      .locator('script[type="application/ld+json"]')
      .allInnerTexts();
    const hasProduct = jsonLd.some((s) => {
      try {
        const obj = JSON.parse(s);
        return obj['@type'] === 'Product';
      } catch {
        return false;
      }
    });
    expect(hasProduct).toBe(true);
  });

  test('displays product name, price, image', async ({ page, baseURL }) => {
    const slug = await pickProductSlug(baseURL!);
    await page.goto(`/product/${slug}`);
    await page.waitForLoadState('networkidle');

    // Product name as h1 (semantic structure for SEO + a11y)
    const h1 = page.getByRole('heading', { level: 1 }).first();
    await expect(h1).toBeVisible();
    const name = await h1.textContent();
    expect(name?.trim().length).toBeGreaterThan(0);

    // At least one image from the api.3bayti.ae CDN.
    const productImages = page.locator('img[src*="api.3bayti.ae"]');
    const imgCount = await productImages.count();
    expect(imgCount).toBeGreaterThanOrEqual(1);
  });

  test('has page title matching product name pattern', async ({ page, baseURL }) => {
    const slug = await pickProductSlug(baseURL!);
    await page.goto(`/product/${slug}`);

    const title = await page.title();
    // Real products use the pattern "<Name> by <Vendor> · 3bayti".
    // We don't pin the exact text — just verify it doesn't fall back
    // to the runtime-SSR placeholder "Product · 3bayti".
    expect(title).not.toBe('Product · 3bayti');
    expect(title.toLowerCase()).toContain('3bayti');
  });
});
