# Day 4 Completion — Legacy Data Migration

**Date:** 12 May 2026
**Status:** ✅ COMPLETE
**Duration:** Migration script: 13.5s. Full Day 4 work (debug + fixes): ~3 hours.

## What shipped

The v3 API now serves real client data:

| Resource | Count | Notes |
|---|---|---|
| Categories | 8 | All 8 legacy categories with their @tui.* icons |
| Users | 9,330 | 36 emails suffixed for collision; 4 NULL phones tolerated |
| Vendors | 104 | 101 named + 3 synthetic (auto-promoted from product owners) |
| Products | 2,160 | 1,923 active, 235 soft_deleted, 2 draft (6 orphans skipped) |
| Reviews | 27 | Vendor-attached only (no product link) |
| Email conflicts | 36 | Renamed losers in `migration_email_conflicts` table |

Production endpoints verified working:
- `GET /v3/categories` → 8 real categories
- `GET /v3/products?limit=N` → real products with real vendor + category data
- `GET /v3/products/{slug}` → detail returns including images JSONB
- `GET /v3/vendors` → 104 vendors
- `GET /v3/vendors/{slug}` → detail returns including legacy_logo_data_url
- `GET /v3/sitemap-data` → enumerates everything for SEO

## Architecture decisions locked

1. **UPSERT semantics across the board.** Every migration step is INSERT-or-UPDATE keyed by `legacy_*_id`. Safe to re-run as legacy DB drifts before go-live.

2. **Stable fields preserved on re-sync:**
   - User emails (login stability)
   - Category / vendor / product slugs (SEO/URL stability)
   - Product `created_at` (legacy date preserved)
   - Vendor `owner_user_id` (FK stability)

3. **Tracked fields updated on re-sync:**
   - User name/phone/country/role flags
   - User password_hash (if rotated; never blanked)
   - Vendor description, contact, store_*, tax_*, approval state
   - Product everything-except-slug (price, stock, images, status, sizes, colors, etc)
   - Review star/title/comment/reply

4. **Single-process orchestrator.** aaPanel disables passthru/exec/shell_exec/system/popen on CLI. Steps run sequentially in-process via the `Bayti\Api\Migration\MigrationSteps` class. proc_open is available as a fallback but unused.

5. **Email collisions surfaced, not auto-merged.** 36 users have suffixed emails (`+legacy{id}@...`) and `migration_email_conflicts` rows. They can't log in until manually merged. Post-demo task.

6. **Stock_status enum extended.** Legacy `on_backorder` (11 rows) preserved by adding `STOCK_BACKORDER` to the Product entity enum.

## Bugs caught + fixed during Day 4

Listed for post-mortem and future hardening discussion:

### Bug 1: `passthru()` disabled in aaPanel CLI
- **Symptom:** Orchestrator died with `Call to undefined function passthru()`
- **Cause:** aaPanel disables passthru, exec, shell_exec, system, popen as a security default
- **Fix:** Merge per-script logic into `MigrationSteps` class, run in-process
- **Commit:** 003b49d

### Bug 2: Wrong column name `is_2fa_enabled` vs `is_2fa`
- **Symptom:** Step 3 (users) failed with `column "is_2fa_enabled" does not exist`
- **Cause:** Confused entity getter name (`is2faEnabled()`) with column name (`is_2fa`)
- **Fix:** Correct column name in MigrationSteps + standalone script
- **Future:** Schema-driven validation in CI (post-demo)
- **Commit:** 7df9d16

### Bug 3: TIMESTAMPTZ microsecond precision rejected by Doctrine
- **Symptom:** All endpoints returned 500 INTERNAL_ERROR after migration completed
- **Cause:** Raw SQL `NOW()` returns microsecond-precision; Doctrine's DateTimeTzImmutableType only accepts second-precision
- **Fix:** Wrap all `NOW()` in `date_trunc('second', NOW())` + repair SQL for existing rows
- **Future:** Schema migrations should declare TIMESTAMPTZ(0) to enforce at DB level. Or write a custom Doctrine type that tolerates both precisions.
- **Commits:** aafb26f (migration + repair SQL)

### Bug 4 (minor, not fixed in Day 4): Slug stability + Arabic transliteration
- Vendor #3427 with Arabic name "مخور ساره" got slug `vendor-3427` (fallback). Stable for the demo. Could improve with proper Arabic-to-Latin transliteration library.
- Vendor #4722 has HTML-entity-encoded name "Ether &amp; Moon" verbatim from legacy. Cosmetic; can be patched with a one-off SQL UPDATE post-demo.

## Known limitations carried forward

1. **36 users can't log in.** Renamed-email rows. Manual merge needed post-demo.
2. **Vendor logos show placeholder.** Base64 preserved in `legacy_logo_data_url` but `logo_url` is NULL. M5 image migration converts.
3. **Reviews don't link to products.** All 27 attach to vendor only by design.
4. **Deletes don't propagate.** A `reconcile-deletes.php` script will be built for final cutover, NOT during regular re-syncs.
5. **Synthetic vendor names** for 3 product owners without store_name. "Store - {email-prefix}". Slugs stay forever.

## Operational notes for Sodiq

**Re-running the migration** (e.g., before go-live to catch fresh legacy edits):

```bash
cd /www/wwwroot/3bayti/apps/api
LOG=/tmp/migrate-$(date +%Y%m%d-%H%M%S).log
WARN=$LOG.warnings

# NO --wipe-seed flag on re-runs
php bin/migrate-from-legacy/migrate-all.php 2>"$WARN" | tee "$LOG"
```

**Verifying any re-sync run wrote second-precision timestamps:**

The migration is now self-correcting. But to audit any new rows for the precision bug:

```sql
-- Any rows where date_trunc('second', x) != x?
SELECT 'products' AS t, COUNT(*) FROM products
  WHERE updated_at != date_trunc('second', updated_at)
UNION ALL
SELECT 'vendors',  COUNT(*) FROM vendors
  WHERE updated_at != date_trunc('second', updated_at)
UNION ALL
SELECT 'users',    COUNT(*) FROM users
  WHERE updated_at != date_trunc('second', updated_at);
-- All should return 0.
```

**Repair script (fix-timestamp-precision.sql)** is idempotent. Safe to re-run if any row sneaks in with microsecond precision.

## What's next: Day 5

Day 5 begins the frontend flip. apps/web (Angular 17 SSR) gets pointed at v3 catalog endpoints instead of legacy `api.3bayti.ae`. The api-client routing layer built on Day 3 makes this surgical — a handful of endpoint constants change.

Pre-Day-5 work for the morning:
- Patch the "Ether &amp; Moon" HTML entity issue (single SQL UPDATE)
- Confirm at least one real legacy user can log in via v3 (proves bcrypt compat)
- Quick review of which apps/web pages depend on which API endpoints (already mapped in docs/plans/m2-ten-day-rollout.md §6)
