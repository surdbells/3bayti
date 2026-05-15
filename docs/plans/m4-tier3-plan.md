# M4 — Tier 3 Features (Deferred from M3)

**Status:** ⏸️ Planning only. Execution begins after M3 closure (M3.4 complete).
**Plan author:** Sodiq + Claude
**Planning date:** May 16, 2026
**Companion docs:**
- `docs/plans/m3.2-master-plan.md` — M3.2 master plan (Tier 1+2 features)
- `docs/plans/m3-plan.md` — M3 master plan (deprecated for forward planning; still authoritative for done work)

---

## 0. Purpose

This document captures the Tier 3 feature scope explicitly deferred from M3 to M4.

**Decision Q-Features (locked):** Tier 1 + Tier 2 features ship in M3. Tier 3 features ship in M4, after legacy retirement.

**Why deferred:** Tier 3 contains heavyweight features (BNPL integrations, gift cards, loyalty program, visual search, A/B testing infrastructure, affiliate program, etc.) that would expand the M3 envelope from ~40-50 weeks to ~90-120 weeks. Holding legacy alive for that timeline imposes maintenance burden and slows iteration speed. Better to retire legacy at the Tier 1+2 feature parity point and iterate Tier 3 against a cleaner v3-only codebase.

**Total realistic M4 envelope:** ~50-70 weeks of solo work at the quality bar set in M3.

---

## 1. Tier 3 feature inventory

Listed in approximate execution priority (highest business impact first, where impact is judged by typical multivendor ecommerce metrics).

### 1.1 Payment & checkout enhancements

**M4.1 — Buy Now Pay Later integrations (Tabby + Tamara)** ~4-6 weeks
- Tabby is more dominant in UAE; Tamara primarily Saudi
- Both follow same general pattern: customer selects at checkout → redirect to BNPL approval → return URL with approval token → backend confirms with BNPL API → order proceeds
- New `PaymentGatewayInterface` adapter implementations (already pluggable per M3.1.6 architecture)
- Webhook handling for each BNPL provider's lifecycle events (approved, declined, settled, returned, late_payment)
- Refund flow extension (BNPL refunds have different rules than card refunds)
- Per-customer eligibility check at checkout (BNPL APIs return whether this customer qualifies)
- Sandbox onboarding for both providers (each takes 2-3 weeks of partner engagement)

**M4.2 — Saved payment methods (Noon vault tokenization)** ~3-5 days
- Requires Noon partnership conversation to enable vault feature on the account
- Customer opts in at checkout; token stored against User
- Subsequent checkouts let customer pick from saved tokens
- Vault token never touches our systems (PCI scope minimized)

**M4.3 — Apple Pay + Google Pay via Noon hosted page** ~3-5 days (if not shipped in M3.2.Y.2-F)
- Depends on whether Noon's hosted page supports passing express-checkout flags
- Conditional ship: if Noon supports it, ship in M3.2; if not, M4.3

### 1.2 Gift cards & promotions

**M4.4 — Gift cards (purchasable + redeemable)** ~3-4 weeks
- New financial instrument requires balance ledger architecture
- `gift_card` entity with balance, redemption_history, expiration, issued_to, purchased_by
- Purchase flow: select card → choose amount → choose recipient → payment → email with redemption code
- Redemption flow: enter code at checkout → balance applied → remaining charged to payment method
- Refund handling: refunds to gift card OR to original payment method (customer choice)
- Fraud controls: redemption velocity limits, IP/device fingerprint checks
- Accounting: gift card sales are deferred revenue, recognized on redemption
- Admin UI: card issuance, balance adjustment, fraud freeze

**M4.5 — Loyalty / rewards program** ~4-6 weeks
- Points ledger architecture similar to gift cards
- Earning rules: per-order percentage, signup bonus, referral bonus, review bonus
- Redemption rules: minimum threshold, redemption rate, max-per-order cap
- Tier system: bronze/silver/gold based on lifetime spend; tier benefits (multipliers, exclusive access)
- Points expiration policy
- Customer-facing dashboard: points balance, tier progress, earning/redemption history
- Admin UI: program configuration, manual point adjustments, tier overrides

