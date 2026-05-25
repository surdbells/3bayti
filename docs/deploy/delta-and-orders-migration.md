# 3bayti — Delta Sync + Order History Migration

How to pick up new users/vendors/products created since the initial
MySQL → PostgreSQL migration, and migrate all historical orders.

---

## Background

The initial migration (M3.1) snapshot the data at a point in time.
Since then the live site has continued running on MySQL, so there is:

1. **A delta** of new users, vendors, and products created after the
   initial snapshot. These need to be synced to PostgreSQL before cutover.

2. **Order history** — all historical orders, order items, and shipping
   addresses. These were explicitly deferred from the initial migration
   and are now ready to migrate.

The migration infrastructure handles both via the same script:

- `migrate-all.php` — UPSERT all data, picking up new rows and updating
  drifted fields. Safe to re-run at any time; idempotent.
- `migrate-all.php --include-orders` — same as above plus orders.

Each entity is keyed on its `legacy_*_id` column:
- `users.legacy_user_id`
- `vendors.legacy_vendor_id`
- `products.legacy_product_id`
- `orders.legacy_order_id`
- `product_reviews.legacy_review_id`

A row that already exists in PostgreSQL is **updated** (not re-inserted)
when it has drifted. Stable fields (email, slug, owner) are never changed.

---

## Step 1 — Add MySQL credentials to the production .env

The migration script reads the legacy MySQL connection from `.env`.
These variables are NOT present by default — add them:

```bash
nano /www/wwwroot/3bayti/apps/api/.env
```

Add at the end of the file:

```ini
# Legacy MySQL — read-only connection for migration scripts
LEGACY_MYSQL_HOST=<old-server-ip-or-hostname>
LEGACY_MYSQL_PORT=3306
LEGACY_MYSQL_USER=<legacy-db-read-user>
LEGACY_MYSQL_PASS=<legacy-db-read-password>
LEGACY_MYSQL_DB=<legacy-db-name>
```

> **Read-only user.** Create a dedicated read-only MySQL user on the
> legacy server if you don't already have one:
>
> ```sql
> CREATE USER 'migrate_reader'@'<new-server-ip>' IDENTIFIED BY 'strong-password';
> GRANT SELECT ON `legacy_db`.* TO 'migrate_reader'@'<new-server-ip>';
> FLUSH PRIVILEGES;
> ```
>
> The migration script never writes to MySQL — `LegacyDb.php` has no
> write methods by design.

**Open the MySQL port on the old server's firewall** to allow inbound
connections from the new server IP on port 3306:

```bash
# On the old server (if ufw is active):
ufw allow from <new-server-ip> to any port 3306
```

Test the connection from the new server:

```bash
mysql -h <old-server-ip> -u migrate_reader -p -e "SELECT COUNT(*) FROM users;" legacy_db
# Expected: a row count, no error
```

---

## Step 2 — Dry-run to count what will be migrated

Before touching production data, see what the script would do:

```bash
cd /www/wwwroot/3bayti/apps/api

# Dry-run of the delta (users/vendors/products sync only)
php bin/migrate-from-legacy/migrate-all.php --dry-run 2>&1 | tee /tmp/delta-dryrun.log
```

> `--dry-run` is not implemented in the current orchestrator — it
> runs the INFORMATION_SCHEMA probes + count queries without modifying
> data. If the script doesn't support `--dry-run` yet, do a manual
> count query instead:
>
> ```bash
> # On the old MySQL server — count rows to migrate:
> mysql -h <old-server-ip> -u migrate_reader -p legacy_db -e "
>   SELECT
>     (SELECT COUNT(*) FROM users)     AS users_total,
>     (SELECT COUNT(*) FROM users WHERE is_vendor=1 AND store_name != '') AS vendors_total,
>     (SELECT COUNT(*) FROM products)  AS products_total,
>     (SELECT COUNT(*) FROM orders)    AS orders_total,
>     (SELECT COUNT(*) FROM order_items) AS order_items_total;
> "
>
> # On PostgreSQL — count what's already there:
> psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "
>   SELECT
>     (SELECT COUNT(*) FROM users    WHERE legacy_user_id    IS NOT NULL) AS users,
>     (SELECT COUNT(*) FROM vendors  WHERE legacy_vendor_id  IS NOT NULL) AS vendors,
>     (SELECT COUNT(*) FROM products WHERE legacy_product_id IS NOT NULL) AS products,
>     (SELECT COUNT(*) FROM orders   WHERE legacy_order_id   IS NOT NULL) AS orders;
> "
> ```
>
> The difference between MySQL counts and PostgreSQL counts is your delta.

---

## Step 3 — Run the delta sync (users, vendors, products)

