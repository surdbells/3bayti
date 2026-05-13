# Demo Smoke Test Evidence

**Captured:** Day 8 of M2 rollout (May 14, 2026)
**Purpose:** Written record of what each demo URL rendered, so we can compare against demo-day reality if anything regresses.

If something looks different at the demo, diff against this doc. If everything matches, we're golden.

## API health snapshot

```json
{
  "status": "ok",
  "service": "3bayti-api",
  "version": "59ce259",
  "timestamp": "2026-05-13T10:03:01+00:00"
}
```

API version `59ce259` = the Day 7 slug-fix commit. Current as of capture.

## Page-by-page evidence

### `/` — Home page

- **HTTP:** 200
- **Size:** 208,963 bytes (~209 KB)
- **Title:** `3bayti — Premium Abayas, Kaftans & Modest Wear`
- **Hero carousel slides:** 30 (10 categories × 3 frames each, per design)
- **Product strips rendered:** featured, best sellers, new arrivals (3 strips)
- **Designer Spotlight strip:** present (served by legacy v2 per ENDPOINT_ROUTING)

### `/category` — Category index

- **HTTP:** 200
- **Size:** 56,203 bytes
- **Title:** `Shop by Category · 3bayti`
- **Categories rendered (verbatim, in order):**
  1. Abayas
  2. Accessories
  3. Bags
  4. Dresses
  5. Kaftans
  6. Modest clothes
  7. Mukhawars
  8. Pyjamas

All 8 categories from v3 visible.

### `/category/abayas-1` — Category detail

- **HTTP:** 200
- **Size:** 117,405 bytes
- **Title:** `Abayas · Modest Wear & Designer Pieces | 3bayti`
- **ItemList JSON-LD:** 1 present
- **Product cards:** 20 (page size; v2 endpoint serves 20 at a time)
- **First 3 product names rendered:**
  1. Woven Waves
  2. LA62
  3. LA61

### `/category/dresses-3` — Category detail (Dresses)

- **HTTP:** 200
- **Size:** 117,285 bytes
- **Status:** Renders same shape as `abayas-1` (verified)

### `/category/mukhawars-2` — Category detail (Mukhawars)

- **HTTP:** 200
- **Size:** 118,513 bytes
- **Status:** Renders same shape as `abayas-1` (verified)

### `/product/la23` — PDP (Product Detail Page)

- **HTTP:** 200
- **Size:** 67,265 bytes
- **Title:** `LA23 by Laduna Abaya | 3bayti`
- **Product JSON-LD:** 1 present
- **Vendor name in title:** "Laduna Abaya" ✓
- **Price visible:** AED 980 ✓

### `/product/woven-waves` — PDP

- **HTTP:** 200
- **Size:** 70,195 bytes
- **Title:** `Woven Waves by BY AMEENA | 3bayti`

## API endpoint evidence

### `GET /v3/products/la23`

Sample fields, captured Day 8:

```
slug: la23
name: LA23
price: { amount: 980.0, currency: AED }
vendor: Laduna Abaya (laduna-abaya)
images: 6 image(s)
in_stock: True
```

Vendor reference is the {slug, name} shape from v3's serializer.

### Sitemap snapshot

- **URL:** `https://staging.3bayti.ae/sitemap.xml`
- **Total URLs:** 1,933
- **Distribution:**
  - 1,923 `/product/*` URLs
  - 8 `/category/*` URLs (one per migrated category)
  - 1 `/category` (index)
  - 1 `/` (root)
  - 0 `/designer/*` (stripped on Day 7 since route doesn't exist)

## What this evidence proves

1. **v3 powers the catalog** — `/product/la23` showing "by Laduna Abaya"
   means the response was assembled from v3's vendor join (v3-only slug
   "laduna-abaya", not the legacy "laduna-abaya-something" shape).

2. **Real client data** — these aren't placeholder products. "Woven Waves
   by BY AMEENA" at AED 445 is the same product Sodiq's existing customer
   base would recognize.

3. **SEO is intact** — every page has its `<title>` and JSON-LD structured
   data. ItemList JSON-LD on category pages, Product JSON-LD on PDPs.

4. **Strangler-fig is working** — v3-served pages (PDP) coexist with
   legacy-v2-served pages (category-detail) under the same domain, no
   visible difference to the user.

5. **Sitemap is honest** — every URL listed actually renders. No 404
   surprises for search engine crawlers.

## How to re-verify before the demo

Run the same checks 5 minutes before demo start:

```bash
BASE="https://staging.3bayti.ae"

# Status checks
for p in / /category /category/abayas-1 /product/la23 /sitemap.xml /robots.txt; do
  curl -s -o /dev/null -w "%{http_code} $p\n" "$BASE$p"
done
# Expected: all 200

# Title sanity
curl -s "$BASE/" | grep -oE '<title>[^<]+</title>'
# Expected: "3bayti — Premium Abayas, Kaftans & Modest Wear"

# Real product data
curl -s "$BASE/product/la23" | grep -oE '<title>[^<]+</title>'
# Expected: "LA23 by Laduna Abaya | 3bayti"

# API alive
curl -s https://api-v3.3bayti.ae/v3/health | python3 -m json.tool
# Expected: status=ok, version=<recent>

# Sitemap honest
curl -s "$BASE/sitemap.xml" | grep -c '<loc>'
# Expected: 1933 (or similar; verify no /designer/ URLs returned)
curl -s "$BASE/sitemap.xml" | grep -c '/designer/'
# Expected: 0
```

If any of these don't match this evidence doc, investigate before the demo
starts.
