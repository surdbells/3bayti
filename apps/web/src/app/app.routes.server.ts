import { RenderMode, ServerRoute } from '@angular/ssr';

/**
 * Server-side route configuration for Angular SSR.
 *
 * The default rule (`'**' → Prerender`) handles every static route.
 * For routes with parameters (`/category/:slug`, `/product/:slug`,
 * eventually `/designer/:slug`), we explicitly list which parameter
 * values to prerender via `getPrerenderParams`.
 *
 * `getPrerenderParams` runs ONCE at build time, on the build machine.
 * It receives no per-request context — it's pure data fetching for
 * the prerender phase.
 */

const API_BASE = process.env['API_BASE_URL'] || 'https://api.3bayti.ae/v2';

/**
 * How many product PDP pages to prerender at build time.
 *
 * Trade-off: prerendering all 1,657 products would push build time
 * past Cloudflare Pages' practical limits (rough estimate: 5+ minutes).
 * Capping at 200 keeps build under 2-3 minutes while still giving us
 * crawler-friendly static HTML for the 200 most recent products.
 *
 * The remaining ~1,457 products fall back to RenderMode.Server (runtime
 * SSR) — they're still indexable, just with a slower first byte and
 * higher edge cost per request. New products get prerendered on the
 * next deploy automatically.
 *
 * If we ever need to bump this number, the build-time impact scales
 * linearly: ~1.5-2s per extra prerendered page.
 */
const PRODUCT_PRERENDER_CAP = 200;

/**
 * Fetch every category slug at build time. Used by the prerender
 * provider for /category/:slug.
 *
 * Failure handling: if the API is unreachable, return an empty list
 * so the build doesn't blow up. Cloudflare Pages would deploy without
 * the category-detail pages prerendered, and they'd 404 until the
 * next successful build. Better than a hard build failure.
 */
async function fetchCategorySlugs(): Promise<string[]> {
  try {
    const res = await fetch(`${API_BASE}/categories`);
    if (!res.ok) {
      console.warn(`[SSR] /categories returned ${res.status}; skipping category-detail prerender`);
      return [];
    }
    const json = await res.json() as { data: Array<{ slug: string }> };
    const slugs = (json.data ?? []).map((c) => c.slug).filter(Boolean);
    console.log(`[SSR] Prerendering /category/:slug for ${slugs.length} categories: ${slugs.join(', ')}`);
    return slugs;
  } catch (err) {
    console.warn(`[SSR] fetchCategorySlugs failed:`, err instanceof Error ? err.message : err);
    return [];
  }
}

/**
 * Fetch the most recent N product slugs at build time. Used by the
 * prerender provider for /product/:slug.
 *
 * The API returns products sorted by newest first by default, so taking
 * the first N gives us the most recently-added products — the ones
 * most likely to be linked from category pages people visit RIGHT NOW.
 *
 * Failure modes mirror fetchCategorySlugs: API unreachable → empty list,
 * build proceeds with no PDPs prerendered, runtime SSR catches every
 * request. Better than a hard fail.
 */
async function fetchRecentProductSlugs(limit: number): Promise<string[]> {
  /* The API caps limit at 60 per request, so paginate. */
  const PAGE_SIZE = 60;
  const slugs: string[] = [];

  try {
    for (let offset = 0; offset < limit; offset += PAGE_SIZE) {
      const pageLimit = Math.min(PAGE_SIZE, limit - offset);
      const url = `${API_BASE}/products?limit=${pageLimit}&offset=${offset}&sort=newest`;
      const res = await fetch(url);
      if (!res.ok) {
        console.warn(`[SSR] /products returned ${res.status} at offset=${offset}; stopping`);
        break;
      }
      const json = await res.json() as {
        data: Array<{ slug: string }>;
        meta?: { has_more?: boolean };
      };
      const pageSlugs = (json.data ?? []).map((p) => p.slug).filter(Boolean);
      slugs.push(...pageSlugs);
      if (pageSlugs.length < pageLimit) {
        /* Backend ran out of products before we hit the cap. */
        break;
      }
    }
    console.log(`[SSR] Prerendering /product/:slug for ${slugs.length} products (cap: ${limit})`);
    return slugs;
  } catch (err) {
    console.warn(`[SSR] fetchRecentProductSlugs failed:`, err instanceof Error ? err.message : err);
    return slugs; /* return whatever we got before the failure */
  }
}

export const serverRoutes: ServerRoute[] = [
  {
    /* Category detail — prerender every existing category slug at
       build time. As of W2.0 there are 8 categories; this number
       grows naturally as new categories are added without code
       changes (build-time fetch picks them up). */
    path: 'category/:slug',
    renderMode: RenderMode.Prerender,
    async getPrerenderParams() {
      const slugs = await fetchCategorySlugs();
      return slugs.map((slug) => ({ slug }));
    },
  },
  {
    /* Product detail — prerender the 200 most-recent products at
       build time; runtime SSR handles the rest.

       Fallback strategy: defaults to PrerenderFallback.Server, which
       means slugs not in the prerender list are rendered on demand
       at request time. Search engines hitting un-prerendered URLs
       still get full HTML; users get a slightly slower first byte. */
    path: 'product/:slug',
    renderMode: RenderMode.Prerender,
    async getPrerenderParams() {
      const slugs = await fetchRecentProductSlugs(PRODUCT_PRERENDER_CAP);
      return slugs.map((slug) => ({ slug }));
    },
  },
  {
    /* Default: prerender everything else at build time. */
    path: '**',
    renderMode: RenderMode.Prerender,
  },
];