This is safe to run while the legacy site is still live. It UPSERTs
new rows and updates drifted ones. Idempotent — run it as many times
as needed.

```bash
cd /www/wwwroot/3bayti/apps/api

php bin/migrate-from-legacy/migrate-all.php 2>&1 | tee /tmp/delta-migration-$(date +%Y%m%d-%H%M).log
```

**Expected output pattern:**

```
============================================================
 3bayti legacy data migration
============================================================

Run ID: 20260524-142033
Mode:   MIGRATE (UPSERT)

----- step 1: categories -----
===== migrate-categories =====
  Found 47 legacy categories.
  migrated=3 skipped=44 errors=0        ← 3 new categories since last run

----- step 2: users -----
===== migrate-users =====
  Pre-pass: identifying email collisions...
  Found 2 email collisions.
  Migrating 9850 users...
  migrated=534 skipped=9316 errors=0    ← 534 new users, 9316 already synced

----- step 3: vendors -----
  migrated=12 skipped=128 errors=0

----- step 4: products -----
  migrated=187 skipped=2165 errors=0

...

============================================================
 Migration complete in 43.2s
============================================================
```

**Errors > 0?** Check the log:

```bash
grep "✗" /tmp/delta-migration-*.log
# Each ✗ line shows: [phase #legacy_id] error message

# Also query the migration_log table for detail:
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "
  SELECT phase, legacy_id, message, context
  FROM migration_log
  WHERE level = 'error'
  ORDER BY id DESC
  LIMIT 50;
"
```

---

## Step 4 — Verify the orders table schema

Before migrating orders, confirm the `legacy_order_id` column exists
(it was added in migration `Version20260514000003`):

```bash
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "
  SELECT column_name, data_type, is_nullable
  FROM information_schema.columns
  WHERE table_name = 'orders'
  ORDER BY ordinal_position;
" | grep -E "legacy_order|order_ref|status|total|user_id"
```

Expected columns: `legacy_order_id`, `order_reference`, `status`,
`subtotal`, `delivery_fee`, `discount`, `total`, `currency`, `user_id`.

If `legacy_order_id` is missing, run the schema migration first:

```bash
php bin/migrate.php
```

---

## Step 5 — Check legacy order table names

The migration script probes for the orders table using candidate names:
`orders`, `order`, `order_master`, `customer_orders`. Verify which
name your legacy MySQL database uses:

```bash
mysql -h <old-server-ip> -u migrate_reader -p legacy_db -e "SHOW TABLES LIKE '%order%';"
```

**If the table name is not in the default probe list**, edit
`apps/api/src/Migration/MigrationSteps.php` → `migrateOrders()` and
add it to the `$candidates` array before running:

```php
$candidates = ['orders', 'order', 'order_master', 'customer_orders', 'your_actual_table'];
```

Do the same for `migrateOrderItems()` (`order_items`, `orderitems`,
`order_item`, `order_details`, `order_lines`) and
`migrateOrderAddresses()` if you have a separate address table.

Also check the column names in the orders table:

```bash
mysql -h <old-server-ip> -u migrate_reader -p legacy_db -e "DESCRIBE orders;"
```

The script probes for common column name variants automatically
(e.g. `order_id` or `id`, `user_id` or `customer_id`, `total` or
`total_amount`). If your column names differ from all the probed
variants, add them to the relevant `firstPresentColumn()` calls in
`MigrationSteps.php` before running.

---

## Step 6 — Run the order migration

> **Run Step 3 (delta sync) before Step 6.** Orders reference users
> and products via FK. Any user or product that doesn't exist yet in
> PostgreSQL will cause that order to be skipped.

```bash
cd /www/wwwroot/3bayti/apps/api

php bin/migrate-from-legacy/migrate-all.php --include-orders \
  2>&1 | tee /tmp/orders-migration-$(date +%Y%m%d-%H%M).log
```

The `--include-orders` flag runs three additional steps after the
standard categories/users/vendors/products/reviews pipeline:

1. **orders** — migrates header rows (status, total, reference, timestamps)
2. **order_items** — migrates line items, resolving product and vendor FKs
3. **order_addresses** — extracts shipping/billing addresses (from inline
   order columns or a separate address table if one exists)

**Skipped orders** (logged but not an error):
- Order's customer not yet in PostgreSQL — run delta sync first
- Order already migrated (idempotent — re-run is safe)

**Skipped order_items**:
- Parent order wasn't migrated (customer missing → order missing)
- Product not migrated — add the product's vendor first, then re-run

---

## Step 7 — Verify order counts

