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

### 2.O — M3.2.X.10 — Smoke-test Faceted search backend

```bash
# 1. No migration is needed for X.10 — the existing GIN indexes on
#    products.available_sizes + products.available_colors (added in
#    Version20260512000001) are reused. Verify they're present:
psql "$STAGING_DSN" -c "\d products" | grep -E "available_sizes|available_colors|GIN"
# Expect: products_sizes_idx (GIN), products_colors_idx (GIN)

# 2. Verify the endpoint responds. Public endpoint, no auth needed:
curl -s "$STAGING_BASE/v3/products/facets" | python3 -m json.tool | head -30
# Expect: { data: { size, color, price, vendor, category }, meta: { total_products, applied_filters } }

# 3. Smoke-test each filter axis:

# 3a. Category filter
curl -s "$STAGING_BASE/v3/products/facets?category=abayas" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('total:', d['meta']['total_products'])
print('applied:', d['meta']['applied_filters'])
print('color values:', [v['value'] for v in d['data']['color']['values'][:5]])"
# Expect: total reflects 'abayas' category; applied_filters echoes 'abayas'
# Expect: color facet counts respect category but NOT itself (disjunctive)

# 3b. Disjunctive verification — refining by color should NOT zero
#     out the color facet (it would if disjunctive wasn't working).
curl -s "$STAGING_BASE/v3/products/facets?category=abayas&colors[]=Black" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('total:', d['meta']['total_products'])
print('colors returned:', len(d['data']['color']['values']))
print('first 3:', [(v['value'], v['count']) for v in d['data']['color']['values'][:3]])"
# Expect: total drops vs 3a (filtered to Black only)
# Expect: color facet STILL shows multiple values (Black, White, ...) — disjunctive
# If you see only 1 color value matching the filter, disjunctive is BROKEN

# 3c. Size refinement via array form
curl -s "$STAGING_BASE/v3/products/facets?sizes[]=M&sizes[]=L" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('size facet:', [(v['value'], v['count']) for v in d['data']['size']['values']])
print('total:', d['meta']['total_products'])"
# Expect: total reflects products with M OR L (not AND)
# Expect: size facet shows ALL sizes (XS, S, M, L, XL, XXL) — disjunctive

# 3d. Comma-separated form alias
curl -s "$STAGING_BASE/v3/products/facets?sizes=M,L" | python3 -m json.tool | grep -A 1 '"sizes"'
# Expect: applied_filters.sizes = ['M', 'L']

# 3e. Price-band shape
curl -s "$STAGING_BASE/v3/products/facets" | python3 -c "
import json, sys
d = json.load(sys.stdin)
for b in d['data']['price']['values']:
    print(f\"{b['value']}: count={b['count']} min={b['min']} max={b['max']}\")"
# Expect: 5 bands (0-50, 50-100, 100-250, 250-500, 500+) with counts;
# bands with 0 count are suppressed per Q-Empty-Facets

# 3f. Fulltext + facets together
curl -s "$STAGING_BASE/v3/products/facets?q=evening+dress" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('matches:', d['meta']['total_products'])
print('q echoed:', d['meta']['applied_filters'].get('q'))"
# Expect: total matches the fulltext query; applied_filters.q = 'evening dress'

# 3g. Unknown slug soft-fail (200 with empty facets)
curl -s -w '\nHTTP %{http_code}\n' "$STAGING_BASE/v3/products/facets?vendor=does-not-exist" | tail -3
# Expect: HTTP 200, total_products=0, applied_filters.vendor='does-not-exist'

# 4. Performance check via PSR-3 logs. Run the same request 10x
#    and tail the app log:
for i in $(seq 1 10); do curl -s -o /dev/null "$STAGING_BASE/v3/products/facets?category=abayas"; done
tail -50 apps/api/var/logs/app-*.log | grep -E "facets\\.(computed|slow_response)" | tail -10
# Expect: 10 'facets.computed' lines with duration_ms < 100
# If you see ANY 'facets.slow_response' warnings → Q-Caching = B trigger
# (see Operator deferred item #15 below)

# 5. Verify the route ordering didn't regress GET /v3/products/{slug}.
#    The literal segment 'facets' must NOT match the slug controller.
curl -s -o /dev/null -w 'HTTP %{http_code}\n' "$STAGING_BASE/v3/products/some-real-slug"
# Expect: HTTP 200 (or 404 if slug doesn't exist) — but NOT a JSON
# response with 'facets' shape (data.size etc.)

# 6. Verify the mobile/web catalog page hasn't regressed by sending
#    a baseline product list query:
curl -s "$STAGING_BASE/v3/products?category=abayas&min_price=50&limit=12" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('items:', len(d['data']))
print('total:', d['meta']['total'])"
# Expect: same shape and counts as before X.10 (no regression on
# the existing endpoint; X.10-C refactored ProductFilterParser but
# ListProductsController was NOT migrated to it yet, so this is a
# pure sanity check)
```

