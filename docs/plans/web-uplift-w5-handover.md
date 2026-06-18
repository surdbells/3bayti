# Web Uplift — §6 QA backlog handover (W5 / PDP #10)

Continuation doc for the customer storefront (`apps/web`) §6 QA work. Pick up
from here in a fresh session.

## Where things stand

Local `main` is **10 commits ahead of `b389bb2`** (none pushed yet — see "Handoff"):

| Commit | Stream | QA items |
|--------|--------|----------|
| `d4d10e8` | W1.1 | #5 shared FilterBar component + spec + i18n |
| `10a5386` | W1.2 | #4 category filters → top bar; FacetFilters retired |
| `40b72f5` | W1.3 | #2/#3 best-sellers + new-arrivals filters + URL sync |
| `c8540ab` | W2   | #6 tabbed search (Products \| Stores) |
| `dcac76a` | W3   | #8 compact ProductStrip density (home strips) |
| `ddb0f81` | W5.1 | #10g variant hints → styled inline notices |
| `f710011` | W5.2 | #10c lightbox loads full-size zoom (was 200px thumb) |
| `0809237` | docs | (this handover doc) |
| `00a5620` | W5.3 | #10b PDP thumbnails → left vertical rail (RTL-safe) |
| `35acb5d` | W5.4 | #10e PDP description/reviews/details → premium tabs |

**Gates at HEAD (`35acb5d`):** vitest **767 / 74** ✓ · `ng build` ✓ ·
`tsc --noEmit -p tsconfig.app.json` clean ✓ · 0 regressions.

### QA backlog status
- #2 ✅ · #3 ✅ · #4 ✅ · #5 ✅ · #6 ✅ · #8 ✅
- #1, #7 — ⛔ **deploy-blocked**, NOT frontend bugs. The api commits that
  unblock them (`df1c86a` H2.A pagination, `83ebd65` H0.1 featured-vendors
  fallback) are already on `main` but the live api at `api-v3.3bayti.ae` does
  not reflect them → the **api auto-deploy/redeploy is the broken link**.
  Operator must trigger the droplet api redeploy, then #1 pagination + #7 home
  Store Spotlight light up with no new web code.
- #10: a ✅ · g ✅ · c ✅ · b ✅ · e ✅ · **h ✅ already present** ·
  **f ✅ already present** · **d ⚠ API-blocked (only item left)**

## Pre-flight catches (do NOT re-implement)
- **#10h (share row incl. WhatsApp):** already done. `product-detail.html:291`
  renders `<ui-share-buttons [url]="shareUrl()" [title]="p.name" />`, and
  `shared/ui/share-buttons.ts` already covers WhatsApp, copy-link, native share,
  Facebook, X, Telegram.
- **#10f (recs 5×2, 10 total):** already done. `RecommendationsService.DEFAULT_LIMIT
  = 10`; `product-detail.ts relatedProducts` does `.slice(0, 10)`; the SCSS
  `.pdp-related__grid` already steps 2 → 3 (≥600px) → `repeat(5)` (≥1280px).

## Remaining work

### #10b — Thumbnails on the LEFT ✅ DONE (`00a5620`)
CSS-only reflow: at ≥768px `.pdp-gallery` is a flex row with the thumbnail rail
ordered first (`order: -1`, inline-start; RTL mirrors to inline-end), vertical,
capped + scrollable. Mobile keeps the stacked grid. No markup/logic change.

### #10e — Premium TABS instead of accordion ✅ DONE (`35acb5d`)
Three disclosures → dynamic tab strip + single panel. Reviews always present;
Description/Details tabs only when they have content; default = first available.
WAI-ARIA tablist/tab/tabpanel, roving tabindex, arrow-key nav, RTL. `#reviews`
deep-link preserved (id on the Reviews tab; fragment opens that tab). Reviews
spec passed unmodified. **Follow-up recommendation:** the W2 search overlay uses
the same tabs pattern inline — extract a shared `shared/ui/tab-group` component
and adopt it in both (search + PDP) to remove the duplication. Deferred to avoid
destabilising shipped W2; operator decision.

### #10d — Store information block ⚠ API-blocked (ONLY ITEM LEFT)
- A minimal vendor line already exists at `product-detail.html` (the
  `.pdp-vendor` name + `vendorUrl()` link to `/stores/:slug`).
- The richer block the item asks for (logo + rating) needs fields that
  `VendorRef { slug, name }` does not carry. **Decision needed (operator):**
  (a) extend the product-detail API payload with `vendor.logo_url` + `vendor.rating`
  (+ `rating_count`), or (b) have the PDP fetch the store by slug via the
  existing stores endpoint to enrich client-side (extra request). Until one is
  chosen, only the name+link is possible. Recommend (a) — single payload, no
  extra round-trip; mirrors how `DirectoryStore` already exposes logo/rating.

## Environment + workflow notes (carry forward)
- **Build:** `node_modules/.bin/ng build` (the direct binary). `pnpm exec ng build`
  FAILS — it trips on the web `package.json` `"packageManager": "npm@…"` field
  and exits 1 with one line. pnpm is fine for install (`corepack` → pnpm 9.15.0).
- **Test:** `node_modules/.bin/vitest run [path]`. Full suite ~767/74.
- **Type-check:** `node_modules/.bin/tsc --noEmit -p tsconfig.app.json`.
- **Signal inputs vs test toolchain:** the vitest `@angular/build:unit-test`
  builder does NOT register signal `input()` — `setInput` and host-template
  binding both throw NG0303. Tested components use **`@Input()`/`@Output()`
  decorators** (see `gift-card-visual.ts`, and the FilterBar conversion in
  `d4d10e8`). For decorator inputs, `componentRef.setInput` works.
- **i18n in specs:** pair `provideI18n()` with `provideHttpClient()` +
  `provideHttpClientTesting()` so the translation XHR is intercepted (otherwise
  unhandled `/i18n/en.json` rejections).
- **Design tokens:** brand-50…700, space-2xs…2xl, radius-sm…pill,
  shadow-card-*, page-max-width 1280 / padding-x 24(48) — in
  `apps/web/src/styles.scss :root`. Headings use 'Playfair Display'. Component
  CSS budget 17kB warn / 18kB error.

## Handoff / push (unchanged constraint)
Claude does **not** handle the GitHub PAT or push — that step stays with the
operator. Each phase boundary produces a `git format-patch` series in
`/mnt/user-data/outputs/`; the operator applies (`git am 0001..000N`) and pushes
with their own credential, which fires the Cloudflare Pages deploy. (The PAT was
pasted in chat several times and should be revoked/rotated.)
