# M3.2 — Consolidated Operator Playbook

**Purpose:** Single staging-then-production runbook for the 10 pending operator follow-ups across M3.2.X.1 through M3.2.X.8 (including X.5 audit-script run).
**Status:** ⏳ Awaiting operator execution
**Estimated operator effort:** ~3-4 hours staging + ~1-2 hours production (excluding the 7-day shadow window for X.1-C-FLIP)
**Last updated:** Monday, May 18, 2026 (Pass 1 of "finish Stream X" — X.5 closed via observability + audit-script approach; X.9 + X.16 scoped out per product decisions; see `stream-x-scope-revision.md`)
**Maintained alongside:** The 9 per-phase closure runbooks remain the canonical detail; this playbook is the execution-time index.

## 🎯 Quick-decision summary

> All 7 phases below sit at "code complete" awaiting operator action. They are independent of each other except where noted below. The recommended order minimizes risk by:
>
> 1. Running migrations first (data-shape changes are reversible code-only; defer to last only if a phase is risky)
> 2. Doing observability + lifecycle phases (X.4, X.6) before consumer-flip phases (X.1-C-FLIP, X.3 verification) so we have monitoring + lifecycle gates in place when traffic shifts
> 3. Deferring X.5 (Dispute eventType) and X.1-C-FLIP (shadow window) — they require time + external triggers
>
> **Pre-flight checks (do first):** §1
> **Staging execution:** §2 — work through this entire section first; verify each step before moving to the next
> **Production execution:** §3 — only after staging clean for 24h
> **Operator-driven deferred items:** §4

## 1. Pre-flight (one-time, do once before §2)

```bash
# Confirm you're on main and up to date
cd ~/3bayti && git checkout main && git pull

# Confirm HEAD is at or past M3.2.X.6-E
git log --oneline -1
# Expected: ea6c629 (or later) M3.2.X.6-E — Closure ...

# Confirm staging API is reachable + admin JWT is available
export STAGING_API="https://staging-api-v3.3bayti.ae"
export ADMIN_JWT="<paste sandbox admin JWT>"
export USER_JWT="<paste sandbox regular user JWT>"

# Sanity check: API responds to a public endpoint
curl -s $STAGING_API/v3/categories | jq '.[0].slug'
# Expected: a category slug string
```

## 2. Staging execution — work through in order

### 2.A — Deploy code to staging

```bash
# Push to main triggers the api.yml workflow (deploy to staging)
# Confirm the deploy in your CI/CD dashboard before proceeding
```

- [ ] Latest main commit has reached staging
- [ ] Health endpoint responds 200: `curl -sf $STAGING_API/v3/health`

### 2.B — Run migrations (5 new ones)

Five migrations were added across M3.2.X.2, M3.2.X.4, M3.2.X.6, M3.2.X.7, M3.2.X.8. Run them in chronological order:

```bash
# Run all pending migrations
cd ~/3bayti/apps/api && composer migrate
# Expected: 5 migrations applied:
#   Version20260516000001 (M3.2.X.2 — featured vendors column)
#   Version20260516000002 (M3.2.X.4 — notification_logs table)
#   Version20260517000001 (M3.2.X.6 — vendor lifecycle status)
#   Version20260518000001 (M3.2.X.7 — vendor preferred_locale)
#   Version20260518000002 (M3.2.X.8 — promo_codes + promo_redemptions tables)
```

- [ ] All 5 migrations applied without error

### 2.C — Verify schema (psql connect to staging DB)

```bash
psql $STAGING_DB_URL -c "\d vendors" | grep -E "is_featured|status|status_changed_at|status_reason|preferred_locale"
# Expected: 5 rows showing the new columns (4 from X.2+X.6, 1 from X.7)

psql $STAGING_DB_URL -c "\dt notification_logs"
# Expected: table exists

psql $STAGING_DB_URL -c "\d notification_logs" | grep -E "order_id|template|status|error_kind"
# Expected: 4 rows showing key columns

psql $STAGING_DB_URL -c "\d+ vendors" | grep -E "chk_vendors_status|idx_vendors_status_owner"
# Expected: CHECK constraint + composite index present

# M3.2.X.8 — promo tables
psql $STAGING_DB_URL -c "\dt promo_codes" | head -3
# Expected: table exists
psql $STAGING_DB_URL -c "\dt promo_redemptions" | head -3
# Expected: table exists
psql $STAGING_DB_URL -c "\d promo_codes" | grep -E "code|discount_type|discount_value|valid_from|valid_until"
# Expected: 5 rows showing key columns
psql $STAGING_DB_URL -c "\d orders" | grep promo_redemption_id
# Expected: 1 row (nullable FK)
```

- [ ] vendors table has 5 new columns from X.2 + X.6 + X.7
- [ ] notification_logs table exists with 6 indexes
- [ ] CHECK constraint chk_vendors_status present
- [ ] Composite index idx_vendors_status_owner present
- [ ] promo_codes table exists with CHECK on discount_type + functional UNIQUE on UPPER(code)
- [ ] promo_redemptions table exists with UNIQUE on order_id
- [ ] orders.promo_redemption_id nullable FK present

