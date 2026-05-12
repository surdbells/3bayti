# 5-day client demo plan (replan against v2.1 roadmap)

**Version:** 1.0
**Date:** 12 May 2026
**Status:** Awaiting approval — no code until this is signed off
**Author:** Sodiq + Claude
**Supersedes:** `docs/plans/m2-catalog.md` (which itself deviated from the v2.1 roadmap)

---

## 1. Why this plan exists

We discovered late on Day 1 that the M2 work we shipped (M2.1.0 + M2.1.A + M2.1.B) was structured around a re-derivation of M2 rather than the actual v2.1 roadmap. The deviations:

| Deviation | What we did | What the roadmap says | Impact |
|---|---|---|---|
| **Brand entity** | Built a Brand entity + 4 admin endpoints + 2 public endpoints + 10 seeded brands | Roadmap has Product → Vendor → Category only. No Brand. | Parked (D-12.7 decision). Schema stays; not exposed in apps/web. |
| **Order: foundation-first** | Built Vendor + Category + Brand as standalone "M2.1" phase | Roadmap has these as inline parts of Product entity work (M2 deliverable #1 is Doctrine entities, all together) | M2.1 work is real and reusable, but Product entity is the missing keystone |
| **Skipped MySQL → Postgres** | Never built data migration | M1 deliverable #13: pgloader-based bulk import of users. M2 deliverable #15: pgloader for catalog. | Critical gap. Without this, demo has no real client data. |
| **Skipped M1 deliverables** | We have auth + account, but missing | M1 deliverable #14: CDC sync; #19: `/v3/measurements/template` | Minor. M1 is effectively complete-enough for demo purposes; these can ship post-demo. |
| **Never touched apps/web** | Backend-only work | M2 exit criteria: "Catalog browse on web fully on `/v3/*`" | Major gap. Demo without apps/web flip = "trust me it works." |
| **Response shape** | v3 returns flat objects `{ "brand": {...} }` | apps/web expects `{ data, meta }` envelope (the v2 shape) | Resolvable by adapter pattern (see §6) |

These aren't "wrong engineering" — the M2.1 work is solid code that we'll keep. But it's not what the roadmap prioritised, and the gaps are what we need to close in 5 days.

---

## 2. What "5-day demo" means

**Confirmed:** Path B from the 12 May replan conversation — "demo to client in 5 days, real users later." NOT a customer-facing launch. NOT all four frontends updated.

**Demo bar (what the client sees on Day 5):**

1. Their real catalog data (2165 products, real vendors, real categories) running on Postgres via `/v3/*` endpoints
2. `apps/web` (staging.3bayti.ae) rendering catalog pages — home, category detail, product detail — from `/v3/*`, not `/v2/*`
3. The admin endpoints from M2.1.A demoed via curl/Postman as proof of admin operations + audit log
4. A short narrative: "this is what end-user rollout looks like; we're ready when you approve scope"

**Explicitly out of scope for demo:**

- `apps/mobile` (separate repo, not touched in this 5-day push)
- `apps/portal` (separate repo, not touched)
- Cart, checkout, orders (these are M3, not M2)
- Search (M2 deliverable but defer to post-demo; Postgres LIKE good enough for demo)
- Image migration (M5 deliverable; demo uses existing image URLs as-is)
- Vendor self-service portal (M4)
- CDC sync layer (we'll do a one-shot migration, accept drift risk for the demo window)

---

## 3. What the roadmap says we still owe from M1 and M2

### M1 gaps (closeable in <1 day each)

| # | Deliverable | Status | 5-day impact |
|---|---|---|---|
| M1.13 | MySQL → Postgres user data migration via pgloader | **NOT BUILT** | **NEEDED if demo includes existing customers logging in.** If demo uses our test admin only, deferrable. |
| M1.14 | CDC sync between MySQL and Postgres | **NOT BUILT** | Deferrable. We accept one-shot migration drift for demo. |
| M1.19 | `/v3/measurements/template` | **NOT BUILT** | Deferrable. Only needed for measurement-aware product pages. |

### M2 work (the bulk of the 5 days)

Roadmap M2 deliverables 1-14 (apps/api work):

| # | Deliverable | Status | Day target |
|---|---|---|---|
| M2.1 | Doctrine entities (Product, Category, Vendor, ProductImage, ProductReview, Collection) | Vendor + Category ✅; Product/Image/Review/Collection NOT BUILT | Day 1 |
| M2.2 | `/v3/products` (paginated, filterable) | NOT BUILT | Day 2 |
| M2.3 | `/v3/products/:slug` | NOT BUILT | Day 2 |
| M2.4 | `/v3/categories` | ✅ (M2.1.A — public list) | Done |
| M2.5 | `/v3/categories/:slug` | ✅ (M2.1.A — public detail) | Done |
| M2.6 | `/v3/vendors` (paginated) | ✅ (M2.1.A — public list; pagination missing) | Day 2 patch (add pagination) |
| M2.7 | `/v3/vendors/:slug` | ✅ (M2.1.A) | Done |
| M2.8 | `/v3/vendors/:slug/products` | NOT BUILT | Day 2 |
| M2.9 | `/v3/vendors/:slug/reviews` | NOT BUILT | **DEFER** (M3 work per §7.6) |
| M2.10 | `/v3/best-sellers` | NOT BUILT | Day 3 (or simplified) |
| M2.11 | `/v3/new-arrivals` | NOT BUILT | Day 3 (or simplified) |
| M2.12 | `/v3/explore` + `/v3/explore/:vertical` | NOT BUILT | **DEFER** (data shape TBD per roadmap) |
| M2.13 | `/v3/search?q=` (Postgres FTS) | NOT BUILT | **DEFER** to post-demo (Postgres LIKE fallback for demo) |
| M2.14 | `/v3/sitemap-data` | NOT BUILT | Day 4 (needed for apps/web build) |
| M2.15 | MySQL → Postgres catalog migration | **NOT BUILT — CRITICAL** | Day 3 |
| M2.16 | Image migration | NOT BUILT | DEFER (M5) |

### packages/api-client work

| # | Deliverable | Status | Day target |
|---|---|---|---|
| 1 | ENDPOINT_ROUTING entries for catalog flipped `'old'` → `'new'` | api-client scaffold exists; flip logic TBD | Day 4 |

### apps/web work

| # | Deliverable | Status | Day target |
|---|---|---|---|
| 1 | Catalog browse from `/v3/*` instead of `/v2/*` | NOT STARTED | Day 4 |
| 2 | `/designer` and `/designer/:slug` pages | NOT STARTED | **DEFER** (roadmap M2 deliverable but not demo-critical) |
| 3 | `/best-sellers`, `/new-arrivals`, `/explore` routes | NOT STARTED | **DEFER** |
| 4 | `/search` | NOT STARTED | **DEFER** |

---

## 4. Day-by-day plan

### Day 1 (today, 12 May) — Discovery + Product entity

| Block | Task | Output |
|---|---|---|
| AM-1 | Read this plan, get approval | Plan signed off |
| AM-2 | Inspect legacy MySQL: list databases, find products/categories/vendors/reviews tables, sample 5 rows each, document column → field mapping into a discovery doc | `docs/discovery/legacy-mysql-schema.md` |
| AM-3 | Reconcile legacy fields against ProductDetail interface in apps/web/src/app/features/catalog/product.model.ts. Identify the impedance mismatch (size/color arrays, primary_image, vendor ref, etc.) | Mapping table in discovery doc |
| PM-1 | Write Doctrine migration: `products`, `product_images`, `product_reviews`, `collections` tables with `legacy_product_id` columns for traceability | New migration file |
| PM-2 | Create Product, ProductImage, ProductReview entities + repositories | New entity files |
| PM-3 | Commit + push (CI green) | Tag: M2.2.0 |

**End-of-day check:** Doctrine entities for catalog complete. Migrations applied to staging. No products yet — just empty tables.

### Day 2 — Public read endpoints (the demo-critical ones)

| Block | Task | Output |
|---|---|---|
| AM-1 | `/v3/products` (paginated, filterable: ?category=, ?vendor=, ?in_stock=, ?min_price=, ?max_price=, ?sort=). Returns `{ data: Product[], meta: PaginationMeta }` envelope | 1 endpoint |
| AM-2 | `/v3/products/:slug` returning ProductDetail shape (description, images, sizes, colors, reviews) | 1 endpoint |
| PM-1 | `/v3/vendors/:slug/products` (paginated) | 1 endpoint |
| PM-2 | Add pagination to existing `/v3/vendors` + `/v3/categories` (move from M2.1.A flat lists to roadmap-shape paginated lists) | 2 endpoints patched |
| PM-3 | Commit + push (CI green) | Tag: M2.2.1 |

**End-of-day check:** All "catalog read" endpoints exist on `api-v3.3bayti.ae`. Smoke-tested via curl. Still no real data.

### Day 3 — MySQL → Postgres catalog migration

| Block | Task | Output |
|---|---|---|
| AM-1 | Write pgloader config OR custom PHP migration script (decision based on Day-1 schema discovery — if legacy is clean enough, pgloader; if messy with size/color encoding, custom PHP) | Migration script |
| AM-2 | Dry-run migration in a staging schema (`bayti_v3_staging`). Validate row counts and a 50-row sample by diff against legacy. | Validation report |
| PM-1 | Run real migration into `bayti_v3`. Verify counts: 2165 products, all categories, all vendors. | Live data |
| PM-2 | Hit the Day-2 endpoints with curl + browser. Spot-check 10 products end-to-end (list → detail → vendor → category). | Visual verification |
| PM-3 | Commit migration script + tag + push | Tag: M2.2.2 |

**End-of-day check:** `https://api-v3.3bayti.ae/v3/products` returns real 2165 products. Real categories. Real vendors. The new API has the client's data.

### Day 4 — apps/web flip to v3

| Block | Task | Output |
|---|---|---|
| AM-1 | Update `apps/web/src/app/core/api/api-config.service.ts`: add `v3BaseUrl`. Keep v2BaseUrl in place as fallback. | 1 file |
| AM-2 | Update catalog feature services (product-detail.ts, home-data.service.ts, category-detail.ts) to use v3BaseUrl. **One-line change per service.** Verify response shape matches expectations. | 3-5 files |
| PM-1 | `/v3/sitemap-data` endpoint (mirrors v2 shape per roadmap M2 deliverable #14) so apps/web's postbuild sitemap generator works unchanged | 1 endpoint |
| PM-2 | Local apps/web run pointing at api-v3.3bayti.ae. Browse to: home, category page, product detail page. Visual verification. | Working dev server |
| PM-3 | Deploy apps/web to staging (Cloudflare Workers). Hit staging.3bayti.ae and confirm catalog renders from v3. | Live staging |

**End-of-day check:** staging.3bayti.ae catalog pages serve from `api-v3.3bayti.ae` instead of `api.3bayti.ae/v2`. End-to-end works.

### Day 5 — Polish + demo prep

| Block | Task | Output |
|---|---|---|
| AM-1 | Bug fixes from Day-4 surfacing | Stability |
| AM-2 | Dry-run of demo flow — full walkthrough, top to bottom, taking notes on rough edges | Demo script |
| PM-1 | Document the M2 endpoints in `packages/api-contracts/openapi.yaml` for the demo (live OpenAPI viewer = visual proof) | OpenAPI updated |
| PM-2 | Polish the M2.1.A admin endpoints' demo curl scripts (Brand/Vendor/Category create + edit + audit log show) | `docs/demo/admin-walkthrough.md` |
| PM-3 | Pre-demo runbook: order of operations, talk track, backup plans if something breaks live | `docs/demo/runbook.md` |

**End of day:** Demo-ready.

---

## 5. Explicit non-goals (so we don't drift)

These items appeared in M2 roadmap but are deferred to post-demo:

- `/v3/vendors/:slug/reviews` — review reads (M3 reviews work per §7.6, not M2)
- `/v3/best-sellers`, `/v3/new-arrivals`, `/v3/explore` — listing pages; flag them as "coming next week" in demo
- `/v3/search` — Postgres FTS; demo can show search-by-LIKE if time permits, otherwise "coming next week"
- `/designer` and `/designer/:slug` pages in apps/web (existing vendor pages stay as-is)
- Image migration (base64 → Flysystem) — M5
- CDC sync layer for ongoing MySQL→Postgres parity — accept drift risk
- `/v3/measurements/template` — only relevant for variant-aware UI
- New routes in apps/web (`/best-sellers`, `/new-arrivals`, `/explore`, `/search`) — out of scope

---

## 6. Response shape strategy

The roadmap's per-endpoint migration sequence (§9.3 step 1) is explicit: **"with the contract that matches the old one's response shape (envelope + payload), so consumers don't change."**

This means v3 catalog endpoints MUST return `{ data, meta }` envelope to match v2.

**Implication for M2.1.A endpoints we already shipped:** They return flat shapes (e.g., `{ "brand": {...} }`). For internal admin endpoints this is fine — no external consumer relies on shape yet. **But the new M2.2 catalog endpoints (built Day 2) MUST adopt the envelope shape from the start.**

We will NOT retrofit M2.1.A admin endpoints to use the envelope (no consumer demands it). New endpoints will be enveloped. This creates a minor inconsistency we accept for now.

---

## 7. Risks + mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Legacy MySQL schema is messier than expected | Medium | Day 3 slip | Day 1 discovery is the trigger — if schema is bad, we cut scope on Day 2 (drop reviews, drop variants encoding) |
| Image storage is base64 in DB, not URLs | High | Demo looks broken without images | Detect on Day 1. If true: emit placeholder image URLs in the migrated rows; image migration becomes a flag-on-product-card "Image coming soon" affordance |
| apps/web's catalog services aren't as cleanly abstracted as I hope | Medium | Day 4 slips into Day 5 | Day 1 + Day 4 AM include time to actually read each feature service. If they're tangled, we negotiate scope (e.g., flip only product-detail, keep category on v2) |
| Postgres v3 endpoint response shape doesn't match apps/web exactly | High | Day 4 visual bugs | Day 2 includes deliberate shape match via OpenAPI yaml as the contract. Day 4 verifies. |
| Day-5 dry-run reveals showstopper | Low | No demo | Day 5 has a half-day buffer; if showstopper found in AM, PM is bug-fix |
| Client demo on Day 5+1 changes scope | Medium | Confusion | This plan IS the scope document for the client conversation. Stick to it. |

---

## 8. Decisions locked in this plan

1. **Brand entity:** parked (D-12.7). No work on Brand this 5-day window. Reconsider post-demo.
2. **CDC sync:** deferred. One-shot migration accepted for demo.
3. **/v3/measurements/template:** deferred to post-demo.
4. **/v3/search:** deferred (Postgres LIKE acceptable for demo if used; recommend NOT demoing search at all).
5. **Image migration:** deferred to M5 per roadmap. Demo accepts whatever shape images are in.
6. **apps/web new routes** (`/designer/:slug`, `/best-sellers`, etc.): deferred.
7. **Response envelope:** new M2 endpoints adopt `{ data, meta }` shape. M2.1.A admin endpoints stay flat (no consumer impact).
8. **Mobile + portal:** out of scope entirely for 5-day demo.

---

## 9. Approval

Before any Day-1 code is written, this plan needs explicit sign-off:

- [ ] Day-by-day sequencing makes sense
- [ ] Scope cuts (§5, §8) are acceptable
- [ ] Demo bar (§2) matches client expectation
- [ ] Risk mitigations (§7) are credible

Once signed off, this plan supersedes `docs/plans/m2-catalog.md` for the 5-day window.
