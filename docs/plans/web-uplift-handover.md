# 3bayti Web Storefront Uplift — Handover

**Created:** Thursday, June 18, 2026
**Last commit:** `c8e0797` (fix(web): logged-in add-to-cart authoritative reload)
**Repository:** https://github.com/surdbells/3bayti.git
**Working dir:** `/home/claude/work/3bayti`
**App under work:** `apps/web` (Angular customer storefront)
**Working tree:** clean

This is everything a fresh conversation needs to continue the **web storefront
uplift** (the customer-facing `apps/web`). The immediate next task is
**completing gift cards**. Read this top to bottom, verify state, then pre-flight
the gift-cards feature before planning.

> NOTE on the API stream: `docs/plans/stream-y-handover.md` is a *separate*
> handover for the Slim/Doctrine **API** work (Stream Y). This document is only
> about the Angular web app.

---

## 1. Operator preferences (carried forward — do not deviate)

From the user's `<userPreferences>`. Internalize before planning or executing.

- **Never assume.** Ask for clarification on any unclear requirement before proceeding.
- **Every task begins with a structured Implementation Plan**; request approval before coding.
- **Break work into phases + sub-phases**; once a phase is approved, run its sub-phases as
  one continuous run until done or context limits hit.
- **No stubs/placeholders** — production-ready, fully integrated, scalable, maintainable, clean.
- **Premium UI/UX** — modern, responsive, accessible, consistent, polished.
- Clean, documented code; proper validation/error-handling/logging/security; no tech debt.
- Think like a senior architect; long-term stability over quick fixes; surface issues early.
- **Stage-gate review against the plan doc** — re-read the plan before/while executing.
- **Status snapshot of phases/sub-phases after every commit.**
- **Comprehensive handover docs** when the chat grows long.
- **Zero regressions, no compromises on quality, never sacrifice quality for speed.**

Approval shorthand the operator uses: short phrases ("Approved. All recommended.",
"Proceed", "continue", "keep going here", "begin here"). They communicate tersely and
expect momentum, but still want decisions surfaced (not silently assumed).

---

## 2. Repository state + toolchain

- **Branch:** `main` · **HEAD:** `c8e0797` · **tree:** clean.
- **Test baseline (web):** ~**767 tests / 74 files** green (the logged-in-cart fix modified
  existing specs; no net file change). Verify with the command below before trusting it.

### Build / test / type-check commands (IMPORTANT — use these exact ones)
```bash
cd /home/claude/work/3bayti/apps/web

# Build — use the DIRECT binary. `pnpm exec ng build` FAILS (it trips on the web
# package.json "packageManager": "npm@..." field and exits 1 with one line).
node_modules/.bin/ng build

# Tests (vitest via @angular/build:unit-test)
node_modules/.bin/vitest run                         # full suite
node_modules/.bin/vitest run src/app/path/to.spec.ts # one file

# Type-check
node_modules/.bin/tsc --noEmit -p tsconfig.app.json
```
- **Install** is fine with pnpm: `corepack` → pnpm 9.15.0, `pnpm install`.
- Expected non-fatal noise in passing tests: jsdom `Could not parse CSS`,
  `[RoutedHttpClient] … failed 4xx/5xx` log lines. Pre-existing `ng build` warnings:
  NG8113 unused-import in a few legacy components + Sass `@import` deprecations.

### Push from container (SECURE — do NOT hardcode a token anywhere)
The repo's GitHub PAT-in-URL approach has caused two burned tokens; **never embed a
PAT in the git remote URL and never commit one to a file (including this doc).** The
git remote is kept clean: `https://github.com/surdbells/3bayti.git` (no token).

Set up auth ONE of these ways (operator does this once on the box/container):
- **SSH (preferred):** add a deploy key / your SSH key to GitHub, then
  `git remote set-url origin git@github.com:surdbells/3bayti.git` and push over SSH.
