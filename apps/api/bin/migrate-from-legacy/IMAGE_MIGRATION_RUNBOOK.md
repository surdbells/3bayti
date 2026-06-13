# Phase E — Legacy Image Migration Runbook

**Goal:** copy legacy product/vendor images off the legacy host (`api.3bayti.ae`) into v3 Flysystem storage (`apps/api/var/uploads/`, served at `api-v3.3bayti.ae/uploads/...`) and rewrite the v3 DB URL columns — so the legacy host can later be decommissioned.

**Script:** `apps/api/bin/migrate-from-legacy/migrate-images.php`
**Run location:** the v3 API server (`/www/wwwroot/3bayti/apps/api`).
**Storage target (this phase):** local disk (`var/uploads/`). R2 swap is deferred — a single DI change later, no script change.

---

## What it migrates

| Source (v3 DB column, set by earlier import) | → Destination file | → DB rewritten to |
|---|---|---|
| `products.primary_image_url` (legacy URL) | `products/{vendor-slug}/{ulid}.{ext}` | new uploads URL |
| `products.images[]` (legacy URLs) + `product_images` rows | same | new uploads URLs |
| `vendors.logo_url` (legacy URL) | `vendors/{slug}/logo.{ext}` | new uploads URL |
| `vendors.cover_image_url` (legacy URL) | `vendors/{slug}/cover.{ext}` | new uploads URL |
| `vendors.legacy_logo_data_url` (base64 blob) | `vendors/{slug}/logo.{ext}` | `logo_url` set, blob cleared |
| `vendors.legacy_cover_data_url` (base64 blob) | `vendors/{slug}/cover.{ext}` | `cover_image_url` set, blob cleared |

**Not migrated (by design):** categories (legacy has no image, only an icon *name*), collections/styles/brands (no legacy image import), order-item snapshots / gift-card / return photos (transactional, not legacy catalog).

**Idempotent:** any URL already under `UPLOADS_PUBLIC_URL` is skipped. Re-running only retries what's missing/errored.

---

## Pre-flight (do this first)

1. **Deploy first.** This phase assumes the full session's API code is already deployed (`git pull` on the server + php-fpm reload). The script uses the deployed entities/DI.

2. **Confirm env vars** in `apps/api/.env` on the server:
   ```
   UPLOADS_PUBLIC_URL=https://api-v3.3bayti.ae/uploads
   LEGACY_PRODUCT_IMAGE_HOST=https://api.3bayti.ae/vendors/products
   ```
   - `UPLOADS_PUBLIC_URL` MUST be the real public origin that serves `var/uploads/` (verify the Apache/Nginx alias actually serves a known file under `/uploads/`). If this is wrong, every rewritten URL will be wrong.
   - `LEGACY_PRODUCT_IMAGE_HOST` only matters if any stored URL is still a bare relative path; the import already stored absolute URLs, so this is mostly a safety net.

3. **Confirm the legacy host still serves images.** Pick a product's `primary_image_url` from the DB and `curl -I` it — expect `200`. If the legacy host is already down, migration can't fetch over HTTP (use `--ssh-copy`, see below).

4. **Back up** the `products`, `product_images`, and `vendors` tables (or snapshot the DB). The script rewrites URL columns and nulls the legacy blob columns — a backup makes any re-run/rollback trivial.

5. **Disk space.** `var/uploads/` will hold every image. Check free space (`df -h`) — estimate ~ (avg image size) × (product images + vendor logos/covers).

---

## Execution (escalating safety)

Run from `/www/wwwroot/3bayti/apps/api`.

```bash
# 1) DRY RUN — no writes. Validates plan + counts against the real DB.
php bin/migrate-from-legacy/migrate-images.php --dry-run --limit=10

# 2) SINGLE-ITEM smoke tests — real writes, one row each.
php bin/migrate-from-legacy/migrate-images.php --product-id=<a real product id>
php bin/migrate-from-legacy/migrate-images.php --vendor-id=<a real vendor id>
#    → then verify the new URLs serve (curl -I the printed uploads URL → 200),
#      and that the product/vendor renders in the portal/app.

# 3) FULL RUN.
php bin/migrate-from-legacy/migrate-images.php
#    (or, if running ON the legacy host with the image dir mounted/local,
#     skip thousands of HTTP fetches by reading from disk:)
php bin/migrate-from-legacy/migrate-images.php --ssh-copy=/path/to/legacy/vendors/products

# 4) RE-RUN to mop up any errored images (idempotent — skips done ones).
php bin/migrate-from-legacy/migrate-images.php
```

Useful flags: `--products-only`, `--vendors-only`, `--limit=N`.

---

## Post-migration verification

1. **No legacy URLs remain.** Expect `0` from each:
   ```sql
   SELECT count(*) FROM products
     WHERE primary_image_url LIKE '%api.3bayti.ae%'
        OR images::text     LIKE '%api.3bayti.ae%';
   SELECT count(*) FROM vendors
     WHERE logo_url LIKE '%api.3bayti.ae%'
        OR cover_image_url LIKE '%api.3bayti.ae%';
   ```
2. **Blob columns drained.** Expect `0`:
   ```sql
   SELECT count(*) FROM vendors
     WHERE legacy_logo_data_url IS NOT NULL
        OR legacy_cover_data_url IS NOT NULL;
   ```
3. **Spot-check rendering** in the portal (product list/detail, store logo/cover) and the customer app.
4. **Review STDERR** from the run for per-image errors (missing legacy files). These are listed and safe to re-run; any that persist are legacy files that no longer exist on the source host — note them.

**Only after** (1)+(2) are clean and rendering is verified should the legacy host be considered safe to decommission for images.

---

## Rollback

The migration is forward-only but reversible from your table backup: restore `products` / `product_images` / `vendors` URL + blob columns. The copied files in `var/uploads/` are harmless to leave in place (a re-run will reuse/overwrite paths).
