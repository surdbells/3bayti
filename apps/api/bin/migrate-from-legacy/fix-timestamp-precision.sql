-- =============================================================================
-- Fix DateTimeTzImmutable precision bug from initial migration run
-- =============================================================================
--
-- Background
-- ----------
-- The Day 4 migration scripts wrote TIMESTAMPTZ values with sub-second
-- precision into created_at and updated_at columns by using `NOW()` in
-- SQL. Postgres `NOW()` returns microsecond-precision timestamps:
--   2026-05-12 21:53:15.167576+00
--
-- Doctrine's DateTimeTzImmutableType only accepts second-precision when
-- hydrating from the DB, expected format "Y-m-d H:i:sO". Anything with
-- a decimal portion throws InvalidFormat at hydration time.
--
-- That means: any row written by Day 4 migration with NOW() can't be
-- read back through the API. The exception cascades to every endpoint
-- that joins or eager-loads vendors/categories/products/reviews.
--
-- Migration scripts have been fixed to use date_trunc('second', NOW())
-- going forward (commit 7e... ). This SQL script repairs the existing
-- data so the API works immediately.
--
-- Idempotent — safe to re-run. Just truncates already-truncated values.
-- =============================================================================

BEGIN;

-- categories: 8 rows
UPDATE categories SET
    created_at = date_trunc('second', created_at),
    updated_at = date_trunc('second', updated_at);

-- users: ~9,330 rows
UPDATE users SET
    created_at = date_trunc('second', created_at),
    updated_at = date_trunc('second', updated_at),
    last_login_at = CASE
        WHEN last_login_at IS NOT NULL THEN date_trunc('second', last_login_at)
        ELSE NULL
    END,
    password_changed_at = CASE
        WHEN password_changed_at IS NOT NULL THEN date_trunc('second', password_changed_at)
        ELSE NULL
    END,
    deleted_at = CASE
        WHEN deleted_at IS NOT NULL THEN date_trunc('second', deleted_at)
        ELSE NULL
    END;

-- vendors: ~104 rows
UPDATE vendors SET
    created_at = date_trunc('second', created_at),
    updated_at = date_trunc('second', updated_at);

-- products: ~2,160 rows
UPDATE products SET
    created_at = date_trunc('second', created_at),
    updated_at = date_trunc('second', updated_at);

-- product_reviews: 27 rows
UPDATE product_reviews SET
    created_at = date_trunc('second', created_at),
    updated_at = date_trunc('second', updated_at);

-- migration_email_conflicts: ~36 rows
UPDATE migration_email_conflicts SET
    created_at = date_trunc('second', created_at);

-- migration_log: log table, not entity-mapped but truncate for consistency
UPDATE migration_log SET
    created_at = date_trunc('second', created_at);

-- Quick verification: should return zero rows after this fix
SELECT 'categories' AS table_name, COUNT(*) AS rows_with_micros
    FROM categories
    WHERE EXTRACT(MICROSECOND FROM updated_at) != 0 OR EXTRACT(MICROSECOND FROM created_at) != 0
UNION ALL
SELECT 'users', COUNT(*) FROM users
    WHERE EXTRACT(MICROSECOND FROM updated_at) != 0 OR EXTRACT(MICROSECOND FROM created_at) != 0
UNION ALL
SELECT 'vendors', COUNT(*) FROM vendors
    WHERE EXTRACT(MICROSECOND FROM updated_at) != 0 OR EXTRACT(MICROSECOND FROM created_at) != 0
UNION ALL
SELECT 'products', COUNT(*) FROM products
    WHERE EXTRACT(MICROSECOND FROM updated_at) != 0 OR EXTRACT(MICROSECOND FROM created_at) != 0
UNION ALL
SELECT 'product_reviews', COUNT(*) FROM product_reviews
    WHERE EXTRACT(MICROSECOND FROM updated_at) != 0 OR EXTRACT(MICROSECOND FROM created_at) != 0;

COMMIT;
