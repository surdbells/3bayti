# M3.2 — Consolidated Operator Playbook

**Purpose:** Single staging-then-production runbook for the 7 pending operator follow-ups across M3.2.X.1 through M3.2.X.6.
**Status:** ⏳ Awaiting operator execution
**Estimated operator effort:** ~3-4 hours staging + ~1-2 hours production (excluding the 7-day shadow window for X.1-C-FLIP)
**Last updated:** Sunday, May 17, 2026
**Maintained alongside:** The 7 per-phase closure runbooks remain the canonical detail; this playbook is the execution-time index.

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

### 2.B — Run migrations (3 new ones)

Three migrations were added across M3.2.X.2, M3.2.X.4, M3.2.X.6. Run them in chronological order:

```bash
# Run all pending migrations
cd ~/3bayti/apps/api && composer migrate
# Expected: 3 migrations applied:
#   Version20260516000001 (M3.2.X.2 — featured vendors column)
#   Version20260516000002 (M3.2.X.4 — notification_logs table)
#   Version20260517000001 (M3.2.X.6 — vendor lifecycle status)
```

- [ ] All 3 migrations applied without error

### 2.C — Verify schema (psql connect to staging DB)

```bash
psql $STAGING_DB_URL -c "\d vendors" | grep -E "is_featured|status|status_changed_at|status_reason"
# Expected: 4 rows showing the new columns

psql $STAGING_DB_URL -c "\dt notification_logs"
# Expected: table exists

psql $STAGING_DB_URL -c "\d notification_logs" | grep -E "order_id|template|status|error_kind"
# Expected: 4 rows showing key columns

psql $STAGING_DB_URL -c "\d+ vendors" | grep -E "chk_vendors_status|idx_vendors_status_owner"
# Expected: CHECK constraint + composite index present
```

- [ ] vendors table has 4 new columns from X.2 + X.6
- [ ] notification_logs table exists with 6 indexes
- [ ] CHECK constraint chk_vendors_status present
- [ ] Composite index idx_vendors_status_owner present

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

## 3. Production execution

**Pre-condition: §2 staging items 2.A through 2.K complete and staging has been stable for ≥24 hours with no regressions.**

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
# Expected: same 3 migrations applied
```

- [ ] All 3 migrations applied on production without error

### 3.C — Verify production schema

Run the same `\d` commands from §2.C against the production DB.

- [ ] vendors table has all new columns
- [ ] notification_logs table exists
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

Repeat §2.F through §2.J against the production API (limited to read-only smoke tests + 1-2 controlled write tests if safe to do so). DO NOT create test vendors on production unless you have a cleanup plan.

- [ ] Admin endpoint smoke tests pass on production
- [ ] Lifecycle gate verified with at least one production vendor
- [ ] Audit log entries appearing

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

### 4.B — M3.2.X.5 — Dispute eventType empirical capture

**Status:** ⏳ Blocks M3.2.X.5 implementation entirely

The M3.2.X.5 phase is blocked on capturing real `eventType` strings from Noon sandbox dispute webhooks. Until an operator triggers a sandbox dispute (which is a manual Noon workflow), we don't know the actual string values to put in the `DISPUTE_EVENT_TYPES` constant.

**Operator actions:**

```bash
# 1. Place a sandbox order through staging
# 2. Use Noon's sandbox console to manually trigger a dispute on that order
# 3. Capture the webhook payload from your webhook receiver logs
#    (look for POST /webhook/noon entries)
# 4. Note the exact eventType string(s) Noon sent
# 5. Provide those strings back to the development team to resume M3.2.X.5
```

- [ ] Sandbox dispute triggered
- [ ] Webhook payload captured
- [ ] eventType strings provided to dev team

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
| M3.2.X.6 | `docs/runbooks/m3.2/m3.2.x.6-completion.md` |

## 7. Sign-off

After all §2 and §3 boxes are checked, mark this playbook complete:

- [ ] All staging items (§2) completed and verified
- [ ] All production items (§3) completed and verified
- [ ] All deferred items (§4) either completed or tracked separately
- [ ] No rollbacks executed (or rollbacks executed and root-caused)

Date completed (staging): ________________
Date completed (production): ________________
Operator name: ________________

After sign-off, the playbook is archived as a record of the rollout. Future M3.2 phases (X.7 onwards) will get their own per-phase runbooks and may eventually trigger a new consolidated playbook if multiple phases batch up again.
