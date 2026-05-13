# M3 Backlog — Post-Demo Work

**Last updated:** Day 8 of M2 rollout (May 14, 2026)
**Purpose:** Single source of truth for every item deferred from M2 to M3. Consolidates the scattered followups across Days 4-8.

## How this list is organized

- **🔴 Critical** — must ship in M3 for the product to function correctly across all surfaces
- **🟡 Important** — ships in M3 but not blocking; can be sequenced after Critical
- **🟢 Polish** — quality-of-life or cosmetic; can defer further if M3 timeline tightens

Each item links back to the day's completion doc where it was discovered.

---

## 🔴 Critical (must ship in M3)

### MOBILE-FLIP — apps/mobile catalog + auth + cart endpoint migration

**Source:** Day 7 completion
**Scope:** ~37 files in apps/mobile/src using NetworkService + ~123 invocations
**Why critical:** Mobile is one of three customer-facing surfaces. M3 needs to bring it onto v3 so the database becomes the source of truth across all clients.
**Estimated:** 5-7 days
**Key gotchas (from Day 7 audit):**
- v3 only has 2 of mobile's 9 auth endpoints (login, register); reset/OTP flows need v3-side endpoint work first
- Mobile's `/customer/*` endpoints have custom response shapes nothing in v3 mirrors — needs either a NetworkService-level shape adapter, OR per-file rewrites
- Native re-publish to App Store + Play Store has its own review cycle

### PORTAL-FLIP — apps/portal catalog + admin endpoint migration

**Source:** Day 8 audit
**Scope:** ~61 files using CrudService + ~97 endpoint constants in portal's GlobalComponent
**Why critical:** Portal is the vendor admin surface; vendors need it on v3 for the rollout to be considered complete.
**Estimated:** 7-10 days (larger than mobile due to v3 admin endpoint gaps)
**Key gotchas (from Day 8 audit):**
- v3 has barely 3-4% of portal's admin endpoints (only `/admin/brands`, `/admin/vendors`, `/admin/categories`)
- Most needed admin endpoints (orders, users, commissions, products, notifications, tickets) return 500 — not built
- Need to BUILD the v3 admin endpoints before flipping portal

### V3-FEATURED-VENDORS — Build `/v3/featured-vendors` endpoint

**Source:** Day 5 (apps/web Designer Spotlight strip)
**Currently:** Returns 500. apps/web's home page Designer Spotlight strip uses legacy v2 via ENDPOINT_ROUTING.
**Required:** Curated vendor list with embedded top-N products per vendor
**Estimated:** 1-2 days
**Notes:** Once shipped, flip `'GET /featured-vendors'` to `target: 'new'` in feature-flags.ts.

### V3-CATEGORIES-DETAIL — Add embedded products + meta to `/v3/categories/:slug`

**Source:** Day 5 (apps/web category-detail page)
**Currently:** Returns only category metadata. apps/web routes this endpoint to legacy v2 because it needs `data.products` array and `meta.total_products` + `meta.page_size`.
**Required options:**
1. Augment endpoint to include products + meta (matches v2 shape, 1-day work)
2. OR refactor apps/web's CategoryDetailComponent to fetch products separately via `/v3/products?category_slug=:slug`
**Estimated:** 1 day option 1; 0.5 day option 2 (with risk of v3 shape drift)
**Notes:** Once shipped, flip `'GET /categories/:slug'` to `target: 'new'`.

### EMAIL-CONFLICTS — Reset campaign for 36 conflict-renamed users

**Source:** Day 4 (migration step)
**Currently:** 36 users have emails suffixed (`+legacy{ID}@domain`) in `migration_email_conflicts` table. They cannot log in until manually merged.
**Required:** Email/SMS campaign with one-click reset links
**Estimated:** 1 day backend, 0.5 day frontend, plus marketing copy
**Impact:** 0.4% of user base (36 of 9,330)
**Notes:** Defer until immediately before legacy retirement so users don't re-conflict in subsequent re-syncs.

### RECONCILE-DELETES — Build `reconcile-deletes.php` for final cutover

**Source:** Day 4 (migration framework)
**Currently:** UPSERT-based migration scripts handle new/updated rows but NOT deletes. Rows deleted from legacy MariaDB stay in v3 PostgreSQL.
**Required:** A separate script to be run pre-cutover that finds v3 rows with `legacy_*_id` NOT in legacy's current row set, soft-deletes them.
**Estimated:** 1 day
**Notes:** This is THE script that enables the legacy-to-v3 cutover. Must be tested against a snapshot before running on production.

---

## 🟡 Important (ships in M3 but flexible sequencing)

### V3-ADMIN-ENDPOINTS — Build out admin endpoint surface

**Source:** Day 8 (portal audit)
**Currently:** ~3 admin endpoints exist (brands, vendors, categories). Need ~30+ for portal parity.
**Estimated:** 5-7 days
**Notes:** Prerequisite for PORTAL-FLIP. Can be sequenced as small endpoint batches (e.g. day 1: orders + users; day 2: commissions + processing; etc.)

### DESIGNER-ROUTES — Build `/designer` + `/designer/:slug` in apps/web

**Source:** Day 7 (sitemap stripping)
**Currently:** 104 vendor URLs were stripped from sitemap because routes don't exist. Vendors are not browsable by URL on apps/web.
**Required:** Two Angular routes + components (vendor index + vendor detail)
**Estimated:** 2-3 days
**Notes:** Once shipped, restore the vendor loop in `apps/web/scripts/generate-sitemap.mjs` (clearly documented in the comment block where it was removed).

### IMAGE-MIGRATION — Convert legacy base64 vendor logos to hosted URLs

