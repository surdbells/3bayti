# Legacy MySQL discovery — `sql_3bayti_ae`

**Date:** 12 May 2026
**Source:** Live production MySQL on droplet (`142.93.172.195:3306`)
**Schema:** `sql_3bayti_ae`
**Read-only inspection** — no modifications made.

---

## 1. High-level numbers

| Table | Rows | Size | Notes |
|---|---|---|---|
| `users` | 9,416 | **387.5 MB** | 9,299 are vendors (~99%); only 4 admin emails. **Big** — base64 store_logo/cover columns likely the cause. |
| `notifications` | 9,218 | 1.5 MB | Notification log |
| `ec_cart_items` | 2,281 | 3.4 MB | Carts present but not used by demo |
| `wishlist` | 2,113 | 0.4 MB | |
| **`products`** | **1,925** | **2.6 MB** | *Note: actual count is 2,165 (Sodiq said earlier; `TABLE_ROWS` is an estimate)* |
| `customer_wishlist_label` | 521 | | |
| `vendor_follows` | 380 | | |
| `vendor_custom_labels` | 353 | | |
| `store_sizes_measure` | 199 | | Per-store measurement schemas |
| `payment_attempts` | 157 | | |
| `ec_reviews` | 27 | | **Reviews exist but very few** |
| `styles` | 22 | | |
| `chat_messages` | 21 | | |
| `tickets` | 11 | | |
| `category` | 8 | | **Only 8 categories** (not the deeper tree I assumed) |
| `collections` | 5 | | |
| `coupons` | 3 | | |
| **`ec_orders`** | **0** | | **NO ORDERS YET in production.** This is a pre-launch state. |

### Implications

- **2,165 products + 9,416 users + 0 orders** = catalog is real, customer accounts exist, but no real commerce has happened yet. **Order migration is not part of the 10-day plan** because there's nothing to migrate.
- **Only 8 categories** = trivially migratable. We don't need a deep tree migration.
- **27 reviews** = small enough to migrate easily or skip for demo.
- **2,281 cart items** = transient state, can be ignored.

---

## 2. Schema findings — the big surprises

### 2.1 Products table — variants are encoded as 22 boolean columns

The roadmap and Q3 said "products are flat, no variants table." But it's **more interesting than that**: each product row has 22 `size_*` boolean columns indicating which sizes are available, plus a single `colors` varchar(500) field:

```
size_xs, size_s, size_m, size_l, size_xl, size_xxl,
size_50, size_51, ..., size_64,
size_custom,
colors varchar(500) default 'black'
```

**This is a flat schema with embedded "is this size available" booleans, plus colors as a free-form string.** Each product is still one SKU at one price — but it claims to be available in multiple sizes/colors. Stock tracking is at product level (`quantity`), NOT per size/color.

The web app's `ProductDetail.sizes` and `ProductDetail.colors` arrays are **constructed from these columns at query time** by the legacy PHP code — checking which `size_*` is `1` and emitting the size labels.

**Migration strategy:**
- v3 Product entity stores these as a JSON `available_sizes` array + `available_colors` string array
- Migration script reads the 22 size_* booleans and emits the array

### 2.2 Categories table — extremely minimal

```sql
category(category_id, icon, category_name, is_active)
```

**No slug. No description. No display_order. No parent_id. No hierarchy.** Eight rows, flat list.

Our v3 `Category` entity (M2.1.0 schema) supports nested categories with paths. We over-engineered. For demo, we'll migrate the 8 legacy categories as roots, ignoring the parent/path/display_order fields.

**Wait — what about `clothing/womens/abayas` etc?** Those were OUR seed data, not the client's data. The client's actual catalog has 8 flat categories. We need to discover what they are (next step).

### 2.3 Users table — confirms vendors are people, not a separate entity

`users.is_vendor=1` makes a user a vendor. The user record carries:
- Personal fields (first_name, last_name, email, phone, password, etc.)
- Store fields (store_name, store_slug, store_logo as LONGBLOB, store_cover as LONGBLOB, store_address, store_bank_name, etc.)
- Tax fields (vat_status, trade_license_number, tax_registration_number, vat_registration_effective_date)
- Body measurements (arm, bust, hip, length, armhole, shoulder)
- Billing address (billing_name, billing_city, billing_area, etc.)

**Our v3 design has `vendors` as a separate table.** This is correct — separating vendor business data from user identity is the right call. But:
- For migration, we'll need to **split each is_vendor=1 user into a `users` row + a `vendors` row** in Postgres
- Store logo/cover are LONGBLOB → these are likely the cause of the 387.5 MB users table size. Image migration to Flysystem (M5 work) becomes relevant for the demo if logos are needed.

### 2.4 User passwords ARE bcrypt-compatible 🎉

Sample rows show password column values like:
```
$2y$10$qC2KkVpDcMBgRlhvwu65jud  (60 chars)
$2y$10$7epegMKzgQAeACe1kCDcb.1
$2y$10$Nv7heSt1AbIk3stMvquS3.2
```

