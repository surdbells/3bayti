# M2 v3 Migration — Status One-Pager

**As of:** Day 7 of M2 rollout (May 13, 2026)
**Audience:** Demo audience, project stakeholders, future contributors

Use this when an audience member asks "what's actually done and what isn't."
Be upfront. Pre-emptive honesty deflates "gotcha" questions.

---

## ✅ DONE in M2

### Infrastructure
- **v3 API live** at `api-v3.3bayti.ae` (Slim 4 + Doctrine + PostgreSQL 18)
- **53 endpoints** implemented and unit-tested across categories, products, vendors, auth, account, sitemap
- **packages/api-client** routing layer with feature-flag table; flip endpoints between v3 and legacy by editing one constant
- **CI on three apps**: web (with deploy to Cloudflare Workers), mobile (build only), portal (build only)
- **pnpm workspace** consolidated; one lockfile, one resolution mechanism

### Data
- **9,330 users** migrated from legacy MariaDB to PostgreSQL with bcrypt passwords preserved (zero password resets needed)
- **104 vendors** migrated including base info, slugs, descriptions
- **2,160 products** migrated (1,923 active, 235 soft-deleted, 2 draft) with prices, sizes, colors, images
- **8 categories** with display order preserved
- **27 reviews** (vendor-attached)
- **Re-runnable migration scripts** (UPSERT-keyed by legacy_*_id) so the migration can re-sync after every change to the legacy DB until cutover

### Web app (apps/web)
- **Catalog reads on v3** for home page, category index, product detail (`/products`, `/products/:slug`, `/categories`)
- **Auth on v3** for login, register (apps/web's existing implementation routes through RoutedHttpClient)
- **Strangler-fig flip mechanism** working end-to-end: per-endpoint routing, per-base-URL discovery
- **SSR + TransferState** preserved through the migration; SEO unaffected
- **Sitemap consistency** with the prerender pipeline; URLs in sitemap.xml match what the SSR serves
- **Drift-proof CI checks** — picks slugs dynamically from build output, doesn't break on data renames
- **Deployed to** staging.3bayti.ae via Cloudflare Workers

### Operational
- Day-by-day completion docs in `docs/runbooks/`
- Demo runbook ready (Phase 7.C)
- Architecture diagram ready (Phase 7.D)
- Live verification commands documented
- Rollback procedure documented: one-line edit to feature-flags.ts, ~3min CI redeploy

---

## ⏸️ DEFERRED to M3 (and beyond)

### Endpoint parity gaps in v3
| Endpoint | Why deferred | Workaround |
|---|---|---|
| `GET /v3/categories/:slug` (embedded products + meta) | v3 returns only category metadata; legacy v2 has embedded products list. | apps/web routes this one endpoint to legacy v2 via ENDPOINT_ROUTING. |
| `GET /v3/featured-vendors` | Endpoint not built (returns 500); needs curated-vendors-with-nested-products logic. | apps/web's Designer Spotlight strip stays on legacy v2. |

### Other apps
| App | What's deferred | Impact |
|---|---|---|
| `apps/mobile` | Catalog + auth + cart endpoint flip | Mobile still hits legacy v1. ~37 files × 123 NetworkService invocations to migrate. App stores re-submission needed after. |
| `apps/portal` | Catalog + admin endpoint flip | Vendor admin still hits legacy v1. Angular 19 (older than web's 21). |

### Data quality issues
| Issue | Severity | Status |
|---|---|---|
| 36 users with email conflicts (renamed to `email+legacy{ID}@domain`) | They cannot log in until manually merged | In `migration_email_conflicts` table; M3 reset campaign |
| 67 of 100 vendors have HTML entities in their description | Cosmetic — currently rendered nowhere | M3 cleanup; see `deferred-vendor-description-cleanup.md` |
| 2 vendors with synthetic names (`Store - {email-prefix}`) | Cosmetic | Manual cleanup post-demo |
| Vendor logos NULL (base64 in `legacy_logo_data_url`) | Vendor pages would show placeholder | M3 image migration step |
| Some Arabic vendor names → fallback slugs (`vendor-3427`) | Cosmetic, slug stable | Proper Arabic-to-Latin transliteration is M3 |

### Routes not built
| Route | Where | Workaround |
|---|---|---|
| `/designer` (index) | apps/web | 404. Sitemap doesn't list this route. |
| `/designer/:slug` | apps/web | 404. Sitemap lists 104 such URLs — search engines crawl, find 404, downgrade. **Should be removed from sitemap or routes built.** |

### Reconciliation
| Item | Why deferred |
|---|---|
| Legacy MariaDB deletions don't propagate to v3 | M3 needs `reconcile-deletes.php` for the final cutover |
| Vendor product counts ≠ migrated product counts (some orphans skipped) | Acceptable for demo (6 orphans of 2,166); resolve in M3 |

---

## 🎯 What this proves at the demo

> **"We can route any endpoint from any client to v3 or legacy independently, with rollback in 3 minutes."**

That's the M2 win. Not "everything is on v3" — but "we have the infrastructure to flip everything to v3, safely, one endpoint at a time, with rollback."

For a multi-app SaaS with 9,330 users, this is the right outcome. Big-bang migrations break in production. Strangler-fig migrations get faster as confidence grows. M3 is incremental endpoint flips — each a few hours of work — until 100% of traffic is on v3.

---

## Quantitative summary

- **v3 endpoints implemented:** 53
- **v3 endpoints actively serving traffic:** ~10 (catalog reads from apps/web)
- **Endpoints still on legacy:** ~150+ (mobile, portal, cart, checkout, orders, tickets, chat, vendor dashboard, admin, etc.)
- **% of API surface on v3:** ~25%
- **% of WEB traffic going through v3:** ~80% (catalog reads dominate)
- **Days remaining until demo:** 3
- **Demo readiness:** confident

---

## How to read this list at the demo

If someone asks "what's the % done?" — answer **infrastructure is 100% done; endpoint coverage is 25%; web traffic coverage is 80%.** All three are true and tell different stories.

If someone says "you didn't do mobile?" — answer **"we did the workspace + CI infrastructure for mobile on Day 6 so M3 can do the endpoint flip safely. Mobile changes need App Store re-submission, which has its own cycle outside this rollout. M3 starts the mobile endpoint flip."**

If someone says "is it ready for production?" — answer **"the strangler-fig flip is production-ready; catalog reads on staging.3bayti.ae have been serving real client data since Day 5 without a regression. Cutover to production is a DNS swap (`3bayti.ae` → Cloudflare Workers) we'd do after a full smoke test."**
