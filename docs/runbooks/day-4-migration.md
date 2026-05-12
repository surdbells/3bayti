# Day 4 — Legacy Data Migration Runbook

**Status:** Scripts ready. Execution pending.
**Date:** 12 May 2026
**Mode:** UPSERT — safe to re-run multiple times as legacy data evolves.

This is the make-or-break day of the 10-day rollout (per `docs/plans/m2-ten-day-rollout.md` §11). After this runs successfully, v3 has real client data and Days 5–7 can flip the frontends.

## Re-sync semantics

Migration scripts use UPSERT (insert-or-update). Re-running them is safe and useful:

- **New legacy rows** appearing between runs → picked up as INSERT in next sync.
- **Edited legacy rows** (vendor changes price, customer changes name) → picked up as UPDATE in next sync.
- **Deleted legacy rows** → NOT detected. Soft-deletes/cleanup are a separate concern (`reconcile-deletes.php` planned for final cutover, NOT in this work).

### Stable fields preserved across re-syncs

Per decisions on Day 4:

| Resource | Stable (never changes on re-sync) | Tracked (updates from legacy) |
|---|---|---|
| Categories | slug | name, icon, is_active |
| Users | email, password_changed_at | first/last name, phone, country, roles, password_hash if rotated |
| Vendors | slug, owner_user_id | name, description, contact, store_*, tax_*, approval |
| Products | slug, created_at | everything else (price, stock, sizes, colors, status, flags, images) |
| Reviews | (no slug/email to stabilize) | star, title, comment, vendor_reply |

**Why stable?** Slugs are SEO/URL identifiers — flipping them breaks external links and search rankings. Emails are login identifiers — flipping them locks users out.

**Implication:** if a vendor renames their store from "Aurora" to "Aurora Boutique" on legacy after first sync, the v3 vendor will display the new name but keep the old slug `aurora` (not `aurora-boutique`). Same for products.

## Pre-execution checklist

### 1. Add `LEGACY_MYSQL_*` env vars to `.env`

On the server at `apps/api/.env`, append:

```
LEGACY_MYSQL_HOST=142.93.172.195
LEGACY_MYSQL_PORT=3306
LEGACY_MYSQL_USER=sql_3bayti_ae
LEGACY_MYSQL_PASS=4faa7d87a3ac28
LEGACY_MYSQL_DB=sql_3bayti_ae
```

Verify the .env is readable by www user only:

```bash
ls -la apps/api/.env
# Expect: -rw------- 1 www www
```

**Rotate these credentials post-demo.**

### 2. Confirm migrations applied

```bash
sudo -u postgres /www/server/pgsql/bin/psql -d bayti_v3 -c "
SELECT version, executed_at FROM doctrine_migration_versions
WHERE version LIKE '%2026051%'
ORDER BY executed_at;
"
```

Expected: rows for Version20260512000001, Version20260512000002, Version20260512000003.

### 3. Verify legacy DB reachability from server

```bash
cd /www/wwwroot/api-v3.3bayti.ae/apps/api
php -r '
require "vendor/autoload.php";
$_ENV["LEGACY_MYSQL_HOST"] = "142.93.172.195";
$_ENV["LEGACY_MYSQL_PORT"] = "3306";
$_ENV["LEGACY_MYSQL_USER"] = "sql_3bayti_ae";
$_ENV["LEGACY_MYSQL_PASS"] = "4faa7d87a3ac28";
$_ENV["LEGACY_MYSQL_DB"]   = "sql_3bayti_ae";
$db = new Bayti\Api\Migration\LegacyDb();
echo "Users: " . $db->count("users") . "\n";
echo "Products: " . $db->count("products") . "\n";
'
```

Expected: ~9328 users, ~2165 products.

## Execution — INITIAL run

The first time, you want to wipe the fictional seed data first. Use the `--wipe-seed` flag:

```bash
cd /www/wwwroot/api-v3.3bayti.ae/apps/api
php bin/migrate-from-legacy/migrate-all.php --wipe-seed 2>&1 | tee /tmp/migrate-$(date +%Y%m%d-%H%M%S).log
```

Expected runtime: ~5 minutes.

## Execution — SUBSEQUENT runs (re-sync)

For ongoing syncs as new legacy data appears, do NOT pass `--wipe-seed`:

```bash
cd /www/wwwroot/api-v3.3bayti.ae/apps/api
php bin/migrate-from-legacy/migrate-all.php 2>&1 | tee /tmp/migrate-$(date +%Y%m%d-%H%M%S).log
```

Expected runtime: 1-5 minutes depending on volume of changes since last sync (mostly read-time for unchanged rows).

The orchestrator now prints "SKIPPED rollback-fictional-seed" on subsequent runs, which is what you want. **Never pass `--wipe-seed` after the initial migration** — it would delete all migrated data.

