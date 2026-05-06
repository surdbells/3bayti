# 3bayti — Platform Roadmap

**Version:** 2.1
**Date:** 6 May 2026
**Author:** Engineering (Claude + Sodiq)
**Scope:** Whole platform — backend API, customer web, customer + vendor mobile, vendor + admin portal
**Future monorepo:** [`surdbells/3bayti`](https://github.com/surdbells/3bayti) (planned, not yet created)
**Component repos (current):**
- [`surdbells/3bayti-web`](https://github.com/surdbells/3bayti-web) — customer web (Angular 21 SSR), production at [https://staging.3bayti.ae](https://staging.3bayti.ae)
- [`surdbells/abayti_app`](https://github.com/surdbells/abayti_app) — mobile app (Ionic Angular + Capacitor 8), customer + lite vendor surface
- [`surdbells/abayti_vendor`](https://github.com/surdbells/abayti_vendor) — vendor + admin portal (Angular 19, desktop)
- Backend (private) — PHP 8 + MySQL, deployed at `https://api.3bayti.ae`

### Changelog

| Version | Date | Changes |
|---|---|---|
| **2.1** | 6 May 2026 | **Order processing lifecycle standardised.** Added Decision 12 with 9 sub-decisions covering multi-vendor sub-orders (A2 pattern), separate payment + fulfillment statuses (B2 pattern), separate RMA entity for returns (C2 pattern), Cash on Delivery fully designed and admin-toggle-gated (default OFF), customer cancellation until vendor processes, vendor decline within 24h with auto-refund, admin-configurable unpaid timeout (default 24h), partial returns with vendor-fault shipping refund rules, per-vendor return windows. New §5 with full state machines, schema, endpoints, notifications, audit log. New tables (orders, sub_orders, order_items, payments, return_requests, order_state_history, platform_settings) added to Appendix D. M3 deliverables expanded with state-machine implementation + background jobs + admin settings. M3 entry questions updated to focus on remaining operational decisions. |
| **2.0** | 6 May 2026 | **Strategic direction shift.** Roadmap re-scoped from "single-repo Angular web app + backend wrappers" to "consolidated platform across web + mobile + vendor + backend." Backend gets rewritten on Lodgik/Guard51 stack (Slim 4 + Doctrine 3 + PostgreSQL 16 + JWT + UAE-adapted services) instead of wrap-don't-rewrite. All four codebases consolidate into a new monorepo at `surdbells/3bayti`. Strangler-fig migration; old backend stays live until each surface is moved. Two vendor surfaces stay (desktop portal canonical for power users, mobile store-dashboard canonical for on-the-go actions). Phase 3+ of the original roadmap is paused; the new "M-phases" (M0–M5) replace them and run on the new platform. Section 3 Decision 3 (wrap don't rewrite) and parts of Decision 4 are superseded; Decisions 1, 2, 5, 6 carry forward. New decisions D7–D11 added. Critical security findings logged in §10. |
| 1.1 | 5 May 2026 | Phase order revised based on owner direction: **Home page** ships before auth. Cart-strategy pivots to local-first with merge-on-login. Wishlist follows the same pattern. Checkout adopts inline login-or-register flow (no pure guest checkout, but no upfront login wall either). Card design language locked in §4.4. |
| 1.0 | 5 May 2026 | Initial comprehensive roadmap. |

---

## Document control

| Field | Value |
|---|---|
| Status | Active — drives all subsequent work |
| Audience | Engineering team, project owner, eventual investors / non-technical stakeholders |
| Update cadence | Per phase close. Out-of-cycle updates allowed for scope changes. |
| Source of truth | This document. If a decision contradicts this doc, this doc loses unless updated here too. |
| Versioning | Semver-style. Major (e.g. 2.0) for direction changes that supersede previous decisions. Minor (e.g. 1.1) for scope/phase tweaks. Patch for typo/clarification edits. |

---

## Table of contents

1. [Executive summary](#1-executive-summary)
2. [Reality check — how we got here](#2-reality-check)
3. [Locked decisions (the non-negotiables)](#3-locked-decisions)
4. [Target platform architecture](#4-target-platform-architecture)
5. [Order processing lifecycle](#5-order-processing-lifecycle)
6. [Monorepo structure & tooling](#6-monorepo-structure--tooling)
7. [Backend endpoint inventory (213 endpoints across surfaces)](#7-backend-endpoint-inventory)
8. [Page-by-page surface mapping](#8-page-by-page-surface-mapping)
9. [Migration strategy (strangler-fig, parallel operation)](#9-migration-strategy)
10. [Phased roadmap — pre-monorepo phases (history) + M-phases (forward)](#10-phased-roadmap)
11. [Phase 1 deep dive — Home Page (historical, completed)](#11-phase-1-deep-dive--home-page-historical-completed)
12. [Cross-cutting concerns](#12-cross-cutting-concerns)
13. [Risk register](#13-risk-register)
14. [Open questions](#14-open-questions)
15. [Appendix A — Glossary](#appendix-a--glossary)
16. [Appendix B — Decision log](#appendix-b--decision-log)
17. [Appendix C — References](#appendix-c--references)
18. [Appendix D — Database schema inventory](#appendix-d--database-schema-inventory)
19. [Appendix E — Critical security findings (existing backend)](#appendix-e--critical-security-findings-existing-backend)

---

## 1. Executive summary

**3bayti** is an e-commerce marketplace for modest fashion (abayas, kaftans, modest wear) curated from independent UAE designers. The platform serves three audiences (customers, vendors/designers, platform admins) across four codebases (web, mobile, vendor-portal, backend), all currently running in production at `*.3bayti.ae` for real users and orders.

### Where we are (v1.x history — completed)

- ✅ **Catalog browse on web** end-to-end at `staging.3bayti.ae` — home with hero carousel + product strips + categories + designer spotlight + footer; category index; category detail; PDP with reviews, JSON-LD, related products; soft-and-subtle shadow design language locked in.
- ✅ **Cart on web (local-first)** — Phase 2 shipped: localStorage-backed cart, header badge, /cart page, sold-out treatment on PDP, ToastService, ToastHostComponent, multi-vendor cart support.
- ✅ **Cloudflare Workers + Static Assets** SSR/prerender infrastructure stable at `staging.3bayti.ae`.
- ✅ **W2.0 backend foundation** (`/v2/` namespace) deployed, serving the web's catalog needs (8 read-only endpoints behind `https://api.3bayti.ae/v2/*`).
- ✅ **Mobile app (`abayti_app`)** — 153 TypeScript files, 38 customer pages + 1 lite vendor page (store-dashboard). Ionic Angular 21.1 + Capacitor 8. Ships iOS and Android via Capacitor. **In production with real users.**
- ✅ **Vendor + admin portal (`abayti_vendor`)** — Angular 19.2 SPA, 88 components across 15 functional areas (admin, admin-products, collections, commissions, customers, logistics, processing, returns, sales, stores, tickets, transactions, users, etc.). **In production with real vendors and admins.**
- ✅ **Backend** — PHP 8 + MySQL, 213 endpoints across 8 directories (`customer/`, `customer/v2/`, `vendors/`, `admin/`, `users/`, `chat/`, `utility/`, `webhooks/`). Hosted at `api.3bayti.ae`. **In production.**

### Where we're going (v2.0 — direction lock)

**Strategic decision (6 May 2026, project owner):** consolidate the four codebases into a single monorepo at `surdbells/3bayti`, rebuild the backend on the Lodgik/Guard51 stack, migrate surfaces from old PHP backend onto new API one at a time via strangler-fig, retire the old backend only when feature-parity is reached.

The monorepo will contain (target state):

```
3bayti/                          ← new repo, surdbells/3bayti
├── apps/
│   ├── api/                     ← new backend: Slim 4 + Doctrine 3 + PostgreSQL 16
│   ├── web/                     ← migrated from surdbells/3bayti-web
│   ├── mobile/                  ← migrated from surdbells/abayti_app (Ionic Angular)
│   └── portal/                  ← migrated from surdbells/abayti_vendor (Angular 19→21)
├── packages/
│   ├── api-contracts/           ← OpenAPI spec + generated TypeScript types
│   ├── shared-ui/               ← cross-app components (used by web + portal)
│   ├── design-tokens/           ← brand colors, typography, spacing
│   └── api-client/              ← Angular HttpClient with auth + retry, used by all 3 frontends
├── docs/
│   └── roadmap.md               ← this document, the source of truth
└── tools/
    ├── ci/                      ← GitHub Actions workflows
    └── scripts/                 ← migrations, codegen, deploys
```

The migration is **strangler-fig**: new backend (`apps/api`) runs in parallel with old PHP backend at a new host. Each surface migrates onto the new backend one feature at a time. Old PHP backend stays live throughout — it serves the surfaces that haven't migrated yet. Retired only when all four frontends are off it.

### Phasing summary (v2.0)

The v1.x phases (0, 1, 2) are **completed history** and stay in this document for context. The forward-looking phases are now the **M-phases** (M0 through M5):

| # | Phase | Est. weeks | Status |
|---|---|---|---|
| 0 | **Cleanup** — merge queued PRs + W2.2c (404 status) | 0.5 | ✅ Complete |
| 1 | **Home Page (full)** | 2.5–3 | ✅ Complete (deployed `staging.3bayti.ae`) |
| 2 | **Cart (local-first)** | 1.5–2 | ✅ Complete (deployed) |
| **M0** | **Monorepo foundation** — create `surdbells/3bayti`, scaffold `apps/api` skeleton, wire CI, migrate `3bayti-web` in as `apps/web` | 2–3 | Next |
| **M1** | **Auth & users in new API** — `/v3/auth/*` endpoints, JWT-based, role-aware (customer/vendor/admin/etc.). Web `apps/web` migrates auth to v3. | 3–4 | After M0 |
| **M2** | **Catalog in new API** — `/v3/products/*`, `/v3/categories/*`, `/v3/vendors/*`. Web migrates from `/v2/*` to `/v3/*`. | 2–3 | After M1 |
| **M3** | **Cart, checkout, orders, payments** — `/v3/cart/*`, `/v3/checkout/*`, Noon Payments integration, `/v3/orders/*`. Web's Phase 3 (auth + checkout) is built here on the new API. | 4–6 | After M2 |
| **M4** | **Mobile + portal migration** — `apps/mobile` and `apps/portal` migrated into monorepo. Both move from old PHP backend to new `/v3/*` API endpoint by endpoint. Old PHP backend slowly drained. | 6–8 | After M3 |
| **M5** | **Old backend retirement, hardening, image migration completion** — base64-to-Flysystem cleanup finished, old PHP cluster decommissioned, observability + monitoring hardened across all surfaces. | 2–3 | After M4 |

**Total forward effort: 19–27 weeks** at solo-developer pace. This is a **multi-month strategic project**, not a quarter-scoped feature delivery. Realistic delivery timeline if work is uninterrupted: **5–7 months**. Realistic if interrupted by ongoing production support of the existing four codebases: **8–12 months**.

The original roadmap's Phases 3–9 (Auth+Checkout, Orders, Wishlist+Reviews, Browse Plus, Messages+Tickets, Chat, Hardening) are **not separately phased anymore** — their content is folded into M1–M5 because each one needs the new API in place first. They are referenced in §9 but are no longer independent phases.

### Why this direction

The original v1.1 plan was "wrap don't rewrite" — keep the existing PHP backend and add new `/v2/` endpoints on top. That plan made sense when the only target surface was the web. It stops making sense when:

1. **Three frontends already drift** — web, mobile, and portal all consume the same backend differently, with three different conventions for auth, error handling, and payload shape. Without shared types or a common API client, every endpoint change creates three separate consumer changes.
2. **The existing backend has architectural issues** that can't be papered over: token-always-valid auth bug (Appendix E), inconsistent table naming (`ec_cart_items` vs `products` vs `users`), mixed naming conventions, hardcoded SMS provider credentials, no CORS for web origins.
3. **Wrapping doesn't fix the data layer** — image base64 storage, currency mismatches in legacy code, two parallel auth flows (email-password + phone-OTP). `/v2/` wrappers can't reach into the data; only a rewrite can.
4. **The monorepo solves the drift** — a shared `packages/api-contracts` (OpenAPI → TypeScript types) means all three frontends consume the same shapes. A shared `packages/api-client` means one HTTP layer (auth, retry, error handling) used everywhere.
5. **Strangler-fig keeps risk low** — old backend stays live, new backend only takes traffic for endpoints it has implemented, each surface migrates when ready, rollback is simple (point DNS back).

The cost is high (months of work) but the benefit is paid forward: every future feature ships once into shared types, not three times across drifted codebases.

---

## 2. Reality check — how we got here

This document was originally written (v1.0) because of a scope discovery in week 1 of W2.2b: until the mobile app's full endpoint surface was inventoried, the website project was being executed against a much narrower understanding (catalog browse only, ~7 endpoints). v1.1 corrected the phase order and locked the customer-facing scope.

**v2.0 is a second, deeper reality check.** During Phase 3 (Auth + Checkout) planning, two simultaneous discoveries forced a strategic re-think:

**Discovery 1 — the existing backend is more broken than was visible from the W2.0 patch alone.**

When the engineer (me) explored the full backend reference for Phase 3 planning, several issues surfaced that aren't addressable by adding more `/v2/` wrappers:

- **`verify_vendor()` always returns `100`** — the function that's supposed to validate a user's session token literally hardcodes `return 100` after computing a `similar_text` percentage it never reads. Every "authenticated" request currently passes auth regardless of token validity. (Detailed in Appendix E.) This is a critical security finding.
- **SQL injection vector** in `verify_vendor()` — `WHERE user_id = '$user_id'` with no parameter binding.
- **Hardcoded SMS provider credentials** in `getAuthToken()` — production keys baked into source.
- **Two parallel auth flows** with no clear ownership: email+password (in `users/login.php`) and phone OTP (in `customer/sendOTP.php` + `validateOTP.php`).
- **JWT library installed but unused** — `firebase/php-jwt` 6.11 is in `composer.json`, but no code path uses it. Tokens are opaque DB-stored strings.
- **Currency mismatch** — `Customer::order_summary()` outputs prices with `(₦)` (Nigerian Naira), products are priced in AED. Code was apparently forked from a Nigerian project and not fully localised.
- **No CORS** — none of the existing endpoints set `Access-Control-Allow-Origin`. The web app cannot call them from the browser without backend-side CORS work or a CORS proxy.

**Discovery 2 — the platform is already four codebases, not one web project.**

When the owner described the broader goal — "merge mobile, vendor portal, admin portal, and main website into a single monorepo" — the engineer recognised that the v1.x plan ("standalone web project") was a partial view of the platform. The reality is:

- `surdbells/3bayti-web` (Angular 21 SSR — the project this roadmap was originally written for)
- `surdbells/abayti_app` (Ionic Angular 21.1 + Capacitor 8 — production mobile app, 153 TS files, customer + vendor surfaces)
- `surdbells/abayti_vendor` (Angular 19.2 — production vendor + admin portal, 183 TS files, 88 components, 15 functional areas)
- Backend (private, PHP 8 + MySQL, 213 endpoints across 8 directories)

All four are in production with real users. All four consume the same backend. None share types or contracts.

**The fix: this v2.0 update.** The roadmap is now scoped to the whole platform. The forward path is monorepo + new backend + strangler-fig migration.

### What this means for prior work

Everything shipped to date (W1.x foundation, W2.0–W2.2b, Phase 0, Phase 1, Phase 2 cart) is **kept**. None of it needs to be undone:

- The catalog browse story (Phase 1) is correct and stays live at `staging.3bayti.ae`.
- The Phase 2 local-first cart is correct — it'll integrate cleanly with the new backend's `/v3/cart/*` once auth is in place.
- The W2.0 catalog backend patch (`/v2/products`, `/v2/categories`, etc.) keeps running until the new API offers `/v3/*` equivalents.
- The CI pipeline, Cloudflare Workers infra, design system, design tokens — all reusable.

What changes: **nothing more is added to the standalone `surdbests/3bayti-web` repo as a feature.** From M0 onward, that repo is migrated into `apps/web` of the monorepo and continues evolving there. The standalone repo is kept as an archive after migration.

### What this means for the existing surfaces in production

| Surface | Current state | Migration timing |
|---|---|---|
| Web (`3bayti-web`) | Live at `staging.3bayti.ae`, catalog + cart | Migrates first, in M0 (becomes `apps/web`). Continues to use old `/v2/*` until M2. |
| Mobile (`abayti_app`) | Live in App Store + Play Store | Stays on old backend until M4. Migrates to monorepo + new API together. |
| Portal (`abayti_vendor`) | Live, admins + vendors | Stays on old backend until M4. Migrates to monorepo + new API together. Angular 19→21 upgrade happens during migration. |
| Old PHP backend | Live at `api.3bayti.ae` | Stays live throughout M0–M4. Decommissioned only in M5 once all surfaces are off it. |

**Zero-downtime migration is a hard constraint.** Mobile users keep ordering, vendors keep managing inventory, admins keep approving stores — throughout. The strangler-fig is what enables this; details in §8.

### What this means for the project owner

This is a **multi-month commitment** before user-visible improvements ship. M0–M2 alone is roughly 2–3 months of foundation work that delivers no new features visible to customers. M3 finally unblocks Phase 3 (Auth + Checkout) on the new platform.

If the schedule pressure makes this trade-off unacceptable, an alternative path would be: **build Phase 3 on the existing PHP backend with security/auth fixed, then revisit the monorepo later.** That decision is owned by the project owner and reflected in §13 (Open questions).

---

## 3. Locked decisions

These decisions govern downstream choices. Each has a status: **active** (still binding), **superseded** (replaced — kept here for history), or **adjusted** (still binding but with v2.0 modifications).

Changing any active or adjusted decision requires updating this document.

### Decision 1 — Scope: full customer-side parity
**Status: active (carried forward from v1.1)**

The website ships **all customer-facing pages and endpoints** present in the mobile app. Vendor admin features remain on the desktop portal (`apps/portal`) and the mobile lite vendor surface (`apps/mobile/src/app/vendor/store-dashboard`).

**v2.0 clarification:** "Full parity" now also applies between the new `/v3/*` API and the old `/customer/*` mobile API. Each endpoint the customer-side needs gets a `/v3/` counterpart in the new API. Old endpoints stay live until the migration retires them.

### Decision 2 — Behavior fidelity: mobile guides intent; web wins on UX
**Status: active (carried forward from v1.1)**

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

**v2.0 clarification:** This decision now applies to every frontend in the monorepo. The portal (Angular, desktop) and mobile (Ionic, touch) each get to use platform-native patterns where it matters, but the *capabilities* are aligned through the shared API.

### Decision 3 — Backend strategy: build /v2/ wrappers for everything
**Status: SUPERSEDED by Decision 9 (rebuild backend on Lodgik/Guard51 stack). Kept for historical context.**

~~Every endpoint the web depends on gets a `/v2/` version with consistent envelope, error handling, debug mode.~~

The wrap-don't-rewrite strategy worked for catalog browse (Phase 1, deployed and stable) but does not solve the platform-wide problems identified in v2.0 §2: critical auth bug, SQL injection, hardcoded credentials, image storage in base64, currency mismatches, no CORS. v2.0 replaces this with Decision 9 (rebuild backend) and Decision 10 (strangler-fig migration).

The existing `/v2/` catalog endpoints (`/v2/products`, `/v2/categories`, `/v2/vendors`, `/v2/sitemap-data`) **stay live** until M2 ships their `/v3/*` replacements. They are not retired, they're not migrated — they just get superseded route by route.

### Decision 4 — Auth strategy: JWT in localStorage, required only at checkout
**Status: ADJUSTED — when auth is required is unchanged, but how it's implemented changes**

When auth is required (the right column of the table below) is **unchanged from v1.1**. What changes in v2.0 is *what's behind the JWT*:

- **v1.x assumption:** "JWT (signed, HS256), bearer header — already present for mobile." This was wrong. The existing backend has the JWT library installed (`firebase/php-jwt 6.11`) but doesn't use it; production tokens are opaque DB-stored strings, and the verification function is broken (Appendix E).
- **v2.0 implementation:** The new `/v3/auth/*` endpoints in `apps/api` (M1) implement proper JWT signing (HS256), token expiry (15 min access tokens), refresh tokens (7 days, single-use rotation), and a real verification middleware. Web reads tokens from localStorage as before — that's a frontend-side decision and stays.

When auth is required:

| Surface | Auth required? | Notes |
|---|---|---|
| Browse (home, categories, PDP, designers, search) | ❌ No | Open to all |
| Cart (add/remove/quantity) | ❌ No | Local-first; persisted to `localStorage` for guests |
| Wishlist (add/remove) | ❌ No | Same local-first pattern as cart |
| Account pages (profile, orders, addresses, measurements, settings, messages, tickets) | ✅ Yes | Auth-gated, redirect to inline-login if not signed in |
| Checkout entry | ✅ Yes | Triggered from cart's "Checkout" button — inline login-or-register modal |
| Reviews (read) | ❌ No | Public data |
| Reviews (write, helpful) | ✅ Yes | Auth-gated |
| Follow vendor | ✅ Yes | Auth-gated |
| Vendor management (price changes, order processing) | ✅ Yes (vendor role) | `apps/portal` (desktop) and `apps/mobile/vendor/*` (lite) |
| Admin management (approvals, commissions, etc.) | ✅ Yes (admin role) | `apps/portal` only |

**Role-based access:** the new `/v3/auth/login` returns a JWT whose payload carries the user's role flags (`is_customer`, `is_vendor`, `is_admin`, `is_finance`, `is_support`, `_sub_admin`). Frontends use those flags to gate routes. Backend middleware re-validates the role server-side on every request — frontend role checks are convenience, not security.

**Implications:**
- Account pages use **client-side rendering only** (or skeleton SSR + client hydration with no auth-dependent data).
- Cart pages use CSR to read local cart state but are not auth-gated.
- SEO impact on auth-gated pages: zero — they're `noindex` anyway (private user data).
- XSS posture: localStorage tokens are vulnerable to XSS. Mitigations: strict CSP headers (M5 hardening), input sanitisation everywhere, no inline scripts, use of Angular's built-in DOM sanitisation. We accept this trade-off because httpOnly cookies require CORS-credentials configuration that's difficult across multiple subdomains.
- CSRF: not a concern with `Authorization: Bearer` (browsers don't auto-attach the header on cross-origin requests the way they do cookies). State-changing endpoints still validate the JWT.

### Decision 5 — Cart strategy: local-first with merge on login
**Status: active (carried forward from v1.1, partially shipped in Phase 2)**

The cart is a `localStorage`-backed structure for guests. Items added while not logged in persist locally. When a user logs in, local cart items are sent to the backend and merged with the user's existing server cart, then the local cart is cleared.

**Phase 2 status:** the local-first half is **shipped**. The merge-on-login half is deferred to M3 (when auth and `/v3/cart/*` are both in place).

**Local cart shape (current, in production):**
```typescript
interface CartItem {
  key: string;              // 'p:<product_id>' or 'p:<product_id>:v:<variant_id>'
  product_id: number;
  slug: string;
  name: string;
  vendor_name: string;
  vendor_slug?: string;
  image_url: string | null;
  unit_price: { amount: number; currency: string };
  qty: number;
  added_at: number;        // ms timestamp
}
interface CartStateV1 {
  version: 1;
  items: CartItem[];
  last_updated: number;
}
```

Stored under key `3bayti.cart.v1` in localStorage.

**Merge rules** (M3):
- If server cart is empty → push entire local cart to server.
- If both have items → merge by `key`. Quantities are summed.
- After successful merge, clear localStorage cart.
- If merge fails (network error, 5xx), keep local cart intact and show an error toast.

### Decision 6 — Checkout flow: inline login-or-register
**Status: active (carried forward from v1.1)**

When a guest user clicks "Checkout" with items in cart:

1. We do NOT redirect them to a `/login` page.
2. Instead, a checkout-flow modal/step appears asking for email + password (with a "Create account" toggle).
3. On submit, account is either authenticated (login path) or created in-flow (register path).
4. The cart-merge runs immediately after auth.
5. Checkout proceeds through address → shipping → payment.

**No pure guest checkout.** Every order ends up associated with an account, even if that account was created during the checkout flow.

**v2.0 implementation note:** the inline login-or-register flow now talks to `/v3/auth/login` or `/v3/auth/register`. Email validation runs against `/v3/auth/validate-email` in real-time. Phone OTP (existing pattern from mobile) is also exposed but treated as optional second-factor or an alternative login path, not the primary auth flow.

### Decision 7 — Monorepo consolidation
**Status: active (new in v2.0)**

All four current codebases consolidate into a new monorepo at `surdbells/3bayti`:

```
3bayti/
├── apps/
│   ├── api/        ← new backend (Slim 4 + Doctrine 3 + PostgreSQL 16)
│   ├── web/        ← migrated from surdbells/3bayti-web
│   ├── mobile/     ← migrated from surdbells/abayti_app
│   └── portal/     ← migrated from surdbells/abayti_vendor
├── packages/
│   ├── api-contracts/    ← OpenAPI spec + generated TypeScript types
│   ├── shared-ui/        ← cross-app components (web + portal)
│   ├── design-tokens/    ← brand tokens
│   └── api-client/       ← shared HttpClient layer
├── docs/
└── tools/
```

**Tooling decisions** (locked unless re-debated):
- **Workspace manager:** pnpm workspaces (lighter than Nx/Turbo, handles TS monorepos well, native to JS ecosystem). pnpm chosen over npm/yarn for disk efficiency with shared dependencies across `apps/web` + `apps/mobile` + `apps/portal`.
- **Build orchestration:** Turborepo (caches per-package builds, faster CI).
- **TypeScript:** project references for cross-package types; one `tsconfig.base.json` extended per app.
- **Linting/formatting:** ESLint + Prettier shared config in `packages/eslint-config-3bayti`.
- **Backend (`apps/api`):** Composer-managed PHP, isolated from JS workspace. Lives in monorepo for unified git history but doesn't share package manager with JS apps.

**Old repos' fate:** Once migrated, `surdbells/3bayti-web`, `surdbells/abayti_app`, `surdbells/abayti_vendor` are archived (read-only on GitHub) with a README pointing at the monorepo. Backend repo (private) similarly.

### Decision 8 — Vendor surfaces: two surfaces, distinct scope
**Status: active (new in v2.0)**

Two vendor-facing surfaces stay, with clear scope:

| Surface | Path | Scope | Use case |
|---|---|---|---|
| **Desktop portal** (`apps/portal`) | `vendor.3bayti.ae` | Full vendor + admin management | Power-user workflows: bulk product editing, order processing dashboards, analytics, admin approvals, commission tracking |
| **Mobile lite vendor** (`apps/mobile/src/app/vendor/*`) | Within Capacitor app | Quick vendor actions | On-the-go: change a price, mark order as shipped, respond to a message, check today's sales |

Both share the same `/v3/vendor/*` and `/v3/admin/*` endpoints. The desktop portal exposes more of them; the mobile lite version exposes a subset focused on speed.

**Implications:**
- A user with `is_vendor: true` can use either surface. The data is the same.
- New vendor features go to the desktop portal first; mobile lite version gets a curated subset later.
- Admin-only features (e.g. store approval, commission rates) live exclusively in the desktop portal — admins are office workers, they don't need mobile.

### Decision 9 — Backend rebuild on Lodgik/Guard51 stack
**Status: active (new in v2.0). Supersedes parts of v1.x §4 backend table.**

The new `apps/api` backend is built on the same stack used in Lodgik, Guard51, CITADEL, and CreditX:

| Layer | Technology | Notes |
|---|---|---|
| Runtime | PHP 8.3+ | Same major as existing backend; allows reuse of helper logic where appropriate |
| Framework | Slim 4 | Lightweight, well-documented, predictable routing |
| ORM | Doctrine ORM 3 | Strong type safety, migrations, schema introspection |
| Database | PostgreSQL 16 | **Migration from MySQL.** Schema is rebuilt with consistent naming (no `ec_` prefix vs no-prefix mix), proper FKs, snake_case throughout |
| Auth | `firebase/php-jwt` (used properly this time) + custom middleware | HS256 access tokens (15 min) + refresh tokens (7 day, single-use rotation) |
| Validation | `symfony/validator` | Per-DTO validation rules, no inline checks |
| DI | PHP-DI | Constructor injection, autowired |
| Cache | Redis (predis/predis) | Sessions, query cache, rate limit counters |
| Queues | Symfony Messenger + Redis transport | Async work: emails, SMS, payment webhooks, image processing |
| Email | ZeptoMail | Transactional, templated |
| SMS / OTP | **MessageCentral CPaaS** (existing provider, kept) — alternative: Twilio if MessageCentral coverage drops | Phone OTP, marketing SMS |
| Payments | **Noon Payments** | UAE-native PSP; hosted-redirect or iframe model; backend-only integration (frontend never holds keys) |
| File storage | Flysystem | Multi-adapter (local in dev, S3-compatible in prod). **All product images migrate from base64 → Flysystem-managed files in M5.** |
| PDF | Dompdf | Order receipts, invoices, statements |
| API docs | swagger-php (annotation-driven OpenAPI generation) | Spec auto-generated, lives in `packages/api-contracts/`, types regenerated from it |
| WebSockets | Ratchet (PHP) or Node.js sidecar | For chat module (M4); decision deferred to that phase |
| Observability | Sentry SDK + Cloudflare logs | Errors + performance |

**Migrations from old backend:**
- MySQL → PostgreSQL (data migration tool: pgloader or hand-written migration scripts)
- Base64 images → Flysystem files (data migration: read each LONGTEXT column, decode, save to filesystem, update column to URL)
- `users` table role flags carried forward as-is (`is_customer`, `is_vendor`, `is_admin`, etc.) — schema is good, just gets typed via Doctrine entities
- Cart, orders, wishlist, reviews, tickets — schema reviewed table by table, naming normalised

**What does NOT change from existing backend:**
- The role-based unified user model (`users` table with `is_customer`/`is_vendor`/`is_admin` flags) is correct and stays
- Order workflow (order → payment_attempt → fulfillment) stays
- Topex shipping API integration stays (third-party, no migration possible)
- Noon Payments integration approach (hosted redirect, webhook callback) stays

### Decision 10 — Strangler-fig migration with parallel operation
**Status: active (new in v2.0)**

The new `apps/api` runs in parallel with the existing PHP backend at a separate host (e.g. `api-v3.3bayti.ae`). The migration proceeds **endpoint by endpoint**, not surface by surface.

**Operating model during migration:**
- Old backend (`api.3bayti.ae`): keeps serving every endpoint that hasn't been migrated yet
- New backend (`api-v3.3bayti.ae`): serves only the endpoints that have been built and validated
- Each frontend (web, mobile, portal) is configured at build time to pick which endpoints come from old vs new (via the `packages/api-client` config)
- A "feature flag" mechanism in `packages/api-client` lets us toggle individual endpoints between old and new without redeploying frontends

**Retirement criterion** (per endpoint): the new endpoint passes contract tests against the same fixtures the old one passes, *and* has been live in production for at least 7 days serving real traffic, *and* zero rollback events have occurred. Then the old endpoint is marked deprecated, traffic flips fully, and after another 7 days the old PHP file is removed.

**Old backend stays live for the entire M0–M4 period.** Decommissioning happens in M5, only after every consumer (web + mobile + portal) is fully on the new API and has been stable for at least 30 days.

### Decision 11 — Production support during migration
**Status: active (new in v2.0)**

Bug fixes and critical regressions on the existing four codebases continue to ship throughout the monorepo build. The owner explicitly de-scoped *new feature work* on `3bayti-web` (the standalone web project) until the monorepo migration begins, but maintenance fixes still happen.

For the existing mobile and portal apps in production: bug fixes go to the existing repos until those apps migrate (in M4). Once migrated, fixes go to the monorepo.

**This means duplicate effort during the transition.** A bug found in the mobile app in M2 must be fixed in `surdbells/abayti_app` (production) AND backported to `apps/mobile` in the monorepo (in-progress). This is unavoidable in a strangler-fig migration; the discipline is to keep the duplication as short-lived as possible.

### Decision 12 — Order processing lifecycle (industry-standard, multi-vendor)
**Status: active (new in v2.0). Detailed in §5.**

The platform standardises on an industry-standard multi-vendor order lifecycle. Eight foundational sub-decisions (locked 6 May 2026):

| # | Sub-decision | Choice | Rationale |
|---|---|---|---|
| 12.1 | Order granularity | **Per-vendor sub-order with own status** | Multi-vendor reality: each vendor's portion fulfills independently. Matches Etsy/AliExpress/eBay model. |
| 12.2 | State columns | **Separate `payment_status` and `fulfillment_status`** | Each answers a different business question. Shopify/BigCommerce/Magento standard. |
| 12.3 | Returns model | **Separate RMA entity** (`return_requests` table with own lifecycle) | Returns have their own multi-step workflow distinct from order fulfillment. Amazon/Shopify standard. |
| 12.4 | COD support | **Fully designed; gated behind admin toggle (default OFF)** | UAE-relevant payment method. Designed properly from day 1; deferred to enable when ops policy is ready. |
| 12.5 | Customer cancellation window | **Until vendor marks `processing`** | Customer has agency before vendor commits operational effort. Marketplace standard. |
| 12.6 | Vendor decline | **Allowed within 24 hours of placement, requires reason; auto-refund** | Vendors need legitimate decline (out of stock, can't ship, etc.) without abandoning orders silently. |
| 12.7 | Unpaid order timeout | **Admin-configurable; default 24 hours** | Fashion-appropriate inventory release; admin can adjust based on real abandonment data. |
| 12.8 | Partial returns | **Items refund proportionally; shipping refunded only for vendor-fault cases (defective/wrong-item)** | Industry-common pattern (Shopify, Amazon). "Changed my mind" doesn't undo the cost of getting the package to you. |
| 12.9 | Return window | **Per-vendor policy** (each vendor sets days; admin sets platform default ~7 days) | Marketplace standard. Vendor knows their products and policy. |

Full state diagrams, transition rules, schema, and endpoint contracts in **§5**.

---

## 4. Target platform architecture

This section describes the **target architecture** — what each surface looks like once the migration is complete. The migration path itself is in §8.

### Surface map

```
Customers                Vendors                  Admins
   │                        │                        │
   ├─── Browser ────────► apps/web ◄────────┐         │
   │      (Angular 21)                       │         │
   │                                         │         │
   ├─── iOS / Android ──► apps/mobile ◄──┐  │         │
   │      (Ionic 8 + Capacitor 8)         │  │         │
   │           ▲                           │  │         │
   │           │                           │  │         │
   │      (vendor section in same         │  │         │
   │       binary, gated by role) ────────┘  │         │
   │                                         │         │
                                            │         │
   ┌──────────────── apps/portal ◄──────────┴─────────┤
   │                  (Angular 21, desktop)            │
   │                                                   │
   └─── Browser ──────────────────────────────────────┘

                        ▲
                        │ HTTPS / JSON
                        │
            ┌───────────┴───────────┐
            │                       │
   apps/api (Slim 4)          api.3bayti.ae (legacy PHP)
   PostgreSQL 16              MySQL — drained over M0–M5
   Redis · ZeptoMail
   MessageCentral · Noon
            │                       │
            └────── packages ───────┘
                  (api-contracts,
                   api-client,
                   shared-ui,
                   design-tokens)
```

### Frontend (each app — shared conventions)

All three frontends (web, mobile, portal) target the **same API contract** and consume the **same shared packages**. They differ only in delivery target (browser SSR / native iOS+Android via Capacitor / browser SPA).

| Layer | Web (`apps/web`) | Mobile (`apps/mobile`) | Portal (`apps/portal`) |
|---|---|---|---|
| Framework | Angular 21.2 | Angular 21.1 (Ionic 8) | Angular 21 (upgraded from 19.2 in M4) |
| Renderer | `@angular/ssr` on Cloudflare Workers | Capacitor 8 (iOS + Android) | Static SPA on Cloudflare Pages |
| Styling | Tailwind 4.2 + design tokens | SCSS + Ionic theme + design tokens | Tailwind + design tokens (migrated from current) |
| Reactive primitives | Signals + RxJS 7.8 | Signals + RxJS 7.8 | Signals + RxJS 7.8 |
| Forms | Reactive Forms | Reactive Forms | Reactive Forms |
| HTTP | `packages/api-client` (shared) | `packages/api-client` (shared) | `packages/api-client` (shared) |
| Types | `packages/api-contracts` (shared) | `packages/api-contracts` (shared) | `packages/api-contracts` (shared) |
| Components | `packages/shared-ui` for cross-app + own SCSS for surface-specific | Ionic + custom for surface-specific | `packages/shared-ui` + own SCSS |
| Routing | Angular Router + SSR | Ionic Router | Angular Router |
| Testing | Karma + Jasmine + Playwright e2e | Karma + Jasmine | Karma + Jasmine |

### Backend (`apps/api`)

The new API replaces the existing PHP backend at a new host (`api-v3.3bayti.ae` initially, eventually `api.3bayti.ae` once retirement completes).

| Layer | Technology | Notes |
|---|---|---|
| Runtime | PHP 8.3+ | |
| Framework | Slim 4 | |
| ORM | Doctrine ORM 3 | |
| Database | PostgreSQL 16 | Migrated from MySQL in M0/M1; pgloader-driven |
| Auth | `firebase/php-jwt` (used properly) + custom middleware | HS256, 15-min access + 7-day refresh tokens |
| Validation | `symfony/validator` | |
| DI | PHP-DI | |
| Cache / sessions / rate-limit | Redis (predis/predis) | |
| Queues | Symfony Messenger + Redis transport | |
| Email | ZeptoMail | |
| SMS / OTP | MessageCentral CPaaS | Existing provider kept |
| Payments | Noon Payments | UAE-native; backend only — frontend never holds keys |
| File storage | Flysystem (local in dev, S3-compatible in prod) | All product images migrate from base64 → file URLs |
| PDF | Dompdf | Order receipts, invoices |
| API docs | swagger-php | Auto-generated; output to `packages/api-contracts/openapi.yaml` |
| WebSockets | Ratchet (PHP) or Node.js sidecar | Decision deferred to M4 (chat module) |
| Observability | Sentry SDK | |

### Shared packages (`packages/`)

| Package | Purpose | Consumers |
|---|---|---|
| `api-contracts` | OpenAPI spec generated from `apps/api` annotations + `openapi-typescript`-generated types | All three frontends |
| `api-client` | Angular HttpClient layer with auth interceptor, error normaliser, retry/backoff, feature-flag-driven endpoint routing (old vs new backend) | All three frontends |
| `shared-ui` | Cross-app components (button, modal, form fields, layout primitives, design-token-driven) | `apps/web`, `apps/portal`. Mobile uses Ionic — has its own UI kit. |
| `design-tokens` | Brand colors, typography, spacing, shadow tokens (`--shadow-card-resting` etc.) as both CSS variables and TypeScript constants | All three frontends |
| `eslint-config-3bayti` | Shared ESLint rules | All TypeScript apps |
| `tsconfig-3bayti` | Base TS configs (`base.json`, `app.json`, `lib.json`) extended per app | All TypeScript apps |

### Infrastructure

| Layer | Technology | Notes |
|---|---|---|
| Web edge (`apps/web`) | Cloudflare Workers + Static Assets | SSR + prerender, current setup, unchanged |
| Portal edge (`apps/portal`) | Cloudflare Pages | Static SPA, simpler than Workers — no SSR needed for vendor portal |
| Mobile distribution | Capacitor → App Store + Play Store | Same as today |
| API hosting | TBD — see hosting recommendation below | Cloudflare Workers don't run Slim 4 PHP natively. Options below. |
| Database hosting | TBD — see hosting recommendation below | Managed PostgreSQL preferred over self-hosted |
| Redis hosting | Upstash (serverless Redis) | Pay-per-request, no idle cost |
| Object storage | Cloudflare R2 | S3-compatible, no egress fees, fits existing Cloudflare setup |
| CI/CD | GitHub Actions | Per-app workflows, Turborepo cache speeds repeat runs |
| Observability | Sentry (errors + performance) + Cloudflare Analytics + Logflare/BetterStack for log aggregation | |

### Hosting recommendations (for owner decision)

**API (`apps/api` — Slim 4 PHP):**

| Option | Pros | Cons | Recommended for |
|---|---|---|---|
| **DigitalOcean App Platform** | Simple, Git-driven deploys, managed PostgreSQL adjacent, ~$12-25/mo per app | Less ops control than VPS | **Recommended starting point** — easy to operate, migration-friendly |
| **Hetzner Cloud + manual config** | Cheap (~$5/mo VPS), full control | Manual ops (Caddy/Nginx config, certs, deploys) | Cost-sensitive deployments where ops time is available |
| **AWS ECS Fargate + RDS** | Highly scalable, AWS ecosystem | Complex, expensive for low traffic, longer learning curve | Future scale, not initial deploy |
| **Cloudflare Workers** ❌ | Same provider as web edge | Doesn't run native PHP Slim apps | Not suitable |

**Recommendation:** **DigitalOcean App Platform** for `apps/api`, with **DigitalOcean Managed PostgreSQL** for the database, **Upstash Redis** for cache/queues, **Cloudflare R2** for file storage. Total estimated monthly cost: ~$50-80 at low traffic, scaling smoothly.

**PostgreSQL specifically:**

| Option | Pros | Cons |
|---|---|---|
| DigitalOcean Managed PostgreSQL | Adjacent to App Platform, low-latency, daily backups, reasonable price | DO ecosystem lock-in (mild) |
| Supabase | Includes auth + realtime + storage, generous free tier | Tied to Supabase auth conventions, may conflict with our JWT approach |
| Neon | Serverless Postgres, branching, scales to zero | Newer service, fewer integrations |
| AWS RDS | Mature, scales | Expensive at low traffic |

**Recommendation:** DigitalOcean Managed PostgreSQL (alongside App Platform). Supabase is tempting for the bundled features but its auth doesn't match what we want.

**File storage / images:**

Cloudflare R2 is the clear pick: S3-compatible API (Flysystem has an adapter), no egress fees (which matters for product image traffic), already on the Cloudflare account being used for the web edge. Files served via custom domain (`cdn.3bayti.ae`) backed by R2.

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

### 4.4 Product card design system

**Direction:** Gilded Boutique with **soft and subtle** floating presence.

> **History:** v1.1 of this section locked the language as "Pronounced floating presence" with three-layer shadows reaching `0.16-0.28` opacity. During Phase 1 Week 3 Round 3 visual review (5 May 2026), the language was revised to **soft & subtle** — same two-tone warm-brown shadow palette but pulled back to two layers at `0.04-0.12` opacity. Cards still float; they no longer compete with the product imagery. The shipped values are below.

The product card is the most repeated UI atom on the site — home page strips, category pages, designer pages, search, related-products, wishlist. Locking the design ensures every surface inherits a consistent premium feel.

#### Visual specification (current — what's deployed)

| Property | Value | Rationale |
|---|---|---|
| Card surface | `#fdfaf3` (warm cream, slightly lighter than canvas) | Lifts off the canvas distinctly; reads as "boutique" not "department store" |
| Card border-radius | `20px` | Soft, contemporary; consistent with rounded UI primitives across the brand |
| Card padding | `14px 14px 20px` | Generous breathing room around the image; tighter at meta block |
| Image container border-radius | `14px` | Rounded inset — image floats inside the card with its own padding (not bleeding to the card's edges) |
| Image aspect ratio | `3 / 4` | Portrait orientation — flatters fashion photography, fits more cards per row |
| Shadow at rest | `var(--shadow-card-resting)` = `0 1px 2px rgba(90,58,44,0.04), 0 4px 12px -2px rgba(90,58,44,0.06)` | Soft & subtle — cards float without dominating |
| Shadow on hover | `var(--shadow-card-hover)` = `0 2px 4px rgba(90,58,44,0.06), 0 8px 20px -4px rgba(90,58,44,0.10)` | Slightly amplified — interaction cue without becoming heavy |
| Hover transform | `translateY(-3px)` | Subtle lift; small enough that the card-shadow relationship reads as believable |
| Hover image scale | `transform: scale(1.04)` over `1s cubic-bezier(0.22, 1, 0.36, 1)` | Slow, refined zoom |
| Transition timing | `0.5s cubic-bezier(0.22, 1, 0.36, 1)` | Slow enough to feel deliberate, fast enough not to drag |

Shadow tokens live in `packages/design-tokens` (target state) and currently in `apps/web/src/styles.scss` (`--shadow-card-resting`, `--shadow-card-hover`, `--shadow-carousel-center`, `--shadow-floating`). Shadow colour uses the brand espresso `#5a3a2c` at low alpha rather than neutral grey — gives the shadow a warm tint that matches the cream surface.

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
| Card lift | Whole card hovered | `-3px` translate Y, soft-amplified shadow |

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

#### Cascade across the site

The same shadow tokens are used by every card surface for visual cohesion: `ProductCard`, `DesignerCard`, category tiles, designer skeleton, hero carousel center card. Floating UI elements (strip arrows, carousel arrows, dot indicators) use `--shadow-floating`. Tokens are the single source of truth — changing them in `styles.scss` cascades to all surfaces.

#### Reference

Locked language deployed to production at `https://staging.3bayti.ae` (Phase 1 W3 Round 3, commit `6227ed3` for the shadow tokens, `bc412dc` for the cleanup, `0e68d19` for CI marker fix). All future product cards across the site MUST follow this spec.

---

## 5. Order processing lifecycle

This section defines the **order lifecycle, state machine, and supporting schema** for the new platform. It's foundational — the schema, endpoints, vendor/admin UI flows, payment integration, and notification triggers all derive from what's defined here.

The design is **multi-vendor first** (per Decision 12.1), separates **payment from fulfillment** (per Decision 12.2), and treats **returns as their own RMA workflow** (per Decision 12.3). It supports both card payments (default) and **Cash on Delivery** (Decision 12.4 — fully designed, admin-toggle-gated) so the lifecycle code is complete from day one even though COD launches later.

### 5.1 Domain model — orders, sub-orders, items

When a customer checks out, **one customer-facing `Order` is created**. If that order has items from multiple vendors, the system also creates **one `SubOrder` per vendor**. Each sub-order is the unit that fulfills independently.

```
Order (customer-facing — the receipt)
  ├─ id, order_number ("3B-2026-001234"), customer_id, shipping_address_id
  ├─ subtotal, shipping_total, tax_total, discount_total, grand_total
  ├─ payment_method, payment_status (rolls up from Payments)
  ├─ created_at, updated_at
  │
  └─ SubOrder × N (one per vendor)
      ├─ id, sub_order_number ("3B-2026-001234-V1"), order_id, vendor_id
      ├─ subtotal, shipping_cost, items_total, sub_total
      ├─ fulfillment_status (the per-vendor state machine — see §5.3)
      ├─ shipping_carrier, tracking_number (when shipped)
      ├─ vendor_decline_reason (when vendor_rejected)
      ├─ created_at, processed_at, shipped_at, delivered_at
      │
      └─ OrderItem × N
          ├─ id, sub_order_id, product_id, variant_id, qty
          ├─ unit_price, line_total (price × qty, snapshot at order time)
          ├─ product_name_snapshot, image_url_snapshot (so receipt
          │   stays accurate if product is later renamed/deleted)
          └─ size_snapshot, color_snapshot
```

**Why the snapshot fields:** orders are receipts. They must remain readable in 3 years even if the product is deleted, repriced, renamed, or reassigned to a different vendor. Every field that could change is snapshotted at order placement.

**Order vs SubOrder visibility:**
- **Customer** sees the `Order` (single receipt with the full grand_total) but the SubOrders are exposed as "shipment groups" — "Items from Vendor A (shipped Tue)" / "Items from Vendor B (still preparing)".
- **Vendor** sees only their own `SubOrder` in their portal — they don't see other vendors' portions of the same `Order`.
- **Admin** sees both — full Order plus all SubOrders.

### 5.2 Payment statuses (Order-level)

Payment is tracked at the **Order level** because money is exchanged between the customer and the platform once, regardless of how many vendors are in the order. The platform then settles with vendors separately (commission accounting, payouts) — that's a finance concern, not a customer-facing payment concern.

| Status | Meaning | Triggered by | Allowed transitions |
|---|---|---|---|
| `awaiting_payment` | Order created, no money received yet. Default initial state. | System (on order creation) | → `paid`, `payment_failed`, `expired`, `cancelled` |
| `paid` | Card charged successfully (Noon `CAPTURED`) OR COD order confirmed (will pay on delivery) | Noon webhook (card) / system (COD) | → `partially_refunded`, `refunded`, `disputed` |
| `pending_3ds` | Card payment in 3D-Secure challenge. May resolve in seconds or hours. | Noon webhook (card with 3DS challenge) | → `paid`, `payment_failed` |
| `payment_failed` | Card declined or 3DS abandoned | Noon webhook | → (none — terminal; customer must retry, which creates a new payment_attempt) |
| `expired` | Unpaid for 24+ hours (admin-configurable). System auto-cancels. | System (cron job) | → (none — terminal) |
| `partially_refunded` | Some refund issued (e.g. one of three vendors refunded after their items declined or returned) | Refund processed via Noon | → `refunded`, stays `partially_refunded` for further partial refunds |
| `refunded` | Full order amount refunded | Refund processed via Noon | → (none — terminal) |
| `disputed` | Customer initiated chargeback via their card issuer | Noon webhook (`DISPUTED`) | → `refunded` (if dispute won by customer), `paid` (if dispute won by merchant) |
| `cancelled` | Order cancelled before payment captured | Customer/vendor/admin/system (timeout) | → (none — terminal; if payment was captured, see refund flow) |

#### Payment status notes

- **COD orders** start at `awaiting_payment` until they're confirmed (admin-side check or vendor-side acceptance), then move to `paid` even though no money has actually changed hands. A separate field `cod_collected_at` tracks when cash is actually received at delivery — this is for accounting reconciliation, not for fulfillment flow. If COD is refused at delivery, the order moves to `cancelled` and items go back to inventory.
- **`pending_3ds`** is a short-lived state (<4 hours typically). The unpaid timeout job ignores `pending_3ds` orders (they aren't truly unpaid; they're mid-flow). After 4 hours of `pending_3ds` with no resolution, system flips to `payment_failed`.
- **Refund timing:** Noon Payments takes 5-10 business days to process refunds back to the customer's card. Our `payment_status` flips to `refunded` when Noon confirms via webhook, not when we initiate. UI shows "Refund processing — funds will arrive in 5-10 business days" during the gap.
- **Multi-vendor refund split:** if vendor A declines an order with items from vendors A + B, only A's portion is refunded. Order moves to `partially_refunded`. SubOrder A moves to `vendor_rejected`. SubOrder B continues normally.

### 5.3 Fulfillment statuses (SubOrder-level — the per-vendor state machine)

This is **the per-vendor state machine** referenced by Decision 12.1. Each SubOrder tracks its own fulfillment independently.

| Status | Meaning | Who can transition out | Allowed transitions |
|---|---|---|---|
| `pending` | SubOrder created but order not yet paid. Vendor cannot act yet. | System (on payment confirmation) | → `awaiting_vendor`, `cancelled` (if order is cancelled) |
| `awaiting_vendor` | Order paid; vendor must accept or decline within 24 hours | Vendor | → `processing`, `vendor_rejected`, `cancelled` (customer/admin) |
| `processing` | Vendor accepted; preparing items for shipment. **Customer can no longer self-cancel.** | Vendor | → `ready_to_ship`, `cancelled` (admin only) |
| `ready_to_ship` | Items packed, awaiting carrier pickup. Optional intermediate state — vendors can skip directly to `shipped` | Vendor | → `shipped`, `cancelled` (admin only) |
| `shipped` | Items handed to carrier; tracking number recorded | Vendor / system (Topex tracking webhook) | → `out_for_delivery`, `delivered`, `delivery_failed` |
| `out_for_delivery` | Carrier marked package out for delivery (last-mile) | System (Topex tracking webhook) | → `delivered`, `delivery_failed` |
| `delivered` | Customer received package. Triggers return-window countdown. | System (Topex webhook) / vendor (manual) / customer (confirmed receipt) | → `return_requested` (via RMA flow — §5.5), terminal otherwise |
| `delivery_failed` | Multiple delivery attempts failed; package returned to vendor | System (Topex webhook) / vendor (manual) | → `processing` (retry shipment) or `cancelled` (refund customer) |
| `vendor_rejected` | Vendor declined within 24h window. Auto-refund triggered. | (terminal) | → (none) |
| `cancelled` | SubOrder cancelled before fulfillment. Refund issued. | (terminal) | → (none) |

#### State machine diagram

```
                  ┌────────────┐
                  │  pending   │  (order not yet paid)
                  └──────┬─────┘
                         │ payment captured
                         ▼
                  ┌──────────────────┐
       ┌──────────│  awaiting_vendor │──────────┐
       │ vendor   └──────────────────┘          │ vendor declines
       │ accepts                                │ (within 24h)
       ▼                                        ▼
┌─────────────┐                          ┌──────────────────┐
│ processing  │                          │ vendor_rejected  │
└──────┬──────┘                          │  (auto-refund)   │
       │                                 └──────────────────┘
       │ vendor packs                                   ▲
       ▼                                                │
┌────────────────┐                                      │
│ ready_to_ship  │  (optional)                          │
└──────┬─────────┘                                      │
       │ handed to carrier                              │
       ▼                                                │
┌─────────────┐                                         │
│   shipped   │  (tracking number recorded)             │
└──────┬──────┘                                         │
       │ Topex webhook: out for delivery                │
       ▼                                                │
┌────────────────────┐                                  │
│ out_for_delivery   │                                  │
└──────┬─────────────┘                                  │
       │                                                │
       ├── delivery success ──► delivered               │
       │                                                │
       └── delivery failed ──► delivery_failed          │
                                  │                     │
                                  ├── retry ──► processing ─┐
                                  │                          │
                                  └── give up ──► cancelled ─┴── triggers refund

                          (any state above ↑ can be force-cancelled by admin)
```

#### Customer-facing labels

Internal status names are precise but unfriendly. Customer UI uses softer language:

| Internal status | Customer UI label |
|---|---|
| `pending` | "Awaiting payment" |
| `awaiting_vendor` | "Order placed — awaiting vendor confirmation" |
| `processing` | "Preparing your order" |
| `ready_to_ship` | "Packed and ready to ship" |
| `shipped` | "On its way" |
| `out_for_delivery` | "Out for delivery" |
| `delivered` | "Delivered" |
| `delivery_failed` | "Delivery problem — vendor will contact you" |
| `vendor_rejected` | "Vendor was unable to fulfill — refund issued" |
| `cancelled` | "Cancelled" |

### 5.4 Order-level rollup status

The customer doesn't see `fulfillment_status` per-vendor in the high-level order list — that would be too noisy. They see a **rolled-up Order status** computed from the SubOrders:

| Order rollup | Condition |
|---|---|
| `awaiting_payment` | `payment_status` is `awaiting_payment` or `pending_3ds` |
| `payment_failed` | `payment_status` is `payment_failed` or `expired` |
| `processing` | `payment_status` is `paid` AND any SubOrder is in `awaiting_vendor`, `processing`, or `ready_to_ship` |
| `partially_shipped` | Some SubOrders are `shipped`/`out_for_delivery`/`delivered`, others still pre-shipped |
| `shipped` | All SubOrders are `shipped` or `out_for_delivery` |
| `partially_delivered` | Some SubOrders are `delivered`, others still in transit |
| `delivered` | All SubOrders are `delivered` |
| `partially_cancelled` | Some SubOrders are `vendor_rejected`/`cancelled`, others active |
| `cancelled` | All SubOrders are `cancelled` or `vendor_rejected` |
| `return_requested` | Any SubOrder has at least one active return request |
| `partially_refunded` / `refunded` | Mirrors `payment_status` |

The drill-down view shows the per-vendor breakdown.

### 5.5 Returns / RMA workflow

Returns are a **separate `return_requests` entity** (Decision 12.3). The order itself stays `delivered` — the return is a parallel process.

Each return request is scoped to **one SubOrder** (a customer can't request a return that spans multiple vendors). Within that, the customer can return some or all of the items in that SubOrder (Decision 12.8 — partial returns supported).

#### Return states

| Status | Meaning | Who can transition | Allowed transitions |
|---|---|---|---|
| `requested` | Customer initiated return; awaiting vendor decision | Vendor / admin (override) | → `approved`, `rejected` |
| `approved` | Vendor approved; awaiting customer to ship items back | Customer (mark shipped) / system (timeout) | → `in_transit`, `cancelled` (customer never ships) |
| `rejected` | Vendor declined; customer can dispute via support ticket | (terminal — but support ticket can resurrect) | → (none from RMA; dispute is via tickets) |
| `in_transit` | Customer shipped items back; awaiting vendor receipt | System (Topex webhook on return label) / vendor (manual mark received) | → `received`, `lost_in_transit` |
| `received` | Vendor received returned items; inspecting | Vendor | → `accepted`, `disputed` |
| `accepted` | Vendor confirmed items are returnable; refund being processed | System (Noon webhook) | → `refunded` |
| `disputed` | Vendor claims items were not as described / damaged / used. Admin escalation. | Admin | → `accepted`, `rejected_after_inspection` |
| `rejected_after_inspection` | Admin sided with vendor; items returned to customer; no refund | (terminal) | → (none) |
| `refunded` | Refund processed via Noon | (terminal) | → (none) |
| `cancelled` | Customer abandoned the return (didn't ship within return window) | System (auto-cancel after 14 days of no return shipment) | → (none) |
| `lost_in_transit` | Return shipment lost by carrier; admin decides | Admin | → `refunded` (admin decides to refund anyway), `rejected_after_inspection` |

#### Return eligibility rules (per Decision 12.9)

- **Each vendor sets their own return window** (default 7 days from delivery; can set 0 days = "no returns" for final-sale items)
- A return request can only be opened during the eligibility window
- After window closes, customer cannot self-open a return; must contact support (admin can override)
- Some product categories may be marked "non-returnable" by the vendor regardless of window (e.g. underwear, custom-made pieces)

#### Refund calculation (per Decision 12.8)

When a return is `accepted`:

```
refund_amount = sum(returned_items.line_total)
              - any_proportional_discount_applied_to_returned_items
              + (vendor_fault ? proportional_shipping_cost : 0)
```

- `vendor_fault` is true when return reason is `defective`, `wrong_item`, `not_as_described`, or `damaged_in_transit`
- For `changed_my_mind`, `wrong_size`, `didnt_like`, etc. — shipping is NOT refunded
- Shipping refund is **proportional** to returned items' weight or value (whichever the vendor's policy uses)

#### Return reasons (customer-facing, structured)

| Reason | Vendor fault? | Shipping refund? | Notes |
|---|---|---|---|
| `defective` | Yes | Yes | Item arrived broken/damaged |
| `wrong_item` | Yes | Yes | Vendor sent the wrong item |
| `not_as_described` | Yes | Yes | Item significantly differs from listing |
| `damaged_in_transit` | (carrier) | Yes (paid by platform if claimable from Topex) | Package damaged before arrival |
| `wrong_size` | No | No | Customer ordered wrong size |
| `changed_my_mind` | No | No | Customer no longer wants it |
| `didnt_like` | No | No | Customer dissatisfied with appearance/feel |
| `late_delivery` | (vendor) | Yes | Delivered after vendor's promised window — vendor fault |
| `other` | (manual review) | (manual review) | Free-text reason; admin reviews |

### 5.6 Cancellation rules (Decision 12.5, 12.6, 12.7)

#### Customer-initiated cancellation

A customer can cancel **per SubOrder** (since each vendor fulfills independently):

- **Allowed when** SubOrder status is `pending` or `awaiting_vendor`
- **Not allowed when** SubOrder status is `processing` or beyond (vendor has committed effort)
- After `processing`, customer must contact vendor or admin

If the customer cancels all SubOrders in an Order, the Order rolls up to `cancelled` and a full refund is issued. If they cancel some SubOrders, the Order rolls up to `partially_cancelled` with a partial refund.

#### Vendor-initiated decline (`vendor_rejected`)

A vendor can decline a SubOrder:

- **Allowed within** 24 hours of `awaiting_vendor` state entry
- **Reason required** — structured field with options: `out_of_stock`, `cant_ship_to_area`, `pricing_error`, `fraud_suspected`, `other` (free text)
- **Auto-refund** — system immediately processes refund of that SubOrder's portion via Noon
- **Inventory adjustment** — items returned to vendor's available stock

After 24 hours without action, the system sends an escalation email to the vendor. After 48 hours, admin can intervene (force-cancel from admin side).

#### System-initiated cancellation (timeout)

- **Unpaid order timeout:** orders in `awaiting_payment` for more than `unpaid_timeout_hours` (admin setting, default 24) are auto-cancelled. Items return to inventory. Customer notified via email with a "your cart is waiting" link to come back.
- **Vendor inaction timeout:** SubOrders in `awaiting_vendor` for >48 hours after first reminder are escalated to admin queue. Admin can force-decline (if vendor unresponsive) or force-confirm.
- **`pending_3ds` timeout:** 4 hours; flips to `payment_failed`.
- **Return abandonment timeout:** return requests in `approved` state for >14 days without customer shipping are auto-cancelled.

#### Admin-initiated cancellation

Admin can force-cancel from any state. Use cases:
- Fraud detected post-payment
- Vendor account suspended mid-fulfillment
- Customer request via support ticket beyond normal cancellation window

Admin cancellations require an audit log entry with `actor_id`, `reason`, `notes`.

### 5.7 Cash on Delivery (COD) lifecycle

COD is **fully designed but admin-toggle gated** (Decision 12.4). All the lifecycle code below is built; the admin setting `payments.cod_enabled` (default `false`) controls whether COD is offered at checkout.

#### COD-specific states and behaviors

When `payment_method = 'cod'`:

1. **Order placed** → `payment_status: awaiting_payment`, fulfillment proceeds normally.
2. **System runs COD-confirmation logic** (admin-configurable; could be auto-accept, OTP-confirmation via SMS, manual vendor approval, or admin queue).
3. **Once COD confirmed** → `payment_status: paid` (logically; no money yet). SubOrders move to `awaiting_vendor`.
4. **Fulfillment proceeds** identically to card orders.
5. **At delivery** → carrier collects cash. Order gains `cod_collected_at` timestamp; `cod_collected_amount` field. Fulfillment moves to `delivered`.
6. **If customer refuses delivery** → SubOrder moves to `delivery_failed` → `cancelled`. `payment_status` flips back to `cancelled`. No refund needed (no money was taken).

#### COD-specific safeguards (admin-configurable, all default ON when COD is enabled)

| Setting | Default | Notes |
|---|---|---|
| `cod.enabled` | `false` | Master toggle. When false, COD never appears in checkout. |
| `cod.max_amount_aed` | `1000` | Orders above this amount cannot use COD; checkout shows card-only. |
| `cod.require_phone_otp_confirmation` | `true` | Customer receives OTP after order; must confirm before vendor sees it. |
| `cod.allowed_emirates` | All | Admin can restrict COD to specific emirates (e.g. exclude Fujairah due to logistics). |
| `cod.first_time_customer_max_amount_aed` | `200` | Lower cap for new customers (no order history). |
| `cod.vendor_can_decline` | `true` | Vendors can opt out of COD orders entirely (per-vendor setting). |

#### COD-specific schema additions

- `orders.cod_collected_at` — timestamp when carrier collected cash
- `orders.cod_collected_amount` — actual amount collected (could differ from total if carrier takes service fee)
- `orders.cod_confirmation_method` — `'auto'`, `'otp'`, `'vendor_approval'`, `'admin_review'`
- `orders.cod_confirmed_at` — timestamp of confirmation step
- `vendors.accepts_cod` — boolean per vendor (only honored when `cod.enabled = true` platform-wide)

### 5.8 Admin settings page

The admin portal (`apps/portal`) has a **platform settings page** that controls all configurable lifecycle parameters. Section structure:

```
Platform settings
├── Orders
│   ├── unpaid_timeout_hours          (default: 24)
│   ├── vendor_acceptance_timeout_hours (default: 24)
│   ├── vendor_inaction_escalation_hours (default: 48)
│   ├── pending_3ds_timeout_hours     (default: 4)
│   └── default_return_window_days    (default: 7)
│
├── Returns
│   ├── return_abandonment_days       (default: 14)
│   ├── default_vendor_fault_reasons  (defective, wrong_item, not_as_described, damaged_in_transit, late_delivery)
│   └── allow_post_window_returns_via_support (default: true)
│
├── Payments
│   ├── cod.enabled                   (default: false)  ← master COD toggle
│   ├── cod.max_amount_aed            (default: 1000)
│   ├── cod.require_phone_otp_confirmation (default: true)
│   ├── cod.allowed_emirates          (default: all)
│   ├── cod.first_time_customer_max_amount_aed (default: 200)
│   └── cod.confirmation_method       (default: 'otp' — options: auto, otp, vendor_approval, admin_review)
│
├── Cancellations
│   ├── customer_can_self_cancel_until (default: 'processing')
│   └── vendor_decline_window_hours   (default: 24)
│
└── Notifications
    ├── send_email_on_status_change   (default: true, per-status togglable)
    ├── send_sms_on_status_change     (default: shipped, delivered only — to control SMS costs)
    └── send_push_on_status_change    (default: true, mobile app only)
```

All settings are stored in a `platform_settings` table with `key` / `value_json` columns, cached in Redis, and read on every order/payment/return event. Changes audit-logged with admin user id and timestamp.

### 5.9 Notification triggers

Each state transition fires an event to the notification queue. The notification system (Symfony Messenger handler) decides per-channel based on platform settings + user preferences.

| Transition | Email to customer | SMS to customer | Push to customer | Email/notification to vendor | Email to admin |
|---|---|---|---|---|---|
| Order placed | ✅ Order confirmation | (optional) | ✅ | ✅ "New order received" | — |
| `payment_failed` | ✅ "Payment failed" | — | ✅ | — | — |
| `expired` (unpaid timeout) | ✅ "Cart abandoned" | — | — | — | — |
| `awaiting_vendor` → `processing` | ✅ "Vendor accepted" | — | ✅ | — | — |
| `vendor_rejected` | ✅ "Vendor unable to fulfill — refund issued" | — | ✅ | ✅ "Decline confirmed" | (if vendor decline rate spikes) |
| `processing` → `ready_to_ship` | (optional, low-noise) | — | (optional) | — | — |
| `ready_to_ship` → `shipped` | ✅ Shipped + tracking | ✅ "Your order shipped" | ✅ | — | — |
| `out_for_delivery` | ✅ "Out for delivery" | ✅ | ✅ | — | — |
| `delivered` | ✅ Delivered + review prompt | ✅ "Delivered" | ✅ | — | — |
| `delivery_failed` | ✅ "Delivery problem" | ✅ | ✅ | ✅ "Delivery failed — action required" | (after retries fail) |
| Return `requested` | ✅ Return request received | — | — | ✅ "New return request" | — |
| Return `approved` | ✅ "Approved — please ship" | — | ✅ | — | — |
| Return `rejected` | ✅ "Return declined" + reason | — | ✅ | — | — |
| Return `received` | ✅ "Items received — inspecting" | — | — | — | — |
| Return `refunded` | ✅ Refund issued + 5-10 day note | ✅ "Refund issued" | ✅ | — | — |
| Return `disputed` | ✅ "Under review" | — | — | ✅ "Dispute opened" | ✅ Admin queue |
| Order rollup `cancelled` | ✅ Cancellation confirmation | — | ✅ | (only relevant SubOrders) | — |

All emails are templated in ZeptoMail; templates live in `apps/api/templates/emails/`. SMS templates live in `apps/api/templates/sms/`.

### 5.10 State change history (audit log)

Every transition writes a row to `order_state_history`:

```sql
CREATE TABLE order_state_history (
  id BIGSERIAL PRIMARY KEY,
  entity_type VARCHAR(20) NOT NULL,  -- 'order', 'sub_order', 'return_request'
  entity_id BIGINT NOT NULL,
  from_status VARCHAR(50),
  to_status VARCHAR(50) NOT NULL,
  actor_type VARCHAR(20) NOT NULL,   -- 'customer', 'vendor', 'admin', 'system', 'webhook'
  actor_id BIGINT,                   -- nullable for 'system' / 'webhook'
  reason VARCHAR(50),                -- structured reason code where applicable
  notes TEXT,                        -- free-text notes (admin overrides)
  metadata JSONB,                    -- extra context (e.g. webhook payload, IP, etc.)
  occurred_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_state_history_entity ON order_state_history (entity_type, entity_id, occurred_at);
```

Use cases:
- Customer support — "what happened to order #1234?"
- Compliance — proof of when each state changed and who triggered it
- Analytics — distribution of vendor acceptance times, average time to ship, etc.
- Disputes — audit trail for chargeback responses

### 5.11 Schema additions

Adds these tables to the new `apps/api` PostgreSQL schema. Full schema-rebuild details in Appendix D.

| Table | Purpose |
|---|---|
| `orders` | Customer-facing order record (one per checkout) |
| `sub_orders` | Per-vendor fulfillment unit |
| `order_items` | Line items, scoped to a `sub_order` |
| `payments` | Aggregate payment record per order |
| `payment_attempts` | Each attempt at payment (retries, 3DS, etc.) |
| `shipments` | Shipping/tracking info per sub_order |
| `return_requests` | RMA workflow per sub_order |
| `return_request_items` | Items in a return request (subset of order_items) |
| `order_state_history` | Audit log of all state transitions |
| `platform_settings` | Admin-configurable platform settings (key/value) |

### 5.12 Endpoint surface

Lifecycle endpoints in `apps/api` (full inventory in §7):

**Customer:**
- `POST /v3/checkout/initiate` — creates Order + SubOrders + payment_attempt; returns Noon hosted URL
- `POST /v3/checkout/finalize` — Noon webhook; flips payment_status
- `GET /v3/orders` / `GET /v3/orders/:id`
- `POST /v3/orders/:id/cancel` (or `/v3/sub-orders/:id/cancel` for partial)
- `POST /v3/orders/:id/returns` — open return request
- `GET /v3/returns/:id` / `POST /v3/returns/:id/ship` (mark return shipped)

**Vendor:**
- `GET /v3/vendor/sub-orders` (filter by status)
- `POST /v3/vendor/sub-orders/:id/accept` (→ `processing`)
- `POST /v3/vendor/sub-orders/:id/decline` (→ `vendor_rejected`, body: reason)
- `POST /v3/vendor/sub-orders/:id/ready` (→ `ready_to_ship`)
- `POST /v3/vendor/sub-orders/:id/ship` (→ `shipped`, body: carrier + tracking)
- `POST /v3/vendor/returns/:id/approve` / `decline` / `received` / `accept` / `dispute`

**Admin:**
- `GET /v3/admin/orders` (full filtering, search)
- `POST /v3/admin/orders/:id/force-cancel` (with reason/notes — audit logged)
- `POST /v3/admin/returns/:id/resolve-dispute` (refund or reject after inspection)
- `GET /v3/admin/orders/:id/history` (full state_history view)
- `GET /v3/admin/settings` / `PUT /v3/admin/settings` (platform settings CRUD)

**System (webhook receivers):**
- `POST /v3/webhooks/noon` — payment events
- `POST /v3/webhooks/topex` — shipping events (delivered, out-for-delivery, failed)

### 5.13 Migration from existing data

The existing MySQL has a single `ec_cart_items` table with status strings (`pending`, `paid`, `processing`, `completed`, `failed`, `payment_failed`, `refunded`, `returned`). The migration to the new schema:

1. **Bulk migration job (M3 entry):**
   - Create `orders` from `ec_orders` rows
   - Create `sub_orders` by grouping `ec_cart_items` by (order_id, vendor_store)
   - Create `order_items` by mapping each `ec_cart_items` row
   - Map old statuses to new fulfillment_status:

| Old status | New `payment_status` | New `fulfillment_status` |
|---|---|---|
| `pending` | `awaiting_payment` | `pending` |
| `paid` | `paid` | `awaiting_vendor` |
| `processing` | `paid` | `processing` |
| `completed` | `paid` | `delivered` |
| `failed` / `payment_failed` | `payment_failed` | `cancelled` |
| `refunded` | `refunded` | `cancelled` |
| `returned` | `partially_refunded` (best guess) + create return_request in `refunded` state | `delivered` (with return record) |

2. **Backfill `order_state_history`:** for each migrated order, write a single history row with `actor_type='system'`, `notes='migrated from legacy backend'`, and the migration timestamp. Pre-migration history is lost — accepted as data-debt.
3. **CDC sync** keeps both stores in agreement during M3 until web/mobile/portal all migrate to the new endpoints.

### 5.14 What this section deliberately does NOT cover

- **Vendor payouts / commissions / settlement.** This is a finance concern, separate from order lifecycle. A vendor's payout for an order happens N days after `delivered` (vendor-specific payout schedule), and goes through its own commission-calculation workflow. Designed in M4 as part of vendor portal work.
- **Inventory deduction timing.** Currently leaning toward "deduct at payment confirmation, not at cart-add" (Shopify pattern). Will be locked in M3 entry.
- **Coupon/discount application logic.** Existing `coupons`, `coupon_products`, `coupon_stores`, `coupon_usage` tables carry forward. Coupons applied at order creation; their effect is captured in the `discount_total` snapshot. Logic for which coupons apply to which orders is its own subsystem.
- **Multi-currency.** Platform is AED-only. If multi-currency is ever needed, prices stay in `currency: 'AED'` snapshot in order_items; order rollups stay in AED.
- **Subscriptions / recurring orders.** Out of scope for the platform.

---

## 6. Monorepo structure & tooling

This section describes the **monorepo target** in detail — file tree, package boundaries, build orchestration, CI, deploy pipelines. The migration *into* this structure is in §8.

### 6.1 Monorepo file tree (target)

```
3bayti/
├── apps/
│   ├── api/                        ← Slim 4 backend (PHP)
│   │   ├── public/                 ← entry point (index.php, routes through Slim)
│   │   ├── src/
│   │   │   ├── Domain/             ← Doctrine entities + repositories per bounded context
│   │   │   │   ├── User/
│   │   │   │   ├── Catalog/
│   │   │   │   ├── Cart/
│   │   │   │   ├── Order/
│   │   │   │   ├── Payment/
│   │   │   │   ├── Messaging/
│   │   │   │   └── Common/
│   │   │   ├── Application/        ← use cases, command/query handlers
│   │   │   ├── Infrastructure/     ← adapters (Noon, MessageCentral, ZeptoMail, R2)
│   │   │   ├── Http/               ← Slim controllers, middleware, request/response DTOs
│   │   │   └── Bootstrap.php       ← Slim app factory, DI container wiring
│   │   ├── tests/
│   │   ├── migrations/             ← Doctrine migrations
│   │   ├── composer.json
│   │   └── README.md
│   │
│   ├── web/                        ← migrated from surdbells/3bayti-web
│   │   ├── src/                    (existing structure)
│   │   ├── package.json
│   │   ├── angular.json
│   │   └── wrangler.jsonc
│   │
│   ├── mobile/                     ← migrated from surdbells/abayti_app
│   │   ├── src/
│   │   │   └── app/
│   │   │       ├── customer/       ← existing 38 customer pages
│   │   │       ├── vendor/         ← existing store-dashboard + new lite vendor pages
│   │   │       └── shared/
│   │   ├── android/                (Capacitor native projects)
│   │   ├── ios/
│   │   ├── package.json
│   │   ├── capacitor.config.ts
│   │   └── ionic.config.json
│   │
│   └── portal/                     ← migrated from surdbells/abayti_vendor
│       ├── src/
│       │   └── app/
│       │       ├── backend/        ← admin + vendor management areas (15 areas)
│       │       ├── public/         ← marketing/login pages
│       │       ├── settings/
│       │       └── shared/
│       ├── package.json
│       └── angular.json
│
├── packages/
│   ├── api-contracts/
│   │   ├── openapi.yaml            ← generated from apps/api swagger-php annotations
│   │   ├── src/
│   │   │   └── generated.ts        ← TypeScript types from openapi.yaml
│   │   ├── package.json
│   │   └── README.md
│   │
│   ├── api-client/
│   │   ├── src/
│   │   │   ├── client.ts           ← typed fetch wrapper / Angular HttpClient adapter
│   │   │   ├── auth-interceptor.ts ← JWT injection + refresh-on-401
│   │   │   ├── error-normaliser.ts ← envelope unwrapping
│   │   │   ├── feature-flags.ts    ← per-endpoint old-vs-new routing
│   │   │   └── retry.ts            ← exponential backoff for transient errors
│   │   └── package.json
│   │
│   ├── shared-ui/
│   │   ├── src/
│   │   │   ├── button/
│   │   │   ├── modal/
│   │   │   ├── form-field/
│   │   │   └── ...
│   │   └── package.json
│   │
│   ├── design-tokens/
│   │   ├── src/
│   │   │   ├── colors.ts
│   │   │   ├── typography.ts
│   │   │   ├── spacing.ts
│   │   │   ├── shadows.ts          ← --shadow-card-resting, etc.
│   │   │   └── index.ts            ← export everything
│   │   ├── tokens.css              ← CSS custom properties (consumed by web/portal)
│   │   ├── tokens.scss             ← SCSS variables (consumed by mobile)
│   │   └── package.json
│   │
│   ├── eslint-config-3bayti/
│   │   ├── index.js                ← shared ESLint config
│   │   └── package.json
│   │
│   └── tsconfig-3bayti/
│       ├── base.json
│       ├── app.json
│       ├── lib.json
│       └── package.json
│
├── docs/
│   ├── roadmap.md                  ← THIS DOCUMENT — moved here from 3bayti-web/docs/
│   ├── architecture/
│   │   ├── api-design.md
│   │   ├── auth-flow.md
│   │   └── data-migration.md
│   └── runbooks/
│       ├── deploy-api.md
│       ├── rollback-api.md
│       └── feature-flag-rollout.md
│
├── tools/
│   ├── ci/                         ← GitHub Actions workflow definitions
│   ├── scripts/                    ← deploy, migration, codegen scripts
│   └── docker/                     ← local dev compose files
│
├── pnpm-workspace.yaml             ← lists apps/* and packages/*
├── turbo.json                      ← Turborepo task graph config
├── package.json                    ← root, devDependencies only
├── tsconfig.json                   ← root TS config
├── .gitignore
├── .editorconfig
├── README.md
└── LICENSE
```

### 6.2 Package boundaries — what depends on what

```
                    ┌──────────────────┐
                    │ design-tokens    │
                    │ (no deps)        │
                    └─────────┬────────┘
                              │
              ┌───────────────┼─────────────┐
              │               │             │
        ┌─────▼────┐     ┌────▼─────┐  ┌────▼────────┐
        │ shared-ui│     │ api-     │  │ api-client  │
        │          │     │ contracts│  │             │
        └─────┬────┘     └────┬─────┘  └──────┬──────┘
              │               │                │
              ├───────────────┼────────────────┤
              │               │                │
        ┌─────▼─────┐  ┌──────▼──────┐  ┌──────▼──────┐
        │ apps/web  │  │apps/portal  │  │apps/mobile  │
        └───────────┘  └─────────────┘  └─────────────┘

        ┌──────────────────────────────────────────┐
        │ apps/api (PHP, no JS deps, separate     │
        │  composer workspace inside monorepo)    │
        └──────────────────────────────────────────┘
```

Direction is strictly downward — `design-tokens` knows nothing about `shared-ui`, which knows nothing about `apps/web`. A package never imports from an app. Apps never import from each other.

`apps/api` is structurally separate — it lives in the monorepo for unified git history but its `composer.json` is independent and PHP doesn't go through pnpm.

### 6.3 Build orchestration — Turborepo

`turbo.json` defines task pipelines. Common tasks:

| Task | What it does |
|---|---|
| `build` | Builds all packages + apps in dependency order, cached per-package |
| `lint` | ESLint across all TS packages |
| `test` | Karma + Jasmine (frontend), PHPUnit (api) |
| `test:e2e` | Playwright (web) |
| `dev` | Run all apps + api in parallel for local development |
| `codegen` | Regenerate `packages/api-contracts/src/generated.ts` from `apps/api`'s OpenAPI output |
| `deploy:web` | Cloudflare Workers deploy for `apps/web` |
| `deploy:portal` | Cloudflare Pages deploy for `apps/portal` |
| `deploy:api` | DigitalOcean App Platform deploy for `apps/api` |
| `deploy:mobile` | Capacitor build + App Store / Play Store push for `apps/mobile` |

Dependency graph in `turbo.json` ensures `apps/web` rebuilds when `packages/shared-ui` changes, but not when `apps/portal` changes. This is the central reason for using Turborepo over plain pnpm scripts.

### 6.4 CI/CD pipeline

Per-app GitHub Actions workflows live in `tools/ci/`. Each workflow:

1. **Checks out** the monorepo
2. **Restores Turborepo cache** from previous runs
3. **Installs dependencies** (pnpm or composer depending on app)
4. **Runs only what changed** — Turborepo skips builds for unaffected apps
5. **Builds + tests** the changed app
6. **Deploys** if the build is on `main`

Branch strategy:
- `main` — protected, always deployable
- `feat/*`, `fix/*`, `chore/*` — feature branches, opened as PRs to `main`
- `hotfix/*` — for production hotfixes that bypass the usual queue

Pre-merge checks (required to be green): TS check, lint, unit tests, build, smoke deploy on Cloudflare's `*.workers.dev` preview.

Post-merge: deploy proceeds automatically to `staging.*` subdomains. Production deploys are manual approvals (workflow triggered by tag, e.g. `v2026.05.06`).

### 6.5 Local development

```bash
# Once at the repo root
pnpm install              # installs JS deps for all apps + packages
cd apps/api && composer install && cd ../..

# Run everything (Turborepo runs each app's `dev` script in parallel)
pnpm dev

# Or individually
pnpm --filter @3bayti/web dev
pnpm --filter @3bayti/portal dev
pnpm --filter @3bayti/mobile dev
cd apps/api && php -S localhost:8080 -t public/

# Database (during M0–M5 — migration period)
docker compose up -d postgres redis    # local PostgreSQL + Redis
cd apps/api && composer run migrate    # apply Doctrine migrations
cd apps/api && composer run seed       # seed dev data
```

### 6.6 OpenAPI contract — single source of truth

The most important shared artifact. Workflow:

1. Backend developer writes a new endpoint in `apps/api/src/Http/Controllers/`. Annotates it with swagger-php attributes.
2. `pnpm codegen` (or CI) runs swagger-php → generates `packages/api-contracts/openapi.yaml`.
3. Same script runs `openapi-typescript` → generates `packages/api-contracts/src/generated.ts`.
4. Frontend code in any of the three apps imports types from `@3bayti/api-contracts`:
   ```typescript
   import type { paths } from '@3bayti/api-contracts';
   type LoginRequest = paths['/v3/auth/login']['post']['requestBody']['content']['application/json'];
   ```
5. If the backend contract changes, frontend TypeScript compilation fails until the frontend is updated. **Drift is caught at compile time.**

### 6.7 What the existing repos lose during migration

When `apps/web` lands in the monorepo (M0):
- ❌ Its own GitHub Actions (replaced by monorepo CI)
- ❌ Its own Cloudflare project (replaced by `@3bayti/web` named project)
- ❌ Its own `package.json` at the repo root (becomes `apps/web/package.json`, with shared deps hoisted)
- ✅ Git history preserved via `git subtree` or similar — every commit on the old repo carries through

When `apps/mobile` lands (M4):
- ❌ Capacitor build context changes — paths in `capacitor.config.ts` may need updating for monorepo
- ❌ `surdbells/abayti_app`'s GitHub Releases workflow replaced
- ✅ Native Android/iOS projects stay (Capacitor projects are mostly path-agnostic)
- ✅ Existing Capacitor 8 setup carries forward

When `apps/portal` lands (M4):
- ❌ Angular 19 → 21 upgrade required first (substantial; see M4 details in §9)
- ❌ Standalone-component migration (Angular 19 still uses NgModules in some places)
- ❌ Tailwind 4 upgrade (currently on whatever it has)
- ✅ Component logic mostly portable, just needs version bumps

### 6.8 What new in the monorepo

Things that don't exist today and get built in the monorepo:

- The whole `apps/api` Slim 4 backend
- All `packages/*` (every shared package is new)
- A unified design system between web + portal (currently each defines its own buttons/modals)
- Type-safe API consumption (currently every frontend hand-writes interfaces)
- A unified auth model with proper JWT validation
- A test e2e suite that exercises web + API together (Playwright in `apps/web/e2e/`)

---

## 7. Backend endpoint inventory

This section catalogues every backend endpoint across all surfaces. The numbers grew significantly from v1.1 once vendor + admin portal endpoints were inventoried alongside the mobile customer endpoints.

**Total: 213 PHP endpoints across 8 directories** (excluding `vendor/` Composer libraries):

| Directory | Count | Surfaces served |
|---|---|---|
| `customer/*` | 54 | Mobile customer + (some) web |
| `customer/v2/*` | M4 | Web (catalog only, current `/v2/` patch) |
| `vendors/*` | 49 | Desktop vendor portal + mobile lite vendor |
| `admin/*` | 39 | Desktop admin portal |
| `users/*` | 10 | Auth — shared across all surfaces |
| `chat/*` | 10 | Per-order vendor chat |
| `utility/*` | M5 | Misc helpers (sitemap data, etc.) |
| `webhooks/*` | M1 | Payment webhook from Noon |

The sections below preserve the v1.1 mobile-customer-focused inventory because the mapping web→`/v2/` is still useful for context, but the **destination column** has changed: every endpoint maps to a **`/v3/*` namespace** in the new `apps/api` rather than a `/v2/*` PHP wrapper.

### Phase mapping (v1.x → v2.0)

| v1.x phase | v2.0 phase | Notes |
|---|---|---|
| Phase 1 (Home + auth wrappers) | M0–M1 | Auth wrappers superseded by full `/v3/auth/*` rebuild |
| Phase 3 (Auth + Checkout) | M1 (auth) + M3 (checkout) | Split because auth needs to land first independently |
| Phase 4 (Orders) | M3 (in scope of checkout phase) | Folded in |
| Phase 5 (Wishlist + Reviews) | M2 (catalog includes reviews) + post-M3 wishlist | Reviews are catalog-side; wishlist needs auth |
| Phase 6 (Browse Plus) | M2 | Vendor pages, search, follow-designer |
| Phase 7 (Messages + Tickets) | M3 / M4 | Customer-side in M3, vendor-side in M4 |
| Phase 8 (Chat) | M4 | WebSocket layer decision deferred to this phase |
| Phase 9 (Hardening) | M5 | Plus image migration completion |

The tables below retain the original `Phase` column for historical reference; mentally translate Phase X → corresponding M-phase per the table above.

### Legend

| Symbol | Meaning |
|---|---|
| ✅ Done | Already in `/v2/` (W2.0 patch shipped) — used by web today |
| 🔧 Build | Needs implementing in `apps/api` as `/v3/*` |
| ➖ Skip | Not needed on web, or superseded by another endpoint |
| 🔒 Auth | Requires authenticated user |
| 🌐 Public | Anonymous-friendly |
| 🟡 Hybrid | Behavior changes based on auth state |

### 7.1 Auth (`users/*`) — 7 endpoints

All needed in **M1**. Auth-gated rest of the app.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 1 | `users/login` | POST | 🌐 | 🔧 Build → `/v3/auth/login` | M1 | Returns JWT (proper, not opaque); web stores in localStorage |
| M2 | `users/register` | POST | 🌐 | 🔧 Build → `/v3/auth/register` | M1 | Email + phone + password |
| M3 | `users/resetMobile` | POST | 🌐 | 🔧 Build → `/v3/auth/reset` | M1 | Password reset via OTP |
| M3 | `users/validate` | POST | 🌐 | 🔧 Build → `/v3/auth/validate-phone` | M1 | Pre-register phone-number availability |
| M2 | `users/validate-email` | POST | 🌐 | 🔧 Build → `/v3/auth/validate-email` | M1 | Pre-register email availability |
| M2 | `users/confirm` | POST | 🌐 | 🔧 Build → `/v3/auth/confirm` | M1 | OTP confirmation step |
| M3 | `users/sendOTP` | POST | 🌐 | 🔧 Build → `/v3/auth/send-otp` | M1 | Sends OTP for register/reset |

### 7.2 Account & settings (`customer/settings/*`, `customer/profile`) — 11 endpoints

All needed in **M1**. Account management screens.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 1 | `customer/settings/update-location` | POST | 🔒 | 🔧 Build → `/v3/account/location` | M1 | Sets default city/area |
| M2 | `customer/settings/read-profile` | POST | 🔒 | 🔧 Build → `/v3/account/profile` (GET) | M1 | Reads profile |
| 10 | `customer/settings/update-profile` | POST | 🔒 | 🔧 Build → `/v3/account/profile` (PUT) | M1 | Updates profile |
| 11 | `customer/settings/measurement/read-measurement` | POST | 🔒 | 🔧 Build → `/v3/account/measurements` (GET) | M1 | Reads body measurements |
| 12 | `customer/settings/measurement/update-measurement` | POST | 🔒 | 🔧 Build → `/v3/account/measurements` (PUT) | M1 | Updates body measurements |
| 13 | `customer/settings/billing/read-billings` | POST | 🔒 | 🔧 Build → `/v3/account/addresses` (GET) | M1 | Lists shipping addresses |
| 14 | `customer/settings/billing/update-billing` | POST | 🔒 | 🔧 Build → `/v3/account/addresses` (PUT/POST) | M1 | Adds/updates address |
| 15 | `customer/settings/read-reviews` | POST | 🔒 | 🔧 Build → `/v3/account/reviews` | M3 | Lists user's reviews |
| 16 | `customer/settings/store-reviews` | POST | 🔒 | ➖ Duplicate of #59 | — | Same as `customer/store-reviews` per code inspection |
| 17 | `customer/settings/delete-review` | POST | 🔒 | 🔧 Build → `/v3/account/reviews/:id` (DELETE) | M3 | Deletes user's review |
| 18 | `vendors/measurement/get-measurements` | POST | 🔒 | 🔧 Build → `/v3/measurements/template` | M1 | Reads vendor's measurement schema (e.g. abayas have different fields than kaftans) |

### 7.3 Catalog browse — public (read-heavy) — 18 endpoints

These are the lifeblood of SEO and are mostly already wrapped from W2.0.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 19 | `customer/category` | POST | 🌐 | ✅ Done — `/v2/categories` | done | Already shipped |
| 20 | `customer/category_listing` | POST | 🌐 | ✅ Done — `/v2/categories/:slug` | done | Already shipped |
| 21 | `customer/single_product` | POST | 🌐 | ✅ Done — `/v2/products/:slug` | done | Already shipped |
| 22 | `customer/singleProduct` | POST | 🌐 | ➖ Older variant of #21 | — | Mobile app uses both; web uses `/v2/products/:slug` |
| 23 | `customer/featured` | POST | 🌐 | 🔧 Build → `/v3/products?filter=featured` | M2 (extension) | Home page featured products. Could fold into existing `/v2/products` w/ filter |
| 24 | `customer/filterfeatured` | POST | 🌐 | ➖ Fold into #23 | — | Same data, different filters |
| 25 | `customer/best_sellers` | POST | 🌐 | 🔧 Build → `/v3/products?filter=best-sellers` | M2 | |
| 26 | `customer/best_sellers_listing` | POST | 🌐 | 🔧 Build → `/v3/best-sellers` (paginated) | M2 | Paginated listing with filters |
| 27 | `customer/new_arrivals` | POST | 🌐 | 🔧 Build → `/v3/products?filter=new-arrivals` | M2 | |
| 28 | `customer/new_arrivals_listing` | POST | 🌐 | 🔧 Build → `/v3/new-arrivals` (paginated) | M2 | Paginated listing with filters |
| 29 | `customer/explore` | POST | 🌐 | 🔧 Build → `/v3/explore` | M2 | Curated discovery feed (data shape TBD) |
| 30 | `customer/explore_listing` | POST | 🌐 | 🔧 Build → `/v3/explore/:vertical` | M2 | Detail of an explore vertical |
| 31 | `customer/filterexplore` | POST | 🌐 | ➖ Fold into #30 | — | Same data with filters |
| 32 | `customer/filter_product` | POST | 🌐 | 🔧 Wrap — augment `/v2/products` with filter params | M2 | Filter by size, colour, price, vendor, etc. |
| 33 | `customer/product_by_category` | POST | 🌐 | ➖ Fold into `/v2/categories/:slug` | — | Already returns products in v2 |
| 34 | `customer/products_by_labels` | POST | 🌐 | 🔧 Build → `/v3/labels/:label/products` | M2 | Vendor-defined label browsing |
| 35 | `customer/search` | POST | 🌐 | 🔧 Build → `/v3/search?q=...` | M2 | Full-text search |
| 36 | `customer/sitemap-data` | — | 🌐 | ✅ Done — `/v2/sitemap-data` | done | Already shipped |

### 7.4 Vendor / designer browse — 7 endpoints

Public-facing designer pages.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 37 | `customer/read-vendor` | POST | 🌐 | ✅ Done — `/v2/vendors/:slug` | done | Already shipped |
| 38 | `customer/vendors_list` | POST | 🌐 | ✅ Done — `/v2/vendors` | done | Already shipped |
| 39 | `customer/vendors_products` | POST | 🌐 | 🔧 Build → `/v3/vendors/:slug/products` | M2 | Products by vendor — paginated |
| 40 | `customer/store_latest` | POST | 🌐 | 🔧 Build → `/v3/vendors/:slug/products?sort=newest&limit=10` | M2 | "Latest from this designer" widget |
| 41 | `customer/read_vendor_collection` | POST | 🌐 | 🔧 Build → `/v3/vendors/:slug/collections` | M2 | Vendor's curated collections / labels |
| 42 | `customer/follow` | POST | 🔒 | 🔧 Build → `/v3/vendors/:slug/follow` (POST) | M2 | Auth required |
| 43 | `customer/unfollow` | POST | 🔒 | 🔧 Build → `/v3/vendors/:slug/follow` (DELETE) | M2 | Auth required |

### 7.5 Wishlist — 4 endpoints

Phase 3.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 44 | `customer/read_wishlist` | POST | 🔒 | 🔧 Build → `/v3/wishlist` (GET) | M3 | List wishlist items |
| 45 | `customer/read_wishlist_label` | POST | 🔒 | 🔧 Build → `/v3/wishlist/labels` (GET) | M3 | Wishlist supports user-defined labels (collections) |
| 46 | `customer/add_wishlist_label` | POST | 🔒 | 🔧 Build → `/v3/wishlist/labels` (POST) | M3 | Add a new label/collection |
| 47 | `customer/add_wishlist` | POST | 🔒 | 🔧 Build → `/v3/wishlist/items` (POST) | M3 | Add product to wishlist (under a label) |

**Note:** No "delete from wishlist" endpoint exists in the mobile app's global-component. Either it's reuse-driven (re-adding toggles), or there's a hidden endpoint. Phase 3 must clarify.

### 7.6 Reviews — 3 endpoints

Phase 3.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 48 | `customer/store-reviews` | POST | 🌐 | 🔧 Build → `/v3/vendors/:slug/reviews` (GET) | M3 | Public — read vendor reviews |
| 49 | `customer/add-review` | POST | 🔒 | 🔧 Build → `/v3/reviews` (POST) | M3 | Submit a review (product or vendor) |
| 50 | `customer/helpful` | POST | 🔒 | 🔧 Build → `/v3/reviews/:id/helpful` (POST) | M3 | Mark a review as helpful |

### 7.7 Cart — 4 endpoints

M3.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 51 | `customer/read-cart` | POST | 🔒 | 🔧 Build → `/v3/cart` (GET) | M3 | Read user's cart |
| 52 | `customer/addToCart` | POST | 🔒 | 🔧 Build → `/v3/cart/items` (POST) | M3 | Add item with size/colour/quantity |
| 53 | `customer/removeFromCart` | POST | 🔒 | 🔧 Build → `/v3/cart/items/:id` (DELETE) | M3 | Remove a line item |
| 54 | `customer/IncreaseItem` | POST | 🔒 | 🔧 Build → `/v3/cart/items/:id` (PATCH) | M3 | Quantity ± can be one PATCH endpoint |
| 55 | `customer/decreaseItem` | POST | 🔒 | ➖ Fold into #54 | — | |

**Open question:** Does the mobile app support guest carts? If yes, web should match (probably with localStorage cart that hydrates to server on login). Already confirmed and shipped in Phase 2 (local-first cart). M3 adds the merge-on-login layer.

### 7.8 Checkout & payment — 6 endpoints

M3. Highest-risk part of M3 due to payment integration.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 56 | `customer/payment/initiate_payment` | POST | 🔒 | 🔧 Build → `/v3/checkout/initiate` | M3 | Creates payment session, returns PSP URL/token |
| 57 | `customer/finalize_payment` | POST | 🔒 | 🔧 Build → `/v3/checkout/finalize` | M3 | Webhook-driven order confirmation |
| 58 | `customer/getToken` | POST | 🔒 | 🔧 Build — TBD purpose (investigate during M3) | M3 | Auth header? Payment gateway token? Investigate Phase 5 |
| 59 | `customer/sendOTP` | POST | 🔒 | 🔧 Build → `/v3/checkout/otp/send` | M3 | OTP for high-value purchases? Or 3DS? Investigate |
| 60 | `customer/validateOTP` | POST | 🔒 | 🔧 Build → `/v3/checkout/otp/validate` | M3 | |
| 61 | `topexCities` (external) | GET | 🌐 | 🔧 Proxy via `/v3/shipping/cities` | M3 | Topex shipping API. Web cannot call directly (CORS) — proxy |
| 62 | `topexAreaURL/:cityId` (external) | GET | 🌐 | 🔧 Proxy via `/v3/shipping/areas/:cityId` | M3 | |

**Note on payment:** **Noon Payments** is the chosen PSP (confirmed v2.0). Integration model: backend calls `POST /payment/v1/order/initiate` against Noon's API, receives a hosted checkout URL, web/mobile/portal redirect (or embed) the Noon hosted page. 3DS, Apple Pay, Google Pay, KNET, BENEFIT all handled by Noon's hosted page — no PCI scope on our side. Webhook returns to `/v3/checkout/finalize`. Auth header to Noon: `Authorization: Key Live <NOON_KEY>` from server-side only.

See `apps/api/src/Domain/Payment/NoonPaymentsAdapter.php` (M3 deliverable) for implementation.

### 7.9 Orders — 4 endpoints from old backend, plus new lifecycle endpoints

M3.

The mobile app's existing order endpoints (below) are read-only. The new platform adds **action endpoints** for state transitions per §5 (cancel, return, vendor accept/decline/ship, admin override). Those don't exist in the old backend at all — they're new in M3.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 63 | `customer/read-orders` | POST | 🔒 | 🔧 Build → `/v3/orders` (GET) | M3 | List user's orders (paginated). New shape: per-vendor sub-orders rolled up. |
| 64 | `customer/read-order-details` | POST | 🔒 | 🔧 Build → `/v3/orders/:id` (GET) | M3 | Single order detail. Returns Order + per-vendor SubOrders + items + state history. |
| 65 | `customer/read-customer-orders` | POST | 🔒 | ➖ Duplicate of #63 | — | Investigate if any meaningful difference |
| 66 | `customer/read_orders_listing` | POST | 🔒 | ➖ Duplicate of #63 | — | Same as above |

#### NEW lifecycle endpoints (per §5 — no mobile-app equivalents)

| Endpoint | Method | Auth | Surface | Notes |
|---|---|---|---|---|
| `/v3/orders/:id/cancel` | POST | 🔒 customer | Web/Mobile | Customer-initiated cancellation; allowed only when SubOrder is in `pending`/`awaiting_vendor` |
| `/v3/sub-orders/:id/cancel` | POST | 🔒 customer | Web/Mobile | Cancel a single SubOrder (partial cancellation when multiple vendors) |
| `/v3/orders/:id/returns` | POST | 🔒 customer | Web/Mobile | Open return request (RMA) |
| `/v3/returns/:id` | GET | 🔒 customer | Web/Mobile | Read return status |
| `/v3/returns/:id/ship` | POST | 🔒 customer | Web/Mobile | Customer marks return shipped (with carrier + tracking) |
| `/v3/vendor/sub-orders` | GET | 🔒 vendor | Portal/Mobile | Vendor's queue of sub-orders, filterable by status |
| `/v3/vendor/sub-orders/:id/accept` | POST | 🔒 vendor | Portal/Mobile | Vendor accepts → moves to `processing` |
| `/v3/vendor/sub-orders/:id/decline` | POST | 🔒 vendor | Portal/Mobile | Vendor declines (within 24h) → `vendor_rejected`; auto-refund |
| `/v3/vendor/sub-orders/:id/ready` | POST | 🔒 vendor | Portal/Mobile | Mark ready_to_ship |
| `/v3/vendor/sub-orders/:id/ship` | POST | 🔒 vendor | Portal/Mobile | Mark shipped, body: carrier + tracking |
| `/v3/vendor/returns/:id/approve` | POST | 🔒 vendor | Portal/Mobile | Approve return request |
| `/v3/vendor/returns/:id/decline` | POST | 🔒 vendor | Portal/Mobile | Decline return request (with reason) |
| `/v3/vendor/returns/:id/received` | POST | 🔒 vendor | Portal | Mark return items received |
| `/v3/vendor/returns/:id/accept` | POST | 🔒 vendor | Portal | Accept return after inspection → triggers refund |
| `/v3/vendor/returns/:id/dispute` | POST | 🔒 vendor | Portal | Vendor disputes return after inspection → admin escalation |
| `/v3/admin/orders` | GET | 🔒 admin | Portal | Full admin order search/filter |
| `/v3/admin/orders/:id/force-cancel` | POST | 🔒 admin | Portal | Admin override cancel from any state (audit-logged) |
| `/v3/admin/orders/:id/history` | GET | 🔒 admin | Portal | Full state-change history |
| `/v3/admin/returns/:id/resolve-dispute` | POST | 🔒 admin | Portal | Admin resolves vendor-disputed return (refund or reject) |
| `/v3/admin/settings` | GET, PUT | 🔒 admin | Portal | Read/write platform settings (per §5.8) |
| `/v3/webhooks/noon` | POST | 🔓 system | (webhook) | Noon Payments webhook receiver |
| `/v3/webhooks/topex` | POST | 🔓 system | (webhook) | Topex shipping webhook receiver |

### 7.10 Messages (per-order chat with vendor) — 4 endpoints

M3.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 67 | `customer/read-conversations` | POST | 🔒 | 🔧 Build → `/v3/messages/conversations` | M3 | List conversations |
| 68 | `customer/read-messages` | POST | 🔒 | 🔧 Build → `/v3/messages/:conversationId` | M3 | Read a conversation |
| 69 | `customer/send-message` | POST | 🔒 | 🔧 Build → `/v3/messages/:conversationId/send` | M3 | Send a message |
| 70 | `customer/read-customer-orders` | POST | 🔒 | ➖ Duplicate of orders #63 | — | Confusingly listed in mobile app under both |

**Note:** This may overlap or be replaced by the dedicated chat module (5.13). Phase 7 entry must reconcile the two.

### 7.11 Support tickets — 4 endpoints

M3.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 71 | `customer/create_ticket` | POST | 🔒 | 🔧 Build → `/v3/support/tickets` (POST) | M3 | Open a new ticket |
| 72 | `customer/read_ticket` | POST | 🔒 | 🔧 Build → `/v3/support/tickets` (GET) | M3 | List tickets |
| 73 | `customer/read-ticket-messages` | POST | 🔒 | 🔧 Build → `/v3/support/tickets/:id/messages` (GET) | M3 | Ticket conversation |
| 74 | `customer/send-ticket-message` | POST | 🔒 | 🔧 Build → `/v3/support/tickets/:id/messages` (POST) | M3 | Reply to ticket |

### 7.12 Styles (custom design requests) — 2 endpoints

Phase 2 if simple, Phase 7 otherwise. To investigate.

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 75 | `customer/styles_list` | POST | 🔒 | 🔧 Build → `/v3/styles` (GET) | 2/7 | List user's submitted styles |
| 76 | `customer/create_style` | POST | 🔒 | 🔧 Build → `/v3/styles` (POST) | 2/7 | Submit a custom-style request |

### 7.13 Chat module (per-order vendor chat) — 10 endpoints

M4. This is its own subsystem with separate data model (conversations scoped per order item, prompt templates, image uploads, moderation flags).

| # | Endpoint | Method | Auth | Status | Phase | Notes |
|---|---|---|---|---|---|---|
| 77 | `chat/get_vendors` | POST | 🔒 | 🔧 Build → `/v3/chat/vendors` | M4 | Vendors user has bought from |
| 78 | `chat/get_vendor_orders` | POST | 🔒 | 🔧 Build → `/v3/chat/vendors/:id/orders` | M4 | Orders from this vendor |
| 79 | `chat/get_conversation` | POST | 🔒 | 🔧 Build → `/v3/chat/conversations` | M4 | Get/create conversation for order |
| 80 | `chat/get_messages` | POST | 🔒 | 🔧 Build → `/v3/chat/conversations/:id/messages` | M4 | Paginated message history |
| 81 | `chat/send_message` | POST | 🔒 | 🔧 Build → `/v3/chat/conversations/:id/send` | M4 | Send text/prompt |
| 82 | `chat/upload_image` | POST | 🔒 | 🔧 Build → `/v3/chat/upload` | M4 | Image attachment |
| 83 | `chat/get_prompts` | POST | 🔒 | 🔧 Build → `/v3/chat/prompts` | M4 | Quick-reply prompt templates |
| 84 | `chat/mark_read` | POST | 🔒 | 🔧 Build → `/v3/chat/conversations/:id/read` | M4 | Mark messages read |
| 85 | `chat/get_unread_count` | POST | 🔒 | 🔧 Build → `/v3/chat/unread` | M4 | Total unread badge count |
| 86 | `chat/get_vendor_conversations.php` | POST | 🔒 (vendor) | ➖ Vendor-only | — | Out of scope (vendor admin) |

### 7.14 Out of scope — 2 endpoints

Vendor admin endpoints — explicitly **not** building these on web.

| # | Endpoint | Notes |
|---|---|---|
| 87 | `vendors/toggle_status` | Vendor admin only |
| 88 | `vendors/get_stats.php` | Vendor admin only |

### 7.15 Endpoint summary

| Category | Count | Phase | Notes |
|---|---|---|---|
| Already shipped (`/v2/` web catalog) | 8 | ✅ done | Catalog browse foundation, stays live until M2 |
| New `/v3/*` endpoints needed in `apps/api` | ~140 | M1–M4 | Spread across M-phases per §8.4 wave plan |
| Folded / duplicated (reduce to canonical) | ~12 | — | Reduce to single canonical version per concept during M1+ |
| External (proxy required in `apps/api`) | 2 | M3 | Topex shipping API (`/v3/shipping/cities`, `/v3/shipping/areas/:cityId`) |
| Vendor + admin endpoints (was "out of scope" in v1.x — now in scope) | ~88 | M4 | `/v3/vendor/*`, `/v3/admin/*`, `/v3/chat/*` for portal + mobile lite vendor |
| **Total platform-wide surface** | **~250 endpoints** | | Up significantly from v1.1 ~62 because vendor + admin now in scope |

---

## 8. Page-by-page web mapping

The mobile app has **38 customer pages + 1 lite vendor page** in `surdbells/abayti_app`. The web has **34 customer routes** (1:1 mapping with adaptations) plus the auth routes. Below is every web route with: web path, source mobile page, SSR strategy, depending endpoints, and UX notes.

**Status note (v2.0):** the "Endpoints" column references `/v2/*` for endpoints already shipped (live in production for the catalog browse) and `/v3/*` for endpoints that will be built in `apps/api`. The Phase column has been updated to use M-phases (M1, M2, etc.); see §6 phase mapping. Already-shipped routes are marked "✅ done" in the Phase column.

### 8.1 Public catalog routes (already built or extending existing)

| # | Web route | Mobile source | SSR | Endpoints | Phase | Notes |
|---|---|---|---|---|---|---|
| 1 | `/` | `public/home/home.page` | ✅ Prerender | (static for now; W2.x will wire featured products) | ✅ done | Done |
| 2 | `/category` | `customer/category` (idx) | ✅ Prerender | `/v2/categories` | ✅ done | Done |
| 3 | `/category/:slug` | `customer/category` (detail) | ✅ Prerender top 8 + runtime SSR | `/v2/categories/:slug` | ✅ done | Done |
| 4 | `/product/:slug` | `customer/product/product.page` | ✅ Prerender top 200 + runtime SSR | `/v2/products/:slug` | ✅ done | Done (W2.2b) |
| 5 | `/designer` | `customer/vendors/vendors.page` | ✅ Prerender | `/v3/vendors` | M2 | "Designers" naming for SEO |
| 6 | `/designer/:slug` | (not in mobile as standalone — vendor data shown in product context) | ✅ Prerender top 50 + runtime SSR | `/v3/vendors/:slug`, `/v3/vendors/:slug/products` | M2 | New page on web; mobile shows vendor in modal |
| 7 | `/designer/:slug/reviews` | `customer/store-reviews/store-reviews.page` | 🟡 Runtime SSR | `/v3/vendors/:slug/reviews` | M3 | |
| 8 | `/search` | `customer/search/search.page` | 🟡 Runtime SSR | `/v3/search?q=...` | M2 | |
| 9 | `/best-sellers` | `customer/best-sellers/best-sellers.page` | ✅ Prerender (refresh daily) | `/v3/best-sellers` | M2 | |
| 10 | `/new-arrivals` | `customer/new-arrivals/new-arrivals.page` | ✅ Prerender (refresh daily) | `/v3/new-arrivals` | M2 | |
| 11 | `/explore` | `customer/vertican/vertican.page` | ✅ Prerender | `/v3/explore` | M2 | "Vertican" → renamed `/explore` |
| 12 | `/explore/:vertical` | `customer/vertican` (vertical detail) | ✅ Prerender | `/v3/explore/:vertical` | M2 | |

### 8.2 Authenticated account routes — M1

| # | Web route | Mobile source | SSR | Endpoints | Notes |
|---|---|---|---|---|---|
| 13 | `/account` | `customer/account/account.page` | ❌ CSR | `/v3/account/profile` | Account hub (links to all sub-pages) |
| 14 | `/account/profile` | `customer/profile/profile.page` | ❌ CSR | `/v3/account/profile` | View/edit profile |
| 15 | `/account/addresses` | `customer/addresses/addresses.page` | ❌ CSR | `/v3/account/addresses` | Shipping address management |
| 16 | `/account/measurements` | `customer/measurements/measurements.page` | ❌ CSR | `/v3/account/measurements`, `/v3/measurements/template` | Body measurements; template depends on category |
| 17 | `/account/settings` | `customer/settings/settings.page` | ❌ CSR | `/v3/account/profile`, `/v3/account/location` | Settings hub |

### 8.3 Authenticated transactional routes — M3

| # | Web route | Mobile source | SSR | Endpoints | Phase | Notes |
|---|---|---|---|---|---|---|
| 18 | `/account/wishlist` | `customer/wishlist/wishlist.page` | ❌ CSR | `/v3/wishlist`, `/v3/wishlist/labels` | M3 | |
| 19 | `/account/reviews` | `customer/reviews/reviews.page` | ❌ CSR | `/v3/account/reviews` | M3 | User's own reviews |
| 20 | `/cart` | `customer/cart/cart.page` | ❌ CSR | `/v3/cart`, `/v3/cart/items` | M3 | |
| 21 | `/checkout` | `customer/checkout/checkout.page` | ❌ CSR | `/v3/checkout/initiate`, `/v3/account/addresses`, `/v3/shipping/cities`, `/v3/shipping/areas/:id` | M3 | |
| 22 | `/checkout/success` | `customer/success/success.page` | ❌ CSR | `/v3/orders/:id` | M3 | |
| 23 | `/checkout/failed` | `customer/failed/failed.page` | ❌ CSR | — | M3 | |
| 24 | `/checkout/processing` | `customer/process/process.page` | ❌ CSR | `/v3/checkout/finalize` (polling) | M3 | Webhook landing target |
| 25 | `/account/orders` | `customer/my-orders/my-orders.page` | ❌ CSR | `/v3/orders` | M3 | |
| 26 | `/account/orders/:id` | `customer/orders/orders.page` | ❌ CSR | `/v3/orders/:id` | M3 | |

### 8.4 Authenticated communication routes — M3 / M4

| # | Web route | Mobile source | SSR | Endpoints | Phase | Notes |
|---|---|---|---|---|---|---|
| 27 | `/account/messages` | `customer/messages/messages.page` | ❌ CSR | `/v3/messages/conversations` | M3 | |
| 28 | `/account/messages/:id` | (mobile threads conversation in same page) | ❌ CSR | `/v3/messages/:id` | M3 | |
| 29 | `/account/support` | `customer/ticket-list/ticket-list.page` | ❌ CSR | `/v3/support/tickets` | M3 | |
| 30 | `/account/support/new` | `customer/create-ticket/create-ticket.page` | ❌ CSR | `/v3/support/tickets` (POST) | M3 | |
| 31 | `/account/support/:id` | `customer/ticket-messages/ticket-messages.page` | ❌ CSR | `/v3/support/tickets/:id/messages` | M3 | |
| 32 | `/account/chat` | `pages/chat-vendors/chat-vendors.page` | ❌ CSR | `/v3/chat/vendors`, `/v3/chat/unread` | M4 | Per-order chat hub |
| 33 | `/account/chat/:vendorId` | `pages/chat-orders/chat-orders.page` | ❌ CSR | `/v3/chat/vendors/:id/orders` | M4 | Pick which order to chat about |
| 34 | `/account/chat/:vendorId/:orderId` | `pages/chat/chat.page` | ❌ CSR | `/v3/chat/conversations/...` | M4 | Actual chat |

### 8.5 Auth routes (M1)

| # | Web route | Mobile source | SSR | Endpoints | Notes |
|---|---|---|---|---|---|
| 35 | `/login` | `public/login/login.page` | 🟡 SSR shell | `/v3/auth/login` | |
| 36 | `/register` | `public/register/register.page` | 🟡 SSR shell | `/v3/auth/register`, `/v3/auth/validate-email`, `/v3/auth/validate-phone`, `/v3/auth/send-otp`, `/v3/auth/confirm` | |
| 37 | `/reset` | `public/reset/reset.page` | 🟡 SSR shell | `/v3/auth/reset`, `/v3/auth/send-otp` | |

### 8.6 Skipped or merged

| Mobile page | Why skipped |
|---|---|
| `customer/styles/*` | Custom-style submission. Phase 2 entry will decide if web supports it; may defer to later phase. |
| `public/intro/intro.page` | Mobile-only first-launch tutorial. Web doesn't need it. |
| `public/single/single.page` | Generic "single content" page (legal copy?). Investigate Phase 1; may become `/legal/:slug`. |
| `vendor/store-dashboard/*` | **Now in scope (v2.0).** Mobile lite vendor surface — quick price changes, order status updates, message responses. See Decision 8. Migrates to monorepo as `apps/mobile/src/app/vendor/*` in M4. |
| `pages/vendor-chat-list/*` | **Now in scope (v2.0).** Vendor receives chat threads from customers. Migrates with `apps/mobile` in M4. |

---

## 9. Migration strategy

This section is the **operational playbook** for getting from "four separate codebases on a broken backend" to "consolidated monorepo on a rebuilt backend" without breaking production.

### 9.1 Strategy: strangler-fig with parallel operation

```
                ┌─────────────────────────────────────────────────┐
                │                                                 │
                │   Old PHP backend (api.3bayti.ae)              │
                │   - 213 endpoints across customer/, vendors/,  │
                │     admin/, users/, chat/, utility/            │
                │   - MySQL 5.x                                  │
                │   - Stays LIVE throughout M0–M4                │
                │                                                 │
                └────────────────┬────────────────────────────────┘
                                 │
                  ┌──────────────┴──────────────┐
                  │                             │
            packages/api-client                 │
            (feature-flag-driven                │
             routing per endpoint)              │
                  │                             │
        ┌─────────┴────────┐         ┌──────────┴─────────┐
        │  apps/web        │         │  apps/mobile       │
        │  apps/portal     │         │  (existing repos   │
        │  (in monorepo)   │         │   until M4 cuts    │
        │                  │         │   over)            │
        └──────────────────┘         └────────────────────┘

                                 │
                                 ▼

                ┌─────────────────────────────────────────────────┐
                │                                                 │
                │   New API (api-v3.3bayti.ae)                   │
                │   - Slim 4 + Doctrine 3                         │
                │   - PostgreSQL 16                               │
                │   - Built endpoint by endpoint, M1–M4           │
                │   - Takes over api.3bayti.ae in M5              │
                │                                                 │
                └─────────────────────────────────────────────────┘
```

The fundamental rule: **every endpoint flips from old → new individually**, never as a batch. This is what makes the migration safe.

### 9.2 The feature-flag mechanism

In `packages/api-client`, each endpoint has a config entry:

```typescript
// packages/api-client/src/feature-flags.ts
export const ENDPOINT_ROUTING = {
  'GET /products': 'old',          // /v2/products on api.3bayti.ae
  'GET /products/:slug': 'old',
  'POST /auth/login': 'new',       // /v3/auth/login on api-v3.3bayti.ae
  'POST /auth/register': 'new',
  'GET /cart': 'old',              // not yet migrated
  // ...
} as const;
```

The client wrapper resolves URLs at request time:

```typescript
async function request(method: string, path: string, body?: unknown) {
  const route = `${method} ${path}`;
  const target = ENDPOINT_ROUTING[route] === 'new'
    ? `https://api-v3.3bayti.ae${normalizePath(path, 'new')}`
    : `https://api.3bayti.ae${normalizePath(path, 'old')}`;
  return fetch(target, { method, body, headers: getAuthHeaders() });
}
```

Flipping a single endpoint from `'old'` → `'new'` is a one-line PR. Rolling back is a one-line revert. Both are deployable in minutes, not hours.

### 9.3 Per-endpoint migration sequence

For each endpoint being migrated, the steps are:

1. **Build the new endpoint in `apps/api`** with the contract that matches the old one's response shape (envelope + payload), so consumers don't change. Annotate with swagger-php for OpenAPI. Add unit + integration tests against fixtures.

2. **Deploy to `api-v3.3bayti.ae`** — but with `ENDPOINT_ROUTING` still set to `'old'`. The new endpoint exists but no one calls it.

3. **Contract test against the old endpoint** — write a script that hits both the old and new endpoint with the same input and diffs the response. Run against the production fixtures. Iterate until they match.

4. **Flip ENDPOINT_ROUTING to `'new'` in a canary environment** (e.g. a feature branch deploy of `apps/web` to `staging.3bayti.ae`). Monitor for errors over a few hours.

5. **Flip in production** — merge the routing change. Frontend redeploys, all consumers now hit the new endpoint.

6. **Monitor for 7 days.** Sentry, response-time dashboards, error-rate alerts. Any anomaly: revert (one-line PR).

7. **Mark old endpoint deprecated** — log a warning when called, return a header `X-Deprecated: 2026-XX-XX`. Old endpoint still serves the request.

8. **Wait 30 days.** Verify no traffic going to old endpoint (mobile app + portal still on old until M4). If clean, delete the old PHP file.

### 9.4 Migration order — what moves first

The order matters because some endpoints depend on others:

```
WAVE 1 (M1) — Auth foundation. Nothing else can be migrated until this is in place.
  /v3/auth/login
  /v3/auth/register
  /v3/auth/validate-email
  /v3/auth/validate-phone
  /v3/auth/send-otp
  /v3/auth/confirm
  /v3/auth/reset
  /v3/auth/refresh   (new: refresh token endpoint)
  /v3/auth/logout
  /v3/auth/me        (new: who-am-I endpoint)

  Account hub:
  /v3/account/profile (GET, PUT)
  /v3/account/addresses (GET, POST, PUT, DELETE)
  /v3/account/measurements (GET, PUT)
  /v3/account/location (PUT)
  /v3/measurements/template (GET — vendor's measurement schema per category)

WAVE 2 (M2) — Catalog rebuild. Web's /v2/ catalog endpoints get /v3/ counterparts.
  /v3/products (paginated list with filters)
  /v3/products/:slug
  /v3/categories
  /v3/categories/:slug
  /v3/vendors
  /v3/vendors/:slug
  /v3/vendors/:slug/products
  /v3/vendors/:slug/reviews
  /v3/best-sellers
  /v3/new-arrivals
  /v3/explore
  /v3/search?q=
  /v3/sitemap-data
  /v3/products/:slug/reviews (read)

WAVE 3 (M3) — Cart, checkout, orders, payments. The transactional core.
  /v3/cart (GET)
  /v3/cart/items (POST, DELETE)
  /v3/cart/items/:id (PATCH)
  /v3/cart/merge (POST — guest cart → user cart on login)
  /v3/checkout/initiate
  /v3/checkout/finalize (webhook)
  /v3/checkout/otp/send
  /v3/checkout/otp/validate
  /v3/shipping/cities (proxy to Topex)
  /v3/shipping/areas/:cityId (proxy to Topex)
  /v3/orders (list)
  /v3/orders/:id (detail)
  /v3/products/:slug/reviews (write)
  /v3/account/reviews
  /v3/wishlist
  /v3/wishlist/labels
  /v3/messages/conversations
  /v3/messages/:id
  /v3/support/tickets
  /v3/support/tickets/:id/messages
  /v3/follow-vendor

WAVE 4 (M4) — Vendor + admin endpoints. apps/mobile and apps/portal migrate together.
  Vendor:
  /v3/vendor/products (CRUD)
  /v3/vendor/orders
  /v3/vendor/orders/:id/status
  /v3/vendor/measurements/templates
  /v3/vendor/coupons
  /v3/vendor/labels
  /v3/vendor/dashboard (sales summary)
  /v3/vendor/messages

  Admin:
  /v3/admin/stores (CRUD, approval)
  /v3/admin/users
  /v3/admin/orders
  /v3/admin/commissions
  /v3/admin/transactions
  /v3/admin/returns
  /v3/admin/logistics
  /v3/admin/tickets

  Chat module:
  /v3/chat/conversations
  /v3/chat/messages
  /v3/chat/attachments
  /v3/chat/prompts
  WebSocket endpoint /v3/chat/ws (Ratchet or Node.js sidecar)

WAVE 5 (M5) — Image migration completes, observability hardens.
  Run base64 → Flysystem migration job
  Decommission old PHP files
  Cut DNS api.3bayti.ae → new infrastructure
```

### 9.5 Data migration — MySQL → PostgreSQL

The new API uses PostgreSQL; the old uses MySQL. We can't run two separate databases for the same data — that creates impossible consistency problems.

**Approach:** **synchronous shadow writes** during M1–M4, **flip read-side per consumer** when ready.

1. **M0 prep:** stand up PostgreSQL 16 with the rebuilt schema (Doctrine migrations). Empty.
2. **M1 onwards:** migrate users + auth data first (small table, well-understood). pgloader does the bulk load. Set up a CDC (change data capture) replication using `mysql-postgres-sync` or hand-written triggers for the duration of M1–M4. Every write to MySQL also fires off a sync to PostgreSQL.
3. **Per-endpoint flip:** when an endpoint flips from old → new, both data stores have the data; the new endpoint just reads from PostgreSQL. Old endpoints continue reading from MySQL until they're retired.
4. **M5:** stop the sync. PostgreSQL becomes the only source of truth. Old MySQL kept read-only as a safety backup for 90 days, then archived.

**Risk:** the sync layer can drift. Mitigation: weekly diff-check job during M1–M4 that compares row counts + checksums per table. Alarms if drift > 0.1%.

### 9.6 Image migration (base64 → Flysystem)

Many product images in the existing DB are stored as base64-encoded `LONGTEXT` columns rather than file URLs. This:
- Bloats database size (a single product with 10 images = ~5-20 MB of LONGTEXT)
- Slows queries (any `SELECT *` pulls megabytes)
- Prevents proper CDN caching
- Makes image transformation (resize, webp, etc.) impossible without decoding first

**Migration plan (executes in M5 but can be staged earlier as a background job):**

1. Spin up a worker that iterates the `products` table.
2. For each row with base64 image data: decode → upload to R2 via Flysystem → update column to URL → keep base64 in a backup column for 30 days then drop.
3. Run during off-peak hours, rate-limited so DB load stays low.
4. ~few hours of total run time at ~1k products/min depending on image size.

Already-saved file-path images (per the existing `save_base64_image_to_directory` helper) are migrated similarly — `products_images/foo.jpg` → uploaded to R2 → URL updated to `https://cdn.3bayti.ae/products/foo.jpg`.

### 9.7 Rollback plan per phase

Every M-phase has explicit rollback:

| Phase | Rollback action |
|---|---|
| M0 (monorepo foundation) | `apps/web` is a git-subtree-merge of `surdbells/3bayti-web`. If anything breaks, the old standalone repo is still there and deployable. CI/CD on the old repo stays armed for the duration of M0. |
| M1 (auth) | Each `/v3/auth/*` endpoint flip is a one-line `ENDPOINT_ROUTING` change. Revert = redeploy frontend. ~5 minutes. Existing PHP auth still serves the mobile app. |
| M2 (catalog) | Same as M1 — per-endpoint flips, instant revert. The /v2/ catalog endpoints stay live. |
| M3 (cart/checkout/orders/payments) | The hardest rollback. Once an order is created on the new system, it lives in PostgreSQL. Mitigation: dual-write orders to both MySQL and PostgreSQL during M3 so the old system has the data if we revert. Noon Payment integration: we use the same merchant account, so no payment-side migration needed. |
| M4 (mobile + portal) | Mobile-app cutover is the riskiest. App-store releases can't be rolled back in <24 hours. Mitigation: feature-flag flip in the mobile app itself (`ENDPOINT_ROUTING` config served from `/app_update.json` — already exists in the existing app for kill-switching). If a backend issue surfaces post-release, flip the config server-side; client respects without a release. |
| M5 (retirement) | The point of no return. Before M5, all four consumers are stably on the new API and have been for 30+ days. Decommission proceeds incrementally: first take old PHP read-only (no write traffic), wait 7 days, then archive the directory. Old MySQL DB kept as cold backup for 90 days. |

### 9.8 What this strategy buys us

- **Old backend stays live.** No coordinated downtime. Mobile users keep ordering, vendors keep managing inventory, admins keep approving stores throughout M0–M4.
- **One consumer flipping doesn't affect others.** Web migrating to `/v3/products` doesn't touch what mobile sees.
- **Rollback is per-endpoint, not per-phase.** A bad new endpoint rollback is a one-line revert.
- **Data integrity is verifiable.** Diff-check runs continuously during sync.
- **Production support continues uninterrupted.** Bug fixes go to the old PHP backend until that surface migrates.

### 9.9 What this strategy costs us

- **Months of duplicate-write infrastructure.** The MySQL↔PostgreSQL sync is non-trivial.
- **Bug fixes in two places during M0–M4.** Anything found in production must be fixed on old PHP AND in `apps/api`.
- **Cognitive overhead.** Engineers (you + me) need to remember which endpoints are on which backend at any moment. Mitigation: `packages/api-client` config is the single source of truth for that.
- **Schedule risk.** If M3 stretches longer than planned, migration debt grows. We mitigate by being conservative on M3 scope.

---

## 10. Phased roadmap

Each phase has entry criteria, exit criteria, deliverables, and rollback plan. **No phase begins until the prior phase exits cleanly.**

The phases are now organised in two tracks:

- **v1.x phases (Phase 0, 1, 2)** — completed history. The web project's pre-monorepo work that's already in production. Detail is preserved for reference.
- **M-phases (M0, M1, M2, M3, M4, M5)** — the forward-looking monorepo build. Each one delivers shippable value and incrementally reduces the legacy backend's footprint.

---

### Phase 0 — Cleanup & catalog completion (✅ COMPLETED, kept for history)

**Duration:** 0.5 weeks
**Status:** ✅ Shipped

**What shipped:** Merge of 4 queued PRs (W2.2b PDP, fixes for component init order, SSR projection, Phase 4 followup chore). W2.2c implemented proper HTTP 404 responses for invalid product/category slugs.

**Outcome:** Fully-shipped catalog browse with no known production bugs at `staging.3bayti.ae`.

---

### Phase 1 — Home Page (✅ COMPLETED, kept for history)

**Duration:** 2.5–3 weeks (actual: ~3 weeks)
**Status:** ✅ Shipped to `staging.3bayti.ae`

**What shipped:**
- Hero band with editorial typography
- 8-tile categories grid
- Featured products + Best Sellers + New Arrivals strips, all using a unified `ProductCardComponent`
- Designer spotlight (4 vendor cards)
- Site footer with newsletter signup, social links, legal stubs, contact
- Soft-and-subtle shadow design system locked in (Phase 1 W3 R3, post-pivot from "Pronounced" v1.1 spec)
- 3 new backend `/v2/` endpoints to power the strips

**Detail:** §10 (Phase 1 deep dive) contains the full history of design decisions, weeks of iteration, and rollouts.

---

### Phase 2 — Cart (local-first) (✅ COMPLETED, kept for history)

**Duration:** 1.5–2 weeks (actual: 1 focused session, smaller than estimated)
**Status:** ✅ Shipped to `staging.3bayti.ae`

**What shipped:**
- `CartService` (signal-driven, localStorage-backed, SSR-safe)
- `CartItem` data model with `key` (variant-ready), price snapshot, vendor info
- Add-to-cart button on PDP (in-stock + sold-out treatment)
- Toast notification system (`ToastService` + `ToastHostComponent`)
- Header cart icon with reactive count badge
- `/cart` page (RenderMode.Client, two-column layout, qty stepper, remove, summary)
- localStorage persistence keyed at `3bayti.cart.v1`
- Multi-vendor cart support (vendor name + thumbnail per line item)

**Outcome:** Cart works fully offline-friendly without any auth. Merge-on-login deferred to M3.

---

### M0 — Monorepo foundation

**Duration:** 2–3 weeks
**Status:** Next (after sign-off on this v2.0 roadmap)

**Entry criteria:**
- This v2.0 roadmap reviewed and approved
- Cloudflare account access + DigitalOcean account access confirmed
- New GitHub repo `surdbells/3bayti` created (empty)
- Decision on hosting (per §4) confirmed

**Deliverables:**
1. **Monorepo skeleton** — pnpm workspaces, Turborepo, shared TS configs, ESLint config, `.gitignore`, `README.md`
2. **`apps/api` skeleton** — Slim 4 + Doctrine 3 entry point, DI container, empty PostgreSQL connection, health-check endpoint at `GET /v3/health`. Composer wiring. Empty migrations directory.
3. **`apps/web` migration** — git-subtree merge of `surdbells/3bayti-web` into `apps/web/`, preserving full git history. Build still works. Cloudflare Workers deploy still works.
4. **`packages/design-tokens`** — extracted from `apps/web/src/styles.scss`. Tokens for colors, typography, spacing, shadows, exported as both CSS variables and TS constants.
5. **`packages/api-client`** — initial scaffolding: HttpClient wrapper, auth interceptor (no real auth yet), error normaliser, feature-flag config (initially every endpoint routes to `'old'`).
6. **`packages/api-contracts`** — empty package with `openapi.yaml` placeholder + codegen pipeline wired (so swagger-php → openapi-typescript → generated.ts works). Empty types since no `/v3/*` exists yet.
7. **CI/CD** — GitHub Actions workflows for `apps/web` (preserved from existing) and `apps/api` (new — PHP build + lint + PHPUnit). Turborepo cache wired.
8. **Local dev** — `pnpm dev` starts everything. Docker Compose for local PostgreSQL + Redis. Documented in `README.md`.

**Exit criteria:**
- `pnpm install` works cleanly
- `pnpm dev` starts `apps/web` (loads `staging.3bayti.ae`-equivalent) + `apps/api` (responds to `GET /v3/health`)
- `apps/web` builds and deploys to Cloudflare Workers (still serving `staging.3bayti.ae`)
- `pnpm codegen` runs successfully (empty output, but no errors)
- `apps/api` PHPUnit passes (zero tests, but green)
- New repo is on GitHub, all branches/CI green

**Rollback:** Old `surdbells/3bayti-web` repo remains live and deployable for the duration of M0. If anything goes catastrophically wrong, that repo continues to be the production source.

**What ships to users:** Nothing — this is foundation. `staging.3bayti.ae` continues to serve the same content from a different repo path.

---

### M1 — Auth & users in new API

**Duration:** 3–4 weeks

**Entry criteria:**
- M0 complete
- Decision on token policy (HS256 access + refresh tokens, 15min/7day) confirmed
- PostgreSQL provisioned in production (DigitalOcean Managed PostgreSQL, separate from staging)
- ZeptoMail templates for auth emails reviewed (welcome, password reset, login alert)
- MessageCentral CPaaS production keys + UAE country code confirmed for OTP

**Deliverables:**

In `apps/api`:
1. **Doctrine entities for `User`** — fields covering existing users table including all role flags (`is_customer`, `is_vendor`, `is_admin`, `is_finance`, `is_support`, `_sub_admin`, `is_store_active`, `is_store_approved`, `is_2fa`, `is_active`)
2. **`/v3/auth/login`** — email + password (re-uses existing password_hash from MySQL since hashes are PHP-style and PostgreSQL Doctrine can verify them with the same algorithm)
3. **`/v3/auth/register`** — email + phone + password, kicks off OTP flow
4. **`/v3/auth/send-otp`** — sends to MessageCentral, returns verificationId
5. **`/v3/auth/confirm`** — validates OTP, finalizes user creation
6. **`/v3/auth/validate-email`** — pre-register availability check
7. **`/v3/auth/validate-phone`** — pre-register availability check
8. **`/v3/auth/reset`** — password reset via OTP
9. **`/v3/auth/refresh`** — refresh-token rotation (NEW — doesn't exist in old backend)
10. **`/v3/auth/logout`** — revoke refresh token (NEW)
11. **`/v3/auth/me`** — return current user from JWT (NEW)
12. **JWT middleware** — proper HS256 verification, role extraction, 401 on invalid
13. **MySQL → PostgreSQL user data migration** — pgloader-based bulk import of existing users + role flags + addresses + measurements
14. **CDC sync setup** — every write to MySQL `users` also syncs to PostgreSQL during M1–M4
15. **`/v3/account/profile`** GET + PUT
16. **`/v3/account/addresses`** GET + POST + PUT + DELETE
17. **`/v3/account/measurements`** GET + PUT
18. **`/v3/account/location`** PUT
19. **`/v3/measurements/template`** GET (per-category measurement schema)

In `packages/api-client`:
- Auth interceptor refresh-on-401 logic
- ENDPOINT_ROUTING entries for the new auth + account endpoints, set to `'new'`

In `apps/web`:
- New `AuthService` (replaces stubbed Phase 3 plan), `authGuard`, `guestGuard`
- `/login`, `/register`, `/reset` pages (mobile reference: `public/login`, `public/register`, `public/reset`)
- `/account` hub with sub-routes for profile, addresses, measurements, settings
- Header avatar dropdown (signed-in state) + Sign in link (signed-out state)
- Inline login-or-register modal (used at checkout entry — wired but not exercised until M3)

**Exit criteria:**
- Web user can log in at `staging.3bayti.ae`
- Web user can register a new account end-to-end (email → phone OTP → confirm → logged in)
- Web user can edit profile/addresses/measurements at `/account/*`
- Existing mobile app users can also log into the web (same database, JWT issued from PostgreSQL works)
- All `/v3/auth/*` endpoints have contract tests passing
- 7-day soak test passes (auth endpoints stable)
- CDC sync diff-check < 0.1% drift

**Rollback:** Per-endpoint flip in `ENDPOINT_ROUTING` config — set back to `'old'`, redeploy. The old PHP backend's `users/login.php` etc. still work.

**What ships to users:** Web users can now register and log in. Account management pages live. **Cart, checkout, orders not yet — those wait for M2/M3.**

---

### M2 — Catalog in new API

**Duration:** 2–3 weeks

**Entry criteria:**
- M1 complete and stable for 7 days
- PostgreSQL has production user data, replicated from MySQL via CDC

**Deliverables:**

In `apps/api`:
1. **Doctrine entities** for `Product`, `Category`, `Vendor`, `ProductImage`, `ProductReview`, `Collection`
2. **`/v3/products`** (paginated, filterable: `?category=`, `?vendor=`, `?in_stock=`, `?min_price=`, `?max_price=`, `?sort=`)
3. **`/v3/products/:slug`** (full detail with images, reviews, vendor info)
4. **`/v3/categories`**
5. **`/v3/categories/:slug`**
6. **`/v3/vendors`** (paginated)
7. **`/v3/vendors/:slug`** (full detail with embedded product thumbnails)
8. **`/v3/vendors/:slug/products`**
9. **`/v3/vendors/:slug/reviews`**
10. **`/v3/best-sellers`** (paginated)
11. **`/v3/new-arrivals`** (paginated)
12. **`/v3/explore`** + **`/v3/explore/:vertical`**
13. **`/v3/search?q=`** (Postgres full-text search)
14. **`/v3/sitemap-data`** (mirror of existing /v2/sitemap-data shape so postbuild script doesn't need changes)
15. **MySQL → PostgreSQL catalog data migration** — pgloader runs for `products`, `category`, `users` (vendor rows already there from M1), `ec_reviews`, `collections`. Schema normalised (snake_case, no `ec_` prefix mix).
16. **Image migration job (background, low-rate)** — kicks off and runs incrementally during M2. Most images migrated by end of M2 but not blocking; final cleanup in M5.

In `packages/api-client`:
- ENDPOINT_ROUTING entries for catalog flipped from `'old'` → `'new'`

In `apps/web`:
- Web's catalog browse moves from `/v2/*` to `/v3/*` endpoints. UI unchanged; only the URL the client hits changes.
- `/designer` and `/designer/:slug` pages built (new — didn't exist in v1.x because `/v2/vendors` didn't fully serve them)
- `/best-sellers`, `/new-arrivals`, `/explore` routes built
- `/search` route built (with `/v3/search?q=`)

**Exit criteria:**
- Catalog browse on web fully on `/v3/*`
- All routes (home, category, PDP, designer, search, best-sellers, new-arrivals, explore) work
- SEO regression test: prerender output identical (or improved) vs current
- Image migration ≥80% complete

**Rollback:** ENDPOINT_ROUTING per-endpoint flip back to `'old'`. /v2/ catalog endpoints still serve mobile.

**What ships to users:** Web visitors see /designer and /search pages for the first time. New listing pages (best-sellers, new-arrivals, explore) live. Performance should be slightly better (PostgreSQL queries with proper indexes).

---

### M3 — Cart, checkout, orders, payments, lifecycle

**Duration:** 4–6 weeks (the longest M-phase — most surface area; lifecycle work in §5 is the largest single subsystem)

**Entry criteria:**
- M2 complete and stable for 7 days
- Noon Payments merchant account confirmed, API keys (test + production) available
- Topex shipping API access confirmed
- Decision on web checkout flow: full-page vs modal-based (deferring to phase entry)
- §5 (Order processing lifecycle) reviewed and locked

**Deliverables:**

In `apps/api`:

1. **Doctrine entities for the order lifecycle** (per §5.11): `Order`, `SubOrder`, `OrderItem`, `Payment`, `PaymentAttempt`, `Shipment`, `ReturnRequest`, `ReturnRequestItem`, `OrderStateHistory`, `PlatformSetting`. Plus supporting: `Cart`, `CartItem`, `Address`, `Wishlist`, `Review`, `Ticket`, `TicketMessage`, `Message`.
2. **State machine implementation** — explicit `OrderStateMachine`, `SubOrderStateMachine`, `ReturnRequestStateMachine` classes enforcing transitions per §5.3 and §5.5. No raw status string updates anywhere — always go through state-machine validation.
3. **`/v3/cart`** (GET) + **`/v3/cart/items`** (POST/DELETE) + **`/v3/cart/items/:id`** (PATCH) + **`/v3/cart/merge`** (POST — completes Phase 2's deferred merge-on-login)
4. **`/v3/checkout/initiate`** — creates `Order` + `SubOrders` + `PaymentAttempt`; calls Noon Payments `POST /payment/v1/order/initiate`; returns hosted URL
5. **`/v3/checkout/finalize`** — Noon webhook receiver; flips `payment_status`, fires `awaiting_vendor` for SubOrders, sends confirmation email
6. **`/v3/shipping/cities`** + **`/v3/shipping/areas/:cityId`** (Topex proxy)
7. **Customer order endpoints**: `/v3/orders` (list with rollup status), `/v3/orders/:id` (detail with SubOrders + items + state history), `/v3/orders/:id/cancel`, `/v3/sub-orders/:id/cancel`
8. **Customer return endpoints**: `/v3/orders/:id/returns` (POST), `/v3/returns/:id` (GET), `/v3/returns/:id/ship` (POST)
9. **Vendor sub-order endpoints**: `/v3/vendor/sub-orders` (queue), `/accept`, `/decline`, `/ready`, `/ship` per §7.9
10. **Vendor return endpoints**: `/v3/vendor/returns/:id/{approve,decline,received,accept,dispute}`
11. **Webhook receivers**: `/v3/webhooks/noon` (payment events), `/v3/webhooks/topex` (shipping events — moves SubOrders to `out_for_delivery` and `delivered`)
12. **Background jobs (Symfony Messenger)**:
    - **Unpaid timeout** — runs hourly, cancels orders past `unpaid_timeout_hours`
    - **Vendor inaction escalation** — runs hourly, escalates SubOrders past `vendor_inaction_escalation_hours`
    - **Return abandonment** — runs daily, cancels return_requests past `return_abandonment_days`
    - **3DS timeout** — runs every 15 min, flips `pending_3ds` past `pending_3ds_timeout_hours` to `payment_failed`
13. **Admin settings endpoints**: `/v3/admin/settings` (GET, PUT) + initial seed of all settings per §5.8
14. **Notification handlers** — Symfony Messenger handlers per state transition per §5.9, fanning out to ZeptoMail (email) + MessageCentral (SMS) + push notification provider
15. **`/v3/wishlist`** + `/v3/wishlist/labels`
16. **`/v3/products/:slug/reviews`** (POST/PUT/DELETE) + `/v3/account/reviews`
17. **`/v3/follow-vendor`**
18. **`/v3/messages/conversations`** + `/v3/messages/:id`
19. **`/v3/support/tickets`** + `/v3/support/tickets/:id/messages`
20. **Email templates in ZeptoMail**: order confirmation, payment failed, vendor accepted, vendor rejected, shipped, out for delivery, delivered, return received, refund issued, etc. (full list per §5.9)
21. **PDF generation (Dompdf)** for order receipts — link in confirmation emails

In `apps/web`:

- Phase 2 cart upgraded with `cart.merge()` call after login (wired to `/v3/cart/merge`)
- `/checkout` route built — multi-step (review cart → address → payment) or single page (decision in entry)
- `/checkout/success`, `/checkout/failed`, `/checkout/processing` routes
- `/account/orders` (list with rollup status badge per order) + `/account/orders/:id` (detail with per-vendor SubOrder breakdown, item-level status, "On its way" / "Delivered" timeline)
- `/account/orders/:id/cancel` — customer cancellation flow (only allowed when SubOrders are pre-`processing`)
- `/account/orders/:id/returns/new` — return request flow (item selection, reason picker, notes)
- `/account/returns` + `/account/returns/:id` — list and detail of return requests with status
- `/account/wishlist` + wishlist add/remove from PDP and product cards
- `/account/reviews` + write/edit review on PDP
- `/account/messages`, `/account/support`, `/account/support/new`, `/account/support/:id`
- Header notifications dropdown (order status updates, return updates, message replies)

In `apps/portal` (vendor surface — first taste of the new platform; full migration in M4):

- *Note: full portal migration is M4. M3 builds the **vendor sub-order management** endpoints in `apps/api`. Portal UI work happens in M4. In M3, vendors keep using the old `abayti_vendor` repo.*

In `packages/api-client`:
- ENDPOINT_ROUTING entries for everything above
- State-machine helpers — typed transition functions so frontend code can ask "can this customer cancel this sub-order?" without re-implementing rules

**Exit criteria:**
- A web visitor can: browse → add to cart → log in → cart merges → checkout → pay via Noon → receive email confirmation → see order in `/account/orders` with proper rollup status
- A customer can cancel a SubOrder before vendor processes it
- A customer can open a return request, ship the items, and receive a refund
- Vendor APIs work end-to-end (tested via Postman/Insomnia even though portal UI is M4)
- Test purchase completes end-to-end on staging with all webhook flows
- All 4 background jobs (unpaid timeout, vendor inaction, return abandonment, 3DS timeout) verified working in staging
- 7-day soak test passes
- Wishlist + reviews + messages + support tickets all work

**Rollback:** Per-endpoint flips. Cart/checkout endpoints can revert; old PHP `customer/payment/initiate_payment` still serves mobile. The lifecycle state machine is contained in the new system; reverting an endpoint reverts both the API behavior and the state model for that consumer.

**What ships to users:** Web becomes a complete e-commerce experience. Order tracking with vendor-by-vendor visibility. Return requests work. The platform infrastructure for the full lifecycle exists, even though the vendor portal hasn't migrated yet (vendors still see old portal until M4).

---

### M4 — Mobile + portal migration

**Duration:** 6–8 weeks

**Entry criteria:**
- M3 complete and stable for 14 days (longer soak because M3 introduces actual money)
- App Store + Play Store credentials available for new release submissions
- Roadmap for vendor + admin features confirmed (no last-minute scope additions)

**Deliverables:**

**Mobile (`apps/mobile`):**
1. **Migrate `surdbells/abayti_app` into `apps/mobile/`** via git-subtree merge, preserving history
2. **Replace `GlobalComponent` static-URL pattern** with `packages/api-client`. Every API call now goes through the typed client, with feature-flag routing
3. **Update environment config** — runtime feature flags via `https://api.3bayti.ae/app_update.json` (existing pattern, kept) — but now points endpoint routes at `'new'` for migrated endpoints
4. **Auth flow updated** — uses `/v3/auth/*` (mobile users keep their existing accounts; JWT migration is server-side)
5. **All `/customer/*` calls migrated** to `/v3/*` endpoints (catalog, cart, checkout, orders, account, wishlist, reviews, messages, support)
6. **Vendor surface (`apps/mobile/src/app/vendor/*`)** — store-dashboard upgraded with the new vendor endpoints (`/v3/vendor/*`)
7. **Capacitor 8 build pipeline** in monorepo CI — builds iOS + Android, uploads to App Store Connect / Play Console
8. **App-store release** — new mobile build with feature flags pointing at new API. Slow rollout (20% / 50% / 100% over 2 weeks)

**Portal (`apps/portal`):**
1. **Migrate `surdbells/abayti_vendor` into `apps/portal/`** via git-subtree merge
2. **Angular 19 → Angular 21 upgrade** — incremental, follow Angular's official upgrade guide. Standalone-component migration where still using NgModules. Tailwind 4 upgrade.
3. **Replace ad-hoc HTTP calls** with `packages/api-client`
4. **All vendor portal calls migrated** to `/v3/vendor/*` endpoints
5. **All admin portal calls migrated** to `/v3/admin/*` endpoints
6. **Cloudflare Pages deploy** for portal at `vendor.3bayti.ae`

**Backend `apps/api`:**
1. **Vendor endpoints**: `/v3/vendor/products`, `/v3/vendor/orders`, `/v3/vendor/orders/:id/status`, `/v3/vendor/measurements/templates`, `/v3/vendor/coupons`, `/v3/vendor/labels`, `/v3/vendor/dashboard`, `/v3/vendor/messages`
2. **Admin endpoints**: `/v3/admin/stores`, `/v3/admin/users`, `/v3/admin/orders`, `/v3/admin/commissions`, `/v3/admin/transactions`, `/v3/admin/returns`, `/v3/admin/logistics`, `/v3/admin/tickets`
3. **Chat module**: `/v3/chat/conversations`, `/v3/chat/messages`, `/v3/chat/attachments`, `/v3/chat/prompts`, plus WebSocket `/v3/chat/ws`
4. **WebSocket decision**: choose between Ratchet (PHP) or Node.js sidecar. Documented in `docs/architecture/websocket-decision.md` early in M4.

**Exit criteria:**
- Mobile app v2.0+ live in App Store + Play Store, on new API for all migrated endpoints
- Portal live at `vendor.3bayti.ae`, on new API, Angular 21
- All four consumers (web + mobile + portal + admin via portal) on new API
- 14-day soak test passes for each
- Old PHP backend traffic measurably ≤5% of total (the long tail of old mobile-app users on outdated versions)

**Rollback:** This is the riskiest phase to roll back from. Once a mobile app is in App Store, can't rapidly retract. Mitigation: feature flags in app, rolled by `app_update.json`.

**What ships to users:**
- Mobile app users see no change — same UX, same features, on new infrastructure
- Vendors see updated portal with Angular 21 (faster, cleaner)
- Admins see same — new infrastructure under the hood

---

### M5 — Old backend retirement, hardening, image migration completion

**Duration:** 2–3 weeks

**Entry criteria:**
- M4 complete and stable for 30 days
- Old PHP backend traffic ≤1% of total
- All four consumers on new API

**Deliverables:**

1. **Image migration completion** — finish converting any remaining base64 images to R2-hosted files. Drop `images_base64_backup` columns from all tables. Compress freed DB space.
2. **CDC sync stops** — PostgreSQL becomes single source of truth. MySQL kept read-only for 90 days as cold backup.
3. **Old PHP backend taken read-only** for 7 days (no write traffic; reads still work for any straggler clients)
4. **DNS cut**: `api.3bayti.ae` flipped from old PHP to new Slim 4 API (DNS TTL ≤60s for safety)
5. **Old PHP backend archived** — repo set to read-only, no further deploys
6. **Observability hardening** — Sentry source maps, Cloudflare Analytics dashboards, BetterStack alerts on all critical SLOs (auth uptime, checkout completion rate, p99 latency)
7. **Performance budget enforcement** — CI fails if `apps/web` Lighthouse score drops below thresholds (LCP, INP, CLS)
8. **Security hardening** — rate limiting on auth endpoints, CSP tightening, dependency audit (Snyk or equivalent)
9. **Runbooks documented** — `docs/runbooks/` covers deploys, rollbacks, incident response, common operational scenarios
10. **MySQL archival** — final dump to S3, kept 1 year, then deletion per data retention policy

**Exit criteria:**
- Old PHP backend offline
- Old MySQL DB archived
- All four surfaces fully on new API + PostgreSQL
- Observability dashboards green
- Runbooks reviewed

**Rollback:** Once old backend is offline (after 7-day read-only period passes), rollback requires resurrecting MySQL. We mitigate by keeping MySQL hot for the first 7 days post-cut, allowing a fast revert if a problem surfaces.

**What ships to users:** Nothing user-visible. This is operational completion. The platform is now fully on the new architecture.

---

### Total timeline estimate

| Phase | Estimate (best case) | Estimate (with interruptions) |
|---|---|---|
| M0 | 2 weeks | 3 weeks |
| M1 | 3 weeks | 4 weeks |
| M2 | 2 weeks | 3 weeks |
| M3 | 4 weeks | 6 weeks |
| M4 | 6 weeks | 10 weeks |
| M5 | 2 weeks | 3 weeks |
| **Total** | **19 weeks (~5 months)** | **29 weeks (~7 months)** |

These estimates assume **solo developer + Claude help, full-time focus**. They do not include time spent on production support of the existing four codebases — Decision 11 explicitly carves that out as additional time.

A realistic total assuming bug-fix interruptions and Q&A cycles: **8–10 months** to fully complete M5.

---

## 11. Phase 1 deep dive — Home Page (historical, completed)

> **This section is historical reference.** Phase 1 shipped to `staging.3bayti.ae` in early May 2026. The original entry checklist, architecture details, deliverables breakdown, risks, and exit criteria are preserved below for context — they document a successful completed phase rather than upcoming work. Future M-phase deep-dives will be written to a similar level of detail when each phase is entered.

### 11.1 Phase 1 entry checklist

Before starting Phase 1:

- [x] Phase 0 exit criteria all met (4 PRs merged, W2.2c 404 fix shipped)
- [x] Card design language locked (§4.4 — done; later revised to "soft & subtle" in W3 R3)
- [x] Backend `/v2/products?filter=...&sort=...` query parameter shape agreed
- [x] Mobile app's `customer/featured` response inspected to design `/v2/featured-vendors` envelope
- [x] Decision: home page hero treatment — keep current static hero, or design a new hero band? (Outcome: kept existing hero structure, refreshed typography to match the locked design system; photographic hero deferred to a later editorial pass)

### 11.2 Phase 1 architecture

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

### 11.3 Phase 1 backend deliverables

**4 endpoints to write.** Each follows the W2.0 patch shape: single `.php` file, `v2_init()` at top, consistent envelope, debug mode supported, appropriate Cache-Control.

#### Backend deliverables (Week 1)

1. `/v2/products?filter=featured&limit=12` — returns 12 featured products. Cache: `public, max-age=300, s-maxage=600`.
2. `/v2/products?sort=best-sellers&limit=12` — returns top 12 by aggregate sales. Cache: 1 hour edge.
3. `/v2/products?sort=newest&limit=12` — most recently added 12. Cache: 5 minutes edge.
4. `/v2/featured-vendors?limit=4` — returns 4 vendors with embedded `products[]` (max 4 thumbnails per vendor). Cache: 1 hour edge.

These 3 sort/filter variants on `/v2/products` may share a single PHP file with branch logic, or be separate files — implementation detail. Either way, the response envelope must be identical to the existing `/v2/products` shape so the existing `Product` TypeScript model works.

### 11.4 Phase 1 frontend deliverables

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

### 11.5 Phase 1 risks

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| New `ui-product-card` design breaks one of the existing usages (category page, related products) | Medium | Medium | Visual regression check before merge; test all card-using pages |
| `/v2/featured-vendors` data shape doesn't fit our card pattern (vendor name truncation, rating absence, etc.) | Medium | Low | Component handles defensive cases (no rating, long names, empty product arrays) |
| Heavy hero photography pushes LCP above 2.5s | Medium | Medium | Defer photographic hero to later phase; ship typography-only hero first |
| Horizontal scroll on mobile creates jankiness on low-end devices | Low | Medium | CSS-only scroll snap; no JS-driven smooth scroll; test on 3-year-old mid-tier Android |
| Product strip shows fewer than 12 cards (small dataset) — strip looks empty | High | Low | Hide strip when less than 4 cards returned |

### 11.6 Phase 1 exit criteria

- Home page Lighthouse: ≥92 performance, 100 accessibility, ≥95 best practices, ≥95 SEO.
- All four backend wrappers respond with valid `/v2/` envelope and proper Cache-Control headers.
- Home page renders correctly on 320px (mobile), 768px (tablet), 1280px (desktop), 1920px (wide).
- All product cards across the site (category, related, search, designer pages) use the new §4.4 design.
- Skeleton states display while strips load; failed strips silently omit themselves.
- Site footer is on every page (home, category, PDP, etc.).
- All product card hover states feel correct on mouse, touch, and keyboard navigation.

---

## 12. Cross-cutting concerns

These apply across all phases and should be reviewed at every phase entry. **In v2.0, they apply across all four apps in the monorepo, not just the web project.**

### 12.1 Performance budgets

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

### 12.2 Accessibility

- Every interactive element keyboard-reachable.
- Every form field has a visible label and `aria-describedby` for errors.
- All status changes announced via ARIA live regions.
- Lighthouse a11y score = 100 on every public page.
- axe-core run as part of every E2E test.
- Screen-reader testing: VoiceOver on macOS Safari, NVDA on Windows Firefox — at minimum at every phase exit.
- Colour contrast: WCAG AA minimum, AAA on body text.

### 12.3 Internationalisation (i18n)

Mobile app supports English + Arabic with RTL layout. Web should match.

**Phase plan:**
- Phase 1: Build all UI in English only. Use `i18n` Angular markers from day one (don't bake strings).
- Phase 2: Add Arabic translation files. Test RTL layout.
- Phase 9: Final i18n audit before public launch.

Translation key naming: `feature.component.element` (e.g. `auth.login.submit-button`).

### 12.4 Testing strategy

| Layer | Coverage target | Tools |
|---|---|---|
| Unit (services, pipes, computed signals) | 80%+ on logic-heavy code | Jasmine + Karma |
| Component | Smoke tests at minimum | Angular Testing Library or vanilla |
| E2E | Every critical path (login → buy → support) | Playwright (Phase 5+) |
| Visual regression | Optional — defer to Phase 9 | Percy or Chromatic |
| API contract | Every `/v2/*` endpoint has a contract test | Custom — schema validation per response |

### 12.5 Observability

| Concern | Tool | Phase |
|---|---|---|
| Browser errors | Sentry | 9 |
| Page-level analytics | Cloudflare Web Analytics or Plausible | 9 |
| Server-side errors (Worker) | Cloudflare Logpush + custom dashboard | 9 |
| API error rate | Backend already returns `v2_init` errors; aggregate centrally | 9 |
| Real User Monitoring (RUM) | Cloudflare RUM | 9 |
| Alerts | TBD — paging for 5xx spike, error rate spike | 9 |

### 12.6 Security

| Concern | Mitigation |
|---|---|
| XSS | Angular's built-in template sanitization; never use `[innerHTML]` for user content |
| CSRF | Stateless `Authorization: Bearer` header (no cookies → less CSRF surface) |
| Token theft via XSS | Accepted risk per Decision 4. Mitigated by short-lived (15-min) access tokens + refresh-token rotation |
| Brute force on login | New `apps/api` rate-limits `/v3/auth/login` per IP + per email (Redis-backed counters) |
| Account enumeration | Login error: "Invalid credentials" (don't say "no such email") |
| OTP reuse | Single-use, 5-minute expiry, rate-limited per phone number per hour |
| Session fixation | New token issued on login; refresh token rotation invalidates prior |
| Sensitive data in URL | Never put tokens or PII in URLs |
| HTTPS | Cloudflare enforces (HSTS preload eligible) |
| Cookie security (when added in M3 for payment redirect) | `Secure`, `HttpOnly`, `SameSite=Lax` |
| Content Security Policy | Tighten in M5 (hardening); today permissive on web |
| Dependency security | `pnpm audit` + `composer audit` in CI; Dependabot |
| **Existing backend security findings (Appendix E)** | All resolved by replacing the old auth verification with proper JWT middleware in `apps/api`. The old PHP backend retires in M5; until then it remains broken (token-always-valid in `verify_vendor()`) but the new system doesn't share that code. |
| **CORS** | New `apps/api` sets explicit `Access-Control-Allow-Origin` for `https://3bayti.ae`, `https://staging.3bayti.ae`, `https://vendor.3bayti.ae`, plus `localhost` in dev. Old PHP backend's missing CORS headers are no longer relevant once consumers move to the new API. |
| **Secrets management** | All API keys (Noon Payments, MessageCentral, ZeptoMail, R2 credentials) loaded from environment variables. NO secrets in source. Production secrets stored in DigitalOcean App Platform's secret store. |

### 12.7 SEO

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

### 12.8 Data model concerns

The mobile app's data shapes need mapping to TypeScript interfaces shared between web and (eventually) future projects. We have started this in `src/app/features/catalog/product.model.ts` etc. Each phase should add models for its domain.

**Recommendation:** Centralise these in `src/app/core/models/` once the shape stabilises (around Phase 2).

### 12.9 Versioning the website itself

Currently no version tag on the website. As we ship phases, recommend tagging Git releases:
- `v0.7.0` after Phase 0
- `v1.0.0` after Phase 1 (auth — first public-launchable milestone if needed)
- And so on

Tag releases at every phase exit for rollback safety.

---

## 13. Risk register

Sorted by severity. "Likelihood × Impact" dictates priority.

### High-severity risks

| # | Risk | Likelihood | Impact | Mitigation | Phase |
|---|---|---|---|---|---|
| R1 | **Critical security finding (Appendix E)**: existing backend's `verify_vendor()` returns hardcoded 100, accepting any token. Production currently runs on broken auth. | Confirmed | Critical | Old backend retired in M5. Until then, mobile + portal users are at risk; the new `apps/api` JWT middleware is the fix and lands in M1. Brief window of exposure remains until M4 cuts mobile + portal over. | Resolved by M4 |
| R2 | Data sync (MySQL → PostgreSQL) drift during M1–M4 results in inconsistent reads | Medium | Critical | Weekly diff-check job comparing checksums per table; alarm at 0.1% drift; CDC implementation reviewed before M1 start | M1–M4 |
| R3 | Mobile app rollout (M4) has app-store delays (Apple review, Play Store certification) and cutover plan slips | High | High | Feature flags in app config (`app_update.json`) allow server-side endpoint routing without an app release; submit early to App Store; staged rollout (20%/50%/100%) | M4 |
| R4 | Angular 19 → 21 upgrade in `apps/portal` reveals deeply broken legacy code | Medium | High | Allocate full week within M4 specifically for upgrade; have rollback plan to keep portal on its own repo if upgrade is too painful | M4 |
| R5 | Noon Payments integration is more complex than expected (3DS flows, KNET specifics, refund webhooks) | Medium | Critical | M3 entry checklist requires sandbox account + test card setup before starting; allocate 1 buffer week within M3; integrate via well-tested community wrapper if available | M3 |
| R6 | Auth token leakage via XSS (localStorage) results in account compromise | Low-Medium | Critical | Tightened CSP (M5); never render unsanitised user content; short-lived 15-min access tokens; logout-everywhere capability via refresh-token revocation | M1, M5 |
| R7 | Image migration job corrupts data during base64→file conversion | Low | Critical | Migration is read-only on source; updates only after successful upload; backup column kept 30 days; checksums verified | M2–M5 |
| R8 | Solo developer + monorepo scope means months of no user-visible improvements | Confirmed | High | Set explicit expectations with stakeholders; ensure each M-phase ships SOMETHING (M1 auth, M2 catalog, M3 checkout) so progress is visible; write status updates | All |
| R9 | Production support of 4 codebases during migration consumes 30%+ of engineering time | High | High | Decision 11 acknowledges this; backporting discipline; fix-once-port-fast cadence | M0–M4 |

### Medium-severity risks

| # | Risk | Likelihood | Impact | Mitigation | Phase |
|---|---|---|---|---|---|
| R10 | Mobile app users on outdated versions (no auto-update) keep hitting old endpoints indefinitely | High | Medium | Force-upgrade banner via `app_update.json` for versions older than 6 months; old endpoints kept indefinitely as a "long tail" backstop | M5+ |
| R11 | OTP delivery via MessageCentral CPaaS in UAE has provider issues | Medium | Medium | M1 includes user-facing "resend" affordance; alternative provider Twilio scoped; rate-limit per phone to control SMS costs | M1 |
| R12 | Cart abandonment due to checkout friction on mobile web | Medium | Medium | M3 includes Apple Pay / Google Pay (handled by Noon's hosted page); minimal form fields; address auto-complete | M3 |
| R13 | i18n (Arabic/RTL) breaks in unexpected places when retrofitted | Medium | Medium | Build i18n markers from M0 onward; test RTL during M2; full audit in M5 | All |
| R14 | Search relevance is poor — old backend search just does LIKE queries | Medium | Medium | M2 entry includes evaluating PostgreSQL full-text search; if poor, recommend Meilisearch in M5 | M2, M5 |
| R15 | WebSocket choice (Ratchet PHP vs Node.js sidecar) is wrong | Medium | Medium | M4 entry decision; build a thin proof-of-concept of each; default to Ratchet if PHP-only is acceptable | M4 |
| R16 | DigitalOcean App Platform doesn't scale or has issues | Low | Medium | Easy to migrate to Hetzner + manual config or AWS ECS; deploys are Docker-based by default | M1+ |
| R17 | OpenAPI codegen workflow is fragile and frontends drift from backend | Medium | Medium | CI failure on `apps/web` if generated types differ from committed; codegen runs on every PR | All |

### Low-severity risks

| # | Risk | Likelihood | Impact | Mitigation | Phase |
|---|---|---|---|---|---|
| R18 | GitHub PAT used for git operations gets revoked or rotates without warning | Medium | Low | Plan explicit rotation cadence; switch to deploy-keys when convenient | All |
| R19 | Cloudflare Workers limits exceeded for `apps/web` | Low | Medium | Already on free tier; upgrade to Workers Paid ($5/mo) trivially | M5 |
| R20 | New mobile-app features added by external team that we didn't plan for | Medium | Low | Quarterly review of all four repos; add new endpoints to roadmap | All |

### Resolved risks

| # | Risk | How resolved |
|---|---|---|
| Hydration crash on category pages (field-init-order) | Fixed in `fix/component-field-init-order`, pre-Phase 0 |
| Empty H1/H2 in prerendered HTML (ui-heading) | Fixed in `fix/ui-heading-ssr-projection`, pre-Phase 0 |
| Pronounced shadow language was visually overwhelming | Replaced with "Soft & subtle" in Phase 1 W3 R3, deployed to staging |
| Cart-merge-on-login was scoped into Phase 2 by v1.x | Deferred to M3 in v2.0 once auth lands; Phase 2 ships local-first half only |

---

## 14. Open questions

These are unresolved and should be answered before the relevant M-phase starts. Some carry forward from v1.x; others are new for v2.0.

### M0 entry questions (immediate)

1. **GitHub repo creation**: who creates `surdbells/3bayti`? When? Project owner action.
2. **Hosting accounts**: confirm DigitalOcean account exists or create one. Confirm Cloudflare account access. Confirm Upstash account.
3. **DNS strategy**: do we use `api-v3.3bayti.ae` for the new API during M1–M4, then flip `api.3bayti.ae` in M5? Or use `api.3bayti.ae` from M1 with feature-flag routing in client?
4. **CDC tooling**: which MySQL→PostgreSQL sync tool? `pgloader` for bulk + custom triggers for ongoing? Or a managed CDC service (Striim, Fivetran)?
5. **Roadmap location**: this document currently lives at `surdbells/3bayti-web/docs/roadmap.md`. Move to monorepo `docs/roadmap.md` when M0 starts? Or keep here until M0 ships?

### M1 entry questions (auth)

1. **Token expiry policy**: confirmed plan is 15-min access tokens + 7-day refresh tokens with single-use rotation. Sign off?
2. **Multi-device login**: when user logs in on web, mobile session continues — confirmed yes per existing mobile UX.
3. **Logout-everywhere**: should we add this affordance? *Recommendation: yes.* Yes/no?
4. **Email vs phone primary identifier**: existing backend allows both. Web flow: email-first, phone-OTP as second factor. Confirm?
5. **Password reset path**: mobile uses phone OTP. Web should match — confirm not adding email-link reset?

### M2 entry questions (catalog)

1. **Search backend**: PostgreSQL full-text search initially. If relevance is poor in production, switch to Meilisearch in M5?
2. **Image migration timing**: kick off in M2 (background) or wait until M5? *Recommendation: kick off background job in M2, complete in M5.*
3. **Currency normalisation**: existing code outputs `(₦)` in some places (legacy from a Nigerian project fork). Sweep these in M2 — confirm everywhere outputs AED.

### M3 entry questions (cart/checkout/payments/lifecycle)

Most lifecycle decisions are now **resolved by Decision 12 (§5)** but a few operational questions remain:

1. **Noon Payments sandbox account**: confirmed available before M3 starts?
2. **Tax**: VAT (5%) in UAE — server-calculated at checkout or pre-applied at line-item? Need confirmation. *Recommendation: server-calculated at checkout, captured in `tax_total` snapshot on Order.*
3. **Shipping fees**: how calculated — Topex API at checkout, or vendor flat-rate per order? Need confirmation. *Recommendation: vendor sets per-store policies (flat-rate, weight-based, free-over-X); Topex API used for area lookup only.*
4. **Address validation strictness**: Topex API-validated (strict — must match a known city + area), or free-form (lenient — accept any string)? *Recommendation: validated for picker UI, free-form fallback for text addresses.*
5. **Multi-vendor shipping calculation**: when an order has items from 2 vendors, customer pays sum of vendor shipping fees, OR a single platform-calculated rate? *Recommendation: sum of vendor fees (each vendor ships separately, each charges their own rate).*
6. **Inventory deduction timing**: deduct on cart-add, payment-confirmation, or vendor-acceptance? *Recommendation: vendor-acceptance (Etsy pattern). Cart-add and payment can race against vendor stock.*
7. **Cancellation refund timing**: instant on customer-cancel, or after vendor confirmation? *Recommendation: instant for `pending`/`awaiting_vendor` (no vendor work yet); vendor-confirmed for `processing`+ via support ticket.*
8. **Return reason "other" handling**: who reviews the free-text reason? Vendor, admin, or both? *Recommendation: vendor reviews first; if rejected, admin can override.*
9. **Default vendor return window**: 7 days suggested. Confirmable per Decision 12.9, but what's the platform default that vendors override?
10. **Notifications: SMS opt-out**: do customers have an SMS-opt-out preference? Default ON or OFF?
11. **VAT registration**: is the platform VAT-registered in UAE (TRN)? VAT calculations require a valid TRN.
12. **Order number format**: confirmed format `3B-2026-001234`? Or different?
13. **Sub-order number format**: confirmed `3B-2026-001234-V1` (with V1, V2, etc. for vendor index)? Or use a vendor identifier?
14. **Delivery confirmation method**: who marks `delivered`? *Default plan: Topex webhook. Fallback: vendor manual mark + customer self-confirm via email link.*
15. **Order edits post-placement**: can a customer change shipping address after placement (before vendor accepts)? *Recommendation: yes, until `processing`.*

### M4 entry questions (mobile + portal)

1. **App-store release strategy**: staged rollout percentages? Beta channel via TestFlight / Play Beta first?
2. **WebSocket implementation**: Ratchet (PHP) or Node.js sidecar? *Recommendation: build a thin POC of each early in M4 to inform.*
3. **Angular 19 → 21 strategy**: in-place upgrade vs greenfield rewrite of portal? *Recommendation: in-place upgrade.* Confirm?
4. **Force-upgrade window**: how old can a mobile app be before we force-upgrade via `app_update.json`?

### M5 entry questions (retirement)

1. **MySQL retention**: 90 days post-cutover, or longer for compliance/audit?
2. **Old PHP code archival**: keep repo read-only on GitHub indefinitely, or actually delete?
3. **Performance budget enforcement strictness**: hard fail vs warning on Lighthouse regression?
4. **Cookie banner / privacy compliance**: GDPR-style for international visitors, or UAE-specific rules?

### Cross-cutting open questions

1. **Pricing**: are prices in cents/fils or whole AED? Existing schema seems to use whole AED — confirm before any new code.
2. **Image CDN**: confirm `cdn.3bayti.ae` ownership and can be set up to back R2.
3. **Email sender domain**: ZeptoMail config — `noreply@3bayti.ae`? Confirm DNS records (SPF, DKIM, DMARC) are set up.
4. **Branding alignment**: site uses "3bayti" while repos/code use both "3bayti" and "abayti" interchangeably. Pick one for the new monorepo and code identifiers? *Recommendation: "3bayti" everywhere.*

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
| **PSP** | Payment Service Provider — e.g. Noon Payments (3bayti's choice), Stripe, PayTabs. |
| **3DS** | 3D Secure — bank authentication challenge for online card payments. |
| **MO** | Mobile Origin — used to indicate behavior originated from the mobile app. |
| **Topex** | Third-party shipping provider (`shipperapi.topex.ae`) used for UAE address city/area lookups. |
| **W2.x** | Workstream 2 (catalog) phases. Predates this roadmap; preserved for git history continuity. |
| **M-phase** | A monorepo migration phase (M0 through M5) defined in §9 of v2.0. Replaces v1.x's Phase 3+. |
| **Strangler-fig** | Migration pattern where new system is built alongside old, taking over endpoint by endpoint, until old can be retired. See §8. |
| **CDC** | Change Data Capture — replication mechanism for keeping MySQL and PostgreSQL in sync during M1–M4. |
| **Lodgik / Guard51 / CITADEL / CreditX** | Other DOST/Kodek projects whose Slim 4 + Doctrine 3 + PostgreSQL 16 stack is being adapted for the new `apps/api`. |
| **Flysystem** | PHP file abstraction library. Used to abstract file storage (local in dev, R2 in prod) so `apps/api` doesn't care where images live. |
| **R2** | Cloudflare R2 — S3-compatible object storage, no egress fees. Where product images live in target state. |
| **MessageCentral CPaaS** | SMS/OTP provider used by existing backend. Kept in the new `apps/api`. |
| **ZeptoMail** | Transactional email provider (Zoho). Used for confirmations, receipts, password resets. |
| **Noon Payments** | UAE-native PSP. Backend integration only — frontend never holds API keys. Hosted-redirect or iframe model for checkout. |
| **CDN** | Content Delivery Network. Cloudflare for the web app; `cdn.3bayti.ae` planned for image serving via R2. |

---

## Appendix B — Decision log

Each major decision is logged here with date and rationale.

| Date | Decision | Made by | Rationale |
|---|---|---|---|
| 5 May 2026 | Full customer-side parity is the website scope | Project owner | Q1 in roadmap-scoping conversation |
| 5 May 2026 | Web UX wins over mobile UX in conflict (within behavior parity) | Project owner | Q2 in roadmap-scoping conversation |
| 5 May 2026 | Build `/v2/` wrappers for every endpoint web needs | Project owner | Q3 in roadmap-scoping conversation; **superseded 6 May 2026 by D9** |
| 5 May 2026 | JWT in localStorage for web auth | Project owner | Trade-off: ship speed > XSS hardening |
| 5 May 2026 | Phase 1 = Auth + Account | Project owner | Unlocks every gated downstream feature; **revised v1.1** |
| 5 May 2026 | Comprehensive roadmap document delivered before Phase 1 work begins | Project owner | Hedge against scope drift |
| 5 May 2026 | Vendor admin pages out of scope for web | Engineering recommendation | Vendor admin → mobile-only minimises scope; **revised v2.0 by D8** |
| 5 May 2026 | Web URLs are SEO-friendly slug paths, not 1:1 with mobile routes | Engineering recommendation | SEO-driven routing is a hard requirement |
| 5 May 2026 | "Designer" naming on web (vs "Vendor" in mobile/internal) | Engineering recommendation | Better for SEO and UX |
| 5 May 2026 | **v1.1**: Phase 1 = Home Page (full), not Auth | Project owner | Site must be browsable end-to-end without login; auth required only at checkout |
| 5 May 2026 | **v1.1**: Cart is local-first (`localStorage`) for guests, merged on login | Project owner | Industry-standard e-commerce pattern; preserves discovery experience |
| 5 May 2026 | **v1.1**: Wishlist follows the same local-first → merge-on-login pattern | Project owner | Consistency with cart; lower friction for save-for-later behaviour |
| 5 May 2026 | **v1.1**: Checkout uses inline login-or-register modal, not redirect | Project owner | Reduces cart abandonment; account is created in-flow rather than as a wall |
| 5 May 2026 | **v1.1**: Card design = "Gilded Boutique" with Pronounced shadow | Project owner | Modest-luxury aesthetic; **revised in W3 R3 to Soft & subtle** |
| 5 May 2026 | **v1.1**: Home page goes beyond mobile-app parity | Project owner | Mobile home is a teaser funnel; web home is a full landing + discovery surface |
| 5 May 2026 | Phase 1 W3 R3: shadow language reset to "Soft & subtle" | Engineering + project owner | Visual review concluded pronounced shadows competed with product imagery |
| **6 May 2026** | **D7: Monorepo consolidation at `surdbells/3bayti`** | Project owner | "Multiple codebases drift apart, we need shared types and shared auth"; "long-term we want one platform, not 4 disconnected ones" |
| **6 May 2026** | **D8: Two vendor surfaces — desktop portal canonical for power users + admins, mobile lite for on-the-go** | Project owner | "Both stay, with clear scope" |
| **6 May 2026** | **D9: Backend rebuild on Lodgik/Guard51 stack (Slim 4 + Doctrine 3 + PostgreSQL 16)** | Project owner | "Same stack as Lodgik/CreditX/Guard51, UAE-adapted" |
| **6 May 2026** | **D10: Strangler-fig migration; old backend stays live until M5** | Engineering recommendation | Solo developer + production traffic + zero-downtime requirement leaves no other safe path |
| **6 May 2026** | **D11: Production support during migration is acknowledged duplicate-effort cost** | Engineering recommendation | Unavoidable in strangler-fig; discipline is to keep duplication short |
| **6 May 2026** | **3bayti-web feature work paused; only critical regressions ship** | Project owner | "Stop all 3bayti-web feature work; only critical regressions get fixed" |
| **6 May 2026** | **Roadmap updated in-place to v2.0 (this document) becomes single source of truth** | Project owner | "Update existing roadmap.md — it becomes the single source of truth" |
| **6 May 2026** | **Phase 3 (v1.x Auth + Checkout) paused indefinitely** | Project owner | Replaced by M1+M3 in monorepo |
| **6 May 2026** | **D12: Order processing lifecycle standardised** (multi-vendor sub-orders, separate payment/fulfillment statuses, separate RMA, COD-toggle-gated, 9 sub-decisions on cancellation/timeout/returns) | Project owner | Industry-standard multi-vendor design; full detail in §5 |

---

## Appendix C — References

### Internal

- This roadmap (v2.0): `surdbells/3bayti-web/docs/roadmap.md` (will move to `surdbells/3bayti/docs/roadmap.md` in M0)
- W2.0 backend patch README (deployed): source-of-truth for the existing `/v2/` catalog wrappers. Sodiq has the deployed copy on the server.
- Phase 1 final report: `surdbells/3bayti-web/docs/PHASE_1_REPORT.md`
- Web repo: [`surdbells/3bayti-web`](https://github.com/surdbells/3bayti-web)
- Mobile app repo: [`surdbells/abayti_app`](https://github.com/surdbells/abayti_app)
- Vendor + admin portal repo: [`surdbells/abayti_vendor`](https://github.com/surdbells/abayti_vendor)
- Backend (private, current): `https://api.3bayti.ae/v2/`
- Production web: [`https://staging.3bayti.ae`](https://staging.3bayti.ae)
- Future monorepo: [`surdbells/3bayti`](https://github.com/surdbells/3bayti) (planned, not yet created)
- Lodgik/Guard51/CITADEL/CreditX architecture references: see Sodiq's existing project documentation for stack patterns being adapted into `apps/api`

### External

- Angular SSR docs: [`https://angular.dev/guide/ssr`](https://angular.dev/guide/ssr)
- Angular Signals: [`https://angular.dev/guide/signals`](https://angular.dev/guide/signals)
- Cloudflare Workers + Static Assets: [`https://developers.cloudflare.com/workers/static-assets`](https://developers.cloudflare.com/workers/static-assets)
- Cloudflare R2: [`https://developers.cloudflare.com/r2/`](https://developers.cloudflare.com/r2/)
- Slim 4 framework: [`https://www.slimframework.com/docs/v4/`](https://www.slimframework.com/docs/v4/)
- Doctrine ORM 3: [`https://www.doctrine-project.org/projects/orm.html`](https://www.doctrine-project.org/projects/orm.html)
- PostgreSQL 16: [`https://www.postgresql.org/docs/16/`](https://www.postgresql.org/docs/16/)
- firebase/php-jwt: [`https://github.com/firebase/php-jwt`](https://github.com/firebase/php-jwt)
- Symfony Validator: [`https://symfony.com/doc/current/components/validator.html`](https://symfony.com/doc/current/components/validator.html)
- Symfony Messenger: [`https://symfony.com/doc/current/messenger.html`](https://symfony.com/doc/current/messenger.html)
- PHP-DI: [`https://php-di.org/`](https://php-di.org/)
- Flysystem: [`https://flysystem.thephpleague.com/`](https://flysystem.thephpleague.com/)
- Dompdf: [`https://github.com/dompdf/dompdf`](https://github.com/dompdf/dompdf)
- swagger-php (OpenAPI generation): [`https://zircote.github.io/swagger-php/`](https://zircote.github.io/swagger-php/)
- Noon Payments docs: [`https://docs.noonpayments.com/`](https://docs.noonpayments.com/)
- Topex Shipper API: contact Topex (no public docs URL known)
- Turborepo: [`https://turbo.build/repo`](https://turbo.build/repo)
- pnpm workspaces: [`https://pnpm.io/workspaces`](https://pnpm.io/workspaces)
- DigitalOcean App Platform: [`https://www.digitalocean.com/products/app-platform`](https://www.digitalocean.com/products/app-platform)
- Schema.org Product: [`https://schema.org/Product`](https://schema.org/Product)
- WCAG 2.1 quick reference: [`https://www.w3.org/WAI/WCAG21/quickref/`](https://www.w3.org/WAI/WCAG21/quickref/)
- pgloader (MySQL→PostgreSQL): [`https://pgloader.readthedocs.io/`](https://pgloader.readthedocs.io/)

---

## Appendix D — Database schema inventory

The existing MySQL database has approximately **28 distinct tables**, extracted from PHP query patterns. Naming is inconsistent: some use `ec_` prefix (legacy from a Magento-era codebase), others don't. The new schema in PostgreSQL normalises this — snake_case throughout, no `ec_` prefix mix, consistent naming conventions.

### Existing MySQL tables

| Table | Description | Notes |
|---|---|---|
| `users` | User records — customers, vendors, admins (role-flag-based) | All role flags: `is_customer`, `is_vendor`, `is_admin`, `is_finance`, `is_support`, `_sub_admin`, `is_store_active`, `is_store_approved`, `is_2fa`, `is_active` |
| `category` | Product categories | Singular table name (legacy) |
| `products` | Product master | Some images stored as base64 in `LONGTEXT`; migrating to file-URL refs in M2/M5 |
| `collections` | Curated product groupings | |
| `coupons` | Discount codes | |
| `coupon_products` | Coupon ↔ product mapping | |
| `coupon_stores` | Coupon ↔ store mapping | |
| `coupon_usage` | Tracks which user used which coupon | |
| `customer_wishlist_label` | Wishlist labels (folders) | |
| `wishlist` | Wishlist items | |
| `ec_cart_items` | Cart items | `ec_` prefix |
| `ec_orders` | Orders | `ec_` prefix |
| `ec_reviews` | Product/store reviews | `ec_` prefix |
| `review_helpful` | Review "was this helpful" votes | |
| `notifications` | User notifications | |
| `payment_attempts` | Payment attempts (Noon Payments transactions) | |
| `webhook_events` | Webhook receipts (Noon callbacks) | |
| `chat_conversations` | Per-order chat threads | |
| `chat_messages` | Individual chat messages | |
| `chat_attachments` | Files attached to chats | |
| `chat_prompt_categories` | Pre-defined chat-prompt categories | |
| `chat_prompts` | Prompt templates for vendor responses | |
| `tickets` | Support tickets | |
| `ticket_messages` | Ticket replies | |
| `store_sizes_measure` | Vendor's size/measurement schema per category | |
| `vendor_custom_labels` | Vendor's custom product labels | |
| `vendor_follows` | User-follows-vendor relationship | |
| `styles` | Custom-style submission requests | |

### Naming normalisation in `apps/api` PostgreSQL schema

| Old (MySQL) | New (PostgreSQL) | Reason |
|---|---|---|
| `category` | `categories` | Plural, consistent |
| `ec_cart_items` | `cart_items` | Drop `ec_` prefix |
| `ec_orders` | `orders` | Drop `ec_` prefix |
| `ec_reviews` | `reviews` | Drop `ec_` prefix |
| `customer_wishlist_label` | `wishlist_labels` | Plural, drop `customer_` (table is per-user via FK) |
| `_sub_admin` (column) | `is_sub_admin` | Boolean column naming consistency |

### Schema rebuild approach

Doctrine entities are defined in `apps/api/src/Domain/<Context>/<Entity>.php`. Each entity gets an explicit migration generated via `doctrine:migrations:diff`. The bulk import from MySQL uses pgloader with a custom configuration mapping old table names → new (`category` → `categories` etc.). After bulk load, CDC sync handles ongoing writes during M1–M4.

### New tables introduced by §5 (Order processing lifecycle)

The order lifecycle (Decision 12) introduces tables that don't have direct equivalents in the existing MySQL schema:

| New table | Purpose | Replaces or augments |
|---|---|---|
| `orders` | Customer-facing order record | Replaces `ec_orders` (with restructured fields) |
| `sub_orders` | Per-vendor fulfillment unit | NEW — no equivalent (existing system uses status-per-cart-item) |
| `order_items` | Line items scoped to a sub_order | Replaces the customer-facing role of `ec_cart_items` |
| `payments` | Aggregate payment record per order | NEW — augments `payment_attempts` |
| `payment_attempts` | Each individual attempt at payment (retry, 3DS, etc.) | Carries forward from existing schema with cleanup |
| `shipments` | Shipping/tracking info per sub_order | NEW |
| `return_requests` | RMA workflow per sub_order | NEW (existing system has implicit "returned" status) |
| `return_request_items` | Items being returned (subset of order_items) | NEW |
| `order_state_history` | Audit log of all state transitions on Orders, SubOrders, ReturnRequests | NEW (no audit log exists today) |
| `platform_settings` | Admin-configurable platform settings (key/value) | NEW (existing system has no settings table — defaults are hardcoded in PHP) |

#### `orders` — full schema

```sql
CREATE TABLE orders (
  id BIGSERIAL PRIMARY KEY,
  order_number VARCHAR(20) UNIQUE NOT NULL,    -- "3B-2026-001234"
  customer_id BIGINT NOT NULL REFERENCES users(id),
  shipping_address_id BIGINT REFERENCES addresses(id),
  -- Money totals (snapshot at order placement)
  subtotal_amount NUMERIC(10,2) NOT NULL,
  shipping_total NUMERIC(10,2) NOT NULL DEFAULT 0,
  tax_total NUMERIC(10,2) NOT NULL DEFAULT 0,
  discount_total NUMERIC(10,2) NOT NULL DEFAULT 0,
  grand_total NUMERIC(10,2) NOT NULL,
  currency CHAR(3) NOT NULL DEFAULT 'AED',
  -- Payment
  payment_method VARCHAR(20) NOT NULL,         -- 'card', 'cod', 'apple_pay', 'google_pay'
  payment_status VARCHAR(30) NOT NULL DEFAULT 'awaiting_payment',
  -- COD-specific (NULL for card orders)
  cod_confirmation_method VARCHAR(20),         -- 'auto', 'otp', 'vendor_approval', 'admin_review'
  cod_confirmed_at TIMESTAMPTZ,
  cod_collected_at TIMESTAMPTZ,
  cod_collected_amount NUMERIC(10,2),
  -- Coupon snapshot
  coupon_code VARCHAR(50),
  coupon_discount NUMERIC(10,2) DEFAULT 0,
  -- Lifecycle
  placed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  paid_at TIMESTAMPTZ,
  cancelled_at TIMESTAMPTZ,
  cancellation_reason VARCHAR(50),
  -- Audit
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_orders_customer ON orders (customer_id, placed_at DESC);
CREATE INDEX idx_orders_payment_status ON orders (payment_status) WHERE payment_status IN ('awaiting_payment', 'pending_3ds');
```

#### `sub_orders` — full schema

```sql
CREATE TABLE sub_orders (
  id BIGSERIAL PRIMARY KEY,
  sub_order_number VARCHAR(25) UNIQUE NOT NULL,  -- "3B-2026-001234-V1"
  order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
  vendor_id BIGINT NOT NULL REFERENCES users(id),
  -- Money totals (per-vendor breakdown)
  items_subtotal NUMERIC(10,2) NOT NULL,
  shipping_cost NUMERIC(10,2) NOT NULL DEFAULT 0,
  vendor_discount NUMERIC(10,2) NOT NULL DEFAULT 0,  -- vendor-applied discount
  sub_total NUMERIC(10,2) NOT NULL,
  -- Fulfillment state machine
  fulfillment_status VARCHAR(30) NOT NULL DEFAULT 'pending',
  vendor_decline_reason VARCHAR(50),  -- when fulfillment_status = 'vendor_rejected'
  vendor_decline_notes TEXT,
  -- Shipping
  shipping_carrier VARCHAR(50),
  tracking_number VARCHAR(100),
  estimated_delivery_at TIMESTAMPTZ,
  -- Lifecycle timestamps
  awaiting_vendor_at TIMESTAMPTZ,
  processing_at TIMESTAMPTZ,
  ready_to_ship_at TIMESTAMPTZ,
  shipped_at TIMESTAMPTZ,
  out_for_delivery_at TIMESTAMPTZ,
  delivered_at TIMESTAMPTZ,
  cancelled_at TIMESTAMPTZ,
  -- Audit
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_sub_orders_order ON sub_orders (order_id);
CREATE INDEX idx_sub_orders_vendor ON sub_orders (vendor_id, fulfillment_status, created_at DESC);
CREATE INDEX idx_sub_orders_awaiting ON sub_orders (fulfillment_status, awaiting_vendor_at) WHERE fulfillment_status = 'awaiting_vendor';
```

#### `order_items` — full schema

```sql
CREATE TABLE order_items (
  id BIGSERIAL PRIMARY KEY,
  sub_order_id BIGINT NOT NULL REFERENCES sub_orders(id) ON DELETE CASCADE,
  product_id BIGINT REFERENCES products(id),  -- nullable: product may be deleted later
  variant_id BIGINT,  -- nullable
  qty INTEGER NOT NULL CHECK (qty > 0),
  unit_price NUMERIC(10,2) NOT NULL,
  line_total NUMERIC(10,2) NOT NULL,
  -- Snapshot fields (preserved even if product is deleted/renamed)
  product_name_snapshot VARCHAR(255) NOT NULL,
  product_slug_snapshot VARCHAR(255) NOT NULL,
  image_url_snapshot TEXT,
  size_snapshot VARCHAR(50),
  color_snapshot VARCHAR(50),
  vendor_name_snapshot VARCHAR(255) NOT NULL,
  -- Audit
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_order_items_sub_order ON order_items (sub_order_id);
CREATE INDEX idx_order_items_product ON order_items (product_id);
```

#### `return_requests` — full schema

```sql
CREATE TABLE return_requests (
  id BIGSERIAL PRIMARY KEY,
  return_number VARCHAR(25) UNIQUE NOT NULL,   -- "3B-2026-001234-R1"
  sub_order_id BIGINT NOT NULL REFERENCES sub_orders(id),
  customer_id BIGINT NOT NULL REFERENCES users(id),
  vendor_id BIGINT NOT NULL REFERENCES users(id),
  status VARCHAR(30) NOT NULL DEFAULT 'requested',
  reason_code VARCHAR(50) NOT NULL,            -- 'defective', 'wrong_size', etc.
  reason_notes TEXT,
  vendor_fault BOOLEAN NOT NULL,               -- determines shipping refund eligibility
  -- Refund calculation
  refund_items_total NUMERIC(10,2),
  refund_shipping_total NUMERIC(10,2) DEFAULT 0,
  refund_total NUMERIC(10,2),
  -- Return shipping
  return_shipping_carrier VARCHAR(50),
  return_tracking_number VARCHAR(100),
  -- Lifecycle timestamps
  requested_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  approved_at TIMESTAMPTZ,
  in_transit_at TIMESTAMPTZ,
  received_at TIMESTAMPTZ,
  refunded_at TIMESTAMPTZ,
  rejected_at TIMESTAMPTZ,
  -- Vendor inspection notes
  inspection_notes TEXT,
  -- Audit
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_returns_customer ON return_requests (customer_id, requested_at DESC);
CREATE INDEX idx_returns_vendor ON return_requests (vendor_id, status, requested_at DESC);
CREATE TABLE return_request_items (
  id BIGSERIAL PRIMARY KEY,
  return_request_id BIGINT NOT NULL REFERENCES return_requests(id) ON DELETE CASCADE,
  order_item_id BIGINT NOT NULL REFERENCES order_items(id),
  qty_returning INTEGER NOT NULL CHECK (qty_returning > 0)
);
```

#### `payments` and `payment_attempts`

```sql
CREATE TABLE payments (
  id BIGSERIAL PRIMARY KEY,
  order_id BIGINT NOT NULL REFERENCES orders(id),
  amount NUMERIC(10,2) NOT NULL,
  currency CHAR(3) NOT NULL,
  status VARCHAR(30) NOT NULL,        -- mirrors orders.payment_status
  noon_order_id VARCHAR(100),         -- Noon's order reference
  noon_payment_id VARCHAR(100),       -- Noon's payment reference
  authorized_at TIMESTAMPTZ,
  captured_at TIMESTAMPTZ,
  refunded_at TIMESTAMPTZ,
  refunded_amount NUMERIC(10,2) DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE TABLE payment_attempts (
  id BIGSERIAL PRIMARY KEY,
  payment_id BIGINT NOT NULL REFERENCES payments(id),
  attempt_number INTEGER NOT NULL,
  status VARCHAR(30) NOT NULL,
  failure_reason VARCHAR(100),
  noon_response JSONB,                -- raw webhook payload for forensic review
  created_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
```

#### `platform_settings`

```sql
CREATE TABLE platform_settings (
  key VARCHAR(100) PRIMARY KEY,        -- e.g. 'cod.enabled', 'orders.unpaid_timeout_hours'
  value_json JSONB NOT NULL,
  description TEXT,
  category VARCHAR(50) NOT NULL,       -- 'orders', 'returns', 'payments', 'cancellations', 'notifications'
  updated_by_admin_id BIGINT REFERENCES users(id),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
-- Initial seed values (loaded via Doctrine migration):
-- ('orders.unpaid_timeout_hours', '24', 'Hours before unpaid orders auto-cancel', 'orders'),
-- ('orders.vendor_acceptance_timeout_hours', '24', '...', 'orders'),
-- ('payments.cod.enabled', 'false', 'Master toggle for Cash on Delivery', 'payments'),
-- ('payments.cod.max_amount_aed', '1000', 'Max order amount eligible for COD', 'payments'),
-- ... (full list per §5.8)
```

#### `order_state_history` — already shown in §5.10

The schema is repeated here for completeness in the appendix.

```sql
CREATE TABLE order_state_history (
  id BIGSERIAL PRIMARY KEY,
  entity_type VARCHAR(20) NOT NULL,
  entity_id BIGINT NOT NULL,
  from_status VARCHAR(50),
  to_status VARCHAR(50) NOT NULL,
  actor_type VARCHAR(20) NOT NULL,
  actor_id BIGINT,
  reason VARCHAR(50),
  notes TEXT,
  metadata JSONB,
  occurred_at TIMESTAMPTZ NOT NULL DEFAULT now()
);
CREATE INDEX idx_state_history_entity ON order_state_history (entity_type, entity_id, occurred_at);
CREATE INDEX idx_state_history_actor ON order_state_history (actor_type, actor_id) WHERE actor_id IS NOT NULL;
```

---

## Appendix E — Critical security findings (existing backend)

These were identified during v2.0 reconnaissance of `surdbells/3bayti_backend`. They affect production traffic today (mobile + portal users) but are resolved by the new `apps/api` in M1+.

### E.1 verify_vendor() always returns 100 — token validation broken

**Severity: critical**

**File:** `config/Database.php`, lines 64–78

```php
public function verify_vendor($token, $user_id): float {
    $user_token = "";
    $conn = $this->getConnection();
    $sql = "SELECT token FROM users where user_id = '$user_id'";
    if ($result = mysqli_query($conn, $sql)) {
        if (mysqli_num_rows($result) > 0) {
            $row = mysqli_fetch_assoc($result);
            $user_token = $row['token'];
        }
    }
    similar_text($token, $user_token, $percent);
    return 100;          // <-- ALWAYS RETURNS 100
}
```

The function computes a similarity percentage between the supplied token and the stored token, but **never reads the result**. It always returns the literal `100`. Every PHP endpoint that gates with `if ($token_verification == 100)` thus passes regardless of token validity.

**Effect:** any HTTP request with any valid `user_id` and any random token gets through. Mobile app users are not authenticated.

**Resolution:** new `apps/api` uses proper JWT middleware with HS256 signature verification. M1 deliverable. The old backend retires in M5; until then, the broken auth remains. Mitigation in interim: monitor for unusual access patterns; consider IP-based rate limiting on the existing PHP backend if compromise becomes likely.

### E.2 SQL injection in verify_vendor()

**Severity: critical**

**File:** `config/Database.php`, line 67

```php
$sql = "SELECT token FROM users where user_id = '$user_id'";
```

`$user_id` is the user-supplied request parameter, interpolated directly into the query. A malicious `user_id` value can break out of the string and execute arbitrary SQL.

**Effect:** any caller can read or modify the `users` table. Since the existing backend has no real auth (E.1), this is reachable from anywhere.

**Resolution:** new `apps/api` uses Doctrine's parameterised queries throughout. No raw concatenation possible.

### E.3 Hardcoded SMS-provider credentials

**Severity: high**

**File:** `class/Customer.php`, lines 1038–1071 (`getAuthToken()` method)

```php
$customerId = "C-0B186B587F924BB";
$key = "QDIwMjVHb0JldGE=";    // base64 of "@2025GoBeta"
$email = "rashed11405@gmail.com";
```

MessageCentral CPaaS credentials are baked into PHP source. Anyone with read access to the backend repo (including engineers, contractors, eventual third parties) has these credentials.

**Effect:** the SMS quota and any SMS messages sent are billable to the holder of these credentials. A malicious actor could send arbitrary SMS at the operator's expense.

**Resolution:** `apps/api` loads all secrets from environment variables. Secrets stored in DigitalOcean App Platform's encrypted secret store. Never committed to source.

### E.4 firebase/php-jwt installed but unused

**Severity: medium (not exploitable by itself; signals architectural inconsistency)**

**File:** `composer.json` includes `firebase/php-jwt: ^6.11`. Searching the codebase shows no `use Firebase\JWT\` imports anywhere outside the vendor directory.

**Effect:** the existing tokens are opaque DB-stored strings (per `verify_vendor`'s reference to `users.token`), not JWTs. The library is dead weight. The codebase's claim to be "JWT-based auth" (per existing roadmap v1.1) is incorrect.

**Resolution:** new `apps/api` actually uses firebase/php-jwt to issue and verify HS256-signed JWTs. The old backend's opaque tokens are abandoned in M1 when consumers migrate to `/v3/auth/*`.

### E.5 Two parallel auth flows

**Severity: medium**

The existing backend has two coexisting auth systems:
- Email + password (`users/login.php`, `users/customer_register.php`) — uses `password_hash` + `password_verify`
- Phone OTP (`customer/sendOTP.php`, `customer/validateOTP.php`) — uses MessageCentral CPaaS

There's no clear ownership between them. Some endpoints accept either; others only one. Mobile-app workflow uses OTP for registration but password for subsequent logins — inconsistent.

**Resolution:** `apps/api` unifies them. Email + password is the primary login. Phone OTP is registration confirmation + password recovery. Consistent flow.

### E.6 No CORS headers

**Severity: medium**

None of the existing 213 backend endpoint files set `Access-Control-Allow-Origin` headers. The mobile app doesn't need this (native HTTP client, not subject to CORS), but the web frontend can't call any of these endpoints from the browser without CORS.

**Effect:** the web `apps/web` cannot call the legacy PHP endpoints directly. Currently mitigated by W2.0 patch only including endpoints designed for web (the `/v2/` namespace), but a full migration of existing endpoints would require backend CORS work, OR a CORS-proxy at the edge.

**Resolution:** `apps/api` sets CORS for `https://3bayti.ae`, `https://staging.3bayti.ae`, `https://vendor.3bayti.ae`, plus `localhost` in dev. Web migrates to `/v3/*` and the CORS issue evaporates.

### E.7 Currency mismatch (Naira in AED app)

**Severity: low (cosmetic, but indicative of legacy code)**

**File:** `class/Customer.php`, in `order_summary()` method around lines 1117–1167

The `order_summary()` method outputs HTML for order receipt emails with `(₦)` (Nigerian Naira) symbols, while products are priced in AED. Code was apparently forked from a Nigerian e-commerce project and not fully localised.

**Effect:** order confirmation emails show Naira symbol. Confusing for UAE users.

**Resolution:** new `apps/api` uses ZeptoMail templates with `currency` from the order entity (always AED for 3bayti). No legacy literals.

---

*End of roadmap document v2.0. Maintain in lockstep with reality. When this doc and the code disagree, update one or the other immediately.*



