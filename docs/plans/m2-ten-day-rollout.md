# 10-day migration plan — web + mobile + portal onto v3 API

**Version:** 1.0
**Date:** 12 May 2026
**Status:** Awaiting approval
**Author:** Sodiq + Claude
**Supersedes:** `docs/plans/m2-five-day-demo.md` (the 5-day plan, before scope expanded to all three frontends)
**Grounded in:** `docs/discovery/legacy-mysql-schema.md` + apps/web/mobile/portal codebase scan (all on 12 May 2026)

---

## 1. What the client agreed to

**10 days. All three frontends on v3. Real data.**

Practical definition:
- `apps/web` (Angular 17 SSR) — catalog pages render from `/v3/*` instead of `/v2/*`
- `apps/mobile` (Ionic Angular 21 + Capacitor 8) — catalog + auth + account screens use `/v3/*`
- `apps/portal` (Angular 19, vendor + admin) — at minimum, login + a vendor dashboard view sourced from `/v3/*`
- 1,928 published products + 9,316 user accounts migrated to PostgreSQL
- Vendor logins work in v3 with their existing bcrypt passwords (NO password reset)

---

## 2. Three pieces of GOOD NEWS from discovery

These radically de-risk the 10-day window:

### 2.1 Mobile + portal architecture is migration-friendly

Both apps have:
- A single `baseURL` static constant
- Every endpoint defined as `GlobalComponent.baseURL + 'path'`
- A 30-line HTTP wrapper service (NetworkService for mobile, CrudService for portal)
- No deep coupling to response shapes — features mostly pass-through

**Migration pattern: per-endpoint, change which baseURL is concatenated. Apps don't otherwise know.**

This is the polar opposite of the assumption I was working under yesterday (that mobile would be a weeks-long refactor).

### 2.2 Bcrypt passwords are compatible

Sample users show `$2y$10$...` hashes — PHP `password_hash()` defaults. v3 uses the same algorithm. **Existing users log in to v3 without resetting.** This single fact saves ~1-2 days of UX + comms work.

### 2.3 Product images are file paths, not base64

The biggest worry. Resolved: 1928 products average 43.9 char `image_1` (file paths). v3 points URLs at `https://api.3bayti.ae/vendors/products/<filename>` and the legacy server keeps serving image files throughout M2-M4. **Zero image migration in the 10-day window.**

### 2.4 No orders to migrate

`ec_orders` is empty. Production has zero real orders. M3 work (cart/checkout/orders) is brand-new feature work, NOT a migration. **The 10 days only need to do migration for users + catalog. Everything else is new construction.**

---

## 3. Three pieces of HARD NEWS from discovery

### 3.1 Product variants are 22 boolean columns

`size_xs, size_s, ..., size_64, size_custom` + a comma-separated `colors varchar(500)`. We knew this was flat-not-table. We didn't know it was THIS flat. Migration must transform 22 booleans → an `available_sizes` JSON array.

### 3.2 Legacy categories are 8 flat rows

No tree, no slug, no display_order. The v3 Category entity we built has all those fields. **Our seed data (clothing/womens/abayas/...) is fictional — must be removed.** Real categories: Abayas, Kaftans, Bags, Accessories, Modest clothes, Dresses, Mukhawars, Pyjamas.

### 3.3 Vendors live INSIDE users table

A vendor IS a user with `is_vendor=1` plus ~30 store_* columns. Our v3 has a separate `vendors` table. **Migration must split each vendor row into a User row + a Vendor row.**

### 3.4 Vendor logos are base64 in LONGBLOB

10KB-1.19MB each, stuffed in user rows. Causes the 387.5MB users table size. Demo can show placeholders; image migration is M5 work, NOT in the 10 days.

---

## 4. What's already done (M2.1.A — vendor/category admin scaffolding)

