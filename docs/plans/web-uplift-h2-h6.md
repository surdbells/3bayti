# Web Storefront Uplift — H-Plan (H0 → H6)

**Created:** Wednesday, June 17, 2026
**Repo:** https://github.com/surdbells/3bayti.git · **Working dir:** `/home/claude/work/3bayti`
**Branch:** `main` · **App:** `apps/web` (Angular, deployed to Cloudflare Pages)
**Last commit at doc creation:** `de8a78d` (H1.4 closure)
**HEAD (latest session):** `df94fca` — **H2 COMPLETE** (A · B · C-1 · C-2); full web suite **747** green. All pushed to `main`.

This is the authoritative plan + handover for the customer-storefront visual/UX
uplift driven by the June QA punch-list. A fresh conversation can resume from
here with full continuity.

---

## 1. Operating rhythm (do not deviate)

- Every phase gets a **detailed sub-phase plan** before its execution (like H1 did).
- Within a phase: **continuous run**, committing each sub-phase.
- **Per commit:** `ng build` exit 0 · relevant `vitest` specs green · visual check
  (desktop + mobile screenshots via Playwright) · **status snapshot** to the user.
- **Before every push:** `git pull --no-edit origin main`.
- Commit messages via `git commit -F /tmp/file` heredoc (**never backticks in `-m`**);
  include WHAT / WHY / WHAT'S NOT INCLUDED + gate results + pattern attribution.
- **Full `vitest` suite** at each phase close (baseline **735 tests / 69 files**).
- No stubs/placeholders; production-ready on commit. Surface mid-flight discoveries
  and reconcile before proceeding (never silently assume).

## 2. Environment / architecture facts

- **apps/web** = customer storefront. Angular (signals + OnPush + @ngx-translate),
  structure `src/app/{core,features,shared,layout}`. Build: `pnpm exec ng build`
  (Angular app builder; outputs `dist/3bayti-web/browser`). Tests: vitest
  (`pnpm exec vitest run [path]`). Pre-existing Sass `@import` deprecation warnings
  are expected (not errors).
- **Deploy:** apps/web → **Cloudflare Pages** at `staging.3bayti.ae` (static bundle +
  a Pages Function BFF at `apps/web/functions/auth-proxy/[[path]].ts`). End goal (H5):
  promote to **`3bayti.ae`** root, replacing the legacy "Sell More" seller app.
- **Backend:** `api-v3.3bayti.ae` (Slim4/Doctrine/PostgreSQL). Auth: BFF cookie +
  in-memory Bearer hybrid. CORS verified correct for the staging origin.
- **⚠ Deploy caveats (live verification still pending):**
  - The **api droplet auto-deploy** (`/usr/local/bin/3bayti-deploy.sh`) was confirmed
    **NOT firing** — any `apps/api` change (H0.1, and H2.A/H5 below) needs a manual
    trigger/check before it is live.
  - The **Cloudflare Pages** build for H0.2 + H1 must be confirmed landed; then re-run
    the Playwright checks against `staging.3bayti.ae`.
- **Playwright** (post-deploy verification + local screenshots): chromium at
  `/opt/pw-browsers` (`PLAYWRIGHT_BROWSERS_PATH`); import playwright by absolute path
  `node_modules/.pnpm/playwright@1.50.0/node_modules/playwright/index.mjs`. To screenshot
  locally: `cd dist/3bayti-web/browser && python3 -m http.server <port>` then goto
  `http://localhost:<port>/` (carousel/API data won't load locally, but chrome/layout
  render). Real-account repro creds live OUTSIDE the repo at `/home/claude/_cart_repro.mjs`.

## 3. Status

