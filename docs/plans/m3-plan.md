# M3 Plan — Complete v3 API Migration + Noon Payment Modernization

**Author:** Sodiq's pair (Claude) on behalf of Sodiq Bello
**Status:** DRAFT — awaiting approval before any phase begins
**Created:** Day 8+ of M2 rollout, May 14, 2026
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

#### M3.1.1 — v3 Auth Endpoint Build

**Duration:** 5-7 days
**Output:** 6 new v3 auth endpoints

**Endpoints to build:**

- `POST /v3/auth/send-otp` — replaces `customer/sendOTP` + `users/sendOTP` (dedup'd) — supports email OR phone destination
- `POST /v3/auth/validate-otp` — replaces `customer/validateOTP`
- `POST /v3/auth/validate-email` — replaces `users/validate-email` (email verification)
- `POST /v3/auth/confirm-account` — replaces `users/confirm` (post-OTP account activation)
- `POST /v3/auth/forgot-password-mobile` — replaces `users/resetMobile` (mobile-flow specific?)  TO VERIFY in audit
- `POST /v3/auth/reset-password-otp` — replaces `users/sendOTP` used for password reset (mobile flow)

**Acceptance:**
- All 6 endpoints have controller + Doctrine implementation
- Unit tests for happy path + 3 error cases each
- ENDPOINT_ROUTING entries added with `target: 'old'` (no flip yet)
- v3 API CI passes
- Shape parity with legacy verified via curl side-by-side
- Documented in api-contracts package

#### M3.1.2 — Mobile Adapter Layer (MobileNetworkAdapter)

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

#### M3.1.3 — Mobile Auth Flip (Phase 1: Login + Register)

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

#### M3.1.4 — Mobile Auth Flip (Phase 2: OTP + Reset Flows)

**Duration:** 3-4 days
**Output:** OTP send/validate + password reset routed through v3

Same shape as M3.1.3 but for the 6 new v3 auth endpoints from M3.1.1.

Additional considerations:
- OTP flows interact with the user's phone (SMS) or email — must verify the underlying SMS provider integration in v3 works (likely same Twilio / similar)
- Password reset has a token-exchange flow — ensure tokens issued by v3 can't be confused with legacy tokens

#### M3.1.5 — Mobile Catalog Reads Flip

**Duration:** 5-7 days
**Output:** All 20 catalog read endpoints on v3

**Phased within itself:**
- Day 1-2: Sample 3 catalog endpoints, run shape diff, document deltas
- Day 3-4: Add ENDPOINT_ROUTING entries for all 20, with mobile-specific shape translators in MobileNetworkAdapter where needed
- Day 5: Flip 5 lowest-risk endpoints (ProductCategory, category_listing, search) target='new'
- Day 6: Flip 5 more (new_arrivals, best_sellers, featured, styles_list, store_latest)
- Day 7: Flip the rest

**Risk:** Mobile may rely on legacy-specific response fields that v3 doesn't include. The MobileNetworkAdapter's `translateResponse` function handles this; deltas documented in commit messages.

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
M3.1.3  Mobile auth flip phase 1         2-3d
M3.1.4  Mobile auth flip phase 2         3-4d
M3.1.5  Mobile catalog flip              5-7d
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

Audit needed in M3.2.0 (part of the M3.1.0 cross-app inventory).

Probable scope:
- Auth (login/register/reset) — partial overlap with mobile work
- Cart, checkout, orders — overlap with mobile M3.1.6/M3.1.7
- Account management (addresses, profile, wishlist)
- Designer routes (Day 5 followup; v3 needs /vendors/:slug pages on the web side)

Most of M3.2 reuses v3 endpoints already built in M3.1. So it's mostly:
- Refactor apps/web to use RoutedHttpClient (where it doesn't already)
- Add Noon payment SDK integration to web checkout
- Build /designer/* routes
- Restore /designer/* URLs to sitemap.xml

### 3.2 Web phases

#### M3.2.0 — Audit (part of M3.1.0)

Covered in M3.1.0.

#### M3.2.1 — Web Auth Flip (Phase 2 + Reset Flows)

**Duration:** 2-3 days

Web's login + register were partially flipped in M2 Day 5. Reset flows and OTP flows weren't. Build on M3.1.1's v3 auth endpoints.

#### M3.2.2 — Web Cart + Checkout + Orders Flip

**Duration:** 3-4 weeks (with shadow mode)

Same shadow-mode discipline as mobile. Cart/checkout/orders are the highest-risk web flows because they touch payment.

#### M3.2.3 — Web Noon Payment Integration

**Duration:** 7-10 days

Mobile used Capacitor InAppBrowser for Noon's hosted checkout page. Web uses... well, Noon offers a hosted page (window.location redirect) or an embedded SDK. Hosted page is simpler — go with that for M3, defer embedded SDK to M4 if performance matters.

#### M3.2.4 — Web Designer Routes

**Duration:** 3-4 days

Build `/designer` and `/designer/:slug` Angular routes. Restore vendor entries to sitemap.xml. This depends on M3.1.10's v3 vendor endpoints.

#### M3.2.5 — Web Account Management Flip

**Duration:** 5-7 days

Profile, addresses, wishlist on web. Reuses v3 endpoints from M3.1.10's account work.

### 3.3 Web phase summary

```
M3.2.0  Audit (part of M3.1.0)            covered
M3.2.1  Web auth flip phase 2             2-3d
M3.2.2  Web cart/checkout/orders flip     3-4 WEEKS
M3.2.3  Web Noon payment                  7-10d
M3.2.4  Web designer routes               3-4d
M3.2.5  Web account management            5-7d

TOTAL: ~5-7 weeks (much of it parallel to M3.1 because shared v3 endpoints)
```

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

---

## 7. Timeline summary

This is "whatever it takes — quality over speed" so timelines are estimates, not commitments.

```
M3.1 (Mobile):  ~16-22 weeks  (the long one — biggest surface)
M3.2 (Web):     ~5-7 weeks    (shorter because much reuses M3.1 v3 work)
M3.3 (Portal):  ~9-11 weeks   (admin endpoint build is the slow part)

Total elapsed: ~30-40 weeks of solo work (some overlap because cross-app v3 endpoints serve multiple apps)
```

8-10 months realistic. If progress reveals faster pace, the phases compress naturally. If slower, the conservative C5 mandate (quality over speed) is the right anchor.

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
[ ] Constraints (§0.1) accurately captured
[ ] Non-goals (§0.2) acceptable
[ ] Migration discipline (§1) is the playbook
[ ] Mobile phase plan (§2) covers everything mobile needs
[ ] Web phase plan (§3) covers everything web needs
[ ] Portal phase plan (§4) covers everything portal needs
[ ] Risk register (§6) honest about risk shape
[ ] Timeline (§7) acceptable
[ ] M4 deferrals (§8) explicit
```

Once approved:
- M3.1.0 (cross-app endpoint audit) is the first phase.
- Each subsequent phase requires its own approval gate before starting.
- Per-phase commits, same cadence as M2 Days 5-7.

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