**M4.6 — Tiered pricing (bulk discounts)** ~1-2 weeks
- Per-product or per-vendor tier configuration: buy N for X% off
- UI on product detail page showing tier breakdown
- Cart auto-applies best tier per line item
- Coexists with promo codes (configurable stacking rules)

**M4.7 — Bundle deals** ~1-2 weeks
- Vendor-defined product bundles at discounted price
- "Frequently bought together" UX based on order_items aggregation
- Bundle bypasses individual product discount rules

**M4.8 — Flash sales** ~1-2 weeks
- Time-limited price reductions with countdown UI
- Admin schedules in advance with start/end timestamps
- Auto-revert on expiry
- Live countdown on product detail and cart

### 1.3 Search & discovery

**M4.9 — Visual search (photo → similar products)** ~6-10 weeks
- ML model selection: CLIP, OpenCLIP, or similar vision-language model
- Embedding pipeline: existing products embedded into vector space
- Vector database: pgvector extension or dedicated vector DB
- Search at query time: user uploads photo → embed → nearest-neighbor search → top results
- Mobile camera UX: photo capture flow
- Web file upload UX
- Cold-start: backfill embeddings for existing catalog
- Ongoing: new products embedded on create/update

**M4.10 — A/B testing infrastructure** ~3-4 weeks
- Experiment framework: variant assignment by user_id hash
- Variant tracking in events
- Statistical significance tooling (or integration with PostHog/Statsig)
- Admin UI: create experiment, set variants, monitor live results
- Conversion event definitions: visit, add-to-cart, checkout, purchase
- Multi-variant support (A/B/C)
- Targeting rules: by country, by device, by user segment

**M4.11 — Search analytics** ~1-2 weeks
- Log every search query with results count + click-through
- Admin dashboard: top searches, zero-result searches, click-through rate
- Powers M4.12 synonym tuning

**M4.12 — Search synonyms + typo tolerance beyond Postgres** ~2-3 weeks
- Synonym dictionary (manual curation initially)
- Levenshtein-distance typo tolerance (Postgres pg_trgm extension)
- Re-rank by click-through rate from analytics

### 1.4 Personalization & retention

**M4.13 — Recommendations engine v2 (ML-driven)** ~4-6 weeks
- Beyond Tier 2's "customers who bought X also bought Y" aggregation
- User-item collaborative filtering
- Embedding-based similar-product recommendations
- Real-time personalization: homepage tiles, "recommended for you" sections
- Cold-start: new users get popularity-based recs until purchase history

**M4.14 — Customer segmentation** ~4-6 weeks
- Pre-defined segments: RFM (recency, frequency, monetary), engagement tiers, lifecycle stages
- Custom segment builder: rule-based filtering on customer attributes + behavior
- Segment export for marketing
- Segment-targeted promotions (M4 promo code engine extension)
- Admin UI

**M4.15 — Cohort analysis** ~2-3 weeks
- Retention by signup cohort (D1, D7, D30, D90, D180, D365)
- Revenue per cohort
- LTV (lifetime value) projection
- Admin dashboard with cohort heatmap

**M4.16 — Funnel analytics** ~2-3 weeks
- View → cart → checkout → paid funnel at each step
- Drop-off analysis
- Conversion rate over time
- Per-segment funnel comparison

**M4.17 — Stock notifications ("notify me when back in stock")** ~1 week
- User subscribes from out-of-stock product page
- Cron checks inventory daily
- Email + push when restocked
- Auto-expire subscriptions after 90 days

**M4.18 — Price drop alerts (for wishlisted items)** ~1 week
- Cron checks wishlisted item prices daily
- Email + push when price drops by ≥X%
- User-configurable threshold

### 1.5 Logistics & fulfillment

**M4.19 — Multi-warehouse vendor inventory** ~3-4 weeks
- Vendor entity supports multiple physical locations
- Inventory tracked per location
- Order fulfillment routing: which warehouse ships
- Shipping cost calculation per origin
- Admin UI: warehouse management