**Source:** Day 4 (migration carry-over)
**Currently:** `vendor.logo_url` is NULL; the base64 data sits in `legacy_logo_data_url`. Vendor pages show placeholder.
**Required:** Extract base64 → upload to CDN (Cloudflare R2 or similar) → write hosted URL back to `logo_url`
**Estimated:** 2 days (script + manual review of edge cases like SVG/animated)
**Affected:** ~80 vendors (rough estimate from sampling)

### VENDOR-DESC-CLEANUP — Decode HTML entities in 67 vendor descriptions

**Source:** Day 7 (audited but deferred)
**Currently:** 67 of 104 vendor descriptions have HTML entities (`&amp;`, `&#1605;`, etc.) from legacy WordPress storage.
**Required:** Decision tree first (allow HTML / plain text only / sanitize), then bulk decode script
**Estimated:** 1 day (most time is in pre-decision; script itself is 2-3 hours)
**Notes:** See `docs/runbooks/deferred-vendor-description-cleanup.md` for full audit findings + recommended approach.

### ARABIC-SLUGS — Proper Arabic-to-Latin transliteration

**Source:** Day 4
**Currently:** Vendors with pure-Arabic names get fallback slugs like `vendor-3427`. Stable, but ugly.
**Required:** Integrate a transliteration library (e.g. `transliteration` npm package) and regenerate slugs
**Estimated:** 0.5 day + manual review of edge cases
**Affected:** ~5 vendors based on Day 4 migration log

---

## 🟢 Polish (defer further if needed)

### TS-STRICTNESS-PROMOTE — Move `noPropertyAccessFromIndexSignature` to base.json

**Source:** Day 7 (after enabling per-package in api-client)
**Currently:** Flag is on for `packages/api-client` only. Other three packages (`api-contracts`, `design-tokens`, `shared-ui`) extend the same `base.json` but don't have the flag.
**Required:** Audit each of the three for violations, fix any, then promote flag to `base.json`.
**Estimated:** 0.5 day
**Notes:** Doesn't unlock any new behavior; just hardens against future bugs.

### NODE-MODULES-CLEANUP — Document the local node_modules step for mobile/portal

**Source:** Day 6
**Currently:** Day 6 docs mention Sodiq needs `rm -rf apps/{mobile,portal}/node_modules && pnpm install` after pulling Day 6. Should also be in root README.
**Estimated:** 15 min
**Notes:** Trivial doc update.

### SYNTHETIC-VENDOR-RENAMES — Manually replace `Store - {email}` synthetic names

**Source:** Day 4
**Currently:** 3 vendors with placeholder names like `Store - Ahmedayme2020`. Stable slugs (`store-ahmedayme2020`) preserve any future SEO.
**Required:** Contact each vendor for their real preferred store name + update via SQL
**Estimated:** 0.5 day (mostly waiting on vendor responses)

### PNPM-LOCKFILE-CLEANUP — Remove stale workspace entries

**Source:** Day 6
**Currently:** May have leftover workspace entries in `pnpm-lock.yaml` from before mobile/portal were renamed.
**Estimated:** 15 min — run `pnpm install` after deleting `node_modules`, verify lockfile is clean

### COSMETIC — Vendor `Ether & Moon` description HTML entity

**Source:** Day 7
**Currently:** Description has `&amp;` and `&#8217;` entities; rendered nowhere yet
**Subset of:** VENDOR-DESC-CLEANUP above
**Estimated:** Covered by parent item

---

## ❓ Open questions for M3 kickoff

1. **What's the M3 timeline?** Best guess: 4-6 weeks. Driven by mobile + portal flips + admin endpoint build.

2. **When does legacy retirement happen?** Suggested: 2-4 weeks AFTER M3 complete. Provides backout buffer if M3 surfaces unexpected production issues.

3. **Who owns the v3 admin endpoint build?** Sodiq or a hired backend dev? Affects timeline.

4. **Do we need a staging copy of legacy MariaDB?** For testing the reconcile-deletes script without risking production data.

5. **App Store / Play Store re-submission timing for mobile?** Both have multi-day review cycles; affects mobile rollout schedule.

---

## Day 9 Cutover Checklist (preview)

This isn't M3 work — it's the final piece of M2. Listed here so we don't forget it on Day 9.

### Morning of Day 9 (testing day)

- [ ] End-to-end smoke test of every URL in `docs/runbooks/demo-smoke-test-evidence.md` — confirm all still 200 with expected markers
- [ ] Re-run performance baseline — compare against Day 8 numbers, flag any regressions > 50%
- [ ] Verify slug fix is still applied (`/v3/vendors/ether-and-moon` returns 200)
- [ ] Verify sitemap is honest (no `/designer/` URLs, total count ~1933)
- [ ] DB row count check — counts match Day 8 evidence

### Pre-demo (Day 10 morning)

- [ ] Run the slug-fix SQL one more time (idempotent; no-op if already applied)
- [ ] Pre-warm Cloudflare edge cache via the warm-up script in `performance-baseline.md`
- [ ] Confirm staging.3bayti.ae returns 200 in < 200ms warm
- [ ] Have all `docs/runbooks/*.md` files open in browser tabs
- [ ] Replace `REPLACE_BEFORE_DEMO` in `demo-script.md` with the actual test password
- [ ] Run `pnpm install` locally if anything changed in workspace deps

### Demo (Day 10)

- Follow `docs/runbooks/demo-script.md` exactly
- Use `docs/runbooks/architecture-diagram.md` as the Part 1 visual
- Use `docs/runbooks/m2-rollout-status.md` as the Part 5 honest-state visual
- If asked about specific data, reference `docs/runbooks/db-evidence.md`

### Post-demo

- [ ] Don't deploy anything on demo day after the demo. Wait until next morning.
- [ ] Capture any audience questions that weren't fully answered → add to this backlog
- [ ] Schedule M3 kickoff within a week