| Phase | Item(s) | Status | Commit |
|---|---|---|---|
| H0.1 | stores display fallback | ✅ code (live pending api deploy) | `83ebd65` |
| H0.2 | cart disappears on login | ✅ code (live pending Pages) | `ea7bf41` |
| H1.1 | logo system | ✅ | `af7d77e` |
| H1.2 | hero background (#1) | ✅ | `a31c001` |
| H1.3 | nav (#5) | ✅ | `100c02c` |
| H1.4 | header CTAs (#11) | ✅ | `de8a78d` |
| H2.A | api: /v3/vendors paginate + q + embed products | ✅ | `df1c86a` |
| H2.B | directory: rich 3-per-row store cards | ✅ | `75709fe` |
| H2.C-1 | SearchService (products + stores data layer) | ✅ | `fa7cb05` |
| H2.C-2 | search overlay UI + header trigger + i18n | ✅ | `df94fca` |
| H2.D | H2 closure (full web suite 747 green) | ✅ | this doc |
| H3 | PDP overhaul (incl. #4 empty reviews) | ⬜ next | — |
| H4 | auth & cart | ⬜ | — |
| H5 | vendor-welcome merge + root promotion prep | ⬜ | — |
| H6 | gift-cards + responsive sweep | ⬜ | — |

### Key learnings carried forward
- **H0.2 root cause (fixed):** `applyAuthState()` called `syncLocale()` which threw on
  an undefined locale → bubbled to `hydrate()` catch → wiped the just-established
  session. Fixed in the BFF (`/auth-proxy/me` was double-nesting the user) + the
  `syncLocale` null-guard + a try/catch around `syncLocale` in `applyAuthState`.
- **Logo system (H1.1):** source art is single-colour on transparent/black; rendered
  as **CSS `mask-image`** via a global `.brand-logo` / `--mark` / `--wordmark` in
  `styles.scss` (Angular scopes component styles, so the logo lives globally),
  recoloured per surface. Masks at `public/icons/logo-{mark,wordmark}.png`.
- **Header spec keys on testids**, not labels/order: keep `header-customer`,
  `header-vendor`, `nav-*` testids and the spec stays green through restyles.
- **H1.4 left the "Sell on 3bayti" CTA pointing external** (`app.3bayti.ae`);
  **H5.B re-points it in-app** and must then update the header spec's vendor-href
  assertion (currently asserts it contains `app.3bayti.ae`).

---

## 4. Phase plans (H2 → H6)

Each phase: detailed plan + decision box at execution, then continuous run.

### H2 — Stores experience + global search
*Thin store cards (logo/name only), no pagination, no site-wide search.*
- **H2.A** `apps/api`: embed top-5 products + product count in the store-card payload
  (extend the featured/list-vendors serializer; reuse H0.1 verified-fallback) + tests.
  (Verify first what the featured-vendors endpoint already embeds — X.2 embedded
  top-N products; H0.1 bumped the card to 5.)
- **H2.B** Stores listing page: responsive **3-per-row** grid + **load-more**; card =
  logo/name/description + 5-product preview + "Visit store".
- **H2.C — global header search** (the affordance the nav defers to). **Locked
  decisions:** icon-triggered overlay (not an inline bar) · typeahead with grouped
  results (no separate results page this pass) · per-group cap 6.
  - **part 1 ✅ (`fa7cb05`)** `features/search/search.service.ts` —
    `SearchService.search(query, limit=6): Promise<{products: Product[], stores: DirectoryStore[]}>`,
    parallel `GET /products?q=` + `GET /vendors?q=`, blank query short-circuits, q trimmed.
    Spec: 4 tests. No consumer yet.
  - **part 2 ⬜ (NEXT) — build to the quality bar:**
    1. `features/search/search-overlay.ts` (standalone, OnPush, e.g. `app-search-overlay`).
       Open state + close event (or a service-driven open signal). Behaviour: autofocus input
       on open; debounce ~250ms (signal + setTimeout, cleared each keystroke); call
       `SearchService.search(q)`; **guard stale responses** (track latest query / a request id,
       ignore older resolutions); render TWO groups — **Stores** (row: `logo_url` thumb + name +
       rating chip when `rating_count > 0`, links via the same URL the StoreCard uses) and
       **Products** (row: primary image + name + price + link to the product detail route);
       states: idle prompt, loading, empty ("no results for X"); close on Esc + backdrop;
       a11y: `role="dialog"` + `aria-modal`, input `role="combobox"` with
       `aria-expanded`/`aria-controls`, results `role="listbox"` + `role="option"`, focus
       returns to the trigger on close; RTL via existing dir handling (logical CSS props).
       Arrow-key option nav is a nice-to-have; Esc + click is the floor.
    2. **Header trigger** — search icon button in `layout/header/header.html` header-actions
       (mirror the cart/locale icon buttons; add a glyph to `layout/header/nav-icon.ts` or reuse
       one), open/close state in `header.ts`, styled in `header.scss` to match the other icon
       buttons, hosting `<app-search-overlay>` (testid `header-search`). Keep icon-only <560px
       (header already collapses actions there — see H1.4).
    3. **i18n** — `search.*` keys in BOTH `assets/i18n/en.json` + `ar.json` (placeholder, stores,
       products, noResults, viewAll* if used). AR matches the feminine register used elsewhere.
    4. **Tests** — `search-overlay.spec.ts` (typing triggers a debounced search + renders both
       groups; empty state; Esc closes; a11y roles present) + extend `header.spec.ts` (opening
       shows the overlay — keep it keyed on testids).
  - **READ FIRST for part 2:** `features/catalog/product.model.ts` (Product fields for product
    rows) + the product-detail route (in `app.routes.ts`) for the link target.
- **H2.D** Closure: full `vitest` (expect ~739+), update this status table, re-confirm the
  api-deploy + Pages-deploy caveats for the H2 changes.
- **Backend dependency:** H2.A is live only once the api auto-deploy caveat (§2) is resolved;
  the search overlay returns results only against the deployed `/v3/products` + `/v3/vendors`.

### H3 — PDP overhaul
*#4 empty-reviews copy + overall product-detail quality. Component:
`features/catalog/product-detail*`.*
- **H3.A** Layout + gallery (thumbs/zoom), price (multi-currency in api), vendor link,
  size/color selectors.
- **H3.B** Add-to-cart (variant + qty, optimistic; rides on H0.2 cart fixes).
- **H3.C** Reviews: summary + list + **proper empty state** (inviting copy, never blank).
- **H3.D** Recommendations strip (`/v3/products/{slug}/recommendations`, X.12).
- **H3.E** Closure.
- *Decisions:* reviews **write** vs read-only+empty-state · gallery zoom depth · variant↔model.

### H4 — Auth & cart
*Remaining auth/cart UX after H0.2's fix. Pre-flight the cart + auth components first.*
- **H4.A** Cart page UX (line items, qty/remove, totals, **promo field** via X.8 quote,
  empty state, recs).
- **H4.B** Auth flows polish (login/register/OTP/reset — validation, errors, i18n, a11y).
- **H4.C** Verify-phone / session consistency (badge + checkout gate).
- **H4.D** Closure.
- *Decisions:* mini-cart drawer vs page-only · promo UI · which auth flows need work.

### H5 — Vendor-welcome merge + root-promotion prep
*"Sell on 3bayti" should land in-app; promote apps/web to `3bayti.ae`, replacing the
legacy seller app.*
- **H5.A** In-app vendor-welcome `/sell`: merge legacy "Sell More" content → value prop +
  how-it-works + CTA to seller onboarding.
- **H5.B** **Re-point the Sell CTAs** (header/drawer/footer) from `app.3bayti.ae` → in-app
  `/sell`; **update the header spec vendor-href assertion**.
- **H5.C** Root-promotion prep (routing / `_redirects` / canonical / SEO; legacy handoff
  plan — the DNS flip itself is an ops step).
- **H5.D** Closure.
- *Decisions:* Sell-CTA target (existing vendor onboarding vs new seller-register) · flip
  DNS this pass or prep-only · legacy retirement timing. **Backend touch possible → api caveat.**

### H6 — Gift-cards + responsive sweep
*Gift-card polish + full responsive/RTL QA across everything built. Components:
`features/gift-cards/*`.*
- **H6.A** Gift-cards: landing + purchase/redeem polish.
- **H6.B** **Responsive sweep**: home, stores, PDP, cart, checkout, account, auth, sell,
  gift-cards at mobile/tablet/desktop + RTL; fix overflows, spacing, tap targets.
- **H6.C** a11y + final polish (axe, focus order, contrast).
- **H6.D** Closure + final web-uplift runbook.
- *Decisions:* gift-card specifics (confirm what's broken) · breakpoint targets.

---

## 5. Next action
**H2 is complete.** Resume at **H3 — PDP overhaul** (`features/catalog/product-detail*`),
per the H3 breakdown in §4. As always: detailed plan + decision box first, then
per-sub-phase commits with `git pull --no-edit origin main` before each push and a
status snapshot after each commit.

**Web test baseline is now 747 tests / 71 files** (was 735/69: +SearchService 4,
+SearchOverlay 7, +header 2, −1 directory verified-tile test folded into the card-link
test).

**Latest session log:** completed **all of H2** — H2.A (`df1c86a`), H2.B (`75709fe`),
H2.C part 1 (`fa7cb05`), H2.C part 2 (`df94fca`) — on top of H1, plus this plan doc.
All pushed to `main`. Standing caveats (§2) unchanged: the api auto-deploy must be
triggered for H2.A to be live, and the H2.B directory + H2.C search render real data
only against the deployed `/v3/vendors` + `/v3/products`; a Pages build + Playwright
pass against staging is still pending for H1/H2.