## Per-step expectations

| Step | What | First run | Subsequent runs |
|---|---|---|---|
| 1 | rollback-fictional-seed | "Rollback complete" | "SKIPPED" |
| 2 | migrate-categories | "INSERT 8" | "0-8 UPDATE if any drift" |
| 3 | migrate-users | "INSERT ~9328, 71 conflicts" | "INSERT (new only), UPDATE (edited only)" |
| 4 | migrate-vendors | "INSERT ~140" | "INSERT new + UPDATE edited" |
| 5 | migrate-products | "Processed ~2160" | "Processed = new + edited" |
| 6 | migrate-reviews | "Migrated 27" | "INSERT new + UPDATE edited" |

## Spot-check after migration

### A. Endpoint hits

```bash
# Real products
curl -s 'https://api-v3.3bayti.ae/v3/products?limit=5' | python3 -m json.tool | head -30

# Single product
SLUG=$(curl -s 'https://api-v3.3bayti.ae/v3/products?limit=1' | python3 -c 'import json,sys;print(json.load(sys.stdin)["data"][0]["slug"])')
curl -s "https://api-v3.3bayti.ae/v3/products/$SLUG" | python3 -m json.tool | head -40

# Sitemap-data
curl -s https://api-v3.3bayti.ae/v3/sitemap-data | python3 -c '
import json, sys
d = json.load(sys.stdin)
print(f"categories: {len(d[\"categories\"])}")
print(f"products:   {len(d[\"products\"])}")
print(f"vendors:    {len(d[\"vendors\"])}")
'
# Expected: categories: 8, products: ~1928, vendors: ~140
```

### B. Auth with a real legacy user

Use a customer email + password you know. v3 should accept the legacy bcrypt unchanged:

```bash
curl -s -X POST https://api-v3.3bayti.ae/v3/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"REAL_CUSTOMER_EMAIL","password":"THEIR_PASSWORD"}'
```

Expected: `{ "data": { "access_token": ..., "refresh_token": ... } }`

### C. Inspect migration_log

```bash
sudo -u postgres /www/server/pgsql/bin/psql -d bayti_v3 << 'EOF'
SELECT phase, level, COUNT(*) FROM migration_log
WHERE run_id = (SELECT run_id FROM migration_log ORDER BY id DESC LIMIT 1)
GROUP BY phase, level
ORDER BY phase, level;
EOF
```

### D. Inspect email conflicts

```bash
sudo -u postgres /www/server/pgsql/bin/psql -d bayti_v3 -c "
SELECT legacy_user_id, original_email, renamed_email
FROM migration_email_conflicts
ORDER BY id LIMIT 20;
"
```

Expected: ~71 rows on first run, same set on re-runs (we use ON CONFLICT DO NOTHING).

## Rollback (initial migration only)

If the first migration leaves the DB in a bad state, full rollback:

```bash
sudo -u postgres /www/server/pgsql/bin/psql -d bayti_v3 << 'EOF'
TRUNCATE TABLE product_reviews CASCADE;
TRUNCATE TABLE product_images CASCADE;
TRUNCATE TABLE products CASCADE;
TRUNCATE TABLE migration_email_conflicts CASCADE;
DELETE FROM vendors WHERE legacy_vendor_id IS NOT NULL;
DELETE FROM users   WHERE legacy_user_id   IS NOT NULL;
DELETE FROM categories WHERE legacy_category_id IS NOT NULL;
SELECT setval('products_id_seq', 1, false);
SELECT setval('vendors_id_seq',  1, false);
SELECT setval('users_id_seq',    1, false);
SELECT setval('categories_id_seq', 1, false);
EOF
```

Then re-run `migrate-all.php --wipe-seed`.

**NEVER run this rollback after Days 5-7 have started** — apps/web/mobile/portal will be pointed at v3 by then, and a rollback empties their data source.

## Known limitations (carried forward to demo)

1. **71 users can't log in.** Their emails were suffixed with `+legacy{id}`. These need manual merge — see `migration_email_conflicts`. Post-demo work.

2. **Vendor logos show placeholder.** The `legacy_logo_data_url` column contains base64 from legacy, but the v3 logo_url is NULL. Frontend shows initials/placeholder. M5 image migration fixes this.

3. **Some products have synthetic slugs.** When two products share a name, second one becomes `name-2`. Once assigned, slug never changes.

4. **24 products with no real vendor name.** Their owner-as-vendor row got "Store - <email-prefix>" naming. Once assigned, name updates from legacy but slug stays.

5. **Reviews don't link to products.** All 27 attach to the vendor only.

6. **Deletes don't propagate.** If a vendor soft-deletes a product on legacy after initial migration, the v3 product stays. The `reconcile-deletes.php` script (to be built before go-live) handles this.