**M4.20 — Shipping zones + rates** ~2-3 weeks
- Per-vendor or per-zone shipping cost configuration
- Calculated at checkout based on destination + items
- Free shipping thresholds
- Express vs standard rate tiers
- Admin UI: zone definition

**M4.21 — Order tracking carrier integration v2** ~3-4 weeks (extends M3 Tier 2 tracking)
- API integration with Aramex, DHL, Naqel for live tracking pulls
- Tracking events emit notifications
- Customer-facing tracking page with timeline
- Admin operations dashboard

**M4.22 — Estimated delivery dates at checkout** ~1-2 weeks
- Based on vendor processing time + carrier transit time + customer zone
- Displayed on product detail and at checkout
- Updates after order placed

**M4.23 — Split shipments** ~2-3 weeks
- Single order with items from multiple vendors → multiple parcels
- Each parcel has its own tracking number + delivery status
- Customer-facing UI shows split status

**M4.24 — Pickup points / lockers** ~2-3 weeks
- Noon Locker integration if available
- Customer selects locker at checkout
- Delivery to locker with pickup code email
- 7-day pickup window

**M4.25 — In-store pickup** ~2-3 weeks
- Vendors with physical stores opt in
- Customer selects "pick up at store" at checkout
- Notification when ready for pickup
- QR code for pickup verification

### 1.6 Vendor governance & tools

**M4.26 — Vendor onboarding KYC** ~3-4 weeks
- Document upload: trade license, ID, address proof
- Manual admin review queue
- Approval/rejection workflow with reason
- Resubmission cycle
- Integrates with M3.2.X.6 vendor lifecycle states

**M4.27 — Vendor analytics dashboard v2** ~3-4 weeks (extends M3 Tier 2 vendor analytics)
- Beyond Tier 2 basics: cohort analysis per vendor, conversion funnel for vendor's pages, top traffic sources, abandoned cart rate
- Vendor-customizable date ranges
- Export to CSV/XLSX

**M4.28 — Vendor performance scoring** ~2-3 weeks
- Composite score from fulfillment rate, cancellation rate, dispute rate, review score
- Score-based vendor tier (bronze/silver/gold) visible to customers
- Auto-suspension thresholds (vendor falls below score → admin alert + customer-visible badge)

**M4.29 — Bulk product upload (CSV/XLSX import)** ~2-3 weeks
- Template + import wizard in portal
- Validation: required fields, image URLs, category mapping
- Per-row error reporting
- Dry-run mode

**M4.30 — Product duplication / variant creation** ~1 week
- "Duplicate" button on product detail in portal
- Variant creation (size, color) from parent product
- Inventory tracked per variant

**M4.31 — Webhook subscriptions for vendors** ~2-3 weeks
- Vendor registers webhook URLs for order.placed, order.cancelled, order.shipped, payout.completed
- Outbound delivery with HMAC signature
- Retry policy with exponential backoff + DLQ
- Vendor admin UI: webhook configuration + delivery history

**M4.32 — API tokens for vendors** ~2-3 weeks
- Vendor admin issues tokens for their own integrations (PIM, ERP)
- Scoped permissions (read products, write inventory, etc.)
- Rate limiting per token
- Token rotation + revocation

**M4.33 — Vendor messaging templates** ~1 week
- Pre-written replies to common customer questions
- Template variables (order_number, customer_name)
- Quick-insert from chat UI

**M4.34 — Vendor response to public reviews** ~1 week
- Vendor can post one public reply per review
- Customer notified by email + push
- Admin moderation queue

### 1.7 Customer experience polish

**M4.35 — "Save for later" in cart** ~3-5 days
- Distinct from wishlist
- Moves items out of active cart but keeps in account
- Auto-recovers if price changes

**M4.36 — Recently viewed products** ~3-5 days
- Session-based + persisted per user
- Strip on homepage + product detail page
- Mobile + web