### 2.D — Review M3.2.X.6 backfill results

The migration backfilled existing vendors per the Q-Backfill=A heuristic. Review the results:

```sql
-- 2.D.1: See how vendors got categorized
SELECT status, COUNT(*) AS count FROM vendors GROUP BY status ORDER BY status;
```

- [ ] Counts look sensible — most vendors should be `approved`; a few `pending`; the suspended count should be low

```sql
-- 2.D.2: Vendors mapped to 'suspended' (is_store_approved=true AND is_active=false).
-- These were soft-deleted post-approval. Operator decision needed:
--   - Hard-removed cases (truly gone) — status doesn't matter, leave as-is
--   - Mistakenly soft-deleted — reactivate via POST /v3/admin/vendors/{id}/reactivate
--   - Intentional suspension — leave as suspended
SELECT id, slug, name, status_changed_at FROM vendors
  WHERE status = 'suspended'
  ORDER BY created_at;
```

- [ ] Reviewed each suspended row; decided action (no-op / reactivate / leave)

```sql
-- 2.D.3: 'pending' vendors with live products — these have customer-visible
-- product listings but never got approved. Likely candidates for retroactive
-- approval if they're legitimate vendors.
SELECT v.id, v.slug, v.name, COUNT(p.id) AS product_count
  FROM vendors v
  LEFT JOIN products p ON p.vendor_id = v.id AND p.is_active = TRUE
  WHERE v.status = 'pending'
  GROUP BY v.id, v.slug, v.name
  HAVING COUNT(p.id) > 0
  ORDER BY product_count DESC;
```

- [ ] Reviewed each pending-with-products row; decided action (approve / leave pending)

### 2.E — Apply M3.2.X.6 corrective actions from §2.D

For any vendor identified in §2.D as needing transition, use the admin endpoints:

```bash
# Approve a backfilled pending vendor that should be approved
curl -X POST -H "Authorization: Bearer $ADMIN_JWT" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Retroactive approval — live products, backfill heuristic missed"}' \
  $STAGING_API/v3/admin/vendors/<vendor_id>/approve

# Reactivate a mistakenly-soft-deleted vendor
curl -X POST -H "Authorization: Bearer $ADMIN_JWT" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Backfill review — vendor was soft-deleted in error"}' \
  $STAGING_API/v3/admin/vendors/<vendor_id>/reactivate
```

- [ ] All identified corrections applied on staging

### 2.F — M3.2.X.2 — Flag featured vendors

The Designer Spotlight surface needs at least 3-4 featured vendors flagged to demonstrate the feature on staging.

```bash
# Pick 3-4 approved vendors with strong product catalogs; flag them
# (Use PUT to set is_featured=true)
curl -X PUT -H "Authorization: Bearer $ADMIN_JWT" \
  -H "Content-Type: application/json" \
  -d '{"name":"<existing name>","contact_email":"<existing email>","is_featured":true}' \
  $STAGING_API/v3/admin/vendors/<vendor_id>

# Verify
curl -s $STAGING_API/v3/featured-vendors | jq '.data | length'
# Expected: 3-4 (matching what you flagged)
```

- [ ] 3-4 vendors flagged as featured
- [ ] `/v3/featured-vendors` returns the flagged ones with product info

### 2.G — M3.2.X.4 — Smoke-test notification_logs

Trigger a notification flow (complete a sandbox order) and verify the row appears in `notification_logs`.

```bash
# 1. Place a sandbox order via the normal checkout flow
#    (use the staging mobile or web client; out of scope for this curl
#     example — depends on Noon sandbox state per M3.2.X.1.5)

# 2. After the order completes, verify notification rows appear
psql $STAGING_DB_URL -c "
  SELECT id, order_id, template, recipient, status, error_kind, sent_at
    FROM notification_logs
    ORDER BY id DESC LIMIT 10;
"
# Expected: rows for order.placed.customer + order.placed.vendor templates

# 3. Smoke-test the admin endpoint with each filter
curl -s -H "Authorization: Bearer $ADMIN_JWT" \
  "$STAGING_API/v3/admin/notification-logs?limit=10" | jq '.meta'

curl -s -H "Authorization: Bearer $ADMIN_JWT" \
  "$STAGING_API/v3/admin/notification-logs?status=sent" | jq '.meta.total'

curl -s -H "Authorization: Bearer $ADMIN_JWT" \
  "$STAGING_API/v3/admin/notification-logs?status=failed" | jq '.meta.total'

# Invalid filter should 422
curl -s -o /dev/null -w "%{http_code}\n" \
  -H "Authorization: Bearer $ADMIN_JWT" \
  "$STAGING_API/v3/admin/notification-logs?status=bogus"
# Expected: 422

# 4. Verify the audit log captured the admin reads
psql $STAGING_DB_URL -c "
  SELECT actor_id, changes->'context' AS context, created_at
    FROM audit_log
    WHERE action = 'viewed'
      AND changes->>'context' = '\"admin_notification_logs_list\"'
    ORDER BY created_at DESC LIMIT 5;
"
# Expected: rows for each smoke-test curl above
```