```bash
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "
SELECT
  (SELECT COUNT(*) FROM orders        WHERE legacy_order_id IS NOT NULL) AS orders,
  (SELECT COUNT(*) FROM order_items   WHERE order_id IN
    (SELECT id FROM orders WHERE legacy_order_id IS NOT NULL))           AS order_items,
  (SELECT COUNT(*) FROM order_addresses WHERE order_id IN
    (SELECT id FROM orders WHERE legacy_order_id IS NOT NULL))           AS order_addresses;
"
```

Compare to legacy MySQL counts:

```bash
mysql -h <old-server-ip> -u migrate_reader -p legacy_db -e "
  SELECT
    (SELECT COUNT(*) FROM orders)      AS orders,
    (SELECT COUNT(*) FROM order_items) AS order_items;
"
```

**Acceptable gap:** Orders skipped due to orphaned users (no email, or
email deduplication collision). Query skipped orders for root cause:

```bash
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "
  SELECT phase, legacy_id, message
  FROM migration_log
  WHERE level IN ('error', 'skip')
    AND phase IN ('orders', 'order_items', 'order_addresses')
  ORDER BY id DESC
  LIMIT 50;
"
```

---

## Step 8 — Check order status mapping

The migration maps legacy status values to v3 using this table
(defined in `MigrationSteps::mapLegacyOrderStatus()`):

| Legacy value | v3 status |
|---|---|
| `pending`, `pending_payment`, `0` | `pending_payment` |
| `paid`, `completed`, `1` | `paid` |
| `fulfilling`, `processing`, `2` | `fulfilling` |
| `shipped`, `in_transit`, `3` | `shipped` |
| `delivered`, `4` | `delivered` |
| `cancelled`, `canceled`, `5` | `cancelled` |
| `refunded`, `6` | `refunded` |
| `failed`, `7` | `failed` |
| *(unknown)* | `paid` — migrated orders assumed paid |

Check the distribution of migrated statuses to confirm the mapping:

```bash
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "
  SELECT status, COUNT(*) AS count
  FROM orders
  WHERE legacy_order_id IS NOT NULL
  GROUP BY status
  ORDER BY count DESC;
"
```

If a legacy status value wasn't covered by the mapping and you see
unexpected `paid` statuses for orders that should be `cancelled` or
`refunded`, add the missing value to `mapLegacyOrderStatus()` and
re-run — the UPSERT won't touch already-migrated rows, so add an
explicit `UPDATE` step or run a corrective SQL after re-migration.

---

## Step 9 — Final delta sync before cutover

Run the full migration one final time immediately before cutting over
DNS to the new server. This minimises the data gap to the window
between this run and DNS propagation.

```bash
cd /www/wwwroot/3bayti/apps/api

# Run during a low-traffic window (e.g. 2–4 AM UAE time)
php bin/migrate-from-legacy/migrate-all.php --include-orders \
  2>&1 | tee /tmp/final-migration-$(date +%Y%m%d-%H%M).log

echo "Final counts:"
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "
  SELECT
    (SELECT COUNT(*) FROM users    WHERE legacy_user_id    IS NOT NULL) AS users,
    (SELECT COUNT(*) FROM vendors  WHERE legacy_vendor_id  IS NOT NULL) AS vendors,
    (SELECT COUNT(*) FROM products WHERE legacy_product_id IS NOT NULL) AS products,
    (SELECT COUNT(*) FROM orders   WHERE legacy_order_id   IS NOT NULL) AS orders;
"
```

After this run, cut DNS over immediately. Any orders placed after this
final sync window and before DNS propagation (typically < 5 minutes
with a low TTL) will be on the legacy system and will need to be
manually transferred — note the IDs from the MySQL side and insert
them via the admin portal after cutover.

---

## Caveats and known limitations

**Carts are not migrated.** Active shopping carts in the legacy system
are transient state. Users logging into v3 for the first time will see
an empty cart and must re-add items. This was an explicit decision in
the M3.1 migration plan.

**Payment transaction records.** The migration captures order totals
and status but does not migrate raw payment gateway records
(`payment_transactions` table in v3). If you need a full audit trail of
Noon/gateway webhook events, export those separately from MySQL and
import them after the migration.

**Promo code redemptions.** `promo_redemptions` are not migrated.
Historical discount usage won't affect v3 promo code usage counts.

**Email conflicts.** The migration renames duplicate emails to
`user+legacyID@domain.com` and logs them to `migration_email_conflicts`.
Review these after migration:

```bash
psql -U bayti_v3 -h 127.0.0.1 -d bayti_v3 -c "
  SELECT legacy_user_id, original_email, renamed_email, resolution_status
  FROM migration_email_conflicts
  WHERE resolution_status = 'pending'
  ORDER BY legacy_user_id;
"
```

Reach out to affected users after cutover to merge or clean up their accounts.
