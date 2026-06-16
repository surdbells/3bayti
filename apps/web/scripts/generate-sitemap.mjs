#!/usr/bin/env node
/**
 * Sitemap generator — runs at build time (post-build hook).
 *
 * Reads from the v2 API's `/sitemap-data` endpoint (when available)
 * and writes a fully-populated `sitemap.xml` to the build output.
 * Falls back to a stub sitemap if the API isn't reachable yet
 * (Phase 1: API not yet live, so we always fall back).
 *
 * Usage:
 *   node scripts/generate-sitemap.mjs
 *
 * Wired into npm run build via package.json's `build` script — runs
 * automatically after `ng build`.
 *
 * Environment:
 *   CATEGORY_API_BASE_URL — categories slug source. Default: v2 (legacy)
 *                           because category-detail still routes there
 *                           per ENDPOINT_ROUTING.
 *   PRODUCT_API_BASE_URL  — products slug source. Default: v3.
 *   VENDOR_API_BASE_URL   — vendors slug source. Default: v3.
 *   API_BASE_URL          — legacy single-base fallback if specific
 *                           overrides above aren't set.
 *   SITE_URL              — optional, defaults to https://staging.3bayti.ae
 *   OUTPUT_DIR            — optional, defaults to dist/3bayti-web/browser
 */

import { writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Two API bases for split sitemap fetch — see apps/web/src/app/
 * app.routes.server.ts for the rationale. In short:
 *
 *   /category/:slug -> served from legacy v2 at runtime (target='old'
 *     in ENDPOINT_ROUTING), so sitemap must list v2 slug shapes.
 *   /product/:slug  -> served from v3 at runtime (target='new'), so
 *     sitemap must list v3 slug shapes.
 *
 * If routing decisions flip, update these to match — sitemap entries
 * MUST match the URLs the SSR pass actually prerenders, otherwise
 * search engines see 200s in the sitemap but get 404s on visit.
 *
 * Env var precedence (rarely needed):
 *   {CATEGORY,PRODUCT,VENDOR}_API_BASE_URL specific override
 *   API_BASE_URL                            legacy single-override
 */
const LEGACY_API_BASE_URL = 'https://api.3bayti.ae/v2';
const V3_API_BASE_URL     = 'https://api-v3.3bayti.ae/v3';

const CATEGORY_API_BASE = process.env.CATEGORY_API_BASE_URL || process.env.API_BASE_URL || LEGACY_API_BASE_URL;
const PRODUCT_API_BASE  = process.env.PRODUCT_API_BASE_URL  || process.env.API_BASE_URL || V3_API_BASE_URL;
// Vendors still served by v3 (target='new' for both list + detail).
const VENDOR_API_BASE   = process.env.VENDOR_API_BASE_URL   || process.env.API_BASE_URL || V3_API_BASE_URL;
const SITE_URL = process.env.SITE_URL || 'https://staging.3bayti.ae';
const OUT_DIR  = process.env.OUTPUT_DIR || join(__dirname, '..', 'dist', '3bayti-web', 'browser');

const STATIC_PAGES = [
  { loc: '/',          changefreq: 'weekly',  priority: '1.0' },
  { loc: '/category',  changefreq: 'weekly',  priority: '0.9' },
  // /stores directory index (formerly /designer). Per-store
  // (/stores/:slug) entries come from the vendors loop below.
  { loc: '/stores',    changefreq: 'weekly',  priority: '0.8' },
];

async function fetchSitemapDataFrom(baseUrl) {
  const url = `${baseUrl}/sitemap-data`;
  try {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!res.ok) {
      console.warn(`[sitemap] ${url} responded ${res.status}`);
      return null;
    }
    return await res.json();
  } catch (err) {
    console.warn(`[sitemap] ${url} unreachable (${err.message})`);
    return null;
  }
}

/**
 * Fetch sitemap data from each resource's authoritative backend
 * (per ENDPOINT_ROUTING). Returns a merged result with only the
 * resource types each base is responsible for.
 *
 * If all bases are the same URL we only do one network call —
 * common case in production where everything's on one backend.
 */
async function fetchSitemapData() {
  const distinctBases = new Set([CATEGORY_API_BASE, PRODUCT_API_BASE, VENDOR_API_BASE]);
  const cache = new Map();
  for (const base of distinctBases) {
    cache.set(base, await fetchSitemapDataFrom(base));
  }
  const categorySrc = cache.get(CATEGORY_API_BASE);
  const productSrc  = cache.get(PRODUCT_API_BASE);
  const vendorSrc   = cache.get(VENDOR_API_BASE);

  if (!categorySrc && !productSrc && !vendorSrc) {
    return null;  // All bases unreachable — caller falls back.
  }
  return {
    categories: categorySrc?.categories || [],
    products:   productSrc?.products    || [],
    vendors:    vendorSrc?.vendors      || [],
  };
}