- [ ] Notification rows appear after sandbox order
- [ ] Admin endpoint smoke tests pass (200 + 422)
- [ ] Audit log entries present

### 2.H — M3.2.X.6 — Smoke-test lifecycle transitions

```bash
# Pick a pending vendor from §2.D (or create one via self-serve in §2.I)
export TEST_VENDOR_ID="<id of a pending vendor>"

# Approve
curl -X POST -H "Authorization: Bearer $ADMIN_JWT" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Smoke test — initial approval"}' \
  $STAGING_API/v3/admin/vendors/$TEST_VENDOR_ID/approve | jq '.vendor.status'
# Expected: "approved"

# Suspend
curl -X POST -H "Authorization: Bearer $ADMIN_JWT" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Smoke test — suspension"}' \
  $STAGING_API/v3/admin/vendors/$TEST_VENDOR_ID/suspend | jq '.vendor.status'
# Expected: "suspended"

# Reactivate
curl -X POST -H "Authorization: Bearer $ADMIN_JWT" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Smoke test — reactivation"}' \
  $STAGING_API/v3/admin/vendors/$TEST_VENDOR_ID/reactivate | jq '.vendor.status'
# Expected: "approved"

# Verify audit trail
psql $STAGING_DB_URL -c "
  SELECT actor_id, action, changes->'before'->'status' AS before_status,
         changes->'after'->'status' AS after_status,
         changes->'after'->'status_reason' AS reason
    FROM audit_log
    WHERE subject_type = 'Vendor'
      AND subject_id = $TEST_VENDOR_ID
    ORDER BY created_at DESC LIMIT 3;
"
# Expected: 3 rows showing the approve → suspend → reactivate transitions
```

- [ ] Approve transition succeeded
- [ ] Suspend transition succeeded
- [ ] Reactivate transition succeeded
- [ ] Audit trail captured all 3 transitions with reasons

### 2.I — M3.2.X.6 — Smoke-test self-serve onboarding

```bash
# As a regular user (not yet a vendor), submit onboarding
curl -X POST -H "Authorization: Bearer $USER_JWT" \
  -H "Content-Type: application/json" \
  -d '{
    "slug": "smoke-test-onboarding",
    "name": "Smoke Test Onboarding",
    "contact_email": "smoke@test.example"
  }' \
  $STAGING_API/v3/vendor/onboarding/submit | jq '.vendor.status'
# Expected: "pending"

# Same user can now check their onboarding status
curl -s -H "Authorization: Bearer $USER_JWT" \
  $STAGING_API/v3/vendor/onboarding/status | jq '.vendors[0].status'
# Expected: "pending"

# Verify the lifecycle gate blocks pending vendor from /v3/vendor/orders
curl -s -o /dev/null -w "%{http_code}\n" \
  -H "Authorization: Bearer $USER_JWT" \
  $STAGING_API/v3/vendor/orders
# Expected: 403

curl -s -H "Authorization: Bearer $USER_JWT" \
  $STAGING_API/v3/vendor/orders | jq '.error.code'
# Expected: "VENDOR_NOT_APPROVED"

# Approve the newly-submitted vendor via admin endpoint
# (Get the vendor_id from the submit response)
curl -X POST -H "Authorization: Bearer $ADMIN_JWT" \
  -H "Content-Type: application/json" \
  -d '{"reason":"Smoke test — approve self-served vendor"}' \
  $STAGING_API/v3/admin/vendors/<new_vendor_id>/approve

# Now the user can hit /v3/vendor/orders (lifecycle gate passes)
curl -s -o /dev/null -w "%{http_code}\n" \
  -H "Authorization: Bearer $USER_JWT" \
  $STAGING_API/v3/vendor/orders
# Expected: 200 (empty list — vendor has no orders yet, but endpoint accessible)
```