**M4.37 — Multi-address book** (already in M3 Tier 1; M4 extends with labels)
- Address labels (home, office, gift-recipient names)
- Default per fulfillment type (shipping vs billing)

**M4.38 — Order notes** ~3-5 days
- Customer leaves note for vendor at checkout
- Vendor sees in portal order detail

**M4.39 — Gift wrapping option** ~3-5 days
- Per-item or per-order
- Premium option with charge
- Gift message field

**M4.40 — Cart sharing** ~2-3 days
- Share cart link with others
- Recipient can clone to their own cart
- Useful for gift-giving coordination

**M4.41 — Address autocomplete (Google Places API)** ~2-3 days
- Speeds up address entry
- Reduces typos and bad addresses
- Subject to Google Places API cost

**M4.42 — Returns request flow v2** ~2-3 weeks (extends M3 Tier 1 returns)
- Customer self-serve return initiation with reason + photos
- Vendor approval workflow
- Return shipping label generation
- Refund triggered on return receipt
- Item restocking workflow

**M4.43 — Exchange flow** ~2-3 weeks
- Customer requests exchange (size, color)
- Vendor approves with new item
- Return + reorder in one transaction
- Inventory swap handled atomically

### 1.8 Marketing & SEO

**M4.44 — Product structured data v2** ~1 week
- Extends M3 baseline (JSON-LD on product pages)
- BreadcrumbList per category page
- AggregateRating per product (already partial)
- Offer schema for prices/discounts
- ItemList schema for category and search results
- BlogPost schema if blog ships

**M4.45 — Sitemap regeneration automation** ~3-5 days
- Cron rebuilds sitemap daily
- Incremental updates for new products
- Submits to search engines via ping
- Multiple sitemap files for >50k URLs

**M4.46 — Open Graph + Twitter Card meta tags** ~3-5 days
- Per-product social preview cards
- Per-category preview cards
- Per-vendor preview cards
- Tested via Facebook debugger + Twitter card validator

**M4.47 — Email marketing integration (Mailchimp or Klaviyo)** ~2-3 weeks
- Customer list sync (signups, segments)
- Campaign trigger events sent to ESP
- Unsubscribe sync
- GDPR consent management

**M4.48 — Affiliate program** ~4-6 weeks
- Affiliate signup + approval workflow
- Per-affiliate referral codes + tracking
- Commission rates configurable per affiliate
- Click + conversion attribution
- Affiliate dashboard: earnings, payouts, traffic
- Payout flow (manual initially, automated later)

### 1.9 Operations

**M4.49 — Admin order timeline visualization** ~1-2 weeks
- Chronological view of every state transition for an order
- Powered by existing audit_log + payment_transactions data
- Filterable by event type
- Export to CSV

**M4.50 — Bulk admin actions** ~1-2 weeks
- Bulk refund, bulk cancel, bulk export
- Confirmation UX with row preview
- Async background processing with progress UI
- Audit emission per row

**M4.51 — Export to CSV/XLSX (orders, customers, products)** ~1 week
- Filterable export builders
- Async generation with email notification when ready
- Date range + segment filters

**M4.52 — Multi-currency settlement** ~3-4 weeks
- Vendor payouts in non-AED currencies
- FX rate locking at order time
- Payout reconciliation across currencies
- Accounting integration

**M4.53 — Vendor settlement / payout system v2** ~3-4 weeks
- Beyond M3 manual-trigger model
- Scheduled payouts (weekly/monthly)
- Holdback for dispute reserve
- Payout method support (bank transfer, PayPal, etc.)

### 1.10 Mobile-specific

**M4.54 — Biometric login (Face ID / Touch ID / fingerprint)** ~1 week
- Capacitor biometric plugin
- Secure storage of refresh token behind biometric gate
- Fallback to password
- iOS + Android coverage

**M4.55 — Offline mode (mobile catalog cache)** ~3-4 weeks
- Service worker / Capacitor offline storage for catalog
- Cached product detail pages
- Queued cart additions for sync when online
- Conflict resolution (price changes while offline)

