# 3bayti-web — Comprehensive Project Roadmap

**Version:** 1.1
**Date:** 5 May 2026
**Author:** Engineering (Claude + Sodiq)
**Repository:** [`surdbells/3bayti-web`](https://github.com/surdbells/3bayti-web)
**Production URL:** [https://staging.3bayti.ae](https://staging.3bayti.ae)
**Mobile-app source of truth:** [`surdbells/abayti_app`](https://github.com/surdbells/abayti_app)
**Backend repo (private):** mirrored from W2.0 patch deployed at `https://api.3bayti.ae`

### Changelog

| Version | Date | Changes |
|---|---|---|
| **1.1** | 5 May 2026 | Phase order revised based on owner direction: **Home page** ships before auth. Cart-strategy pivots to local-first with merge-on-login. Wishlist follows the same pattern. Checkout adopts inline login-or-register flow (no pure guest checkout, but no upfront login wall either). Card design language locked in §4.4. |
| 1.0 | 5 May 2026 | Initial comprehensive roadmap. |

---

## Document control

| Field | Value |
|---|---|
| Status | Active — drives all subsequent work |
| Audience | Engineering team, project owner, eventual investors / non-technical stakeholders |
| Update cadence | Per phase close. Out-of-cycle updates allowed for scope changes. |
| Source of truth | This document. If a decision contradicts this doc, this doc loses unless updated here too. |

---

## Table of contents

1. [Executive summary](#1-executive-summary)
2. [Reality check — how we got here](#2-reality-check)
3. [Locked decisions (the non-negotiables)](#3-locked-decisions)
4. [Stack architecture](#4-stack-architecture)
5. [Mobile-app endpoint inventory (105 endpoints, categorised)](#5-mobile-app-endpoint-inventory)
6. [Page-by-page web mapping (28 customer + 6 public pages)](#6-page-by-page-web-mapping)
7. [Phased roadmap (10 phases)](#7-phased-roadmap)
8. [Phase 1 deep dive — Home Page (full)](#8-phase-1-deep-dive--home-page-full)
9. [Cross-cutting concerns](#9-cross-cutting-concerns)
10. [Risk register](#10-risk-register)
11. [Open questions](#11-open-questions)
12. [Appendix A — Glossary](#appendix-a--glossary)
13. [Appendix B — Decision log](#appendix-b--decision-log)
14. [Appendix C — References](#appendix-c--references)

---

## 1. Executive summary

3bayti-web is the public-web counterpart to the **3bayti** mobile commerce app — an e-commerce marketplace for modest fashion (abayas, kaftans, modest wear) curated from independent UAE designers. The mobile app is built in Ionic 8 + Angular 21 + Capacitor 8 and ships to iOS and Android. The web app is being built to **achieve full feature parity with the mobile customer experience**, optimised for browser-native UX, SSR-driven SEO, and progressive shopping.

### Where we are

- ✅ **Cloudflare Workers + Static Assets** infrastructure live at `staging.3bayti.ae`. Top-200 PDPs and 8 categories prerendered. Long-tail products served via runtime SSR.
- ✅ **Catalog browse** end-to-end: home page, category index, category detail, PDP with reviews, JSON-LD, related products.
- ✅ **W2.0 backend foundation** deployed (`/v2/` envelope, defensive error handling, debug mode).
- 🟡 **4 PRs queued** for merge: PDP completion (W2.2b), 2 production bug fixes, CI/docs improvements.

### Where we're going

The mobile app exposes **105 backend endpoints across 5 categories** (`users/*`, `customer/*`, `vendor/*`, `chat/*`, `utility/*`) and **28 customer-facing pages plus 6 public pages**. The website must mirror the customer side of this entirely — auth, profile, addresses, measurements, cart, wishlist, checkout, payment, orders, messages, reviews, support tickets, vendor follow, search.

This is roughly a **5–7 month engagement at single-developer pace**, plus backend wrapping work. Vendor dashboard pages (16 endpoints, 1 page) are explicitly **out of scope** for the web project — they remain mobile-app-only.

### Phasing summary

| # | Phase | Estimated weeks | Status |
|---|---|---|---|
| 0 | **Cleanup** — merge queued PRs + W2.2c (404 status) | 0.5 | Ready to start |
| 1 | **Home Page (full)** — hero + categories + 3 product strips + designer spotlight + footer + 3 backend wrappers + premium card design system | 2.5–3 | After Phase 0 |
| 2 | **Cart (local-first)** — local cart in `localStorage` for guests, cart drawer + cart page UI, "merge on login" hook stubbed | 1.5–2 | After Phase 1 |
| 3 | **Auth + Checkout** — register/login (inline at checkout), profile + addresses + measurements + checkout flow + payment integration. Cart-merge on login fires here. | 6–7 | After Phase 2 |
| 4 | **Orders + Order Detail** | 2–3 | After Phase 3 |
| 5 | **Wishlist + Reviews** — wishlist follows same local-first → merge-on-login pattern as cart | 3–4 | After Phase 4 |
| 6 | **Browse Plus** — search, vendor pages, best-sellers/new-arrivals listings, follow-designer | 3–4 | After Phase 5 |
| 7 | **Messages + Tickets** | 3–4 | After Phase 6 |
| 8 | **Chat module integration** (per-order chat) | 2–3 | After Phase 7 |
| 9 | **Hardening + observability** | 2–3 | After Phase 8 |

**Total: 24–32 weeks of focused engineering**, plus ~50–60 backend `/v2/` wrapper endpoints written in lockstep.

### Why this order

The v1.0 roadmap had Auth as Phase 1. v1.1 inverts that based on owner direction: **the website should be browsable end-to-end without login**, with auth required only at the moment of payment. This is the pattern every modern marketplace uses (Amazon, ASOS, Net-a-Porter) because it preserves the discovery experience and only asks for commitment at the value-exchange step.

The new order is also the *value-flow* order: a visitor arriving today should be able to browse → add-to-cart → checkout (creating account in-flow). Phases 1, 2, and 3 build that flow in order. Account-management features (orders history, wishlist, reviews) come after the checkout flow exists, because they only matter once a user has account state.

---

## 2. Reality check — how we got here

We're writing this document because of a scope discovery in week 1 of W2.2b: until the mobile app's full endpoint surface was inventoried, the website project was being executed against a much narrower understanding (catalog browse only, ~7 endpoints). The W2.0 backend patch I shipped reflects this narrower view — only the read-only browse endpoints were wrapped in `/v2/`.

This is **not** a blame statement. Both parties had context the other didn't:

- I knew the W2.0 backend patch in detail because I wrote it. I assumed catalog browse was a complete project.
- The project owner knew the mobile app existed and that web should mirror it. The owner reasonably assumed the engineer (me) had read the mobile-app repo before scoping.

The fix: this document. From here forward, the **scope is what's in this document**, and any addition gets added here before code is written.

### What this means for prior work

Everything shipped to date (W1.x foundation, W2.0–W2.2b) is **kept**. None of it needs to be undone. The catalog browse story is exactly correct for SEO and bottom-of-funnel — it just isn't enough on its own. Phase 0 closes out the catalog story (404 fix) and Phase 1 starts building on top of it.

### What this means for the mobile app

The mobile app continues to ship independently. The web app is *additive* — it does not replace the mobile app, and the two will coexist with shared backend.

---

## 3. Locked decisions

These three decisions are confirmed by the project owner (5 May 2026) and govern every downstream choice. Changing any of them requires updating this document.

### Decision 1 — Scope: full customer-side parity

The website ships **all 28 customer pages and 64 customer endpoints** present in the mobile app, plus the 7 user/auth endpoints. Vendor admin (16 endpoints, 1 page) is explicitly out of scope — those remain mobile-only.

**Implications:**
- ~50–60 new `/v2/*` endpoints to wrap. (Some endpoints already exist or are trivial mirrors; others need real work.)
- 28 new web routes to build.
- Browser auth, browser cart persistence, browser payment integration — all production-grade.

### Decision 2 — Behavior fidelity: mobile guides intent; web wins on UX

When mobile-app behavior conflicts with web-native conventions, the **web pattern wins** unless it changes what the user can do. Examples:

| Mobile pattern | Web pattern |
|---|---|
| Pull-to-refresh | "Load more" button or scroll-driven (no pull) |
| Bottom tab bar | Top nav with sidebar/drawer |
| Modal sheets | Inline panels or modal dialogs (proper a11y) |
| Tap targets ≥44px | Hover states + smaller targets allowed |
| OS-native pickers | HTML `<select>` / custom dropdown |
| Capacitor Preferences | localStorage |
| Camera capture | File upload `<input type="file">` |

**Discrepancies that change *what* the user can do** (e.g. mobile lets users follow vendors but web doesn't) require explicit acknowledgement here, not silent UX simplification.

### Decision 3 — Backend strategy: build /v2/ wrappers for everything

Every endpoint the web depends on gets a `/v2/` version with:

- Consistent envelope: `{ data: ..., meta: ... }` for success; `{ error: { code, message, details } }` for failure
- `v2_init()` global error handler (already standard from W2.0 patch)
- Cache-Control headers appropriate to the endpoint type
- TypeScript-friendly response shape (no PHP-style mixed numeric/string keys, consistent boolean handling)
- Debug mode for diagnosis without server access

**Implications:**
- Backend track runs in parallel with frontend track.
- ~50–60 new `.php` files to write, drop-in style (matching W2.0 patch shape).
- Web team never calls v1 directly. If a v2 wrapper doesn't exist, web waits for it.
- v1 endpoints stay live for the mobile app indefinitely. We do not migrate the mobile app.

### Decision 4 — Auth strategy: JWT in localStorage, required only at checkout

Confirmed 5 May 2026 by the project owner. **Updated v1.1 (5 May 2026)**: auth is no longer a hard gate for browsing — it's required only at the moment of checkout.

The web mirrors the mobile-app pattern for *how* tokens are stored: JWT issued on login, stored in `localStorage`, attached to requests in an `Authorization: Bearer <token>` header. **Pages that require authentication will not be SSR'd** — they'll render a loading shell server-side and hydrate client-side once the token is read from localStorage.

Where v1.1 diverges from v1.0 is *when* auth is required:

| Surface | Auth required? | Notes |
|---|---|---|
| Browse (home, categories, PDP, designers, search) | ❌ No | Open to all |
| Cart (add/remove/quantity) | ❌ No | Local-first; persisted to `localStorage` for guests |
| Wishlist (add/remove) | ❌ No | Same local-first pattern as cart (Phase 5) |
| Account pages (profile, orders, addresses, measurements, settings, messages, tickets) | ✅ Yes | Auth-gated, redirect to inline-login if not signed in |
| Checkout entry | ✅ Yes | Triggered from cart's "Checkout" button — inline login-or-register modal |
| Reviews (read) | ❌ No | Public data |
| Reviews (write, helpful) | ✅ Yes | Auth-gated |
| Follow vendor | ✅ Yes | Auth-gated |

**Implications:**
- All pages in `/account/*` use **client-side rendering only** (or skeleton SSR + client hydration with no auth-dependent data).
- The cart pages (`/cart`, cart drawer) use CSR to read local cart state but are not auth-gated.
- SEO impact on auth-gated pages: zero — they should be `noindex` anyway (private user data).
- XSS posture: same as mobile app. We accept this trade-off in exchange for shipping speed and matching the existing mobile auth flow.
- CSRF: less of a concern with `Authorization: Bearer` than with cookie-based auth, but still worth thinking about for state-changing endpoints.

### Decision 5 — Cart strategy: local-first with merge on login

**New in v1.1.** The cart is a `localStorage`-backed structure for guests. Items added to the cart while not logged in persist locally across sessions. When a user logs in (whether via the inline checkout flow or via the header login link), any local cart items are sent to the backend cart endpoints and merged with the user's existing server cart, then the local cart is cleared.

**Local cart shape:** `{ items: [{ product_id, slug, name, image_url, vendor, price, currency, size, color, quantity }], updated_at }`. Stored under key `cart` in localStorage.

**Merge rules** (Phase 3, when auth lands):
- If server cart is empty → push entire local cart to server.
- If both have items → merge by `product_id` + size + color. Quantities are summed (a guest who added 2 of a product, then logs into an account that already has 1 of the same product, ends up with 3).
- After successful merge, clear localStorage cart.
- If merge fails (network error, 5xx), keep local cart intact and show an error toast — don't lose data.

**Implications for Phase 2 (Cart):**
- Cart UI works fully without auth.
- Cart total is calculated client-side from local data (price × quantity per line, summed).
- Shipping/tax preview deferred to checkout (server-calculated then).
- "Save for later" feature deferred to a post-launch enhancement.

### Decision 6 — Checkout flow: inline login-or-register

**New in v1.1.** When a guest user clicks "Checkout" with items in cart:

1. We do NOT redirect them to a `/login` page.
2. Instead, a checkout-flow modal/step appears asking for email + password (with a "Create account" toggle).
3. On submit, account is either authenticated (login path) or created in-flow (register path).
4. The cart-merge runs immediately after auth.
5. Checkout proceeds through address → shipping → payment.

**No pure guest checkout.** Every order ends up associated with an account, even if that account was created during the checkout flow. This keeps the data model simple and lets users track orders post-purchase.

**Implications:**
- The auth/checkout phase (Phase 3 in v1.1) treats login and register as a unified flow with one form, not two pages.
- Inline login also works from a header dropdown — same auth state, accessible anywhere.
- Email validation runs in real-time during register (against `/v2/auth/validate-email`).

---

## 4. Stack architecture

### Frontend

| Layer | Technology | Notes |
|---|---|---|
| Framework | Angular | 21.2 — same major version as the mobile app |
| SSR | `@angular/ssr` 21.2.9 on Cloudflare Workers (workerd) | Static-first, runtime fallback for the long tail |
| Styling | Tailwind 4.2 | Utility-first; design-token-driven primitives |
| Reactive primitives | Angular Signals + RxJS 7.8 | Signals for component state, RxJS for HTTP / streams |
| Forms | Angular Reactive Forms | Required for all form pages from Phase 1 onward |
| HTTP | `HttpClient` with interceptors | Auth interceptor (Phase 1), error normaliser, retry/backoff |
| State (cross-component) | Angular Signals + service-level stores | Single source of truth pattern. No Redux/NgRx unless we need time-travel debugging |
| Testing | Karma + Jasmine (default), Playwright for e2e from Phase 5 | E2e starts when checkout is built |

### Backend

| Layer | Technology | Notes |
|---|---|---|
| Runtime | PHP (existing) | Not migrating — wrap, don't rewrite |
| Pattern | One file per endpoint, drop-in deploy | Per W2.0 patch shape |
| Envelope | `{ data, meta }` / `{ error }` | Phase 0 standard |
| Authentication | JWT (signed, HS256), bearer header | Already present for mobile |
| Database | MySQL via mysqli (existing) | New `/v2/` files use the same `CustomerPublic` class pattern |

### Infrastructure

| Layer | Technology | Notes |
|---|---|---|
| Edge | Cloudflare Workers + Static Assets | Top routes prerendered, rest runtime-SSR |
| Sitemap | Generated postbuild from API | Already in place |
| CI/CD | GitHub Actions → Cloudflare | Smoke-tests against `*.workers.dev` and `staging.3bayti.ae` |
| Observability | TBD Phase 9 | Probable: Cloudflare Analytics + Sentry browser SDK |
| Payment | TBD Phase 5 | Mobile uses some payment service via `initiatePayment` / `finalizePayment`; needs investigation |

### SSR boundary policy

Every web route falls into one of three buckets:

| Bucket | SSR? | Examples |
|---|---|---|
| **Public + cacheable** | ✅ Prerendered (top traffic) or runtime-SSR (long tail) | `/`, `/category`, `/category/:slug`, `/product/:slug`, `/vendor/:slug`, `/search` |
| **Public + dynamic** | ✅ Runtime SSR | `/search?q=...` (variable input), `/intro` (legal-info pages) |
| **Authenticated** | ❌ CSR only (skeleton SSR) | `/account/*`, `/cart`, `/checkout`, `/orders/*`, `/wishlist`, `/messages/*` |

The auth bucket renders an unauthenticated shell server-side (header, footer, loading skeleton), then hydrates with the user's data once the JWT is read from localStorage. We add `<meta name="robots" content="noindex">` to these routes so crawlers don't waste budget on them.

### URL structure

Web URLs do **not** map 1:1 to mobile routes. Mobile uses hash-routes and short paths because deep-linking on mobile is rare; web uses canonical, SEO-friendly, slug-based paths.

| Mobile route | Web route |
|---|---|
| `/product` (passes ID via state) | `/product/:slug` (slug in URL — SEO) |
| `/category` (state-driven) | `/category/:slug` |
| `/vendors` | `/designer` (we'll use "designer" for SEO; "vendor" is internal terminology) |
| `/store_reviews` | `/designer/:slug/reviews` |
| `/cart` | `/cart` |
| `/checkout` | `/checkout` |
| `/my-orders` | `/account/orders` |
| `/orders` (single order detail) | `/account/orders/:id` |
| `/wishlist` | `/account/wishlist` |
| `/profile` | `/account/profile` |
| `/addresses` | `/account/addresses` |
| `/measurements` | `/account/measurements` |
| `/settings` | `/account/settings` |
| `/messages` | `/account/messages` |
| `/ticketlist` | `/account/support` |
| `/createticket` | `/account/support/new` |
| `/ticketmessages` | `/account/support/:id` |

**Rationale:** All authenticated routes nest under `/account/*` for clarity. Public catalog routes use SEO-friendly slugs.

### 4.4 Product card design system (locked v1.1)

**Direction:** Gilded Boutique with pronounced floating presence.

The product card is the most repeated UI atom on the site — it appears on home page strips, category pages, designer pages, search, related-products, and wishlist. Locking the design here means every subsequent surface inherits a consistent premium feel.

#### Visual specification

| Property | Value | Rationale |
|---|---|---|
| Card surface | `#fdfaf3` (warm cream, slightly lighter than canvas) | Lifts off the canvas distinctly; reads as "boutique" not "department store" |
| Card border-radius | `20px` | Soft, contemporary; consistent with rounded UI primitives across the brand |
| Card padding | `14px 14px 20px` | Generous breathing room around the image; tighter at meta block |
| Image container border-radius | `14px` | Rounded inset — image floats inside the card with its own padding (not bleeding to the card's edges) |
| Image aspect ratio | `3 / 4` | Portrait orientation — flatters fashion photography, fits more cards per row |
| Shadow at rest | `0 4px 8px rgba(90,58,44,0.08), 0 24px 48px -12px rgba(90,58,44,0.16), 0 8px 16px -4px rgba(90,58,44,0.10)` | Pronounced — gallery-display level of float |
| Shadow on hover | `0 8px 16px rgba(90,58,44,0.10), 0 40px 72px -16px rgba(90,58,44,0.22), 0 16px 28px -8px rgba(90,58,44,0.14)` | Deepens dramatically — confident interaction cue |
| Hover transform | `translateY(-6px)` | Strong lift; commits to the floating feel |
| Hover image scale | `transform: scale(1.04)` over `1s cubic-bezier(0.22, 1, 0.36, 1)` | Slow, refined zoom |
| Transition timing | `0.5s cubic-bezier(0.22, 1, 0.36, 1)` | Slow enough to feel deliberate, fast enough not to drag |

Shadow colour uses the brand espresso `#5a3a2c` at low alpha rather than neutral grey — gives the shadow a warm tint that matches the cream surface (a neutral-grey shadow on cream looks dirty).

#### Typography hierarchy inside card

1. **Vendor name** — `Cormorant Garamond, italic, 14px, var(--color-brand-500)`. Designer-first storytelling. The first thing the user reads.
2. **Product name** — `Playfair Display, 500 weight, 16px, var(--color-brand-700)`. Two-line clamp with `min-height: 2.6em` to prevent jagged grids.
3. **Divider** — gold ornament dot (`4px circle, var(--color-brand-300)`) flanked by hairline lines. Decorative, brand-coded.
4. **Price** — `Inter, 500 weight, 14px, var(--color-brand-700)` with currency in lighter weight (`Inter, 400, 11px, var(--color-text-tertiary)`). Currency is part of the price unit, not a separate label.
5. **Rating** — gold star (`var(--color-brand-500)`) + `Inter, 400, 12px, var(--color-text-secondary)`. Right-aligned in the price row.

#### Affordances on the card

| Element | Position | Behaviour |
|---|---|---|
| Badge (New / Best seller / Sale) | Top-left of image | Pill shape, cream background, `rgba(253,250,243,0.95)` with backdrop blur, brand-200 border |
| Like / save button | Top-right of image | Round, cream backdrop with backdrop blur, brand-200 border, brand-700 stroke icon |
| Image hover scale | Whole card hovered | `1.04x` over 1s |
| Card lift | Whole card hovered | `-6px` translate Y, deepened shadow |

The like button and badges sit *inside* the rounded image container — both have `position: absolute` against the image-wrap. Backdrop blur gives them a frosted-glass feel that works regardless of what's behind them in the photograph.

#### Out-of-stock treatment

When `product.in_stock === false`:
- Image gets `opacity: 0.55` and `filter: grayscale(0.4)`
- An "Out of stock" overlay appears centred over the image (lowercase italic Playfair on a soft cream pill)
- Card hover scale is disabled
- Card lift is disabled
- Cursor changes to `not-allowed`

#### Sale price treatment

When sale: current price uses `var(--color-brand-600)` (slightly darker gold for emphasis). Original price renders to the right in `var(--color-text-tertiary)` with `text-decoration: line-through` and 4px left margin. No flashy "save 20%" badge — restraint is part of the premium feel.

#### Reference

Live preview demonstrating the locked design: `3bayti_shadow_preview.html` (Pronounced section). All future product cards across the site MUST follow this spec.

---

## 5. Mobile-app endpoint inventory

The mobile app declares **88 backend endpoints** in `src/app/global-component.ts`, plus **3 external** endpoints (Topex shipping API). Below is every endpoint, categorised, with its destination phase and `/v2/` wrap status.

### Legend

| Symbol | Meaning |
|---|---|
| ✅ Done | Already wrapped in `/v2/` (W2.0 patch shipped) |
| 🔧 Wrap | Needs a new `/v2/` wrapper before web can use it |
| ➖ Skip | Not needed on web, or duplicated by another endpoint |
| 🔒 Auth | Requires authenticated user (JWT in localStorage) |
| 🌐 Public | Anonymous-friendly |
| 🟡 Hybrid | Behavior changes based on auth state (e.g. cart shows different data) |

### 5.1 Auth (`users/*`) — 7 endpoints

All needed in **Phase 1**. Auth-gated rest of the app.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 1 | `users/login` | POST | 🌐 | 🔧 Wrap → `/v2/auth/login` | 1 | Returns JWT; web stores in localStorage |
| 2 | `users/register` | POST | 🌐 | 🔧 Wrap → `/v2/auth/register` | 1 | Email + phone + password |
| 3 | `users/resetMobile` | POST | 🌐 | 🔧 Wrap → `/v2/auth/reset` | 1 | Password reset via OTP |
| 4 | `users/validate` | POST | 🌐 | 🔧 Wrap → `/v2/auth/validate-phone` | 1 | Pre-register phone-number availability |
| 5 | `users/validate-email` | POST | 🌐 | 🔧 Wrap → `/v2/auth/validate-email` | 1 | Pre-register email availability |
| 6 | `users/confirm` | POST | 🌐 | 🔧 Wrap → `/v2/auth/confirm` | 1 | OTP confirmation step |
| 7 | `users/sendOTP` | POST | 🌐 | 🔧 Wrap → `/v2/auth/send-otp` | 1 | Sends OTP for register/reset |

### 5.2 Account & settings (`customer/settings/*`, `customer/profile`) — 11 endpoints

All needed in **Phase 1**. Account management screens.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 8 | `customer/settings/update-location` | POST | 🔒 | 🔧 Wrap → `/v2/account/location` | 1 | Sets default city/area |
| 9 | `customer/settings/read-profile` | POST | 🔒 | 🔧 Wrap → `/v2/account/profile` (GET) | 1 | Reads profile |
| 10 | `customer/settings/update-profile` | POST | 🔒 | 🔧 Wrap → `/v2/account/profile` (PUT) | 1 | Updates profile |
| 11 | `customer/settings/measurement/read-measurement` | POST | 🔒 | 🔧 Wrap → `/v2/account/measurements` (GET) | 1 | Reads body measurements |
| 12 | `customer/settings/measurement/update-measurement` | POST | 🔒 | 🔧 Wrap → `/v2/account/measurements` (PUT) | 1 | Updates body measurements |
| 13 | `customer/settings/billing/read-billings` | POST | 🔒 | 🔧 Wrap → `/v2/account/addresses` (GET) | 1 | Lists shipping addresses |
| 14 | `customer/settings/billing/update-billing` | POST | 🔒 | 🔧 Wrap → `/v2/account/addresses` (PUT/POST) | 1 | Adds/updates address |
| 15 | `customer/settings/read-reviews` | POST | 🔒 | 🔧 Wrap → `/v2/account/reviews` | 3 | Lists user's reviews |
| 16 | `customer/settings/store-reviews` | POST | 🔒 | ➖ Duplicate of #59 | — | Same as `customer/store-reviews` per code inspection |
| 17 | `customer/settings/delete-review` | POST | 🔒 | 🔧 Wrap → `/v2/account/reviews/:id` (DELETE) | 3 | Deletes user's review |
| 18 | `vendors/measurement/get-measurements` | POST | 🔒 | 🔧 Wrap → `/v2/measurements/template` | 1 | Reads vendor's measurement schema (e.g. abayas have different fields than kaftans) |

### 5.3 Catalog browse — public (read-heavy) — 18 endpoints

These are the lifeblood of SEO and are mostly already wrapped from W2.0.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 19 | `customer/category` | POST | 🌐 | ✅ Done — `/v2/categories` | 0 | Already shipped |
| 20 | `customer/category_listing` | POST | 🌐 | ✅ Done — `/v2/categories/:slug` | 0 | Already shipped |
| 21 | `customer/single_product` | POST | 🌐 | ✅ Done — `/v2/products/:slug` | 0 | Already shipped |
| 22 | `customer/singleProduct` | POST | 🌐 | ➖ Older variant of #21 | — | Mobile app uses both; web uses `/v2/products/:slug` |
| 23 | `customer/featured` | POST | 🌐 | 🔧 Wrap → `/v2/products?filter=featured` | 0 (extension) | Home page featured products. Could fold into existing `/v2/products` w/ filter |
| 24 | `customer/filterfeatured` | POST | 🌐 | ➖ Fold into #23 | — | Same data, different filters |
| 25 | `customer/best_sellers` | POST | 🌐 | 🔧 Wrap → `/v2/products?filter=best-sellers` | 2 | |
| 26 | `customer/best_sellers_listing` | POST | 🌐 | 🔧 Wrap → `/v2/best-sellers` (paginated) | 2 | Paginated listing with filters |
| 27 | `customer/new_arrivals` | POST | 🌐 | 🔧 Wrap → `/v2/products?filter=new-arrivals` | 2 | |
| 28 | `customer/new_arrivals_listing` | POST | 🌐 | 🔧 Wrap → `/v2/new-arrivals` (paginated) | 2 | Paginated listing with filters |
| 29 | `customer/explore` | POST | 🌐 | 🔧 Wrap → `/v2/explore` | 2 | Curated discovery feed (data shape TBD) |
| 30 | `customer/explore_listing` | POST | 🌐 | 🔧 Wrap → `/v2/explore/:vertical` | 2 | Detail of an explore vertical |
| 31 | `customer/filterexplore` | POST | 🌐 | ➖ Fold into #30 | — | Same data with filters |
| 32 | `customer/filter_product` | POST | 🌐 | 🔧 Wrap — augment `/v2/products` with filter params | 2 | Filter by size, colour, price, vendor, etc. |
| 33 | `customer/product_by_category` | POST | 🌐 | ➖ Fold into `/v2/categories/:slug` | — | Already returns products in v2 |
| 34 | `customer/products_by_labels` | POST | 🌐 | 🔧 Wrap → `/v2/labels/:label/products` | 2 | Vendor-defined label browsing |
| 35 | `customer/search` | POST | 🌐 | 🔧 Wrap → `/v2/search?q=...` | 2 | Full-text search |
| 36 | `customer/sitemap-data` | — | 🌐 | ✅ Done — `/v2/sitemap-data` | 0 | Already shipped |

### 5.4 Vendor / designer browse — 7 endpoints

Public-facing designer pages.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 37 | `customer/read-vendor` | POST | 🌐 | ✅ Done — `/v2/vendors/:slug` | 0 | Already shipped |
| 38 | `customer/vendors_list` | POST | 🌐 | ✅ Done — `/v2/vendors` | 0 | Already shipped |
| 39 | `customer/vendors_products` | POST | 🌐 | 🔧 Wrap → `/v2/vendors/:slug/products` | 2 | Products by vendor — paginated |
| 40 | `customer/store_latest` | POST | 🌐 | 🔧 Wrap → `/v2/vendors/:slug/products?sort=newest&limit=10` | 2 | "Latest from this designer" widget |
| 41 | `customer/read_vendor_collection` | POST | 🌐 | 🔧 Wrap → `/v2/vendors/:slug/collections` | 2 | Vendor's curated collections / labels |
| 42 | `customer/follow` | POST | 🔒 | 🔧 Wrap → `/v2/vendors/:slug/follow` (POST) | 2 | Auth required |
| 43 | `customer/unfollow` | POST | 🔒 | 🔧 Wrap → `/v2/vendors/:slug/follow` (DELETE) | 2 | Auth required |

### 5.5 Wishlist — 4 endpoints

Phase 3.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 44 | `customer/read_wishlist` | POST | 🔒 | 🔧 Wrap → `/v2/wishlist` (GET) | 3 | List wishlist items |
| 45 | `customer/read_wishlist_label` | POST | 🔒 | 🔧 Wrap → `/v2/wishlist/labels` (GET) | 3 | Wishlist supports user-defined labels (collections) |
| 46 | `customer/add_wishlist_label` | POST | 🔒 | 🔧 Wrap → `/v2/wishlist/labels` (POST) | 3 | Add a new label/collection |
| 47 | `customer/add_wishlist` | POST | 🔒 | 🔧 Wrap → `/v2/wishlist/items` (POST) | 3 | Add product to wishlist (under a label) |

**Note:** No "delete from wishlist" endpoint exists in the mobile app's global-component. Either it's reuse-driven (re-adding toggles), or there's a hidden endpoint. Phase 3 must clarify.

### 5.6 Reviews — 3 endpoints

Phase 3.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 48 | `customer/store-reviews` | POST | 🌐 | 🔧 Wrap → `/v2/vendors/:slug/reviews` (GET) | 3 | Public — read vendor reviews |
| 49 | `customer/add-review` | POST | 🔒 | 🔧 Wrap → `/v2/reviews` (POST) | 3 | Submit a review (product or vendor) |
| 50 | `customer/helpful` | POST | 🔒 | 🔧 Wrap → `/v2/reviews/:id/helpful` (POST) | 3 | Mark a review as helpful |

### 5.7 Cart — 4 endpoints

Phase 4.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 51 | `customer/read-cart` | POST | 🔒 | 🔧 Wrap → `/v2/cart` (GET) | 4 | Read user's cart |
| 52 | `customer/addToCart` | POST | 🔒 | 🔧 Wrap → `/v2/cart/items` (POST) | 4 | Add item with size/colour/quantity |
| 53 | `customer/removeFromCart` | POST | 🔒 | 🔧 Wrap → `/v2/cart/items/:id` (DELETE) | 4 | Remove a line item |
| 54 | `customer/IncreaseItem` | POST | 🔒 | 🔧 Wrap into `/v2/cart/items/:id` (PATCH) | 4 | Quantity ± can be one PATCH endpoint |
| 55 | `customer/decreaseItem` | POST | 🔒 | ➖ Fold into #54 | — | |

**Open question:** Does the mobile app support guest carts? If yes, web should match (probably with localStorage cart that hydrates to server on login). Phase 4 must confirm.

### 5.8 Checkout & payment — 6 endpoints

Phase 5. Highest-risk phase due to payment integration.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 56 | `customer/payment/initiate_payment` | POST | 🔒 | 🔧 Wrap → `/v2/checkout/initiate` | 5 | Creates payment session, returns PSP URL/token |
| 57 | `customer/finalize_payment` | POST | 🔒 | 🔧 Wrap → `/v2/checkout/finalize` | 5 | Webhook-driven order confirmation |
| 58 | `customer/getToken` | POST | 🔒 | 🔧 Wrap — TBD purpose | 5 | Auth header? Payment gateway token? Investigate Phase 5 |
| 59 | `customer/sendOTP` | POST | 🔒 | 🔧 Wrap → `/v2/checkout/otp/send` | 5 | OTP for high-value purchases? Or 3DS? Investigate |
| 60 | `customer/validateOTP` | POST | 🔒 | 🔧 Wrap → `/v2/checkout/otp/validate` | 5 | |
| 61 | `topexCities` (external) | GET | 🌐 | 🔧 Proxy via `/v2/shipping/cities` | 5 | Topex shipping API. Web cannot call directly (CORS) — proxy |
| 62 | `topexAreaURL/:cityId` (external) | GET | 🌐 | 🔧 Proxy via `/v2/shipping/areas/:cityId` | 5 | |

**Note on payment:** The mobile app uses a payment provider whose name isn't in the code. Phase 5 entry checklist is:
1. Identify the payment provider (Noonpay? Stripe? PayTabs? Telr? Network International?).
2. Confirm web SDK availability and integration pattern (redirect? hosted iframe? Apple/Google Pay tokenisation?).
3. Confirm webhook ownership — mobile-app webhook receiver may need to share with web or be split.

### 5.9 Orders — 4 endpoints

Phase 6.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 63 | `customer/read-orders` | POST | 🔒 | 🔧 Wrap → `/v2/orders` (GET) | 6 | List user's orders (paginated) |
| 64 | `customer/read-order-details` | POST | 🔒 | 🔧 Wrap → `/v2/orders/:id` (GET) | 6 | Single order detail |
| 65 | `customer/read-customer-orders` | POST | 🔒 | ➖ Duplicate of #63 | — | Investigate if any meaningful difference |
| 66 | `customer/read_orders_listing` | POST | 🔒 | ➖ Duplicate of #63 | — | Same as above |

### 5.10 Messages (per-order chat with vendor) — 4 endpoints

Phase 7.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 67 | `customer/read-conversations` | POST | 🔒 | 🔧 Wrap → `/v2/messages/conversations` | 7 | List conversations |
| 68 | `customer/read-messages` | POST | 🔒 | 🔧 Wrap → `/v2/messages/:conversationId` | 7 | Read a conversation |
| 69 | `customer/send-message` | POST | 🔒 | 🔧 Wrap → `/v2/messages/:conversationId/send` | 7 | Send a message |
| 70 | `customer/read-customer-orders` | POST | 🔒 | ➖ Duplicate of orders #63 | — | Confusingly listed in mobile app under both |

**Note:** This may overlap or be replaced by the dedicated chat module (5.13). Phase 7 entry must reconcile the two.

### 5.11 Support tickets — 4 endpoints

Phase 7.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 71 | `customer/create_ticket` | POST | 🔒 | 🔧 Wrap → `/v2/support/tickets` (POST) | 7 | Open a new ticket |
| 72 | `customer/read_ticket` | POST | 🔒 | 🔧 Wrap → `/v2/support/tickets` (GET) | 7 | List tickets |
| 73 | `customer/read-ticket-messages` | POST | 🔒 | 🔧 Wrap → `/v2/support/tickets/:id/messages` (GET) | 7 | Ticket conversation |
| 74 | `customer/send-ticket-message` | POST | 🔒 | 🔧 Wrap → `/v2/support/tickets/:id/messages` (POST) | 7 | Reply to ticket |

### 5.12 Styles (custom design requests) — 2 endpoints

Phase 2 if simple, Phase 7 otherwise. To investigate.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 75 | `customer/styles_list` | POST | 🔒 | 🔧 Wrap → `/v2/styles` (GET) | 2/7 | List user's submitted styles |
| 76 | `customer/create_style` | POST | 🔒 | 🔧 Wrap → `/v2/styles` (POST) | 2/7 | Submit a custom-style request |

### 5.13 Chat module (per-order vendor chat) — 10 endpoints

Phase 8. This is its own subsystem with separate data model (conversations scoped per order item, prompt templates, image uploads, moderation flags).

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 77 | `chat/get_vendors` | POST | 🔒 | 🔧 Wrap → `/v2/chat/vendors` | 8 | Vendors user has bought from |
| 78 | `chat/get_vendor_orders` | POST | 🔒 | 🔧 Wrap → `/v2/chat/vendors/:id/orders` | 8 | Orders from this vendor |
| 79 | `chat/get_conversation` | POST | 🔒 | 🔧 Wrap → `/v2/chat/conversations` | 8 | Get/create conversation for order |
| 80 | `chat/get_messages` | POST | 🔒 | 🔧 Wrap → `/v2/chat/conversations/:id/messages` | 8 | Paginated message history |
| 81 | `chat/send_message` | POST | 🔒 | 🔧 Wrap → `/v2/chat/conversations/:id/send` | 8 | Send text/prompt |
| 82 | `chat/upload_image` | POST | 🔒 | 🔧 Wrap → `/v2/chat/upload` | 8 | Image attachment |
| 83 | `chat/get_prompts` | POST | 🔒 | 🔧 Wrap → `/v2/chat/prompts` | 8 | Quick-reply prompt templates |
| 84 | `chat/mark_read` | POST | 🔒 | 🔧 Wrap → `/v2/chat/conversations/:id/read` | 8 | Mark messages read |
| 85 | `chat/get_unread_count` | POST | 🔒 | 🔧 Wrap → `/v2/chat/unread` | 8 | Total unread badge count |
| 86 | `chat/get_vendor_conversations.php` | POST | 🔒 (vendor) | ➖ Vendor-only | — | Out of scope (vendor admin) |

### 5.14 Out of scope — 2 endpoints

Vendor admin endpoints — explicitly **not** building these on web.

| # | Endpoint | Notes |
|---|---|---|
| 87 | `vendors/toggle_status` | Vendor admin only |
| 88 | `vendors/get_stats.php` | Vendor admin only |

### 5.15 Endpoint summary

| Category | Count | Phase | Notes |
|---|---|---|---|
| Already wrapped (W2.0) | 7 | — | Catalog browse foundation |
| New `/v2/` wrappers needed | ~50 | 1–8 | Spread across phases |
| Folded / duplicated | ~12 | — | Reduce to single canonical version per concept |
| Out of scope | 3 | — | Vendor admin |
| External (proxy required) | 2 | 5 | Topex shipping API |
| **Total customer-side surface** | **~62 endpoints** | | |

---

## 6. Page-by-page web mapping

The mobile app has **28 customer pages + 6 public pages = 34 pages**. The web has **34 routes** (1:1 with adaptations). Below is every route with: web path, source mobile page, SSR strategy, depending endpoints, and UX notes.

### 6.1 Public catalog routes (already built or extending existing)

| # | Web route | Mobile source | SSR | Endpoints | Phase | Notes |
|---|---|---|---|---|---|---|
| 1 | `/` | `public/home/home.page` | ✅ Prerender | (static for now; W2.x will wire featured products) | 0 | Done |
| 2 | `/category` | `customer/category` (idx) | ✅ Prerender | `/v2/categories` | 0 | Done |
| 3 | `/category/:slug` | `customer/category` (detail) | ✅ Prerender top 8 + runtime SSR | `/v2/categories/:slug` | 0 | Done |
| 4 | `/product/:slug` | `customer/product/product.page` | ✅ Prerender top 200 + runtime SSR | `/v2/products/:slug` | 0 | Done (W2.2b) |
| 5 | `/designer` | `customer/vendors/vendors.page` | ✅ Prerender | `/v2/vendors` | 2 | "Designers" naming for SEO |
| 6 | `/designer/:slug` | (not in mobile as standalone — vendor data shown in product context) | ✅ Prerender top 50 + runtime SSR | `/v2/vendors/:slug`, `/v2/vendors/:slug/products` | 2 | New page on web; mobile shows vendor in modal |
| 7 | `/designer/:slug/reviews` | `customer/store-reviews/store-reviews.page` | 🟡 Runtime SSR | `/v2/vendors/:slug/reviews` | 3 | |
| 8 | `/search` | `customer/search/search.page` | 🟡 Runtime SSR | `/v2/search?q=...` | 2 | |
| 9 | `/best-sellers` | `customer/best-sellers/best-sellers.page` | ✅ Prerender (refresh daily) | `/v2/best-sellers` | 2 | |
| 10 | `/new-arrivals` | `customer/new-arrivals/new-arrivals.page` | ✅ Prerender (refresh daily) | `/v2/new-arrivals` | 2 | |
| 11 | `/explore` | `customer/vertican/vertican.page` | ✅ Prerender | `/v2/explore` | 2 | "Vertican" → renamed `/explore` |
| 12 | `/explore/:vertical` | `customer/vertican` (vertical detail) | ✅ Prerender | `/v2/explore/:vertical` | 2 | |

### 6.2 Authenticated account routes — Phase 1

| # | Web route | Mobile source | SSR | Endpoints | Notes |
|---|---|---|---|---|---|
| 13 | `/account` | `customer/account/account.page` | ❌ CSR | `/v2/account/profile` | Account hub (links to all sub-pages) |
| 14 | `/account/profile` | `customer/profile/profile.page` | ❌ CSR | `/v2/account/profile` | View/edit profile |
| 15 | `/account/addresses` | `customer/addresses/addresses.page` | ❌ CSR | `/v2/account/addresses` | Shipping address management |
| 16 | `/account/measurements` | `customer/measurements/measurements.page` | ❌ CSR | `/v2/account/measurements`, `/v2/measurements/template` | Body measurements; template depends on category |
| 17 | `/account/settings` | `customer/settings/settings.page` | ❌ CSR | `/v2/account/profile`, `/v2/account/location` | Settings hub |

### 6.3 Authenticated transactional routes — Phases 3-6

| # | Web route | Mobile source | SSR | Endpoints | Phase | Notes |
|---|---|---|---|---|---|---|
| 18 | `/account/wishlist` | `customer/wishlist/wishlist.page` | ❌ CSR | `/v2/wishlist`, `/v2/wishlist/labels` | 3 | |
| 19 | `/account/reviews` | `customer/reviews/reviews.page` | ❌ CSR | `/v2/account/reviews` | 3 | User's own reviews |
| 20 | `/cart` | `customer/cart/cart.page` | ❌ CSR | `/v2/cart`, `/v2/cart/items` | 4 | |
| 21 | `/checkout` | `customer/checkout/checkout.page` | ❌ CSR | `/v2/checkout/initiate`, `/v2/account/addresses`, `/v2/shipping/cities`, `/v2/shipping/areas/:id` | 5 | |
| 22 | `/checkout/success` | `customer/success/success.page` | ❌ CSR | `/v2/orders/:id` | 5 | |
| 23 | `/checkout/failed` | `customer/failed/failed.page` | ❌ CSR | — | 5 | |
| 24 | `/checkout/processing` | `customer/process/process.page` | ❌ CSR | `/v2/checkout/finalize` (polling) | 5 | Webhook landing target |
| 25 | `/account/orders` | `customer/my-orders/my-orders.page` | ❌ CSR | `/v2/orders` | 6 | |
| 26 | `/account/orders/:id` | `customer/orders/orders.page` | ❌ CSR | `/v2/orders/:id` | 6 | |

### 6.4 Authenticated communication routes — Phase 7-8

| # | Web route | Mobile source | SSR | Endpoints | Phase | Notes |
|---|---|---|---|---|---|---|
| 27 | `/account/messages` | `customer/messages/messages.page` | ❌ CSR | `/v2/messages/conversations` | 7 | |
| 28 | `/account/messages/:id` | (mobile threads conversation in same page) | ❌ CSR | `/v2/messages/:id` | 7 | |
| 29 | `/account/support` | `customer/ticket-list/ticket-list.page` | ❌ CSR | `/v2/support/tickets` | 7 | |
| 30 | `/account/support/new` | `customer/create-ticket/create-ticket.page` | ❌ CSR | `/v2/support/tickets` (POST) | 7 | |
| 31 | `/account/support/:id` | `customer/ticket-messages/ticket-messages.page` | ❌ CSR | `/v2/support/tickets/:id/messages` | 7 | |
| 32 | `/account/chat` | `pages/chat-vendors/chat-vendors.page` | ❌ CSR | `/v2/chat/vendors`, `/v2/chat/unread` | 8 | Per-order chat hub |
| 33 | `/account/chat/:vendorId` | `pages/chat-orders/chat-orders.page` | ❌ CSR | `/v2/chat/vendors/:id/orders` | 8 | Pick which order to chat about |
| 34 | `/account/chat/:vendorId/:orderId` | `pages/chat/chat.page` | ❌ CSR | `/v2/chat/conversations/...` | 8 | Actual chat |

### 6.5 Auth routes (Phase 1)

| # | Web route | Mobile source | SSR | Endpoints | Notes |
|---|---|---|---|---|---|
| 35 | `/login` | `public/login/login.page` | 🟡 SSR shell | `/v2/auth/login` | |
| 36 | `/register` | `public/register/register.page` | 🟡 SSR shell | `/v2/auth/register`, `/v2/auth/validate-email`, `/v2/auth/validate-phone`, `/v2/auth/send-otp`, `/v2/auth/confirm` | |
| 37 | `/reset` | `public/reset/reset.page` | 🟡 SSR shell | `/v2/auth/reset`, `/v2/auth/send-otp` | |

### 6.6 Skipped or merged

| Mobile page | Why skipped |
|---|---|
| `customer/styles/*` | Custom-style submission. Phase 2 entry will decide if web supports it; may defer to later phase. |
| `public/intro/intro.page` | Mobile-only first-launch tutorial. Web doesn't need it. |
| `public/single/single.page` | Generic "single content" page (legal copy?). Investigate Phase 1; may become `/legal/:slug`. |
| `vendor/store-dashboard/*` | Out of scope (vendor admin). |
| `pages/vendor-chat-list/*` | Out of scope (vendor admin). |

---

## 7. Phased roadmap

Each phase has explicit entry criteria, exit criteria, and a "what ships" boundary. **No phase begins until the prior phase exits cleanly.**

### Phase 0 — Cleanup & catalog completion

**Duration:** 0.5 weeks
**Status:** Ready to start

**Entry criteria:**
- 4 PRs queued: `fix/component-field-init-order`, `fix/ui-heading-ssr-projection`, `feat/w2-2b-pdp-completion`, `chore/phase-4-followup`

**Work:**
- Merge the 4 queued PRs in recommended order
- Implement W2.2c — proper HTTP 404 responses for invalid product/category slugs (currently returns HTTP 200 with a "not found" page; bad for SEO)
- Hard-refresh prod verification of every fixed page

**Exit criteria:**
- All PRs merged
- `/product/this-doesnt-exist` returns HTTP 404
- `/category/this-doesnt-exist` returns HTTP 404
- Browser-side hydration verified clean on at least one PDP, category, home

**Ships:** Fully-shipped catalog browse with no known production bugs.

---

### Phase 1 — Home Page (full)

**Duration:** 2.5–3 weeks
**Status:** After Phase 0
**Detail:** See Section 8.

**Entry criteria:**
- Phase 0 closed cleanly. Catalog browse stable.
- Card design system locked in §4.4 (done).

**Backend wrappers needed (~3 + 1 helper):**
- `/v2/products?filter=featured&limit=12` — featured products strip
- `/v2/products?sort=best-sellers&limit=12` — best sellers strip
- `/v2/products?sort=newest&limit=12` — new arrivals strip
- `/v2/featured-vendors?limit=4` — designer spotlight (returns vendors with embedded product thumbnails, mirrors mobile's `customer/featured` shape)

**Frontend:**
- New design-system primitives:
  - `ProductCardComponent` rebuilt to the §4.4 spec (Pronounced shadow, rounded card + inset image, cream surface, Cormorant + Playfair + Inter typography hierarchy)
  - `ProductStripComponent` — horizontal-scrolling row of product cards with section heading + "View all" link
  - `DesignerSpotlightComponent` — vendor card with embedded product thumbnails (parallel to mobile's "Popular Stores" but richer)
- Home page restructure (`/` route):
  1. Hero band with brand identity + value prop + scroll-down indicator
  2. Categories grid (8 tiles linking to `/category/:slug`) — keeping current design
  3. Featured Products strip
  4. Best Sellers strip
  5. New Arrivals strip
  6. Designer Spotlight (4 vendor cards)
  7. Site footer (newsletter signup placeholder, social links, legal links, contact)
- Responsive: 4 cards per row desktop, 3 tablet, 2 mobile (and horizontal scroll on small screens for strips)
- Skeleton states for each strip (avoid jarring blank-then-content)
- All content prerenderable for SEO (JSON-LD `WebSite` schema with `SearchAction` for the search box)

**Ships:** A home page that works as both a landing page (first-time visitor) and a discovery surface (returning visitor). Premium-feel product cards locked in across the site.

**Exit criteria:**
- Home page Lighthouse score ≥ 92 performance, 100 accessibility
- All four backend wrappers respond with valid `/v2/` envelope
- Home page renders correctly on mobile (320px), tablet (768px), desktop (1280px+)
- All product cards on the home page link to PDPs that already work (i.e. no broken paths)

---

### Phase 2 — Cart (local-first)

**Duration:** 1.5–2 weeks
**Status:** After Phase 1

**Entry criteria:**
- Phase 1 closed. Card design + product strip pattern in production.

**No backend wrappers in this phase.** Cart is local-only until Phase 3.

**Frontend:**
- `CartService` — signal-backed singleton managing `localStorage` cart
  - `add(product, size, color, quantity)` — push or merge with existing line
  - `remove(lineId)` — drop a line
  - `update(lineId, quantity)` — change quantity (0 = remove)
  - `clear()` — empty cart
  - `total()` — computed signal of line totals (price × quantity, summed)
  - `count()` — computed signal of total item count for header badge
  - `getMergePayload()` — exposed for Phase 3's auth-flow merge step
- `CartDrawerComponent` — slide-in panel from right edge
  - Triggered from cart icon in header
  - Shows line items with thumbnails + size/color/quantity
  - Quick quantity ± and remove
  - Subtotal at bottom
  - "View cart" button → `/cart`
  - "Checkout" button → triggers Phase 3 auth flow (stub for now: redirects to a placeholder)
- `/cart` page — full-screen cart view
  - Same data as drawer, fuller layout
  - "Continue shopping" link
  - "Checkout" button (primary CTA)
  - Shipping/tax shown as "Calculated at checkout"
- `CartIconComponent` — header icon with badge showing `count()`
- Add-to-cart button on PDPs:
  - Size + color picker (uses existing data from product API)
  - Quantity stepper
  - "Add to cart" CTA fires `cartService.add(...)`, opens drawer
  - Optimistic UI — drawer animates in immediately

**Decision points to resolve at Phase 2 entry:**
- Cart line uniqueness key: `product_id + size + color`? (Same product in two colors = two lines.)
- Cart expiry: never (unless explicitly cleared by user)? Or 30 days idle?
- Header layout: where does the cart icon sit? (Recommendation: top-right, alongside login link.)

**Ships:** Users can add things to cart, see persistent cart across sessions, modify cart contents — all without an account. Checkout button is wired but disabled until Phase 3.

**Exit criteria:**
- Cart survives page refresh, navigation, and browser restart (localStorage works)
- Cart drawer opens/closes smoothly on every page
- Cart total math is correct to the cent
- Empty cart state is designed (not blank)

---

### Phase 3 — Auth + Checkout

**Duration:** 6–7 weeks (most complex phase, most concurrent backend work)
**Status:** After Phase 2

**Entry criteria:**
- Phase 2 closed. Cart works end-to-end as guest.
- Payment provider identified.
- Web SDK / integration pattern confirmed for the payment provider.
- Webhook URLs decided (mobile vs web; can be shared if signature-validated).
- CORS confirmed: backend includes `https://staging.3bayti.ae` and `https://3bayti.ae`.

**Backend wrappers needed (~18):**

*Auth (7):*
- `/v2/auth/login` — POST
- `/v2/auth/register` — POST
- `/v2/auth/validate-email` — POST
- `/v2/auth/validate-phone` — POST
- `/v2/auth/send-otp` — POST
- `/v2/auth/confirm` — POST
- `/v2/auth/reset` — POST

*Account (4):*
- `/v2/account/profile` — GET/PUT
- `/v2/account/addresses` — GET/POST/PUT/DELETE
- `/v2/account/measurements` — GET/PUT
- `/v2/measurements/template` — GET (per-category measurement schema)

*Cart server-merge (3):*
- `/v2/cart` — GET (read user's server cart)
- `/v2/cart/items` — POST (add an item, used during merge)
- `/v2/cart/items/:id` — PATCH (quantity), DELETE (remove)

*Checkout & payment (~5):*
- `/v2/checkout/initiate` — POST (creates payment session, returns PSP URL/token)
- `/v2/checkout/finalize` — POST (webhook handler)
- `/v2/checkout/otp/send` & `/v2/checkout/otp/validate`
- `/v2/shipping/cities` & `/v2/shipping/areas/:cityId` (proxy to Topex)

**Frontend:**

*Auth foundation:*
- `AuthService` (signal-based) — `user`, `isAuthenticated`, `login()`, `register()`, `logout()`, `refreshUser()`
- HTTP interceptor: attaches `Authorization: Bearer <token>` header; on 401, clears localStorage and redirects to login
- Route guards: `authGuard` (redirect to login), `guestGuard` (redirect away from login if already auth'd)
- Header: "Login" / "Register" → user-avatar dropdown menu when authenticated

*Inline auth modal (the v1.1 unique pattern):*
- `AuthModalComponent` — single modal with toggle between "Sign in" and "Create account" tabs
- Triggered by:
  - "Checkout" button in cart drawer / cart page
  - Header "Sign in" link
  - Any auth-gated action (e.g. follow vendor, write review)
- On successful auth, fires the cart-merge step (see Decision 5):
  - Posts local cart payload to `/v2/cart/items` (one POST per line item, or batch endpoint if backend supports it)
  - Awaits all merges
  - Clears localStorage cart on success
  - Continues original flow (e.g. resumes checkout)

*Account pages:*
- `/account` — hub with profile snapshot + links
- `/account/profile` — view/edit profile
- `/account/addresses` — list/add/edit/delete shipping addresses
- `/account/measurements` — body measurements per category
- `/account/settings` — language, location, password change

*Auth pages (for direct entry without modal):*
- `/login` — same form as modal but full-page
- `/register` — multi-step: email/phone → OTP → password → profile
- `/reset` — password reset flow

*Checkout flow:*
- `/checkout` — multi-step:
  - Step 1: Auth check — if guest with cart, show inline auth modal
  - Step 2: Address selection (or add new) — Topex city/area dropdowns
  - Step 3: Shipping options
  - Step 4: Payment method
  - Step 5: Order review
  - Step 6: Payment processing (redirect-based or hosted iframe)
- `/checkout/processing` — webhook return target
- `/checkout/success` — order confirmation
- `/checkout/failed` — error recovery with retry
- Form validation real-time, full keyboard nav, error announcements

**Risks (highest of any phase):**
- Payment provider may require server-side rendering of forms (incompatible with our CSR auth-pages strategy)
- 3DS challenges on mobile and tablet
- Apple Pay / Google Pay tokenisation if supported
- Webhook ordering races (success page renders before webhook fires)
- Cart-merge race conditions if user has two tabs open

**Ships:** Complete authentication, account management, and checkout flow. Money moves from customer to merchant via the payment provider. Local cart automatically migrates to server cart on first login.

**Exit criteria:**
- A new user can register inline at checkout, complete a purchase, and reach an order confirmation — under 4 minutes total from cart entry.
- A returning user can log in (header dropdown), see their cart restored from server, edit, checkout, complete payment.
- A user with items in local cart who logs in sees those items merged into their server cart.
- 401 responses cause auto-logout and redirect.
- All `/account/*` pages have `<meta name="robots" content="noindex">`.
- All Phase 3 endpoints have `v2_init()` global error handlers.
- E2E test suite passes the core happy paths (register-at-checkout, login-and-resume-cart, complete-purchase, order-failed-retry).
- Lighthouse: ≥90 performance on `/login`, `/register`, `/account`, 100 accessibility on all auth/checkout pages.

---

### Phase 4 — Orders + Order Detail

**Duration:** 2–3 weeks
**Status:** After Phase 3

**Backend wrappers needed (~2):**
- `/v2/orders` (GET, paginated)
- `/v2/orders/:id` (GET)

**Frontend:**
- `/account/orders` listing with status filter
- `/account/orders/:id` detail page
- Tracking integration if available (Topex shipping?)
- "Reorder" button (re-adds line items to cart)
- "Need help?" CTA → opens support ticket pre-filled with order context

**Ships:** Users can see their order history and individual order details.

---

### Phase 5 — Wishlist + Reviews

**Duration:** 3–4 weeks
**Status:** After Phase 4

**Wishlist follows the same local-first → merge-on-login pattern as cart** (Decision 5 applied to wishlist).

**Entry criteria:**
- Phase 4 closed.
- Cart-merge mechanism from Phase 3 reused for wishlist-merge.

**Backend wrappers needed (~7):**
- `/v2/wishlist` (GET/POST/DELETE)
- `/v2/wishlist/labels` (GET/POST/DELETE)
- `/v2/wishlist/items` (GET/POST/DELETE)
- `/v2/reviews` (POST)
- `/v2/reviews/:id/helpful` (POST)
- `/v2/account/reviews` (GET/DELETE)
- `/v2/vendors/:slug/reviews` (GET) — public-readable

**Frontend:**
- `WishlistService` mirroring `CartService` pattern — local-first, signal-backed, merge-on-login
- "Add to wishlist" heart button on PDPs and product cards (click = local-add for guests, server-add for auth'd)
- `WishlistDrawerComponent` (parallel to cart drawer) — quick view from any page
- `/account/wishlist` with label-based collections (only shows after login; guests see "Sign in to organise your saves")
- "Add to wishlist" picker (select label or create new)
- Write-review form on PDPs (after order delivered)
- Write-review form on designer pages (after first order from them)
- `/account/reviews` showing user's own reviews
- "Helpful" button on review cards (already-rendered review UI from W2.2b)

**Ships:** Engagement loop. Users can save products as guests, write reviews after purchase, mark reviews helpful.

---

### Phase 6 — Browse Plus

**Duration:** 3–4 weeks
**Status:** After Phase 5

**Entry criteria:**
- Phase 5 closed.

**Backend wrappers needed (~9):**
- `/v2/search?q=...&filters=...`
- `/v2/best-sellers` (paginated, with filters)
- `/v2/new-arrivals` (paginated, with filters)
- `/v2/explore` and `/v2/explore/:vertical`
- `/v2/labels/:label/products`
- `/v2/vendors/:slug/products` (paginated)
- `/v2/vendors/:slug/collections`
- `/v2/vendors/:slug/follow` (POST + DELETE)
- Augment `/v2/products` with rich filter params (size, colour, price-range, vendor)

**Frontend:**
- `/search` route with debounced search-as-you-type
- `/designer` index + `/designer/:slug` detail (replacing modal-based mobile UX)
- `/best-sellers`, `/new-arrivals`, `/explore`, `/explore/:vertical`
- "Follow designer" button on designer pages (auth-gated — triggers inline auth modal)
- Filter UI (faceted: size, colour, price, vendor) on category and search pages
- Updated home page strips can deep-link to these listing pages via the "View all" link

**Ships:** Full discovery surface. Users can search, browse paginated listings, follow designers.

**Exit criteria:**
- Search returns results with relevance ranking
- All listing pages paginate correctly (no infinite-scroll on web — use page-based)
- Follow/unfollow persists across sessions
- All routes have full SEO metadata

---

### Phase 7 — Messages + Tickets

**Duration:** 3–4 weeks

**Backend wrappers needed (~7):**
- `/v2/messages/conversations`
- `/v2/messages/:id`
- `/v2/messages/:id/send`
- `/v2/support/tickets` (GET/POST)
- `/v2/support/tickets/:id/messages` (GET/POST)

**Frontend:**
- `/account/messages` and threaded conversation page
- `/account/support` ticket list
- `/account/support/new` ticket creation form
- `/account/support/:id` ticket conversation
- Real-time updates strategy (polling vs SSE vs WebSocket — decide at phase entry)

**Ships:** Users can communicate with vendors and with platform support.

---

### Phase 8 — Chat module integration

**Duration:** 2–3 weeks

**Backend wrappers needed (~9):**
- All `/v2/chat/*` endpoints listed in Section 5.13

**Frontend:**
- `/account/chat` hub
- `/account/chat/:vendorId` order picker
- `/account/chat/:vendorId/:orderId` chat interface
- Image upload via standard `<input type="file">` (web equivalent of camera)
- Quick-prompt picker
- Personal-info-detection client-side warnings (mirror mobile module)
- Real-time updates (same strategy as Phase 7)

**Ships:** Complete per-order chat with vendors, parity with mobile chat module.

---

### Phase 9 — Hardening + observability

**Duration:** 2–3 weeks

**Work:**
- Sentry browser SDK
- Cloudflare Analytics dashboard
- Error budget definition
- Lighthouse CI integration
- E2E coverage on critical paths (login, add-to-cart, checkout, place-order)
- Performance budget enforcement
- A11y audit + fixes
- Cookie banner / privacy compliance (GDPR-style if EU traffic; UAE consumer protection)
- Rate limiting on auth endpoints
- CSP tightening

**Ships:** Production-grade observability. Site monitored, errors surfaced, performance budget enforced.

---

## 8. Phase 1 deep dive — Home Page (full)

This is the next phase. Deep detail because we'll start coding it.

### 8.1 Phase 1 entry checklist

Before starting Phase 1:

- [ ] Phase 0 exit criteria all met (4 PRs merged, W2.2c 404 fix shipped)
- [ ] Card design language locked (§4.4 — done)
- [ ] Backend `/v2/products?filter=...&sort=...` query parameter shape agreed
- [ ] Mobile app's `customer/featured` response inspected to design `/v2/featured-vendors` envelope
- [ ] Decision: home page hero treatment — keep current static hero, or design a new hero band? (Current recommendation: keep the existing hero structure, refresh typography to match the locked design system, defer photographic hero to a later editorial pass)

### 8.2 Phase 1 architecture

#### Page structure

```
<app-home>
  <hero-band>                     [static; existing structure refreshed]
    <eyebrow>Coming soon</eyebrow>
    <h1>Premium abayas, kaftans...</h1>
    <p>Brand value prop</p>
    <cta>Shop the collection</cta>
  </hero-band>

  <categories-grid>                [existing component, restyled]
    8 category tiles → /category/:slug
  </categories-grid>

  <product-strip>                  [NEW — Featured Products]
    <strip-heading>This week's edit</strip-heading>
    <horizontal-scroll>
      <ui-product-card> × 12       [Pronounced shadow, cream surface]
    </horizontal-scroll>
  </product-strip>

  <product-strip>                  [NEW — Best Sellers]
    <strip-heading>Best sellers</strip-heading>
    <horizontal-scroll>...
  </product-strip>

  <product-strip>                  [NEW — New Arrivals]
    <strip-heading>New arrivals</strip-heading>
    <horizontal-scroll>...
  </product-strip>

  <designer-spotlight>             [NEW — vendor-featured block]
    <strip-heading>Designers we love</strip-heading>
    <vendor-cards> × 4
      <vendor-name + rating>
      <vendor-description>
      <product-thumbnails> × 4
    </vendor-cards>
  </designer-spotlight>

  <site-footer>                    [NEW — proper footer]
    <newsletter-signup placeholder>
    <link-columns>
      Shop / About / Help / Legal
    </link-columns>
    <social-links>
    <copyright>
  </site-footer>
</app-home>
```

#### Data flow

Each strip is independently lazy-loaded with TransferState for SSR-to-client handoff:

1. Server renders skeleton (loading shimmer for each strip)
2. Server fetches the 4 endpoints in parallel during SSR
3. Server inlines the data into TransferState
4. Client hydrates with data already present — no re-fetch

If an endpoint fails (5xx), the strip silently omits itself (don't ship a broken strip). Empty data → strip renders a "Coming soon" pill.

#### Design system primitives (built in Phase 1, used everywhere from here)

- `ProductCardComponent` — rebuilt to §4.4 spec
- `ProductStripComponent` — encapsulates section heading + horizontal scroll + "View all" link
- `DesignerCardComponent` — vendor card with embedded products
- `SiteFooterComponent` — global footer (used on every page from here)
- `SkeletonShimmerComponent` — loading placeholder

### 8.3 Phase 1 backend deliverables

**4 endpoints to write.** Each follows the W2.0 patch shape: single `.php` file, `v2_init()` at top, consistent envelope, debug mode supported, appropriate Cache-Control.

#### Backend deliverables (Week 1)

1. `/v2/products?filter=featured&limit=12` — returns 12 featured products. Cache: `public, max-age=300, s-maxage=600`.
2. `/v2/products?sort=best-sellers&limit=12` — returns top 12 by aggregate sales. Cache: 1 hour edge.
3. `/v2/products?sort=newest&limit=12` — most recently added 12. Cache: 5 minutes edge.
4. `/v2/featured-vendors?limit=4` — returns 4 vendors with embedded `products[]` (max 4 thumbnails per vendor). Cache: 1 hour edge.

These 3 sort/filter variants on `/v2/products` may share a single PHP file with branch logic, or be separate files — implementation detail. Either way, the response envelope must be identical to the existing `/v2/products` shape so the existing `Product` TypeScript model works.

### 8.4 Phase 1 frontend deliverables

#### Week 1 — Design system primitives

- [ ] **`ProductCardComponent` rebuild** — port to §4.4 spec
  - Cream surface (`#fdfaf3`), 20px card radius, 14px image radius with 14px padding
  - Pronounced shadow at rest, deepening on hover with 6px lift
  - Cormorant italic vendor → Playfair product → Inter price/rating typography
  - Gold ornament divider
  - Frosted-glass badge + like button
  - Out-of-stock and sale-price treatments
- [ ] **`ProductStripComponent`** — horizontal-scroll row
  - Section heading (Playfair, brand-700)
  - "View all" link aligned right
  - Horizontal scroll with snap-to-card
  - Custom scroll affordances (left/right arrows on desktop)
  - Touch swipe on mobile
- [ ] **`DesignerCardComponent`** — vendor with thumbnails
- [ ] **`SkeletonShimmerComponent`** — reusable loading placeholder
- [ ] **`SiteFooterComponent`** — global footer

#### Week 2 — Home page assembly

- [ ] Refresh hero band typography to match new design system
- [ ] Categories grid restyled (cards adopt new shadow language)
- [ ] Wire 3 product strips (Featured, Best Sellers, New Arrivals) to `/v2/products?...` endpoints
- [ ] Wire Designer Spotlight to `/v2/featured-vendors`
- [ ] All sections SSR'd via TransferState
- [ ] Skeleton states for all dynamic sections
- [ ] Responsive: 4-up desktop, 3-up tablet, 2-up small tablet, 1.5-up mobile (peeking next card to invite scroll)

#### Week 3 — Cascade + polish

- [ ] **Apply new card design across the site** — category page, related-products, designer pages
- [ ] Update `product-card.scss` once; the card is shared everywhere
- [ ] Visual regression check: every page that uses `ui-product-card` must look right under the new design
- [ ] JSON-LD `WebSite` schema with `SearchAction` for the home page
- [ ] OG images and Twitter card metadata for the home page
- [ ] Performance audit: home page should load in < 2.5s LCP on 4G

### 8.5 Phase 1 risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| New `ui-product-card` design breaks one of the existing usages (category page, related products) | Medium | Medium | Visual regression check before merge; test all card-using pages |
| `/v2/featured-vendors` data shape doesn't fit our card pattern (vendor name truncation, rating absence, etc.) | Medium | Low | Component handles defensive cases (no rating, long names, empty product arrays) |
| Heavy hero photography pushes LCP above 2.5s | Medium | Medium | Defer photographic hero to later phase; ship typography-only hero first |
| Horizontal scroll on mobile creates jankiness on low-end devices | Low | Medium | CSS-only scroll snap; no JS-driven smooth scroll; test on 3-year-old mid-tier Android |
| Product strip shows fewer than 12 cards (small dataset) — strip looks empty | High | Low | Hide strip when less than 4 cards returned |

### 8.6 Phase 1 exit criteria

- Home page Lighthouse: ≥92 performance, 100 accessibility, ≥95 best practices, ≥95 SEO.
- All four backend wrappers respond with valid `/v2/` envelope and proper Cache-Control headers.
- Home page renders correctly on 320px (mobile), 768px (tablet), 1280px (desktop), 1920px (wide).
- All product cards across the site (category, related, search, designer pages) use the new §4.4 design.
- Skeleton states display while strips load; failed strips silently omit themselves.
- Site footer is on every page (home, category, PDP, etc.).
- All product card hover states feel correct on mouse, touch, and keyboard navigation.

---

## 9. Cross-cutting concerns

These apply across all phases and should be reviewed at every phase entry.

### 9.1 Performance budgets

| Metric | Budget | Enforcement |
|---|---|---|
| First Contentful Paint (4G, mid-tier mobile) | < 1.8s | Lighthouse CI in CI/CD |
| Largest Contentful Paint | < 2.5s | Lighthouse CI |
| Time to Interactive | < 3.5s | Lighthouse CI |
| Cumulative Layout Shift | < 0.1 | Lighthouse CI |
| Bundle size (initial JS, gzipped) | < 200 KB | Custom CI check |
| Worker bundle size | < 50 KB | Custom CI check (currently 8 KB ✓) |
| Page weight (HTML, gzipped) | < 50 KB | Custom CI check |

Pages exceeding budget block PR merge. Authenticated CSR pages are exempt from FCP/LCP budgets (skeleton SSR only, real content is post-hydration).

### 9.2 Accessibility

- Every interactive element keyboard-reachable.
- Every form field has a visible label and `aria-describedby` for errors.
- All status changes announced via ARIA live regions.
- Lighthouse a11y score = 100 on every public page.
- axe-core run as part of every E2E test.
- Screen-reader testing: VoiceOver on macOS Safari, NVDA on Windows Firefox — at minimum at every phase exit.
- Colour contrast: WCAG AA minimum, AAA on body text.

### 9.3 Internationalisation (i18n)

Mobile app supports English + Arabic with RTL layout. Web should match.

**Phase plan:**
- Phase 1: Build all UI in English only. Use `i18n` Angular markers from day one (don't bake strings).
- Phase 2: Add Arabic translation files. Test RTL layout.
- Phase 9: Final i18n audit before public launch.

Translation key naming: `feature.component.element` (e.g. `auth.login.submit-button`).

### 9.4 Testing strategy

| Layer | Coverage target | Tools |
|---|---|---|
| Unit (services, pipes, computed signals) | 80%+ on logic-heavy code | Jasmine + Karma |
| Component | Smoke tests at minimum | Angular Testing Library or vanilla |
| E2E | Every critical path (login → buy → support) | Playwright (Phase 5+) |
| Visual regression | Optional — defer to Phase 9 | Percy or Chromatic |
| API contract | Every `/v2/*` endpoint has a contract test | Custom — schema validation per response |

### 9.5 Observability

| Concern | Tool | Phase |
|---|---|---|
| Browser errors | Sentry | 9 |
| Page-level analytics | Cloudflare Web Analytics or Plausible | 9 |
| Server-side errors (Worker) | Cloudflare Logpush + custom dashboard | 9 |
| API error rate | Backend already returns `v2_init` errors; aggregate centrally | 9 |
| Real User Monitoring (RUM) | Cloudflare RUM | 9 |
| Alerts | TBD — paging for 5xx spike, error rate spike | 9 |

### 9.6 Security

| Concern | Mitigation |
|---|---|
| XSS | Angular's built-in template sanitization; never use `[innerHTML]` for user content |
| CSRF | Stateless `Authorization: Bearer` header (no cookies → less CSRF surface) |
| Token theft via XSS | Accepted risk per Decision 4. Mitigated by short-lived tokens (TBD: refresh strategy in Phase 1) |
| Brute force on login | Backend rate-limits `/v2/auth/login` per IP + per email (Phase 1 backend deliverable) |
| Account enumeration | Login error: "Invalid credentials" (don't say "no such email") |
| OTP reuse | Single-use, 5-minute expiry |
| Session fixation | New token issued on login; invalidate prior on logout |
| Sensitive data in URL | Never put tokens or PII in URLs |
| HTTPS | Cloudflare enforces (HSTS preload eligible) |
| Cookie security (when added in Phase 5 for payment redirect) | `Secure`, `HttpOnly`, `SameSite=Lax` |
| Content Security Policy | Tighten in Phase 9; today permissive |
| Dependency security | `npm audit` in CI; Dependabot |

### 9.7 SEO

Already implemented:
- Sitemap.xml generated postbuild (W2.0)
- robots.txt
- Per-page meta titles, descriptions, canonical URLs
- OpenGraph + Twitter cards
- Schema.org JSON-LD on PDPs (Product, BreadcrumbList) — W2.2b
- Real H1/H2 in prerendered HTML — W2.2b ui-heading fix

To do later:
- Schema.org JSON-LD on category pages (`CollectionPage`)
- Schema.org JSON-LD on designer pages (`Organization` or `Brand`)
- Schema.org `WebSite` with `SearchAction` (when search ships in Phase 2)
- Structured FAQ markup if/when we add FAQ pages
- hreflang tags for English/Arabic when i18n ships

### 9.8 Data model concerns

The mobile app's data shapes need mapping to TypeScript interfaces shared between web and (eventually) future projects. We have started this in `src/app/features/catalog/product.model.ts` etc. Each phase should add models for its domain.

**Recommendation:** Centralise these in `src/app/core/models/` once the shape stabilises (around Phase 2).

### 9.9 Versioning the website itself

Currently no version tag on the website. As we ship phases, recommend tagging Git releases:
- `v0.7.0` after Phase 0
- `v1.0.0` after Phase 1 (auth — first public-launchable milestone if needed)
- And so on

Tag releases at every phase exit for rollback safety.

---

## 10. Risk register

Sorted by severity. "Likelihood × Impact" dictates priority.

### High-severity risks

| # | Risk | Likelihood | Impact | Mitigation | Phase |
|---|---|---|---|---|---|
| R1 | Payment provider integration is more complex than 4–5 weeks (e.g. requires server-side rendering of forms; doesn't have web SDK) | Medium | Critical | Phase 5 entry checklist requires identifying the provider and confirming web pattern before starting; pre-buffer 2 extra weeks | 5 |
| R2 | Backend wrapping work falls behind frontend; web blocked waiting for endpoints | High | High | Stagger backend deliverables 1 phase ahead of frontend; me handling both reduces handoff cost | All |
| R3 | Auth token leakage via XSS (localStorage) results in account compromise | Low-Medium | Critical | Tightened CSP (Phase 9); never render unsanitised user content; short-lived tokens; logout-everywhere capability | 1, 9 |
| R4 | Scope creep — new features added mid-phase | High (this is exactly how we got here last time) | High | Strict per-phase exit criteria; new features require updating this doc first; new features added to a future phase, not the current one | All |
| R5 | Performance regression from accumulated JS bundle size as we add features | High | High | Performance budget enforced in CI; lazy-load every route from day one (already done) | All |

### Medium-severity risks

| # | Risk | Likelihood | Impact | Mitigation | Phase |
|---|---|---|---|---|---|
| R6 | Mobile app endpoints we wrap have undocumented behaviors (e.g. nullable fields, weird date formats) | High | Medium | Each endpoint gets a manual integration test on first use; `v2_init` debug mode catches real-world surprises | All |
| R7 | OTP delivery via SMS in UAE has provider issues | Medium | Medium | Phase 1 includes user-facing "resend" affordance; backend has fallback provider | 1 |
| R8 | Cart abandonment due to checkout friction on mobile web | Medium | Medium | Phase 5 includes Apple Pay / Google Pay if supported; minimal form fields; address auto-complete | 5 |
| R9 | i18n (Arabic/RTL) breaks in unexpected places when retrofitted | Medium | Medium | Build i18n markers from day one (Phase 1); test RTL during Phase 2; full audit in Phase 9 | All |
| R10 | Search relevance is poor — backend search just does LIKE queries | Medium | Medium | Phase 2 entry includes evaluating search backend; if poor, recommend Algolia/Meilisearch in Phase 9 | 2 |
| R11 | Real-time messaging architecture choice (Phase 7/8) — polling may not feel "real-time" enough | Medium | Medium | Phase 7 entry includes polling-vs-SSE-vs-WebSocket decision; default to polling for simplicity | 7, 8 |

### Low-severity risks

| # | Risk | Likelihood | Impact | Mitigation | Phase |
|---|---|---|---|---|---|
| R12 | GitHub PAT used for git operations gets revoked or rotates without warning | Medium | Low | Plan explicit rotation cadence; switch to deploy-keys when convenient | 0 |
| R13 | Cloudflare Workers free tier limits (100k req/day, 10ms CPU) are exceeded | Low | Medium | Already monitoring usage; upgrade to Workers Paid ($5/mo) before launch | 9 |
| R14 | Mobile app evolves and new endpoints are added that we didn't plan for | High | Low | Quarterly endpoint audit (re-run our extraction script); add new endpoints to roadmap | All |

### Resolved risks

| # | Risk | How resolved |
|---|---|---|
| Hydration crash on category pages (field-init-order) | Fixed in `fix/component-field-init-order` | Pre-Phase 0 |
| Empty H1/H2 in prerendered HTML (ui-heading) | Fixed in `fix/ui-heading-ssr-projection` | Pre-Phase 0 |

---

## 11. Open questions

These are unresolved and should be answered before the relevant phase starts.

### Phase 1 entry questions

1. **JWT format**: Same as mobile or separate web JWTs? *Recommendation: same.*
2. **Token expiry**: How long is mobile JWT valid? Refresh tokens?
3. **Multi-device**: When user logs in on web, should mobile session continue (one user, multiple sessions)? *Recommendation: yes — matches user expectation.*
4. **Logout-everywhere**: Should we offer a "log out from all devices" affordance?
5. **CORS**: What origin(s) are currently allowed by the backend `Access-Control-Allow-Origin`? Need to add `https://staging.3bayti.ae` and `https://3bayti.ae`.

### Phase 4 entry questions

1. **Guest cart**: Does mobile support adding to cart without auth? If not, web shouldn't either.
2. **Cart merge**: When a guest adds to cart then logs in, do we merge guest cart with their server cart?
3. **Cart expiry**: Server-side cart expiry policy (7 days? Never?)

### Phase 5 entry questions

1. **Payment provider**: Which one is the mobile app using? (Search the mobile app's `process` page TS for clues.)
2. **Apple Pay / Google Pay**: Supported on mobile? Required on web?
3. **3DS**: Required for AED transactions in UAE? (Yes — Mada/UAE central bank rules.)
4. **Cash on Delivery**: Does the mobile app support COD? Web must match.
5. **Tax**: VAT (5%) — server-calculated or client-side?
6. **Shipping fees**: How calculated — server (Topex API integration) or fixed?
7. **Address validation**: Topex API-validated, or free-form?

### Phase 7 / 8 entry questions

1. **Real-time strategy**: Polling, SSE, or WebSocket?
2. **Notification: ** When user receives a new message and isn't in `/account/messages`, how do we notify them? In-page toast? Browser notification? Email?
3. **Vendor presence**: Show vendor as online/offline?

---

## Appendix A — Glossary

| Term | Definition |
|---|---|
| **PDP** | Product Detail Page — the `/product/:slug` page. |
| **PLP** | Product Listing Page — `/category/:slug`, `/search`, `/best-sellers`, etc. |
| **JWT** | JSON Web Token — signed authentication token format. |
| **CSR** | Client-Side Rendering — page renders in browser, server delivers a shell. |
| **SSR** | Server-Side Rendering — full HTML rendered on server, sent to browser. |
| **Prerender** | SSR done at build time and stored as static HTML. |
| **TTFB** | Time To First Byte — server response latency. |
| **FCP** | First Contentful Paint — first visible content. |
| **LCP** | Largest Contentful Paint — main content visible. |
| **TTI** | Time To Interactive — page is responsive to user input. |
| **CLS** | Cumulative Layout Shift — measure of unexpected layout movement. |
| **PSP** | Payment Service Provider — e.g. Stripe, PayTabs, Telr, Network International. |
| **3DS** | 3D Secure — bank authentication challenge for online card payments. |
| **MO** | Mobile Origin — used to indicate behavior originated from the mobile app. |
| **Topex** | Third-party shipping provider (`shipperapi.topex.ae`) used for UAE address city/area lookups. |
| **W2.x** | Workstream 2 (catalog) phases. Predates this roadmap; preserved for git history continuity. |

---

## Appendix B — Decision log

Each major decision is logged here with date and rationale.

| Date | Decision | Made by | Rationale |
|---|---|---|---|
| 5 May 2026 | Full customer-side parity is the website scope | Project owner | Q1 in roadmap-scoping conversation |
| 5 May 2026 | Web UX wins over mobile UX in conflict (within behavior parity) | Project owner | Q2 in roadmap-scoping conversation |
| 5 May 2026 | Build `/v2/` wrappers for every endpoint web needs | Project owner | Q3 in roadmap-scoping conversation |
| 5 May 2026 | JWT in localStorage for web auth | Project owner | Trade-off: ship speed > XSS hardening |
| 5 May 2026 | Phase 1 = Auth + Account | Project owner | Unlocks every gated downstream feature |
| 5 May 2026 | Comprehensive roadmap document delivered before Phase 1 work begins | Project owner | Hedge against scope drift |
| 5 May 2026 | Vendor admin pages out of scope for web | Engineering recommendation | Vendor admin → mobile-only minimises scope |
| 5 May 2026 | Web URLs are SEO-friendly slug paths, not 1:1 with mobile routes | Engineering recommendation | SEO-driven routing is a hard requirement |
| 5 May 2026 | "Designer" naming on web (vs "Vendor" in mobile/internal) | Engineering recommendation | Better for SEO and UX |
| 5 May 2026 | **v1.1**: Phase 1 = Home Page (full), not Auth | Project owner | Site must be browsable end-to-end without login; auth required only at checkout |
| 5 May 2026 | **v1.1**: Cart is local-first (`localStorage`) for guests, merged on login | Project owner | Industry-standard e-commerce pattern; preserves discovery experience |
| 5 May 2026 | **v1.1**: Wishlist follows the same local-first → merge-on-login pattern | Project owner | Consistency with cart; lower friction for save-for-later behaviour |
| 5 May 2026 | **v1.1**: Checkout uses inline login-or-register modal, not redirect | Project owner | Reduces cart abandonment; account is created in-flow rather than as a wall |
| 5 May 2026 | **v1.1**: Card design = "Gilded Boutique" with Pronounced shadow, rounded card + inset image, cream surface | Project owner | Modest-luxury aesthetic, marketplace-appropriate; locked in §4.4 |
| 5 May 2026 | **v1.1**: Home page goes beyond mobile-app parity | Project owner | Mobile home is a teaser funnel; web home is a full landing + discovery surface |

---

## Appendix C — References

### Internal

- W2.0 backend patch README (deployed): the source-of-truth for the backend wrapping pattern. Sodiq has the deployed copy on the server.
- Previous progress report: `3bayti-web_progress_report_2026-05-05.md`
- Mobile app repo: [`surdbells/abayti_app`](https://github.com/surdbells/abayti_app)
- Web repo: [`surdbells/3bayti-web`](https://github.com/surdbells/3bayti-web)
- Production: [`https://staging.3bayti.ae`](https://staging.3bayti.ae)
- Backend (private): `https://api.3bayti.ae/v2/`

### External

- Angular SSR docs: [`https://angular.dev/guide/ssr`](https://angular.dev/guide/ssr)
- Angular Signals: [`https://angular.dev/guide/signals`](https://angular.dev/guide/signals)
- Cloudflare Workers + Static Assets: [`https://developers.cloudflare.com/workers/static-assets`](https://developers.cloudflare.com/workers/static-assets)
- Schema.org Product: [`https://schema.org/Product`](https://schema.org/Product)
- WCAG 2.1 quick reference: [`https://www.w3.org/WAI/WCAG21/quickref/`](https://www.w3.org/WAI/WCAG21/quickref/)
- Topex Shipper API: contact via Topex (no public docs URL known)

---

*End of roadmap document. Maintain in lockstep with reality. When this doc and the code disagree, update one or the other immediately.*



