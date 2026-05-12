# Day 4 — Legacy Data Migration Runbook

**Status:** Scripts ready. Execution pending.
**Date:** 12 May 2026

This is the make-or-break day of the 10-day rollout (per `docs/plans/m2-ten-day-rollout.md` §11). After this runs successfully, v3 has real client data and Days 5–7 can flip the frontends.

## Pre-execution checklist

### 1. Add `LEGACY_MYSQL_*` env vars to `.env`

On the server at `/www/wwwroot/api-v3.3bayti.ae/apps/api/.env` (or wherever .env lives), append:

```
LEGACY_MYSQL_HOST=142.93.172.195
LEGACY_MYSQL_PORT=3306
LEGACY_MYSQL_USER=sql_3bayti_ae
LEGACY_MYSQL_PASS=4faa7d87a3ac28
LEGACY_MYSQL_DB=sql_3bayti_ae
```

Note: legacy creds are the same we read from `/www/wwwroot/api.3bayti.ae/config/Database.php` during discovery. **Rotate these post-demo.**

Verify the .env is readable by www user only:

```bash
ls -la apps/api/.env
# Expect: -rw------- 1 www www
```

### 2. Confirm migrations applied

After auto-deploy of commit `a88e391` (the checkpoint) and the final commit on Day 4, three new migrations should have run:

```bash
sudo -u postgres /www/server/pgsql/bin/psql -d bayti_v3 -c "
SELECT version, executed_at FROM doctrine_migration_versions
WHERE version LIKE '%2026051%'
ORDER BY executed_at;
"
```

Expected: rows for `Version20260512000001`, `Version20260512000002`, `Version20260512000003`.

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
$c  = $db->count("users");
echo "Legacy users count: $c\n";
'
```

Expected: `Legacy users count: 9328` (or close).

## Execution

Once pre-flight passes, run the orchestrator:

```bash
cd /www/wwwroot/api-v3.3bayti.ae/apps/api
php bin/migrate-from-legacy/migrate-all.php 2>&1 | tee /tmp/migrate-$(date +%Y%m%d-%H%M%S).log
```

Expected runtime: ~5 minutes (categories <1s, users ~30s, vendors <10s, products ~2min, reviews <1s). The orchestrator prints per-step progress.

If a step fails, the orchestrator stops. To resume:

```bash
# Fix whatever broke, then:
php bin/migrate-from-legacy/migrate-all.php --skip-seed
```

The `--skip-seed` flag skips the rollback step (already done). Each migration script is idempotent — already-migrated rows are skipped via `legacy_*_id` lookup.

## Per-step expectations

| Step | What | Expected output |
|---|---|---|
| 1 | rollback-fictional-seed | "Rollback complete. brands=0 categories=0 vendors=0" |
| 2 | migrate-categories | "Migrated 8, Skipped 0, Errors 0" |
| 3 | migrate-users | "Migrated ~9328, Email conflicts 71" |
| 4 | migrate-vendors | "Migrated ~140, Skipped 0, Errors 0" |
| 5 | migrate-products | "Migrated ~2160, Skipped few orphans" |
| 6 | migrate-reviews | "Migrated 27, Skipped if vendor missing" |

If significant deviations: stop, investigate, don't proceed to spot-check.

## Spot-check after migration

### A. Endpoint hits

```bash
# List products (should return real abayas now, not empty)
curl -s https://api-v3.3bayti.ae/v3/products?limit=5 | python3 -m json.tool | head -30

# Single product detail
SLUG=$(curl -s https://api-v3.3bayti.ae/v3/products?limit=1 | python3 -c '
import json, sys
print(json.load(sys.stdin)["data"][0]["slug"])
')
curl -s https://api-v3.3bayti.ae/v3/products/${SLUG} | python3 -m json.tool | head -50

# Sitemap-data (no longer empty)
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

Pick a known test customer from legacy. Their bcrypt password should
work in v3 unchanged:

```bash
# Use a customer email you know the password for. Example shape:
curl -s -X POST https://api-v3.3bayti.ae/v3/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"REAL_CUSTOMER_EMAIL","password":"THEIR_PASSWORD"}'
```

Expected: `{ "data": { "access_token": ..., "refresh_token": ... } }`

If 401: bcrypt mismatch. Check the user's `password_hash` in v3:
```sql
SELECT email, LEFT(password_hash, 7) FROM users WHERE email = 'REAL_CUSTOMER_EMAIL';
```

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

Expected: ~71 rows. Each shows a user whose email was suffixed.

## Rollback

If the migration leaves the DB in a bad state, full rollback:

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

Then re-run `migrate-all.php` from scratch.

## Known limitations (carried forward to demo)

1. **71 users can't log in.** Their emails were suffixed with `+legacy{id}`. These need manual merge — see `migration_email_conflicts`. Post-demo work.

2. **Vendor logos show placeholder.** The `legacy_logo_data_url` column contains base64 from legacy, but the v3 logo_url is NULL. Frontend shows initials/placeholder. M5 image migration fixes this.

3. **Some products have synthetic slugs.** When two products share a name, second one becomes `name-2`. Rare but possible.

4. **24 products with no real vendor name.** Their owner-as-vendor row got "Store - <email-prefix>" naming. Demo-acceptable.

5. **Reviews don't link to products.** All 27 attach to the vendor only.