**M4.56 — Deep linking** ~1 week
- Universal Links (iOS) + App Links (Android)
- Share product URL → opens app if installed, web if not
- Branch.io or native deep link handling

**M4.57 — App store optimization** ~1-2 weeks
- Better screenshots, app preview video
- Description copy optimization
- Localized listings (English + Arabic)
- ASO tooling subscription (App Annie, Sensor Tower)

### 1.11 Trust & safety

**M4.58 — Customer review verification** (already partial — extends with badging)
- Only verified purchasers can review
- "Verified Purchase" badge on review display
- Anti-fraud heuristics (review velocity, sock-puppet detection)

**M4.59 — Fraud detection v1** ~2-3 weeks
- Velocity rules (orders per device per day, payment failures per card)
- Rule-based scoring at checkout
- Admin review queue for high-risk orders
- Manual approve / decline flow

**M4.60 — Vendor probation system** ~1-2 weeks
- Extends M3 vendor lifecycle states with probation
- Auto-trigger probation on threshold breach (dispute rate, return rate)
- Time-bound probation periods with exit criteria
- Customer-visible "new vendor" badge during probation

---

## 2. M4 milestone structure (proposed)

This is preliminary; refined when M3 closes and we have firsthand data on vendor/customer patterns.

```
M4.0  Re-planning + quality refresh           ~1-2 weeks
M4.1  Payment enhancements (BNPL + saved)     ~5-6 weeks
M4.2  Gift cards + loyalty                    ~7-10 weeks
M4.3  Search + discovery v2                   ~10-15 weeks
M4.4  Personalization + retention             ~10-13 weeks
M4.5  Logistics + fulfillment                 ~14-18 weeks
M4.6  Vendor governance + tools               ~12-15 weeks
M4.7  Customer experience polish              ~7-10 weeks
M4.8  Marketing + SEO + affiliate             ~6-9 weeks
M4.9  Operations + admin                      ~5-7 weeks
M4.10 Mobile-specific (biometric, offline)    ~6-9 weeks
M4.11 Trust + safety                          ~4-6 weeks
M4.12 M4 closure                              ~2-3 weeks

Total M4 envelope: ~80-115 weeks (~18-26 months solo work)
```

---

## 3. Sequencing principles (preliminary)

Until M3 closes, M4 sequencing remains flexible. Likely priorities:

1. **Revenue accelerators first** — BNPL, saved payment methods, promotions, gift cards
2. **Retention drivers second** — loyalty program, recommendations v2, personalization
3. **Operational scaling third** — vendor tools, fraud detection, multi-currency
4. **Growth experiments fourth** — A/B testing infrastructure, affiliate program, advanced analytics

---

## 4. Cross-cutting concerns

**These apply to every M4 feature:**

- Same quality gates as M3 (Playwright + Chromatic + axe + phpunit + phpstan + tsc baselines)
- Same per-phase approval gate structure
- Same per-commit status snapshot format
- Same migration discipline for any feature touching live data
- All features ship with full i18n (English + Arabic)
- All features WCAG AA accessible
- All features documented in runbook + device-test checklist

---

## 5. Non-goals for M4 (explicitly NOT M4 even though they might come up)

- Stripe / Tap / other regional payment gateways (Noon is the M3+M4 gateway)
- WebSocket-based realtime chat (M3 + M4 use polling)
- Formal PCI compliance audit (separate M5 initiative)
- Performance optimization beyond "no regression" (separate M5 initiative)
- 3D product visualization
- AR try-on
- Subscription products (recurring orders)
- Wholesale / B2B pricing tiers
- Custom domain per vendor (white-label)

---

## 6. Approval

This M4 plan document is a **planning artifact only**. No M4 execution approval is sought now. Approval gate sequence:

1. M3 closes (M3.4 declared complete + closure runbook signed off)
2. M4.0 re-planning phase produces a refined master plan reflecting actual M3 learnings
3. Per-phase approval gates apply within M4 same as M3

✅ This document captures Tier 3 scope explicitly so it's not lost between milestones.
