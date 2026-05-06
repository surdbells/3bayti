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
 *   API_BASE_URL    — optional, defaults to https://api.3bayti.ae/v2
 *   SITE_URL        — optional, defaults to https://staging.3bayti.ae
 *   OUTPUT_DIR      — optional, defaults to dist/3bayti-web/browser
 */

import { writeFileSync, existsSync, mkdirSync } from 'node:fs';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

const API_BASE = process.env.API_BASE_URL || 'https://api.3bayti.ae/v2';
const SITE_URL = process.env.SITE_URL || 'https://staging.3bayti.ae';
const OUT_DIR  = process.env.OUTPUT_DIR || join(__dirname, '..', 'dist', '3bayti-web', 'browser');

const STATIC_PAGES = [
  { loc: '/',          changefreq: 'weekly',  priority: '1.0' },
  { loc: '/category',  changefreq: 'weekly',  priority: '0.9' },
  // Phase 2 will add /designers, /about, /contact, /faq, /sizing-guide, etc.
];

async function fetchSitemapData() {
  const url = `${API_BASE}/sitemap-data`;
  try {
    const res = await fetch(url, { headers: { Accept: 'application/json' } });
    if (!res.ok) {
      console.warn(`[sitemap] API responded ${res.status} — using static fallback`);
      return null;
    }
    return await res.json();
  } catch (err) {
    console.warn(`[sitemap] API unreachable (${err.message}) — using static fallback`);
    return null;
  }
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
    for (const vendor of apiData.vendors || []) {
      entries.push({
        loc: `${SITE_URL}/designer/${vendor.slug}`,
        lastmod: vendor.last_modified,
        changefreq: 'weekly',
        priority: '0.6',
      });
    }
    console.log(
      `[sitemap] ${entries.length} URLs total `
      + `(${apiData.categories?.length || 0} categories, `
      + `${apiData.products?.length || 0} products, `
      + `${apiData.vendors?.length || 0} vendors)`
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