- **`gh` CLI:** `gh auth login` (HTTPS, token stored in the system keychain, not the repo).
- **Git credential helper:** `git config --global credential.helper store` (or `cache`),
  authenticate once; the token lives outside the repo.

If a fresh container genuinely has none of the above and a token must be used for a
one-off push, use it **inline and unpersisted** (never `set-url`):
`git push https://<TOKEN>@github.com/surdbells/3bayti.git HEAD:main` — then rotate that
token immediately afterward, because it was exposed on the command line.

**Claude's commit/push discipline here:** commit locally with full gate checks, then
push. If push auth isn't available, produce a `git format-patch origin/main..HEAD -o
/mnt/user-data/outputs/` series for the operator to `git am` + push. Pushing to `main`
fires the Cloudflare Pages build (the staging deploy).

---

## 3. Architecture + deploy

- **apps/web** — Angular (standalone components, **signals + OnPush**, `@ngx-translate`).
  Structure `src/app/{core,features,shared,layout}`. Routes in `src/app/app.routes.ts`.
- **Deploy:** apps/web → **Cloudflare Pages** at `staging.3bayti.ae` (static bundle + a Pages
  Function BFF at `apps/web/functions/auth-proxy/[[path]].ts`). End goal (H5.C, not done):
  promote to the `3bayti.ae` root, replacing the legacy "Sell More" seller app.
- **API:** `api-v3.3bayti.ae` (Slim4/Doctrine/PostgreSQL). Public catalog reads go through
  `RoutedHttpClient` (route KEYS like `'GET /products'`, `'GET /vendors'` mapped by
  ENDPOINT_ROUTING). The **cart/auth** use the raw `HttpClient` against
  `https://api-v3.3bayti.ae/v3/...` directly (auth = BFF cookie + in-memory Bearer hybrid).
- **⚠ Standing deploy caveat:** the API droplet auto-deploy (`/usr/local/bin/3bayti-deploy.sh`)
  was confirmed **NOT firing**. Any `apps/api` change (e.g. H2.A pagination, H0.1
  featured-vendor fallback) is live only after a **manual** api deploy. Several web symptoms
  below are gated on this, not on frontend bugs.
- **Design tokens** (`apps/web/src/styles.scss :root`): `--color-brand-50…700`
  (gold `#b18f1f` / espresso `#5a3a2c` / cream `#f9f4ea` …), `--color-text-primary #2e241c`,
  `--color-bg-canvas/muted`, `--color-border-default`, space-2xs…2xl, radius-sm…pill,
  shadow-card-*, **page-max-width 1280 / padding-x 24(48)**. Headings: 'Playfair Display'.
  Header is LIGHT (#fff). **Component CSS budget: 17kB warn / 18kB error** (raised from 16
  for the feature-rich PDP).

---

## 4. Gotchas + patterns catalog (hard-won — reuse / respect these)