**Smoke-test acceptance**

- [ ] GIN indexes on `available_sizes` + `available_colors` confirmed present
- [ ] `GET /v3/products/facets` returns the canonical envelope with all 5 facets
- [ ] Category filter narrows + applied_filters echoed
- [ ] Disjunctive semantics work: refining by color shows MULTIPLE colors in the color facet
- [ ] Size refinement: both `?sizes[]=` array form and `?sizes=A,B` comma form parsed
- [ ] Price-band shape includes `min` + `max` per band, 0-count bands suppressed
- [ ] Fulltext `?q=` works alongside facet filters
- [ ] Unknown slug returns 200 with empty data + `applied_filters` echo (NOT 404)
- [ ] PSR-3 `facets.computed` debug log fires per request with `duration_ms`
- [ ] No `facets.slow_response` warnings during normal load (or → see follow-up #15)
- [ ] `/v3/products/{slug}` slug controller NOT eaten by the new `/facets` route
- [ ] Existing `/v3/products` endpoint shape + counts unchanged

**Operator deferred items added by X.10:**

15. **Redis caching for facets** — if `facets.slow_response` warnings appear sustained in production logs, enable Q-Caching = B: 5-min Redis TTL keyed on a hash of the canonical filter shape. ~1 day of work. Trigger condition: > 10 `facets.slow_response` warnings/hour across any 4-hour window. The aggregator is already structured to support this — `FacetAggregator::compute` would gain a cache lookup at the top + cache write at the bottom; no further refactoring.
16. **ListProductsController migration to ProductFilterParser** — X.10-C extracted the shared parser but only ListFacetsController was migrated. ListProductsController still uses the old `int FILTER_NOT_FOUND = -1` sentinel pattern. Mechanical refactor; 1-2 hours; ship as a standalone commit when convenient.
17. **Brand + material facets** — Q-Facet-Set locked the v1 facets at 5 (size, color, price, vendor, category). Adding brand or material requires new Product columns + migration + UI work. Defer to M4 unless customer feedback flags it as a friction point.

### 2.P — M3.2.X.14 — Smoke-test Vendor performance metrics

```bash
# 1. No migration needed for X.14 — pure read-side aggregation over
#    existing Order/OrderItem/OrderReturnRequest/OrderDispute tables.
#    Verify those tables are populated with at least 30 days of data:
psql "$STAGING_DSN" -c "SELECT
  (SELECT COUNT(*) FROM orders WHERE paid_at >= NOW() - INTERVAL '30 days') AS recent_orders,
  (SELECT COUNT(*) FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE paid_at >= NOW() - INTERVAL '30 days')) AS recent_items,
  (SELECT COUNT(*) FROM order_return_requests WHERE status IN ('approved', 'picked_up', 'delivered_to_vendor', 'refunded')) AS approved_returns,
  (SELECT COUNT(*) FROM order_disputes) AS total_disputes;"

# 2. Single-vendor smoke-test as admin. Pick a vendor with recent
#    orders from the count above:
ADMIN_TOKEN=...                          # JWT from /v3/auth/login as admin
VENDOR_ID=...                            # vendor with 30+ days of data

curl -s "$STAGING_BASE/v3/admin/vendors/$VENDOR_ID/metrics?days=30" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -m json.tool
# Expect:
#   { data: {
#       vendor_id, vendor_slug, vendor_name,
#       window: { days: 30, since, until },
#       metrics: {
#         fulfillment_rate: { value: 0.xxx, fulfilled_items, total_items },
#         cancellation_rate: { ... },
#         return_rate: { ... },
#         dispute_rate: { value, disputed_orders, total_orders }
#       }
#     }
#   }

# 3. Custom window (e.g. 90 days) + clamp behavior
curl -s "$STAGING_BASE/v3/admin/vendors/$VENDOR_ID/metrics?days=90" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('window.days:', d['data']['window']['days'])
print('total_items:', d['data']['metrics']['fulfillment_rate']['total_items'])"
# Expect: window.days = 90 (NOT 30), total_items >= 30-day count

# Below-min clamp
curl -s "$STAGING_BASE/v3/admin/vendors/$VENDOR_ID/metrics?days=3" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
print('window.days:', json.load(sys.stdin)['data']['window']['days'])"
# Expect: window.days = 7 (clamped)

# Above-max clamp
curl -s "$STAGING_BASE/v3/admin/vendors/$VENDOR_ID/metrics?days=9999" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
print('window.days:', json.load(sys.stdin)['data']['window']['days'])"
# Expect: window.days = 365 (clamped)

# 4. List endpoint — admin sees every vendor
curl -s "$STAGING_BASE/v3/admin/vendor-metrics?days=30&limit=10" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('total vendors:', d['meta']['total'])
print('returned:', len(d['data']))
for row in d['data'][:5]:
    f = row['metrics']['fulfillment_rate']
    print(f\"  {row['vendor_slug']}: fulfillment {f['value']} ({f['fulfilled_items']}/{f['total_items']})\")"
# Expect: alphabetically-sorted vendors with their metrics

# 5. Metric-based sort: top-performers descending
curl -s "$STAGING_BASE/v3/admin/vendor-metrics?days=30&sort=fulfillment_rate_desc&limit=10" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('Top performers:')
for row in d['data']:
    f = row['metrics']['fulfillment_rate']
    print(f\"  {row['vendor_slug']}: {f['value']}\")"
# Expect: descending fulfillment rates. Vendors with null rates (no
# data) should appear LAST.

# 6. Worst-performers ascending
curl -s "$STAGING_BASE/v3/admin/vendor-metrics?days=30&sort=cancellation_rate_desc&limit=5" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
print('High-cancellation vendors:')
for row in json.load(sys.stdin)['data']:
    c = row['metrics']['cancellation_rate']
    print(f\"  {row['vendor_slug']}: {c['value']} ({c['rejected_items']}/{c['total_items']})\")"
# Expect: highest cancellation rates first; investigate any > 5%

# 7. Status filter
curl -s "$STAGING_BASE/v3/admin/vendor-metrics?status=suspended" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
print('Suspended vendors:', json.load(sys.stdin)['meta']['total'])"

# 8. Vendor self-serve view. Use a vendor user's token:
VENDOR_TOKEN=...                         # JWT from /v3/auth/login as the vendor's owner

# Single-store user (no vendor_id needed)
curl -s "$STAGING_BASE/v3/vendor/metrics" \
  -H "Authorization: Bearer $VENDOR_TOKEN" | python3 -m json.tool
# Expect: same shape as admin single-vendor endpoint, scoped to
# the calling user's vendor

# 9. Multi-store user — without vendor_id should 422
# (If your test fixture has a user with multiple approved stores)
MULTI_STORE_TOKEN=...
curl -i -s "$STAGING_BASE/v3/vendor/metrics" \
  -H "Authorization: Bearer $MULTI_STORE_TOKEN" | head -5
# Expect: HTTP 422 with error.code='VENDOR_AMBIGUOUS' +
#         error.details.available_vendor_ids = [...]

# With vendor_id specified
curl -s "$STAGING_BASE/v3/vendor/metrics?vendor_id=101" \
  -H "Authorization: Bearer $MULTI_STORE_TOKEN" | python3 -c "
import json, sys
print('vendor_id:', json.load(sys.stdin)['data']['vendor_id'])"
# Expect: 101

# Cross-tenant attempt (vendor_id not owned by caller)
curl -i -s "$STAGING_BASE/v3/vendor/metrics?vendor_id=999" \
  -H "Authorization: Bearer $VENDOR_TOKEN" | head -1
# Expect: HTTP 404 (opaque, NOT 403 — existence-leak prevention)

# 10. Performance observability check
for i in $(seq 1 10); do
    curl -s -o /dev/null "$STAGING_BASE/v3/admin/vendors/$VENDOR_ID/metrics" \
      -H "Authorization: Bearer $ADMIN_TOKEN"
done
tail -50 apps/api/var/logs/app-*.log | grep -E "vendor_metrics\\.(computed|slow_response)" | tail -15
# Expect: 10 'vendor_metrics.computed' lines with duration_ms < 200
# Any 'vendor_metrics.slow_response' warnings → operator follow-up #18 trigger

# 11. Audit emission verification
psql "$STAGING_DSN" -c "
SELECT action_type, subject_type, subject_id, changes->>'context' AS context, changes->>'window_days' AS window
FROM audit_logs
WHERE changes->>'context' LIKE 'admin_vendor_metrics%'
ORDER BY id DESC LIMIT 5;"
# Expect: VIEWED rows for both single-vendor and list endpoints
```

**Smoke-test acceptance**

- [ ] All 4 source tables have recent data (orders + items + returns + disputes)
- [ ] `GET /v3/admin/vendors/{id}/metrics` returns the canonical envelope with all 4 rates
- [ ] Window-day param: defaults to 30, clamps below-min to 7 and above-max to 365
- [ ] `GET /v3/admin/vendor-metrics` paginates correctly with vendor-field sort (name_asc default)
- [ ] Metric-field sort works (`fulfillment_rate_desc`) with vendors with null data sorted LAST
- [ ] Status filter narrows the list
- [ ] `GET /v3/vendor/metrics` works for single-store users without `vendor_id`
- [ ] Multi-store users without `vendor_id` get 422 `VENDOR_AMBIGUOUS` with the list
- [ ] Multi-store users with valid owned `vendor_id` get their chosen store's metrics
- [ ] Cross-tenant `vendor_id` (not owned) returns opaque 404 (NOT 403)
- [ ] PSR-3 `vendor_metrics.computed` debug log fires per request with `duration_ms`
- [ ] No `vendor_metrics.slow_response` warnings during normal load
- [ ] `audit_logs` table has VIEWED rows for both admin endpoints

**Operator deferred items added by X.14:**

18. **Vendor metrics cache-warming** — current list-with-metric-sort path runs `computeForVendorList` over ALL vendors (not just the page) on every request. 3 queries regardless of vendor count, but the queries scan a 30-day window. At ~500 vendors with sustained traffic this becomes a real concern. Trigger condition: `vendor_metrics.slow_response` warning rate > 5/hour during peak hours. Fix: nightly cron warms a Redis cache of `(vendor_id, days)` → metrics shape; controller falls back to live compute on cache miss. ~2 days of work.
19. **Per-item lifecycle timestamps** — X.14 ships status-derived rates only. Adding mean-time-to-accept, mean-time-to-ship requires per-item `accepted_at`/`shipped_at`/`delivered_at` columns. Migration + backfill + transition-controller updates. ~3 days of work, but only valuable if vendor performance investigation cases regularly demand timing data.
20. **Configurable cancellation taxonomy** — Q-CancellationDef = B locks "vendor-initiated rejections only" as the cancellation rate's numerator. If business analysis later wants to track "customer cancellations after vendor accept" as a separate signal, add a `cancellation_breakdown` block to the response with rejected/customer-cancelled/admin-cancelled counts. ~1 day.

### 2.Q — M3.2.X.17 — Smoke-test Admin order timeline

```bash
# 1. No migration needed — X.17 reads from existing tables only.
#    Verify the source tables have data for a fully-lifecycled order:
psql "$STAGING_DSN" -c "
SELECT
  (SELECT COUNT(*) FROM audit_log WHERE subject_type IN ('Order','OrderItem','OrderReturnRequest')) AS audit_rows,
  (SELECT COUNT(*) FROM notification_logs WHERE order_id IS NOT NULL) AS order_notifications,
  (SELECT COUNT(*) FROM order_return_requests) AS returns,
  (SELECT COUNT(*) FROM order_disputes) AS disputes;"

# 2. Pick a fully-lifecycled order for the smoke test. Ideal: order
#    with audit_log rows, customer notifications, a return request,
#    and a dispute. If staging doesn't have one organically, create
#    one by walking an order through the full lifecycle in the admin
#    UI or via API.
ORDER_ID=...

# 3. Admin timeline — full event history
ADMIN_TOKEN=...                          # JWT from /v3/auth/login as admin

curl -s "$STAGING_BASE/v3/admin/orders/$ORDER_ID/timeline" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('Total events:', d['meta']['total'])
print('Order:', d['meta']['order_reference'])
for evt in d['data'][:15]:
    actor = evt.get('actor', {})
    label = actor.get('label', actor.get('type', '?'))
    print(f\"  {evt['occurred_at']}  {evt['type']:32}  by {label}: {evt['summary']}\")"

# Expect: chronological event list (newest first) including
# order.created, order.paid, notification.sent, return.submitted,
# return.approved, dispute.created, etc.

# 4. Verify each of the 14 event types appears somewhere across
#    your fully-lifecycled order:
curl -s "$STAGING_BASE/v3/admin/orders/$ORDER_ID/timeline?limit=200" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
events = json.load(sys.stdin)['data']
types = set(e['type'] for e in events)
expected = {
    'order.created', 'order.paid', 'order.status_changed',
    'order.item_status_changed', 'notification.sent',
    'return.submitted', 'return.approved', 'return.picked_up',
    'return.received_by_vendor', 'return.refunded',
    'dispute.created'
}
print('Present:', sorted(types & expected))
print('Missing:', sorted(expected - types))
print('Unexpected:', sorted(types - expected - {'notification.failed', 'return.denied', 'return.cancelled', 'dispute.resolved'}))"

# 5. Ordering direction
curl -s "$STAGING_BASE/v3/admin/orders/$ORDER_ID/timeline?order=asc" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
events = json.load(sys.stdin)['data']
# First event should be the earliest (order.created)
print('First event type:', events[0]['type'] if events else 'empty')
print('First occurred_at:', events[0]['occurred_at'] if events else 'empty')"
# Expect: order.created first; subsequent events in chronological order

# 6. Pagination
curl -s "$STAGING_BASE/v3/admin/orders/$ORDER_ID/timeline?limit=3&offset=0" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('Returned:', len(d['data']), 'of', d['meta']['total'])"

curl -s "$STAGING_BASE/v3/admin/orders/$ORDER_ID/timeline?limit=3&offset=3" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('Returned:', len(d['data']), 'of', d['meta']['total'])"

# 7. Vendor self-serve timeline. Use a vendor user who owns items
#    in the test order:
VENDOR_TOKEN=...
curl -s "$STAGING_BASE/v3/vendor/orders/$ORDER_ID/timeline" \
  -H "Authorization: Bearer $VENDOR_TOKEN" | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('Total events:', d['meta']['total'])
for evt in d['data'][:10]:
    print(f\"  {evt['type']:30}  {evt['summary']}\")
# Verify no dispute events appear in vendor scope:
types = set(e['type'] for e in d['data'])
dispute_types = {t for t in types if t.startswith('dispute.')}
print('Dispute events visible to vendor (should be empty):', dispute_types)"
# Expect: SMALLER total than admin view; NO dispute.* events;
# audits limited to vendor's own items; notifications limited
# to vendor's email recipient.

# 8. Cross-tenant attempt (vendor accessing an order with no items
#    from their store)
OTHER_ORDER_ID=...  # an order with NO items from this vendor
curl -i -s "$STAGING_BASE/v3/vendor/orders/$OTHER_ORDER_ID/timeline" \
  -H "Authorization: Bearer $VENDOR_TOKEN" | head -1
# Expect: HTTP 404 (opaque, NOT 403 — existence-leak prevention)

# 9. Multi-store user without vendor_id (if test fixture has one)
MULTI_STORE_TOKEN=...
curl -i -s "$STAGING_BASE/v3/vendor/orders/$ORDER_ID/timeline" \
  -H "Authorization: Bearer $MULTI_STORE_TOKEN" | head -5
# Expect: HTTP 422 with error.code='VENDOR_AMBIGUOUS' +
#         error.details.available_vendor_ids = [...]

# With explicit vendor_id
curl -s "$STAGING_BASE/v3/vendor/orders/$ORDER_ID/timeline?vendor_id=101" \
  -H "Authorization: Bearer $MULTI_STORE_TOKEN" > /dev/null \
  && echo "200 OK"

# 10. Performance observability check
for i in \$(seq 1 10); do
    curl -s -o /dev/null "$STAGING_BASE/v3/admin/orders/$ORDER_ID/timeline" \
      -H "Authorization: Bearer $ADMIN_TOKEN"
done
tail -50 apps/api/var/logs/app-*.log | grep -E "order_timeline\\.(computed|slow_response)" | tail -15
# Expect: 10 'order_timeline.computed' lines with duration_ms < 300
# Any 'order_timeline.slow_response' warnings → operator follow-up #21 trigger

# 11. Audit emission verification (admin endpoint only — vendor
#     endpoint does NOT audit)
psql "$STAGING_DSN" -c "
SELECT action, subject_type, subject_id, changes->>'context' AS context,
       changes->'filters' AS filters
FROM audit_log
WHERE changes->>'context' = 'admin_order_timeline'
ORDER BY id DESC LIMIT 5;"
# Expect: VIEWED rows for the admin endpoint with the filter context;
# vendor endpoint emits NO rows here.

# 12. X.17-B audit emission verification: vendor item transitions
#     now write to audit_log. Trigger a vendor transition and check:
psql "$STAGING_DSN" -c "
SELECT id, action, subject_type, subject_id, user_id,
       changes->'before' AS before, changes->'after' AS after
FROM audit_log
WHERE subject_type = 'OrderItem'
ORDER BY id DESC LIMIT 3;"
# Expect: rows with action='updated', before.item_status and
# after.item_status populated. THIS IS NEW IN X.17-B — before this
# phase, vendor transitions emitted no audit rows.
```

**Smoke-test acceptance**

- [ ] Source tables (audit_log, notification_logs, order_return_requests, order_disputes) have data
- [ ] `GET /v3/admin/orders/{id}/timeline` returns chronological events newest-first by default
- [ ] All 14 event types appear when run against a fully-lifecycled order
- [ ] `?order=asc` reverses to oldest-first
- [ ] Pagination works (limit=3 returns 3 events; offset=3 returns the next page)
- [ ] `GET /v3/vendor/orders/{id}/timeline` returns SMALLER event count than admin view
- [ ] No `dispute.*` events visible to vendors
- [ ] Cross-tenant order access returns opaque 404
- [ ] Multi-store users without `?vendor_id` get 422 `VENDOR_AMBIGUOUS`
- [ ] PSR-3 `order_timeline.computed` debug log fires per request with `duration_ms`
- [ ] No `order_timeline.slow_response` warnings during normal load
- [ ] Admin endpoint emits `audit_log` rows with `context='admin_order_timeline'`
- [ ] Vendor endpoint does NOT emit audit rows (self-view, per spec)
- [ ] **X.17-B specific:** vendor item transitions now produce `audit_log` rows with `subject_type='OrderItem'`, `action='updated'`, and `before`/`after` snapshots of `item_status`. Previously zero. This is the gap-closure that makes vendor transitions visible in the timeline.

**Operator deferred items added by X.17:**

21. **Timeline event archival** — current implementation aggregates events live across 5 tables on every request. For very-old orders with 100+ events (typical of a long return + dispute lifecycle that drags out for months) the merge-sort + pagination overhead grows. Trigger condition: `order_timeline.slow_response` warnings > 5/hour during peak. Fix: archive timeline events into a denormalized `order_timeline_events` table populated by an async cron, with the live aggregation as a fallback for recent orders. ~3 days of work.
22. **Per-item notification attribution** — notifications today carry `order_id` but not `vendor_id`. The vendor timeline filter uses `recipient = vendor.contact_email` as a proxy for "this notification was about my items", which works for vendor-addressed emails but doesn't distinguish customer-addressed notifications by which vendor's items triggered them. Fix: add `vendor_id` column to `notification_logs` (nullable, set at send time when the notification is item-attributed). ~1 day migration + backfill + 1 day notification-emitter updates.
23. **Actor label hydration** — timeline events include actor.type and actor.id but not actor.label (a human-readable identifier like email). Adding labels requires joining user_id → users.email in the builder. Deferred because the admin UI can hydrate labels client-side from the existing /v3/admin/users surface; revisit if a "show me what alice@3bayti.ae did across all orders" feature surfaces. ~0.5 day if it's wanted.

### 2.R — M3.2.X.11 — Smoke-test Abandoned cart recovery emails

X.11 ships a new two-column migration + a new cron command + a new public unsubscribe endpoint. Smoke-test all three on staging before scheduling cron production.

```bash
# 1. Apply the migration (X.11-A two-column add)
cd apps/api && php bin/migrate.php

# 2. Verify schema:
psql "$STAGING_DSN" -c "
SELECT column_name, data_type, is_nullable, column_default
FROM information_schema.columns
WHERE (table_name='notification_logs' AND column_name='cart_id')
   OR (table_name='users' AND column_name='marketing_emails_opt_out')
ORDER BY table_name, column_name;"
# Expect:
#  notification_logs.cart_id              integer    YES   NULL
#  users.marketing_emails_opt_out         boolean    NO    false

# Verify index:
psql "$STAGING_DSN" -c "
SELECT indexname, indexdef FROM pg_indexes
WHERE tablename = 'notification_logs' AND indexname = 'idx_notification_logs_cart_id';"
# Expect: partial index with 'WHERE cart_id IS NOT NULL'

# 3. Identify an abandoned cart for testing. Staging usually has a
#    few naturally; if not, fabricate one:
psql "$STAGING_DSN" <<'SQL'
-- Find an active cart with items that's old enough
SELECT c.id, c.user_id, u.email, c.status,
       c.updated_at,
       (SELECT COUNT(*) FROM cart_items WHERE cart_id = c.id) AS item_count,
       (SELECT COUNT(*) FROM notification_logs nl
        WHERE nl.cart_id = c.id AND nl.template = 'cart.abandoned.customer') AS prior_reminders
FROM carts c
INNER JOIN users u ON u.id = c.user_id
WHERE c.status = 'active'
  AND u.email <> ''
  AND c.updated_at < NOW() - INTERVAL '24 hours'
  AND EXISTS (SELECT 1 FROM cart_items WHERE cart_id = c.id)
ORDER BY c.updated_at ASC
LIMIT 10;
SQL
# If nothing returned: artificially backdate a recent cart's updated_at:
# UPDATE carts SET updated_at = NOW() - INTERVAL '25 hours' WHERE id = <test_cart_id>;

# 4. Dry-run the cron command — lists eligible cart IDs without sending
cd apps/api
php bin/console carts:send-abandonment-reminders --dry-run
# Expect output:
#  Sending abandoned cart reminders (threshold 24h, batch 100) [DRY RUN]
#  Found <N> eligible cart(s).
#  Eligible cart IDs (no emails sent):
#    <comma-separated list>
#  [DRY RUN] N cart(s) would be processed.

# 5. Try non-default options to verify clamping:
php bin/console carts:send-abandonment-reminders --dry-run --threshold-hours=9999
# threshold gets clamped to 168 (one week) — should see "(threshold 168h, ...)"

php bin/console carts:send-abandonment-reminders --dry-run --threshold-hours=0
# clamped to 1 — "(threshold 1h, ...)" — likely Found 0 (carts updated <1h ago are rare)

# 6. Real run (sends emails). Cap with small batch first:
php bin/console carts:send-abandonment-reminders --batch-size=1
# Expect:
#  Found 1 eligible cart(s).
#  Summary table:
#    Found     | 1
#    Processed | 1
#    Errors    | 0

# 7. Verify the email landed (check inbox of the test user or the
#    ZeptoMail dashboard). Body MUST contain:
#    - cart items as a bulleted list
#    - 'Resume Your Cart' CTA button (HTML body) +
#      'Resume your cart:' line (text body)
#    - 'unsubscribe here' link pointing at
#      https://<staging-host>/v3/notifications/unsubscribe?token=...

# 8. Verify the notification_log row was written:
psql "$STAGING_DSN" -c "
SELECT id, cart_id, order_id, template, recipient, status,
       sent_at, error_message
FROM notification_logs
WHERE template = 'cart.abandoned.customer'
ORDER BY id DESC LIMIT 3;"
# Expect: row with status='sent', cart_id=<test_cart_id>, order_id IS NULL

# 9. Verify idempotency — re-run the command. The cart should now
#    be excluded because of the prior log row.
php bin/console carts:send-abandonment-reminders --batch-size=10
# Expect: Found 0 (same cart not re-sent) UNLESS there are OTHER eligible carts

# 10. Test the unsubscribe endpoint. Copy the token from the email
#     and hit the endpoint manually:
TOKEN="<copy-from-email>"
curl -i -s "https://staging.3bayti.ae/v3/notifications/unsubscribe?token=$TOKEN" | head -20
# Expect: HTTP/2 200, Content-Type: text/html; charset=utf-8,
# body contains "You've been unsubscribed."

# 11. Verify opt-out flag was set:
psql "$STAGING_DSN" -c "
SELECT id, email, marketing_emails_opt_out
FROM users WHERE id = <test_user_id>;"
# Expect: marketing_emails_opt_out = TRUE

# 12. Re-click the same link (idempotent — should still show success):
curl -i -s "https://staging.3bayti.ae/v3/notifications/unsubscribe?token=$TOKEN" | head -5
# Expect: HTTP/2 200, same success page

# 13. Test invalid token paths:
curl -i -s "https://staging.3bayti.ae/v3/notifications/unsubscribe" | head -3
# Expect: HTTP/2 400 — invalid/expired page

curl -i -s "https://staging.3bayti.ae/v3/notifications/unsubscribe?token=garbage" | head -3
# Expect: HTTP/2 400

# 14. Force-create a second test user, opted out from the start.
#     Verify the cron command SKIPS them, doesn't send, but DOES
#     write a SKIPPED notification_log row:
psql "$STAGING_DSN" -c "UPDATE users SET marketing_emails_opt_out = TRUE WHERE id = <opted_out_test_user_id>;"
# Then engineer an eligible-but-opted-out cart and run the command.
psql "$STAGING_DSN" -c "
SELECT id, cart_id, template, status, error_message
FROM notification_logs
WHERE cart_id = <opted_out_user_cart_id>
ORDER BY id DESC LIMIT 1;"
# Expect: row with status='skipped', error_message='marketing_opted_out'
# This is the 'persistent suppression marker' — the cart is now
# permanently excluded from future cron runs.

# 15. Verify the X.4-C admin notification logs surface shows the
#     cart reminders:
ADMIN_TOKEN=...
curl -s "https://staging.3bayti.ae/v3/admin/notification-logs?template=cart.abandoned.customer&limit=10" \
  -H "Authorization: Bearer $ADMIN_TOKEN" | python3 -c "
import json, sys
d = json.load(sys.stdin)
for log in d['data']:
    print(f\"  cart_id={log.get('cart_id', '-'):>5}  status={log['status']:>7}  recipient={log['recipient']}\")"
# Expect: rows visible with cart_id populated

# 16. Performance observability:
tail -100 apps/api/var/logs/app-*.log | grep -E "cart_(abandonment|notification|reminders)" | tail -20
# Expect:
#   cart_abandonment_finder.computed   (debug, per cron run)
#   cart_notification.sent             (info, per successful send)
#   cart_reminders.batch_complete      (info, per cron run)
#   unsubscribe.completed              (info, per click)
# NO cart_abandonment_finder.slow_response warnings during normal load

# 17. Schedule the cron. Recommended cadence: every 1-2 hours.
#     With default 24h threshold + 100 batch size that comfortably
#     handles operators with ~5000 active carts/day at typical
#     5-10% abandonment rates.
#     Example crontab line:
#        0 */2 * * * cd /var/www/3bayti/apps/api && php bin/console carts:send-abandonment-reminders >>/var/log/3bayti/cart-reminders.log 2>&1
```

**Smoke-test acceptance**

- [ ] Migration applied: `notification_logs.cart_id` exists nullable + `users.marketing_emails_opt_out` exists boolean default FALSE
- [ ] Partial index `idx_notification_logs_cart_id` exists `WHERE cart_id IS NOT NULL`
- [ ] `--dry-run` lists eligible carts without sending or writing logs
- [ ] `--threshold-hours` clamped to [1, 168]
- [ ] Default run sends emails to eligible customers + writes SENT notification_log rows with cart_id populated
- [ ] Re-running command produces zero re-sends (idempotency via NOT EXISTS guard)
- [ ] Unsubscribe endpoint returns 200 + HTML success page for valid token
- [ ] Unsubscribe endpoint returns 400 + HTML error page for invalid/missing/expired token
- [ ] `users.marketing_emails_opt_out` flips to TRUE on first successful unsubscribe
- [ ] Re-clicking the unsubscribe link is idempotent (200 success page, no second flush)
- [ ] Opted-out user is SKIPPED by the cron with a SKIPPED notification_log row (persistent suppression marker)
- [ ] X.4-C admin notification-logs surface shows cart.abandoned.customer entries filterable by template
- [ ] PSR-3 `cart_abandonment_finder.computed`, `cart_notification.sent`, `cart_reminders.batch_complete` log events fire on every cron run
- [ ] No `cart_abandonment_finder.slow_response` warnings during normal staging load
- [ ] Production cron schedule decided (recommended every 1-2 hours)

**Operator deferred items added by X.11:**

24. **Multi-touch reminder sequence** — Q-AbandonmentWindow = B locked single threshold for v1. Once conversion data is available (probably 30-60 days after launch), assess uplift from a multi-touch sequence: 24h + 72h + 168h (one week) reminders. Each threshold would write a different template (`cart.abandoned.day1`, `cart.abandoned.day3`, `cart.abandoned.day7`) and the Finder's NOT EXISTS guard would need to be narrowed to per-template rather than any-template. Migration adds an `abandonment_stage` column to cart-scoped notification_logs OR change the per-template guard logic. ~2 days of work plus 2 new templates × 2 locales = 4 new template methods. Decision criteria: track 'cart abandoned but no day-1 reminder yet' → 'day-1 reminder fired but no purchase yet' → … funnels in analytics. Only ship multi-touch if the marginal lift over single-touch crosses ~2% additional conversion.

25. **Per-template opt-out preferences** — Q-OptOutHandling = A locked at user-level boolean for v1. If customer support starts seeing "I want to opt out of cart reminders but keep newsletter subscriptions" requests (or vice versa), upgrade to a per-template `notification_preferences` table keyed on (user_id, template) with `is_opted_out` boolean. Migration + UI for managing preferences in `/v3/me/preferences` + per-template checks in the marketing send paths. ~3 days of work. Defer until customer-support data justifies the granularity.

26. **Cart abandonment dashboard for ops** — beyond the existing /v3/admin/notification-logs surface, ops will want trend-level metrics: open rate (requires webhook integration with ZeptoMail), conversion-to-purchase from reminder (requires correlating cart.abandoned.customer logs to subsequent orders via user_id + time window), drop-off by cart value bucket, opt-out rate over time. ~5-7 days of work depending on chart depth. The data is already in notification_logs + orders + users today; this is purely a UI + aggregation layer. Defer until X.13 (Vendor analytics dashboard) ships and establishes the dashboard infrastructure pattern this would extend.

## 3. Production execution

**Pre-condition: §2 staging items 2.A through 2.R complete and staging has been stable for ≥24 hours with no regressions.**

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