- [ ] Self-serve submit creates pending vendor + flips is_vendor
- [ ] Status endpoint accessible to pending vendor
- [ ] Lifecycle gate blocks pending vendor with `VENDOR_NOT_APPROVED`
- [ ] After admin approval, vendor can access /v3/vendor/* endpoints

### 2.J — M3.2.X.3 — Verify category rendering

```bash
# Verify the apps/web /category/:slug pages render correctly
# (visit in a browser; out of scope for curl)

# Sanity check: backend endpoint serves category detail
curl -s "$STAGING_API/v3/categories/<some-slug>" | jq '.category.slug'
# Expected: the slug you queried
```

- [ ] At least one category renders correctly on staging web UI

### 2.K — M3.2.X.1.5 — Verify Noon payment flow

```bash
# Complete a sandbox checkout end-to-end
# (out of scope for curl — use the staging web/mobile client + Noon sandbox)
```

- [ ] At least one full checkout → payment → order confirmation flow completes successfully on staging

### 2.L — M3.2.X.7 — Verify Arabic email locale routing

Migration `Version20260518000001` was applied in §2.B. Verify the column + smoke-test the routing.

```bash
# Verify the new column + constraint
psql $STAGING_DB_URL -c "\d vendors" | grep -E "preferred_locale|chk_vendors_preferred_locale"
# Expected: column + CHECK constraint present
```

```bash
# Smoke-test customer-side Arabic email:
# 1. Set a test user's locale to 'ar' via their existing profile endpoint
curl -X PATCH -H "Authorization: Bearer $USER_JWT" \
  -H "Content-Type: application/json" \
  -d '{"locale": "ar"}' \
  $STAGING_API/v3/me/profile

# 2. Place a sandbox order as that user (via staging web/mobile)
# 3. Verify the order-placed email is in Arabic
#    (check ZeptoMail dashboard or inbox)
```

```bash
# Smoke-test vendor-side Arabic email:
# 1. Set a sandbox vendor's preferred_locale to 'ar'
curl -X PUT -H "Authorization: Bearer $ADMIN_JWT" \
  -H "Content-Type: application/json" \
  -d '{"name":"<existing>","contact_email":"<existing>","preferred_locale":"ar"}' \
  $STAGING_API/v3/admin/vendors/<vendor_id>

# 2. Place a sandbox order that includes products from that vendor
# 3. Verify the vendor-facing order-placed email is in Arabic
```

```bash
# Verify admin emails STAY English:
# 1. Trigger a sandbox dispute (via Noon sandbox console)
# 2. Confirm the ops@3bayti.ae dispute alert email is ENGLISH
#    regardless of any other Arabic preferences in the test data
```

```sql
-- Existing user impact check: count users already on Arabic
-- (these will start receiving Arabic emails the moment X.7 deploys)
SELECT locale, COUNT(*) FROM users GROUP BY locale ORDER BY locale;
```

- [ ] Schema verified
- [ ] Customer-side Arabic email smoke test passed
- [ ] Vendor-side Arabic email smoke test passed
- [ ] Admin dispute email verified English
- [ ] Existing Arabic-locale user count reviewed; downstream communication decided
- [ ] Native Arabic reviewer pass scheduled (see `m3.2.x.7-completion.md` §Native reviewer pass; ~1-2 hours)

### 2.M — M3.2.X.8 — Smoke-test promo code engine

The M3.2.X.8 migration `Version20260518000002` was run as part of §2.B. This step verifies the promo code engine end-to-end on staging: admin CRUD + customer quote + checkout-with-promo + rejection paths + legacy deprecation header.

**Pre-condition:** `Version20260518000002` has been applied. Verify:

```bash
psql $STAGING_DB_URL -c "\d promo_codes" | head -5
# Expected: table exists with code, discount_type, discount_value, ... columns
psql $STAGING_DB_URL -c "\d promo_redemptions" | head -5
# Expected: table exists with order_id (UNIQUE) FK to orders
psql $STAGING_DB_URL -c "SELECT column_name FROM information_schema.columns WHERE table_name='orders' AND column_name='promo_redemption_id';"
# Expected: 1 row
```

**Step 1 — Seed a sample promo:**

```bash
curl -X POST $STAGING_API/v3/admin/promo-codes \
  -H "Authorization: Bearer $ADMIN_JWT" \
  -H "Content-Type: application/json" \
  -d '{
    "code": "STAGING10",
    "description": "Staging smoke test — 10% off",
    "discount_type": "percentage",
    "discount_value": "10.00",
    "min_subtotal": "50.00",
    "usage_limit_per_user": 1,
    "is_active": true
  }' | jq '.data.id, .data.code, .data.currently_time_valid'
# Expected: <id>, "STAGING10", true
export PROMO_ID=<id from above>
```

**Step 2 — Admin CRUD round-trip:**

```bash
# List filter
curl -sf "$STAGING_API/v3/admin/promo-codes?is_active=true" -H "Authorization: Bearer $ADMIN_JWT" | jq '.meta.total'
# Expected: >= 1

# Get by id with redemption count
curl -sf $STAGING_API/v3/admin/promo-codes/$PROMO_ID -H "Authorization: Bearer $ADMIN_JWT" | jq '.data.redemption_count'
# Expected: 0

# Update — partial
curl -X PUT $STAGING_API/v3/admin/promo-codes/$PROMO_ID \
  -H "Authorization: Bearer $ADMIN_JWT" \
  -H "Content-Type: application/json" \
  -d '{"description": "Updated description"}' | jq '.data.description'
# Expected: "Updated description"
```

**Step 3 — Customer quote endpoint (no order created):**

```bash
# Add an item to the user's cart first (subtotal >= 50.00 for STAGING10)
# ... use existing POST /v3/cart/items flow ...

# Then quote
curl -X POST $STAGING_API/v3/cart/quote \
  -H "Authorization: Bearer $USER_JWT" \
  -H "Content-Type: application/json" \
  -d '{"promo_code": "STAGING10"}' | jq '.data | {discount, total, applied_promo}'
# Expected: discount > 0, total = subtotal - discount, applied_promo.code = "STAGING10"
```

**Step 4 — Checkout with promo (server-authoritative):**

```bash
# Initiate checkout WITH the promo code
curl -X POST $STAGING_API/v3/checkout/initiate \
  -H "Authorization: Bearer $USER_JWT" \
  -H "Content-Type: application/json" \
  -d '{"promo_code": "STAGING10"}' \
  -i | head -20
# Expected: HTTP/1.1 200
# Expected: NO X-Bayti-Deprecation header on this response
# Expected JSON body: checkout_url + order_reference

# After completing the Noon sandbox flow, verify redemption persisted
curl -sf $STAGING_API/v3/admin/promo-codes/$PROMO_ID -H "Authorization: Bearer $ADMIN_JWT" | jq '.data.redemption_count'
# Expected: 1
```

**Step 5 — Verify applied_promo on the order:**

```bash
# Fetch the order detail (assuming order_id from the checkout response)
curl -sf $STAGING_API/v3/orders/$ORDER_ID -H "Authorization: Bearer $USER_JWT" | jq '.applied_promo'
# Expected: { code: "STAGING10", discount_type: "percentage", discount_value: "10.00", discount_amount: "<computed>", redeemed_at: "..." }
```

**Step 6 — Rejection paths:**

```bash
# Unknown code
curl -X POST $STAGING_API/v3/cart/quote \
  -H "Authorization: Bearer $USER_JWT" \
  -H "Content-Type: application/json" \
  -d '{"promo_code": "NOPE"}' | jq '.error.code'
# Expected: "PROMO_NOT_FOUND"

# Per-user limit reached (the seeded code has usage_limit_per_user=1; we just used it)
curl -X POST $STAGING_API/v3/cart/quote \
  -H "Authorization: Bearer $USER_JWT" \
  -H "Content-Type: application/json" \
  -d '{"promo_code": "STAGING10"}' | jq '.error.code'
# Expected: "PROMO_USER_LIMIT_REACHED"
```

**Step 7 — Legacy deprecation header:**

```bash
# Old-style request with raw `discount` field, NO promo_code
curl -i -X POST $STAGING_API/v3/checkout/initiate \
  -H "Authorization: Bearer $USER_JWT" \
  -H "Content-Type: application/json" \
  -d '{"discount": "5.00"}' 2>&1 | grep -i 'x-bayti-deprecation'
# Expected: X-Bayti-Deprecation: client-supplied discount accepted as-is; pass promo_code instead
```

**Step 8 — Soft-delete an in-use code:**

```bash
# The code has 1 redemption now → soft delete (is_active=false), not hard delete
curl -X DELETE $STAGING_API/v3/admin/promo-codes/$PROMO_ID -H "Authorization: Bearer $ADMIN_JWT" -i | head -1
# Expected: HTTP/1.1 204

# Verify still present but inactive
curl -sf $STAGING_API/v3/admin/promo-codes/$PROMO_ID -H "Authorization: Bearer $ADMIN_JWT" | jq '.data.is_active, .data.redemption_count'
# Expected: false, 1
```

- [ ] Sample promo created via admin POST
- [ ] Admin list + get + update CRUD round-trip works
- [ ] Customer `POST /v3/cart/quote` returns price breakdown with discount + applied_promo block
- [ ] `POST /v3/checkout/initiate` with promo_code creates order with the server-computed discount + persists redemption row
- [ ] Order detail surfaces `applied_promo` block from snapshot fields
- [ ] PROMO_NOT_FOUND + PROMO_USER_LIMIT_REACHED rejection paths return structured 422
- [ ] Legacy raw-discount request emits `X-Bayti-Deprecation` header
- [ ] Soft-delete on in-use code preserves the row with is_active=false

### 2.N — M3.2.X.18 — Smoke-test Returns request flow

```bash
# 1. Migration is `Version20260518000003` — already applied in §2.B if
#    rolled forward from a clean baseline. Verify the 4 tables exist:
psql "$STAGING_DSN" -c "\dt order_return_request*; \dt order_return_refunds"
# Expect: order_return_requests, order_return_request_items,
#         order_return_request_photos, order_return_refunds

# 2. Configure photo storage. Local-filesystem on staging is fine; prod
#    SHOULD point to R2/S3 (env vars added in X.18-B):
#    RETURN_PHOTO_STORAGE_DRIVER=local   (or 's3' for prod)
#    RETURN_PHOTO_STORAGE_PATH=apps/api/var/uploads/return-photos  (local)
#    RETURN_PHOTO_STORAGE_S3_KEY=...     RETURN_PHOTO_STORAGE_S3_SECRET=...
#    RETURN_PHOTO_STORAGE_S3_REGION=auto RETURN_PHOTO_STORAGE_S3_BUCKET=...
#    RETURN_PHOTO_STORAGE_S3_ENDPOINT=...  (R2: https://<account>.r2.cloudflarestorage.com)
# Ensure the local directory exists and is writable by the web user:
mkdir -p apps/api/var/uploads/return-photos
chmod 775 apps/api/var/uploads/return-photos

# 3. End-to-end smoke test through the 5-state lifecycle.
#    Pick a delivered order from a test customer < 14 days old (paid_at):
ORDER_ID=...                            # delivered test order
CUSTOMER_TOKEN=...                      # JWT from /v3/auth/login as the customer
ADMIN_TOKEN=...                         # JWT from /v3/auth/login as admin
VENDOR_TOKEN=...                        # JWT from /v3/auth/login as the order's vendor user

# 3a. Customer submits return with photo
curl -X POST "$STAGING_BASE/v3/orders/$ORDER_ID/returns" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" \
  -F "reason=defective" \
  -F "customer_notes=Item broke on day two of use" \
  -F "order_item_ids[]=<item-id>" \
  -F "photos[]=@/path/to/evidence.jpg;type=image/jpeg"
# Expect 201 with { data: { id, status: 'pending', photos: [...] } }
RETURN_ID=...

# 3b. Verify customer + vendor + admin notification rows landed
psql "$STAGING_DSN" -c "SELECT template, status, recipient
                       FROM notification_logs
                       WHERE template LIKE 'return.submitted.%'
                       ORDER BY id DESC LIMIT 5;"
# Expect 3 rows: return.submitted.customer, .vendor, .admin

# 3c. Photo served (auth-gated)
curl -i "$STAGING_BASE/v3/returns/$RETURN_ID/photos/<photo-id>" \
  -H "Authorization: Bearer $CUSTOMER_TOKEN" | head -5
# Expect 200 with Content-Type: image/jpeg

# 3d. Same photo, stranger token → 404 (existence-leak prevention)
curl -i "$STAGING_BASE/v3/returns/$RETURN_ID/photos/<photo-id>" \
  -H "Authorization: Bearer <stranger token>" | head -5
# Expect 404

# 3e. Admin views return + sees suggested_refund_amount
curl "$STAGING_BASE/v3/admin/returns/$RETURN_ID" \
  -H "Authorization: Bearer $ADMIN_TOKEN"
# Expect 200 with data.suggested_refund_amount populated

# 3f. Admin approves
curl -X POST "$STAGING_BASE/v3/admin/returns/$RETURN_ID/approve" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"admin_notes": "Photo evidence clear"}'
# Expect status flipped to 'approved'; new notification row 'return.approved.customer'

# 3g. Admin marks picked up (logistics did the physical pickup)
curl -X POST "$STAGING_BASE/v3/admin/returns/$RETURN_ID/mark-picked-up" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -d '{}'
# Expect status flipped to 'picked_up'; notification 'return.picked_up.customer'

# 3h. Vendor confirms physical receipt
curl -X POST "$STAGING_BASE/v3/vendor/returns/$RETURN_ID/confirm-receipt" \
  -H "Authorization: Bearer $VENDOR_TOKEN" -d '{}'
# Expect status flipped to 'delivered_to_vendor';
# notification 'return.received_by_vendor.customer'

# 3i. Admin records the manual refund
curl -X POST "$STAGING_BASE/v3/admin/returns/$RETURN_ID/record-refund" \
  -H "Authorization: Bearer $ADMIN_TOKEN" -H "Content-Type: application/json" \
  -d '{"method": "bank_transfer", "amount": "90.00",
       "reference": "STAGING-TEST", "notes": "End-to-end smoke"}'
# Expect status='refunded' (terminal); notification 'return.refunded.customer'

# 4. Arabic locale routing — repeat 3a as an Arabic-locale customer and
#    verify the customer email lands in MSA (subject starts with
#    'تم استلام طلب إرجاع'). Admin notification SHOULD STAY English
#    (Q-VendorAdminLocale = A locked).

# 5. Photo storage cleanup — orphan-photo-cleanup cron is not yet
#    wired into systemd/Kubernetes (operator follow-up). The pattern
#    is documented in apps/api/bin/sweep-orphan-return-photos.php
#    (TODO: write this script as part of the operator follow-up
#    backlog). Schedule it nightly with:
#    0 3 * * * /usr/local/bin/php /var/www/3bayti/apps/api/bin/sweep-orphan-return-photos.php
#    The script should walk apps/api/var/uploads/return-photos/ and
#    DELETE any file whose path isn't referenced in
#    order_return_request_photos.storage_path, with a 7-day grace
#    period (don't delete files less than 7 days old to avoid
#    racing in-flight uploads).
```

**Smoke-test acceptance**

- [ ] 4 tables present in DB (`order_return_requests`, `order_return_request_items`, `order_return_request_photos`, `order_return_refunds`)
- [ ] Photo upload directory exists + writable
- [ ] Customer submit returns 201 with photo metadata in `data.photos[]`
- [ ] Notification logs row count = 3 after submit (customer + vendor + admin)
- [ ] Photo serve returns the bytes with correct Content-Type for the order's customer
- [ ] Photo serve returns 404 for an unrelated user (existence-leak prevention)
- [ ] Admin detail endpoint surfaces `suggested_refund_amount`
- [ ] Full lifecycle traversal: pending → approved → picked_up → delivered_to_vendor → refunded
- [ ] Notification email fires on every transition
- [ ] Arabic locale customer receives Arabic subject; admin notification stays English
- [ ] Orphan-photo-cleanup cron documented (operator follow-up to wire the script)

**Operator deferred items added by X.18:**

11. **Photo storage cron** — write + schedule `bin/sweep-orphan-return-photos.php` to delete photo files orphaned by failed submissions (DB transaction rollback after blob upload). 7-day grace period.
12. **Photo backup strategy** — if storage driver is local, configure off-host backup (rsync to backup VPS, or move to R2 once volume warrants). Photos are legal evidence of return-eligibility decisions; data loss has compliance + dispute consequences.
13. **Eligibility window override mechanism** — current 14-day window is constant `DEFAULT_WINDOW_DAYS` in `ReturnRequestEligibilityService`. Future enhancement: per-vendor + per-category overrides via admin. Out of scope for X.18; tracked here.
14. **Vendor portal mock-up** — the vendor return endpoints exist (X.18-E) but the vendor portal UI is M4-Y-side work; until then, vendors interact via direct API calls or 3bayti ops relays.

## 3. Production execution

**Pre-condition: §2 staging items 2.A through 2.N complete and staging has been stable for ≥24 hours with no regressions.**

### 3.A — Deploy code to production

```bash
# Trigger production deploy via your CI/CD process (depends on workflow setup;
# typically a manual approval gate on the main branch's deploy pipeline)
```

- [ ] Production deploy completed
- [ ] Production health endpoint responds 200

### 3.B — Run migrations on production

```bash
# Use production DB credentials
cd ~/3bayti/apps/api && composer migrate
# Expected: same 5 migrations applied
```

- [ ] All 5 migrations applied on production without error

### 3.C — Verify production schema

Run the same `\d` commands from §2.C against the production DB.

- [ ] vendors table has all new columns (is_featured, status, status_changed_at, status_reason, preferred_locale)
- [ ] notification_logs table exists
- [ ] promo_codes + promo_redemptions tables exist
- [ ] orders.promo_redemption_id FK present
- [ ] All constraints + indexes present

### 3.D — Production backfill review

Run the same SQL from §2.D against production data. Production has MORE vendors than staging — expect 2-3x the volume.

- [ ] Reviewed status distribution on production
- [ ] Reviewed suspended vendors (decided action for each)
- [ ] Reviewed pending-with-products vendors (decided action for each)

### 3.E — Apply production corrective actions

Same as §2.E but against production. Use a fresh admin JWT for production.

- [ ] All identified production corrections applied

### 3.F — Production smoke tests

Repeat §2.F through §2.M against the production API (limited to read-only smoke tests + 1-2 controlled write tests if safe to do so). DO NOT create test vendors, test orders, or test promo codes on production unless you have a cleanup plan.

For M3.2.X.8 specifically: production smoke can be reduced to (a) one admin-side `POST /v3/admin/promo-codes` + `DELETE` round-trip for a code prefixed `PRODSMOKE-` (hard-deletes since zero redemptions), (b) one `POST /v3/cart/quote` against a real user cart with an inactive sentinel code expecting 422, (c) verifying no `X-Bayti-Deprecation` header appears on the standard mobile build's checkout-initiate requests in APM. Skip the full happy-path checkout-with-promo flow on production — the staging smoke already proves the engine works end-to-end.

- [ ] Admin endpoint smoke tests pass on production
- [ ] Lifecycle gate verified with at least one production vendor
- [ ] Audit log entries appearing
- [ ] M3.2.X.8: admin promo CRUD round-trip + customer rejection-path smoke clean on production
- [ ] M3.2.X.8: no `X-Bayti-Deprecation` header observed in standard mobile build traffic (confirms no consumer still on the legacy raw-discount path)

## 4. Deferred items — operator-driven, may take days/weeks

These items are time-dependent or require external triggers that can't happen in a single playbook session.

### 4.A — M3.2.X.1-C-FLIP — 7-day shadow window

**Status:** ⏳ Awaiting shadow data accumulation

The `best_sellers` endpoint shipped in M3.2.X.1 ran in "shadow mode" since deploy — the new query runs alongside the old one for 7 days; metrics are captured but only the old query's results are returned. After 7 days, operator flips `target='old'→'new'`.

**Operator actions:**

```bash
# Monitor shadow metrics in your APM dashboard for 7 days
# Verify the new query: (1) returns same products as old within tolerance,
#                       (2) has acceptable latency, (3) hasn't errored

# After 7 days clean, flip the target
# (Specific flip mechanism: config flag, env var, or migration — see
#  docs/runbooks/m3.2/m3.2.x.1-completion.md for the canonical method)
```

- [ ] 7-day shadow window completed cleanly
- [ ] `target` flipped from `old` to `new`
- [ ] Post-flip metrics monitored for 24h

### 4.B — M3.2.X.5 — Dispute eventType audit (run after staging + production deploys)

**Status:** ✅ X.5 code shipped. Operator follow-up is now a quick read-only script, not a blocking sandbox trigger.

**Background:** May 18, 2026 product decision confirmed 3bayti runs against live Noon production credentials inherited from legacy. The original X.5 plan (manual sandbox dispute trigger to capture eventType strings) was replaced with two pieces of machinery shipped in the X.5 code:

1. **Observability hook** in `NoonWebhookController` — every webhook now passes through `emitDisputeShapedWarning`. Any eventType containing `'dispute'` or `'chargeback'` substring (case-insensitive) that ISN'T in the recognized `DISPUTE_EVENT_TYPES` constant produces a `noon.webhook.unknown_dispute_event_type` warning in production logs / Sentry.
2. **Audit script** `apps/api/bin/audit-dispute-event-types.php` — read-only operator tool that enumerates every distinct event_type ever seen in `payment_webhook_events`, flags unrecognized dispute-shaped strings, prints a backfill SQL template.

**Operator actions:**

```bash
# Run the audit against staging (after staging deploy)
cd /www/wwwroot/3bayti/apps/api
/www/server/php/83/bin/php bin/audit-dispute-event-types.php

# Expected output: "Result: CLEAN — no action required." OR
# a list of unknown dispute-shaped event_type strings to triage.

# Run the audit against production (after production deploy)
# Same command, run against production DB credentials.
```

**Interpreting results:**

- **"Result: CLEAN — no action required."** → Done. Either no dispute events have arrived yet, or every dispute eventType is already in our recognized list.
- **"Result: ACTION REQUIRED — see Section 2 above."** → For each ⚠ flagged event_type string:
  1. Cross-check it against Noon's merchant portal API docs to confirm it's a real dispute eventType (not a partial substring match coincidence)
  2. If confirmed: add it to `NoonWebhookController::DISPUTE_EVENT_TYPES`, ship a one-line follow-up commit
  3. Use the backfill SQL template printed by the script to retroactively create `order_disputes` rows for any historical events that should have created them when they originally arrived

**Ongoing observability** (passive):

The warning hook means new unknown dispute eventTypes auto-surface in production. Monitor for `noon.webhook.unknown_dispute_event_type` log lines. If any appear:
1. Pull the full payload via `SELECT payload FROM payment_webhook_events WHERE idempotency_key = '...'`
2. Verify it's a real Noon dispute eventType
3. Add to constant + run the backfill template

- [ ] Audit script run against staging — result captured
- [ ] Audit script run against production — result captured
- [ ] If any unknown strings flagged: triaged + DISPUTE_EVENT_TYPES updated + backfill run

### 4.C — Branch protection + Chromatic + a11y allowlist + Mobile Playwright promotion

These are infrastructure follow-ups from M3.2.0. See `docs/runbooks/m3.2/m3.2.0-completion.md` for canonical detail.

- [ ] Chromatic operator setup completed
- [ ] a11y allowlist baseline established
- [ ] Mobile Playwright promoted from baseline → required
- [ ] Branch protection rules configured

## 5. Rollback procedures

If verification fails at ANY step in §2 or §3:

### 5.A — Code-only rollback (preferred — fastest)

```bash
# Revert the offending phase's commits in reverse order
# Find the commit chain in the per-phase runbook
cd ~/3bayti && git revert <newest-commit-of-phase>..<oldest-commit-of-phase>
git push
```

**Database columns from already-run migrations can stay** — they become unused but harmless. The application code reverts to the pre-phase behavior.

### 5.B — Migration rollback (only if code rollback isn't enough)

```bash
cd ~/3bayti/apps/api && composer migrate:down
# Doctrine reverts the most recent migration. Run repeatedly to undo multiple.
```

**Caution:** `migrate:down` is destructive. It will drop columns / tables. Only use if you're certain no downstream system depends on the data.

## 6. Per-phase canonical detail (cross-reference)

If a step in this playbook is ambiguous, the per-phase closure runbook has the canonical detail:

| Phase | Closure runbook |
|---|---|
| M3.2.0 | `docs/runbooks/m3.2/m3.2.0-completion.md` |
| M3.2.X.1 | `docs/runbooks/m3.2/m3.2.x.1-completion.md` |
| M3.2.X.1.5 | `docs/runbooks/m3.2/m3.2.x.1.5-completion.md` |
| M3.2.X.2 | `docs/runbooks/m3.2/m3.2.x.2-completion.md` |
| M3.2.X.3 | `docs/runbooks/m3.2/m3.2.x.3-completion.md` |
| M3.2.X.4 | `docs/runbooks/m3.2/m3.2.x.4-completion.md` |
| M3.2.X.5 | `docs/runbooks/m3.2/m3.2.x.5-completion.md` |
| M3.2.X.6 | `docs/runbooks/m3.2/m3.2.x.6-completion.md` |
| M3.2.X.7 | `docs/runbooks/m3.2/m3.2.x.7-completion.md` |
| M3.2.X.8 | `docs/runbooks/m3.2/m3.2.x.8-completion.md` |
| Stream X scope revision (May 18, 2026) | `docs/runbooks/m3.2/stream-x-scope-revision.md` |

## 7. Sign-off

After all §2 and §3 boxes are checked, mark this playbook complete:

- [ ] All staging items (§2) completed and verified
- [ ] All production items (§3) completed and verified
- [ ] All deferred items (§4) either completed or tracked separately
- [ ] No rollbacks executed (or rollbacks executed and root-caused)

Date completed (staging): ________________
Date completed (production): ________________
Operator name: ________________

After sign-off, the playbook is archived as a record of the rollout. Future M3.2 phases (X.9 onwards) will get their own per-phase runbooks and may eventually trigger a new consolidated playbook if multiple phases batch up again.