From yesterday's session:
- v3 has Vendor entity + 4 admin CRUD endpoints + 2 public read endpoints
- v3 has Category entity + 4 admin CRUD endpoints + 2 public read endpoints
- v3 has Brand entity (PARKED — not in roadmap, not exposed)
- Audit log + admin auth + JWT all functional
- 3 fictional vendors, 9 fictional categories, 13 fictional brands in PostgreSQL (will be **replaced** by migration on Day 4)

This work is **mostly reusable.** The Vendor entity needs a few field adjustments to match legacy (commission_rate, store_*, billing_*), but the entity + endpoints exist.

---

## 5. The 10-day plan — overview

| Day | Theme | Key deliverable |
|---|---|---|
| 1 | Today: discovery COMPLETE, M2 Product entity build | Doctrine entities + migrations for Product, ProductImage, ProductReview, Collection |
| 2 | M2 public read endpoints | `/v3/products`, `/v3/products/:slug`, `/v3/vendors/:slug/products`, others |
| 3 | apps/api: api-client routing + Vendor entity field adjustments | api-client supports envelope, Vendor entity matches legacy shape |
| 4 | **Migration day**: MySQL → Postgres for users + vendors + categories + products | All client data in Postgres |
| 5 | apps/web flip catalog endpoints | Staging website renders from v3 |
| 6 | apps/mobile API surface flip (catalog + auth + account) | Mobile dev build talks to v3 |
| 7 | apps/portal API surface flip (catalog + auth) | Portal dev build talks to v3 |
| 8 | End-to-end testing across surfaces | All three apps verified against v3 |
| 9 | Production deploys: web staging, mobile beta, portal staging | Live demo environments |
| 10 | Demo dry-run + buffer | Demo-ready |

---

## 6. Day-by-day detail

### Day 1 (TODAY — 12 May) — Foundation

**Theme:** Discovery wrap-up + Product entity foundation.

| Block | Task | Output |
|---|---|---|
| AM-1 | Approval on this plan | Plan signed off |
| AM-2 | Roll back fictional seed data: drop fictional vendors, categories, brands. Migrations to reset state. | DB at zero |
| AM-3 | Doctrine migration: `products`, `product_images`, `product_reviews` tables with `legacy_*_id` columns. | Migration file |
| PM-1 | Product / ProductImage / ProductReview entities + repositories | Entity files |
| PM-2 | Adjust Vendor entity: add legacy `store_*` fields (logo_data_url, cover_data_url, store_status, vat_status, etc.) | Updated entity + migration |
| PM-3 | Test suite for the new entities (basic create/read/update) | Tests passing |
| PM-4 | Commit + push (CI green) | Tag M2.2.0 |

**End of day:** Empty Postgres, but schema ready for migration. Product entity exists.

### Day 2 — Catalog public reads

**Theme:** Build the read endpoints apps/web needs.