**These are bcrypt hashes (PHP `password_hash()` with `PASSWORD_BCRYPT`).** Length 60 confirms it. Our v3 auth uses PHP `password_hash` with default bcrypt cost — **the hashes are directly compatible.**

**Implication:** existing customers can log in to v3 with their existing passwords. No password reset needed. This is the single biggest win of the discovery — saves us a "force everyone to reset" UX nightmare.

### 2.5 Token-based auth (legacy) still works alongside JWT (v3)

Legacy auth uses a per-user `token varchar(255)` column with weak verification (`similar_text` with similarity check returning hardcoded `100`). This is broken security-wise but doesn't affect us — we're moving everyone to JWT. We just need to **not migrate the `token` column** (it's a session value, not a credential).

### 2.6 Orders are NOT in production yet

`ec_orders` table exists but is **empty**. Same for `order_status`, `payment_logs`, `audit_histories`, `webhook_events`. The legacy backend has the schema for orders but real customers haven't placed any.

**This is huge.** The client's "rollout" doesn't have to preserve order history because **there is none.** M3 (cart/checkout/orders) is a future-feature build, not a migration. We don't owe data migration of orders for the demo.

### 2.7 Image storage — mixed

From `Database.php`:
- `save_base64_image_to_directory()` exists → some uploads are converted from base64 to files in `products_images/`
- `$product_image_base = "https://api.3bayti.ae/vendors/products/"` → suggests file-based for products
- But `store_logo longblob` + `store_cover longblob` columns in users → vendor logos ARE base64 blobs in DB
- Sample product preview I didn't check yet — need to see whether `image_1` is a file path or base64 data

**Need:** sample `image_1` and `images` columns from a real product to confirm storage strategy.

---

## 3. What we owe the v3 entities

Mapping legacy → v3:

### Products

| Legacy column | v3 field | Notes |
|---|---|---|
| `product_id` | `legacy_product_id` (kept), `id` (auto) | Preserve for traceability |
| `store_id` | `vendor_id` (FK) | Need to resolve legacy `store_id` → new `vendor_id` via `legacy_vendor_id` lookup |
| `category_id` | `category_id` (FK) | Same lookup pattern |
| `name` | `name` | direct |
| `description` | `description` | direct (LONGTEXT → TEXT) |
| `status` | `status` (enum) | values TBD |
| `image_1` | `primary_image_url` | might be a file path, might be base64 — TBD |
| `images` | `images` (json/array) | LONGTEXT, likely a JSON array of paths/base64 |
| `quantity` | `stock_quantity` | direct |
| `price` | `price` | direct (double unsigned) |
| `sale_price` | `sale_price` | direct (generated column in legacy) |
| `size_xs ... size_custom` (22 cols) | `available_sizes` (jsonb) | TRANSFORM: collect 1-valued size_* into array |
| `colors` | `available_colors` (jsonb) | TRANSFORM: split varchar(500) on comma |
| `is_featured`, `is_hot`, `is_new`, `is_sale` | `flags` (jsonb) or individual booleans | direct |
| `try_on_active` | `try_on_enabled` | rename for clarity |
| `created_at`, `updated_at` | `created_at`, `updated_at` | direct |
| `collection` | TBD | text column, unclear semantics |
| `delivery_note`, `delivery_time`, `custom_delivery_time` | `delivery_info` (jsonb) | bundle |

**Missing in legacy that v3 needs:** `slug`. We'll generate slugs from `name` during migration with collision suffix `-2`, `-3`, etc.

### Users

| Legacy column | v3 field | Notes |
|---|---|---|
| `user_id` | `legacy_user_id` (kept), `id` (auto) | |
| `email` | `email` | direct |
| `password` | `password_hash` | bcrypt → bcrypt, **compatible** |
| `first_name`, `last_name` | direct | |
| `phone`, `countryCode` | `phone`, `country_code` | direct |
| `is_active`, `is_admin`, `is_vendor`, `is_customer` | direct booleans on User | direct |
| `created`, `updated`, `last_login`, `approved` | timestamps | direct |
| `arm`, `bust`, `hip`, `length`, `armhole`, `shoulder` | `measurements` (separate table) | EXTRACT to measurements table |
| `billing_name`, `billing_phone`, ..., `villa_number` | `addresses` (separate table) | EXTRACT to addresses table |
| `store_*` (when is_vendor=1) | `vendors` (separate table) | EXTRACT to vendor table |
| `avatar`, `id_front`, `id_back`, `license_doc` | profile uploads | LONGTEXT → file URLs via image migration |
| `token` | DROP | session value, not credential |
| `_sub_admin`, `is_finance`, `is_support`, `is_platformUser` | additional roles | extend User entity |
| `is_2fa` | `is_2fa_enabled` | direct |

### Categories

| Legacy | v3 | Notes |
|---|---|---|
| `category_id` | `legacy_category_id` (kept), `id` (auto) | |
| `category_name` | `name` | |
| `icon` | `icon` (new field needed in v3) or `image_url` | small varchar(50) — probably an icon class name like `bi-shirt` |
| `is_active` | `is_active` | direct |
| — | `slug` | generate from name |
| — | `parent_id`, `path`, `display_order`, `description` | **leave NULL/default** — legacy doesn't have these |

**Action:** drop or null out the seed data we made (clothing > womens > abayas tree). The real catalog has 8 flat categories.

### Reviews

`ec_reviews` (27 rows) — small enough to migrate trivially. Will inspect schema on Day 1.

---

## 4. What we DON'T migrate (deferred / not relevant)

| Concern | Decision |
|---|---|
| `ec_orders` + `ec_cart_items` + `payment_attempts` + `order_status` | Zero/transient. M3 scope. Defer entirely. |
| `chat_messages`, `chat_conversations`, `chat_prompts` | M4 scope. 21 messages, easy to migrate later. |
| `tickets`, `ticket_messages` | M4 scope. Defer. |
| `notifications` | 9,218 rows but transient — defer. |
| `styles` (22 rows) | Style Hub feature (M3+). Defer. |
| `coupons`, `coupon_*` | Future. Defer. |
| `wishlist`, `customer_wishlist_label` | M3 scope. Defer. |
| `vendor_follows` | M3 scope. Defer. |
| `vendor_custom_labels` | M3-M4 scope. Defer. |
| `payment_logs`, `audit_histories`, `webhook_events` | All empty. Defer. |
| `store_sizes_measure` | Per-store measurement template — relates to `/v3/measurements/template` endpoint we owe. Defer to post-demo. |
| `newsletters` | Empty. Defer. |

---

## 5. Critical questions — RESOLVED

1. **Are `image_1` and `images` columns file paths or base64?** ✅ **File paths.** All 1,928 published products have `image_1` average length 43.9 chars (max 45 chars) — paths like `products_images/68cbf52d22813_1758197037.webp`. The `images` LONGTEXT is a JSON array of similar paths. **NO image migration needed for product display.** v3 points URLs at `https://api.3bayti.ae/vendors/products/<filename>` and the legacy server continues serving files.

2. **What are the 8 category names?** ✅
   - 1: Abayas (icon @tui.sparkles)
   - 2: Mukhawars (icon @tui.sun)
   - 3: Kaftans (icon @tui.flower)
   - 4: Bags (icon @tui.backpack)
   - 5: Accessories (icon @tui.sprout)
   - 6: Modest clothes (icon @tui.leaf)
   - 7: Dresses (icon @tui.flower)
   - 8: Pyjamas (icon @tui.backpack)

3. **Product `status` enum values?** ✅
   - `published` (1928 rows) → migrate as `active`
   - `deleted` (235 rows) → soft-delete, migrate but mark `is_active=false`
   - `draft` (2 rows) → migrate as `draft`

4. **Reviews schema?** ✅ 12 columns: id, customer_id, store_id, customer_name, customer_email, product_name, star (double), title, comment, vendor_reply, status, created_at. **No product_id column** — reviews tie to store + product_name string. Migration needs to resolve product_name → product_id by lookup. 27 rows total, easy.

5. **`store_logo` LONGBLOB type?** ✅ **base64 data-URLs** in BLOB column. Confirmed via byte-pattern detection.
   - Sizes range 10KB → 1.19MB per logo
   - This is why the `users` table is 387.5MB
   - **For demo:** option A — skip vendor logo migration, show placeholder. Option B — migrate during M5 image work. Option C — fast-extract logos into files and update URL during catalog migration script. **Recommended: option A for demo, image migration job runs post-demo.**

---

## 6. Image-storage summary (final)

| Image type | Storage | Migration urgency |
|---|---|---|
| Product images (`image_1`, `images`) | **File paths** in `products_images/` subdir of legacy backend | **None.** v3 points URLs at legacy server. |
| Vendor logos (`users.store_logo`) | base64 data-URLs in LONGBLOB | Defer. Demo shows placeholder. |
| Vendor cover photos (`users.store_cover`) | base64 data-URLs in LONGBLOB | Defer. Demo shows placeholder. |
| User avatars (`users.avatar`) | LONGTEXT (probably base64) | Defer. Demo shows initials/placeholder. |
| ID docs (`id_front`, `id_back`, `license_doc`) | LONGTEXT (likely base64) | Defer — not in demo scope (admin-only). |

---

## 6. The biggest architectural learning

The legacy schema is **flatter than the v3 schema we built.**

- v3 Categories: nested tree with parent/path → legacy: flat 8 rows
- v3 Vendors: separate entity → legacy: user row with `is_vendor=1`
- v3 Products with images table → legacy: 22 size_* booleans + JSON images blob

**This is fine — v3 is the right design for the future.** But the migration scripts will need to denormalize-then-renormalize for vendors, expand-then-collapse for sizes, and synthesize fields like `slug` from `name`.

**Migration complexity ranking:**
1. **Categories**: trivial (8 rows, direct copy + slug generation)
2. **Users (non-vendor)**: easy (column-to-column, bcrypt-compatible)
3. **Users (vendors)**: medium (split into users + vendors + addresses)
4. **Products**: medium-high (size column transformation, image strategy)
5. **Reviews**: trivial-easy (27 rows)
6. **Addresses + Measurements (from users)**: medium (extract sub-records)