function xmlEscape(s) {
  return s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&apos;');
}

function urlEntry({ loc, lastmod, changefreq, priority }) {
  const lines = [`  <url>`, `    <loc>${xmlEscape(loc)}</loc>`];
  if (lastmod) lines.push(`    <lastmod>${lastmod}</lastmod>`);
  if (changefreq) lines.push(`    <changefreq>${changefreq}</changefreq>`);
  if (priority) lines.push(`    <priority>${priority}</priority>`);
  lines.push('  </url>');
  return lines.join('\n');
}

function buildSitemap(entries) {
  const xml = [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
    ...entries.map(urlEntry),
    '</urlset>',
  ].join('\n');
  return xml + '\n';
}

async function main() {
  console.log('[sitemap] generating…');

  const entries = STATIC_PAGES.map((p) => ({
    loc: SITE_URL + p.loc,
    changefreq: p.changefreq,
    priority: p.priority,
  }));

  const apiData = await fetchSitemapData();
  if (apiData) {
    /* Append dynamic entries from the API. Slug-based URLs follow the
       route patterns: /category/:slug, /product/:slug, /designer/:slug. */
    for (const cat of apiData.categories || []) {
      entries.push({
        loc: `${SITE_URL}/category/${cat.slug}`,
        lastmod: cat.last_modified,
        changefreq: 'daily',
        priority: '0.8',
      });
    }
    for (const product of apiData.products || []) {
      entries.push({
        loc: `${SITE_URL}/product/${product.slug}`,
        lastmod: product.last_modified,
        changefreq: 'weekly',
        priority: '0.7',
      });
    }
    /*
     * Designer (vendor) URLs. Restored in M3.2.Y.4-D now that
     * /designer and /designer/:slug are implemented (Y.4-B/C) and
     * prerendered at build time (app.routes.server.ts fetchVendorSlugs).
     *
     * Before Y.4 these were deliberately excluded: the Day-7 audit
     * found 104 /designer/* URLs pointing at routes that returned 404,
     * which would have downgraded the site's crawl trust. Those routes
     * now emit real static HTML, so the URLs are safe — and valuable —
     * to advertise to crawlers again.
     *
     * priority 0.6: below products (0.7) and categories, since a
     * designer page is a navigational hub rather than a conversion
     * leaf, but still a first-class indexable destination.
     */
    for (const vendor of apiData.vendors || []) {
      if (!vendor.slug) continue;
      entries.push({
        loc: `${SITE_URL}/stores/${vendor.slug}`,
        lastmod: vendor.last_modified,
        changefreq: 'weekly',
        priority: '0.6',
      });
    }
    console.log(
      `[sitemap] ${entries.length} URLs total `
      + `(${apiData.categories?.length || 0} categories, `
      + `${apiData.products?.length || 0} products, `
      + `${apiData.vendors?.length || 0} stores)`
    );
  } else {
    console.log(`[sitemap] static-only mode: ${entries.length} URLs`);
  }

  if (!existsSync(OUT_DIR)) {
    mkdirSync(OUT_DIR, { recursive: true });
  }
  const outPath = join(OUT_DIR, 'sitemap.xml');
  writeFileSync(outPath, buildSitemap(entries));
  console.log(`[sitemap] wrote ${outPath}`);

  /* robots.txt — generated here so the Sitemap: URL stays in sync with
     SITE_URL. Static public/robots.txt was removed when SITE_URL became
     configurable. */
  const robotsPath = join(OUT_DIR, 'robots.txt');
  const robotsBody =
    `# robots.txt for 3bayti web\n` +
    `# ${SITE_URL}/robots.txt\n` +
    `\n` +
    `User-agent: *\n` +
    `Allow: /\n` +
    `Disallow: /_dev/\n` +
    `Disallow: /api/\n` +
    `\n` +
    `Sitemap: ${SITE_URL}/sitemap.xml\n`;
  writeFileSync(robotsPath, robotsBody);
  console.log(`[sitemap] wrote ${robotsPath}`);
}

main().catch((err) => {
  console.error('[sitemap] FAILED:', err);
  process.exit(1);
});