| Block | Task | Output |
|---|---|---|
| AM-1 | `/v3/products` (paginated, filterable: category, vendor, in_stock, min/max price, sort). Returns `{ data, meta }` envelope per roadmap §9.3. | 1 endpoint |
| AM-2 | `/v3/products/:slug` returning full ProductDetail shape (matching apps/web's existing ProductDetail interface) | 1 endpoint |
| PM-1 | `/v3/vendors/:slug/products` (paginated) | 1 endpoint |
| PM-2 | `/v3/sitemap-data` (matches v2 shape so apps/web's postbuild script works unchanged) | 1 endpoint |
| PM-3 | Refactor existing M2.1.A endpoints (`/v3/categories`, `/v3/vendors`) to use `{ data, meta }` envelope — same shape, just wrapped | 2 endpoints retrofitted |
| PM-4 | Smoke test via curl against empty DB (200 OK, empty arrays) | Validation |
| PM-5 | Commit + push | Tag M2.2.1 |

**End of day:** All catalog read endpoints exist. Still no data, but APIs respond correctly.

### Day 3 — packages/api-client wiring + envelope adapter

**Theme:** Make the routing layer actually work.

| Block | Task | Output |
|---|---|---|
| AM-1 | Implement ENDPOINT_ROUTING per roadmap §9.2. Each entry routes `'old'` (legacy) or `'new'` (v3). Default everything to `'old'` initially. | feature-flags.ts populated |
| AM-2 | api-client wrapper resolves `${method} ${path}` → real URL via ENDPOINT_ROUTING. Handles auth header injection per route. | Working request function |
| AM-3 | Response transformer: legacy returns `{response_code, status, data, message}`; v3 returns `{ data, meta }`. Wrapper normalizes both to `{ data, meta }` for consumers. | Adapter logic |
| PM-1 | Type generation: openapi-typescript run against `packages/api-contracts/openapi.yaml`. **Update openapi.yaml first** — add all M2.2 endpoints we built Day 2. | Generated types |
| PM-2 | Smoke test: from a test script in api-client, call legacy `/v2/categories` and new `/v3/categories` via the wrapper, assert both return normalized shape | Tests pass |
| PM-3 | Commit + push | Tag M2.2.2 |

**End of day:** packages/api-client is real. Flipping `'old' → 'new'` for an endpoint is one line.

### Day 4 — Migration day

**Theme:** The actual MySQL → Postgres pump. This is the day the demo becomes real.

| Block | Task | Output |
|---|---|---|
| AM-1 | Write migration scripts in `apps/api/bin/migrate-from-legacy/`: separate files for categories, users, vendors, products, product_images, reviews. Each idempotent (skip if `legacy_*_id` already present in Postgres). | ~6 scripts |
| AM-2 | Dry-run migration into `bayti_v3_staging` schema. Compare row counts to MySQL. Spot-check 50 random products end-to-end (legacy → migrated → readable via /v3/products/:slug). | Migration validated |
| PM-1 | Run live migration into `bayti_v3`. Verify: 9316 users, 9299 vendors (subset of users), 8 categories, 1928 published products + 235 deleted, 27 reviews. | Live data |
| PM-2 | Hit catalog endpoints with curl — should now return real products from real vendors in real categories | Live verification |
| PM-3 | Spot-check legacy customer login: pick a real user, attempt login via `/v3/auth/login` with their bcrypt password (you'll need to know one user's password OR have a friendly test user). | Auth validated |
| PM-4 | Commit migration scripts + push | Tag M2.2.3 |

**End of day:** v3 API has the client's real data. Catalog browse works via curl. Existing customer passwords work.

### Day 5 — apps/web catalog flip

**Theme:** First frontend on v3.

| Block | Task | Output |
|---|---|---|
| AM-1 | Update apps/web's api-config.service.ts: add v3BaseUrl. Keep v2BaseUrl alongside for fallback. | 1 file |
| AM-2 | Refactor apps/web's catalog feature services to go through packages/api-client wrapper (instead of direct HttpClient calls). | 5-8 files in apps/web/src/app/features/ |
| AM-3 | Update ENDPOINT_ROUTING: flip `GET /products`, `GET /products/:slug`, `GET /categories`, `GET /categories/:slug`, `GET /vendors`, `GET /vendors/:slug` to `'new'` | feature-flags.ts updated |
| PM-1 | Local apps/web dev server. Browse: home page, category page (each of 8 categories), product detail (sample 10 products), vendor page. Verify all rendering. | Working dev |
| PM-2 | Fix shape mismatches between v3 response and what apps/web feature components expect (this is where the day either expands or contracts) | Bug fixes |
| PM-3 | Deploy apps/web to staging Cloudflare Workers | Live staging |
| PM-4 | Verify staging.3bayti.ae renders catalog from v3 | Live verification |

**End of day:** staging.3bayti.ae catalog is on v3. Browser DevTools network tab proves it.

### Day 6 — apps/mobile catalog + auth flip

**Theme:** Mobile on v3.

| Block | Task | Output |
|---|---|---|
| AM-1 | Add `packages/api-client` as workspace dep in apps/mobile package.json. Add v3 routing logic. | 1-2 files |
| AM-2 | Replace mobile's `GlobalComponent.*` constants with api-client calls in the key services: AuthService (login, register, OTP), NetworkService usages in catalog pages. | ~10-15 files |
| AM-3 | Mobile app uses `apiClient.request('POST', '/auth/login', body)` instead of `NetworkService.post_request(body, GlobalComponent.UserLogin)`. | Pattern change |
| PM-1 | Update ENDPOINT_ROUTING to flip all auth + catalog browse endpoints to `'new'` | feature-flags.ts updated |
| PM-2 | Build mobile app locally (Ionic serve). Walk through: login, browse categories, view product, view vendor. | Working dev |
| PM-3 | Fix any mobile-specific shape mismatches (Capacitor's HTTP plugin can have quirks vs Angular HttpClient) | Bug fixes |

**End of day:** Mobile dev build talks to v3 for catalog + auth. Native build still pending (Day 9).

### Day 7 — apps/portal catalog + auth flip

**Theme:** Vendor/admin portal on v3.

| Block | Task | Output |
|---|---|---|
| AM-1 | Same pattern as Day 6: integrate api-client into portal, replace `GlobalComponent.*` with `apiClient.request()` in core services | ~10 files |
| AM-2 | Flip auth + admin catalog endpoints (`/v3/admin/categories`, `/v3/admin/vendors`, `/v3/admin/products` — last one ships Day 7 PM if needed) | feature-flags updated |
| AM-3 | Admin-only endpoints we still need: `/v3/admin/products` CRUD (DEFER if too much for the day; portal can use vendor-side endpoints for the demo) | Decision point |
| PM-1 | Build portal locally. Walk through: admin login, see vendor list, see product list, view a product detail. | Working dev |
| PM-2 | Fix shape mismatches. | Bug fixes |
| PM-3 | Decide: do we need a vendor-side `/v3/vendor/products` (separate from admin)? **Recommendation: NO for demo. Show admin view only.** | Scope locked |

**End of day:** Portal dev build talks to v3 for catalog + auth + admin reads.

### Day 8 — End-to-end testing across surfaces

**Theme:** Find what breaks before the client does.

| Block | Task | Output |
|---|---|---|
| AM | Cross-surface test matrix: same user logs in on web AND mobile AND portal. Same product viewed across surfaces shows consistent data. Vendor's products show same across web (public) and portal (admin). | Test report |
| PM-1 | Bug bash on what AM revealed | Bug fixes |
| PM-2 | Performance check: how slow is `/v3/products` paginated against 1928 rows? Add indexes if needed. | Perf OK |
| PM-3 | Audit: which endpoints are still on `'old'` for each app? Document. | Routing-state doc |

**End of day:** Cross-surface validated. Known-good state across all three apps.

### Day 9 — Production deploys

**Theme:** Real production demo environments.

| Block | Task | Output |
|---|---|---|
| AM-1 | apps/web to production Cloudflare Workers (staging.3bayti.ae or whichever domain client demo uses) | Live |
| AM-2 | apps/mobile: TestFlight (iOS) + Play Internal (Android) build with v3 routing. *Requires app store credentials.* | Beta build available |
| AM-3 | apps/portal: deploy to portal staging (whatever hosting is set up) | Live |
| PM-1 | Hit each in the browser/installed-app, verify functionality | Manual test |
| PM-2 | Document known gaps to declare in the demo: "wishlist still on legacy", "cart still on legacy", "vendor self-service still on legacy", etc. | Demo limitations doc |

**End of day:** Production-equivalent environments running v3.

### Day 10 — Demo prep

**Theme:** Polish + dry-run.

| Block | Task | Output |
|---|---|---|
| AM | Dry-run of demo flow start to finish. Take notes on rough edges. | Demo script |
| PM-1 | Polish rough edges. Improve error messages. Add loading spinners where missing. | UI polish |
| PM-2 | Pre-demo runbook: order of operations, talk track, backup plans | Runbook |
| PM-3 | Write the "what's next" doc the client takes away — concrete next-iteration plan | Roadmap excerpt |

**End of day:** Demo-ready.

---

## 7. Scope cuts (explicit non-goals)

These appear in the v2.1 roadmap but are NOT in this 10-day window:

- M3 cart/checkout/orders (no data to migrate, no demo flow includes purchasing)
- M3 wishlist, reviews-write, messaging, support tickets
- M4 chat WebSocket
- M5 image migration (vendor logos stay base64 in legacy DB; placeholders in v3)
- M2 search endpoint (Postgres FTS — demo uses LIKE if any search shown)
- M2 best-sellers, new-arrivals, explore endpoints — DEFER unless quick
- /v3/measurements/template (M1 holdover) — DEFER
- CDC sync layer — one-shot migration, accept drift
- New apps/web pages (designer, best-sellers, new-arrivals, explore, search routes) — DEFER

---

## 8. Risk register

| Risk | Likelihood | Day exposed | Mitigation |
|---|---|---|---|
| Day-4 migration script has off-by-one in size column mapping | Medium | Day 4 | Dry-run schema first; verify 50 random products before live run |
| apps/web feature services tangled, refactor takes longer than 1 day | Medium | Day 5 | Day 5 PM has flex; can defer non-critical pages (designer page) to Day 8 |
| Mobile or portal has hidden API calls we didn't see in GlobalComponent | Medium | Day 6/7 | Grep for any direct `api.3bayti.ae` strings; surface them in initial scan |
| Bcrypt verify fails for some users (encoding edge cases) | Low | Day 4 | Pre-migration: grep for any non-`$2y$10$` passwords; flag for reset |
| App store review for mobile delays beta build | High | Day 9 | TestFlight + Play Internal are usually fast; if stuck, demo from local Ionic serve on iPad |
| Client demo on Day 11+ expects something we excluded | Medium | Day 11 | Day 10 includes "what's next" doc that frames future iterations |
| Day-4 migration runs slowly (9316 users × LONGTEXT) | Medium | Day 4 | Migration is offline; allow 2-3 hours; chunk by 500 rows |
| `/v3/products` paginated query is slow with no indexes | Medium | Day 2-8 | Day 8 dedicated perf check; add Postgres indexes for `vendor_id`, `category_id`, `status`, `(created_at DESC)` |

---

## 9. Decisions locked in this plan

1. Brand entity: parked. Schema stays; not exposed in any frontend.
2. Vendor logos: placeholder in demo. Image migration in M5.
3. Vendor self-service portal pages: NOT migrated in 10 days. Portal demo shows admin view only.
4. Cart/checkout: legacy backend continues serving (mobile still calls `customer/addToCart` etc against api.3bayti.ae).
5. Search: NO search in demo. If demoed, Postgres `ILIKE` fallback.
6. Categories: 8 flat real categories REPLACE our fictional seed tree.
7. Response envelope: `{ data, meta }` mandatory on all v3 catalog endpoints. M2.1.A endpoints retrofitted Day 2 PM.
8. Existing user passwords work without reset (bcrypt-compatible).
9. Product images stay in legacy backend's filesystem; v3 URLs point at api.3bayti.ae.
10. CDC sync: NOT implemented. Accept drift during demo window. Production rollout (post-demo) requires either CDC or a hard cutover plan.

---

## 10. Approval checklist

- [ ] Day-by-day sequencing makes sense
- [ ] Scope cuts (§7) acceptable
- [ ] Risk mitigations (§8) credible
- [ ] Decisions (§9) locked
- [ ] Client demo expectation matches §1

Sign off → start Day 1 work.

---

## 11. What we'll know by end of Day 4

The critical checkpoint. After migration completes, we either:
- ✅ See real data in v3 → Day 5+ continues as planned
- ❌ Migration breaks → we pause and replan with Day 4 as the new Day 1

Day 4 is the "go / no-go" for the rest of the plan. Watch for it.