| Gotcha / pattern | Detail |
|---|---|
| **Build command** | `node_modules/.bin/ng build`. `pnpm exec ng build` fails on the `packageManager` field. |
| **Signal `input()` in tests** | The vitest `@angular/build:unit-test` builder does NOT register signal `input()`/`output()` — `setInput` + host-binding throw NG0303. **Tested components use `@Input()`/`@Output()` decorators** (see `gift-card-visual.ts`, `filter-bar.ts`, `search-overlay.ts`). `componentRef.setInput` works for decorator inputs. |
| **i18n in specs** | Pair `provideI18n()` with `provideHttpClient()` + `provideHttpClientTesting()` so the `/i18n/en.json` XHR is intercepted; otherwise unhandled promise rejections. Translations live at `apps/web/public/i18n/{en,ar}.json` (raw unicode; AR uses a feminine register, e.g. `header.sell` = "بيعي على 3bayti"). |
| **Custom-element host `display`** | A component host (`<ui-store-card>`, etc.) defaults to `display:inline`. It auto-blockifies when it's a *direct* grid/flex item, but NOT when wrapped in another element. If a card renders fine in one place and collapses ("1 item, partial") in another, set `:host{display:block;height:100%}`. (This was the `/stores` directory bug.) |
| **Cart mutations → authoritative reload** | Authenticated `CartService.addItem`/`updateQty` must, after the POST/PATCH, **reload via `GET /v3/cart`** and set the signal from that — the mutation response is not a reliable full cart and left the drawer empty. `removeItem` already did this; all three are now consistent. |
| **Cart dual-backing** | Guest cart = localStorage; auth cart = `/v3/cart`. Sign-in merges guest→server via `POST /v3/cart/merge` (idempotent, self-healing on `refresh()`); logout drops to empty guest cart (does not seed from server). |
| **Listing/PDP tabs duplication** | Search overlay (#6) and PDP (#10e) both implement a tab strip inline. A shared `shared/ui/tab-group` was deferred — extract it if touching both. |
| **Doctrine/api note** | (API side) `directoryShape` on `VendorSerializer` is a publicShape superset (id/slug/name/desc/logo/cover/is_verified + rating + products); `/v3/vendors` paginates with `?limit&offset&q` (H2.A). Live only after the api deploy. |

---

## 5. What shipped (web uplift to date)

All committed + pushed to `main`. Reference plan: `docs/plans/web-uplift-h2-h6.md`.

- **H1 — chrome/nav/logo/CTAs.** Logo system (CSS-mask mark+wordmark), richer hero gradient,
  nav (Categories · Stores · New In · Best Sellers · Gift Cards), header CTAs ("Sign in",
  "Sell on 3bayti"). *Header mark icon later removed (QA #9) — wordmark only.*
- **H2 — stores experience + global search.** `/v3/vendors` paginate + `?q=` + embedded
  products (`directoryShape`, **API — needs deploy**); directory rich 3-per-row store cards;
  `SearchService` (parallel products+stores); global header search overlay.
- **H3 — PDP #4 empty-reviews.** Reviews section always renders, with an inviting read-only
  empty state. (Pre-flight found the PDP was already substantially built.)
- **H4 — auth & cart: already built.** Cart page (qty/remove/totals/promo/empty) + all 5 auth
  flows (login/register/forgot/reset/verify-phone) + account exist. No build was needed.
- **H5.A/B — vendor welcome.** `/sell` pitch page existed; Sell CTAs re-pointed to it.
- **W-stream (operator + assistant) — QA items shipped:** filters on best-sellers/new-arrivals
  (#2/#3) + a shared **filter-bar** component (#5/#4); **tabbed search results** Products|Stores
  with counts (#6); compact ProductStrip density for home strips (#8); PDP **left thumbnail
  rail** (#10b), **full-size lightbox zoom** (#10c), **premium tabs** replacing the accordion
  (#10e), **styled variant hints** (#10g); container max-width 1280.
- **Post-deploy QA fixes:** header icon removed (#9); `/stores` render bug + page size 10 (#1);
  **logged-in add-to-cart empty-drawer fix** (`c8e0797`).

---

## 6. Remaining work backlog (prioritized for the fresh conversation)

### Immediate next task — **Gift cards (operator QA #2: "gift card still not completed")**
Gift-cards is **heavily scaffolded** — `features/gift-cards/` has landing, detail, redeem,
my-gift-cards, checkout-handoff, visual, model, service (+ specs); routes wired
(`gift-cards`, `gift-cards/redeem`, `account/gift-cards`, `account/gift-cards/:id`).
**So this is completion, not a build.** First step: **pre-flight `features/gift-cards/*`**
(open each page + the service + the routes + run the gift-card specs) to pin down the exact
gap — likely the **purchase/buy flow** (buying a gift card → checkout) or an incomplete page —
then ask the operator one targeted clarifying question if the gap is ambiguous, plan it, and
complete it with tests. Build + targeted spec + snapshot + commit per sub-phase.

### Other tracked items (in `web-uplift-h2-h6.md` §6 + the W5 handover)
- **#7 homepage stores section not showing** — the "stores + 5 latest" home section. LIKELY the
  api-deploy caveat (`/v3/featured-vendors` verified-fallback un-deployed). Confirm the section
  also renders (it may share the `:host` card gotcha if it wraps `ui-store-card`).
- **#10d store-info block on PDP** — ⚠ API decision needed: a name+link vendor line exists;
  the richer block (logo + rating) needs `vendor.logo_url`/`vendor.rating`/`rating_count`
  which `VendorRef {slug,name}` lacks. **Recommend (a):** extend the product-detail API payload
  (single round-trip; mirrors `DirectoryStore`). Alt (b): client-side fetch store-by-slug.
- **H5.C — root-promotion prep** — promote apps/web to `3bayti.ae` (routing / `_redirects` /
  canonical / SEO + legacy seller-app handoff). Partly ops; the DNS flip is infra.
- **H6 — responsive/a11y sweep** — once the above are closed, a pass across home/stores/PDP/
  cart/checkout/account/auth/sell/gift-cards at mobile/tablet/desktop + RTL.

### Standing live-verification (not code; operator/infra)
- **Trigger the API deploy** (auto-deploy not firing) — unblocks H2.A batching + #1's
  "all-at-once" + #7 homepage stores + H0.1 fallback.
- **Cloudflare Pages build + Playwright pass** against `staging.3bayti.ae` for the shipped web work.
- **Rotate the GitHub PATs** shared this session (both burned) and move to SSH/`gh`/credential helper.

---

## 7. Plan / reference documents
- `docs/plans/web-uplift-h2-h6.md` — the H-plan (H0–H6), the **§6 post-deploy QA backlog**, and
  the **#10 PDP-optimization spec** (a done; b–h tracked). The authoritative web-uplift plan.
- `docs/plans/web-uplift-w5-handover.md` — W5 PDP handover (#10b/c/e/g done, #10d API-blocked) +
  the toolchain/test gotchas.
- `docs/plans/stream-y-handover.md` — separate **API** stream handover (not web).

---

## 8. Pre-flight checklist (fresh conversation)
- [ ] Read this handover + `docs/plans/web-uplift-h2-h6.md` §6 + the W5 handover.
- [ ] Verify state: `git log --oneline -1` → `c8e0797`; `git status --short` empty;
      `cd apps/web && node_modules/.bin/ng build` → exit 0; `node_modules/.bin/vitest run | tail -3`
      → ~767/74 green.
- [ ] Confirm push auth is set up the **secure** way (§2) before committing — do not embed a token.
- [ ] **Pre-flight `features/gift-cards/*`** to find the exact gap before planning.
- [ ] First message to operator: acknowledge handover, confirm state, state the gift-cards
      pre-flight finding, and ask the one clarifying question needed to scope completion.

## 9. Operating rhythm (every sub-phase)
- Re-read the relevant plan section; confirm previous sub-phase committed + clean tree.
- Production-ready only; reuse the §4 patterns; keep changes scoped to the sub-phase.
- Add tests as you go (decorator inputs; i18n+http providers in specs).
- Before commit: `node_modules/.bin/ng build` exit 0 · targeted `vitest` green · (full suite at
  phase close) · keep the component CSS budget under 18kB.
- Commit message: WHAT / WHY / WHAT'S NOT INCLUDED + gate results; `git pull --no-edit` (or fetch)
  before push; **status snapshot after every commit**.
- Handover refresh when the chat grows long (replicate this doc).

## 10. Closing note
The storefront turned out far more complete than the original H-plan assumed — H3/H4/H5 and
most QA items reduced to targeted fixes, now shipped. The disciplined pattern that worked:
**pre-flight every area first** (it's usually partly built), fix the real gap, test, commit,
snapshot. Gift cards is next and is a completion task — pre-flight, scope the one gap, finish it.
Same rhythm, same discipline, same standards.

— Handover prepared at commit `c8e0797`, Thursday, June 18, 2026.
