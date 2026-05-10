# M2 — Catalog Plan

**Status:** Planned, not yet started
**Date authored:** 10 May 2026
**Predecessor:** M1.6 (hardening, complete through M1.6.1.C; M1.6.3.A, M1.6.3.D, M1.6.4 deferred)
**Successor:** M3 (orders + cart)
**Estimated total effort:** Largest milestone in the roadmap. Multi-week of focused work.

## Why M2

M1 delivered the user side: registration, login, profile, addresses,
measurements. That gives us authenticated users with no products to
buy. M2 is the catalog itself — categories, products, variants,
attributes, pricing, inventory, images, search.

M2 is structurally the most consequential milestone we'll do. Every
later milestone references the catalog: M3 orders snapshot products,
M4 vendors own products, M5 search/filter-by-attribute depends on the
attribute schema. Decisions made in M2 are extremely hard to walk
back without data migration pain.

## Locked decisions

These five decisions are fixed before any code:

| # | Decision | Implication |
|---|---|---|
| Q1 | **Fashion/apparel focus** | Variant-heavy, attribute-heavy filtering. Size + color + material as first-class concepts. |
| Q2 | **Mixed: off-the-shelf AND made-to-order** | Schema must support both modes on the same product. Inventory is per-variant for off-the-shelf; made-to-order has no stock count. |
| Q3 | **Multi-vendor from day 1** | Vendor entity created in M2 (even if only one vendor populated initially). Products belong to a vendor. |
| Q4 | **Postgres + Meilisearch** | M2 ships full-text + facet search via Meilisearch. Postgres is source of truth; Meilisearch is the query side. |
| Q5 | **English-only product copy in M2** | Defer Arabic to M2.x or M3. Acknowledged trade-off — UAE market is largely Arabic-first. |

Five additional decisions locked with my recommendation (override
in this doc if any feel wrong before implementation begins):

| # | Decision | Why |
|---|---|---|
| Q6 | **Adjacency list for category tree** | Simplest to maintain; nested set wins for tree-rebuild perf which we don't need at our scale |
| Q7 | **Price stored on the variant level** | "Same Abaya, larger size" can cost more without restructuring. `min(price)` query for "from X" display. |
| Q8 | **Inventory stub: integer column on variant** | No reservations, warehouses, or movement tracking. Decrement on order, show "out of stock" at 0. |
| Q9 | **DigitalOcean Spaces for images** | S3-compatible. Pre-signed PUT for upload, public-read GET for display. Defer CDN to M5+. |
| Q10 | **Cart entity stub in M2, controllers in M3** | Get FK direction right now; controllers wait until M3 actually implements cart ops. |

## Q5 trade-off note (English-only)

The UAE market is materially Arabic-speaking, with maybe 60-70% of
shoppers preferring Arabic over English for an e-commerce experience.
Shipping English-only products is a real go-to-market constraint.

The plan assumes you'll either:
1. Re-enable Arabic before public launch (M2.x mini-phase that adds
   `name_ar`, `description_ar` columns + admin UI translation
   workflow), OR
2. Soft-launch in English to a smaller audience while Arabic-locale
   product copy is generated, OR
3. Ship the storefront English-first and add Arabic in M3 alongside
   order localization.

The schema is designed to make adding `*_ar` columns a one-migration
ALTER, not a structural change. So the trade-off is real but the
recovery is cheap. **Strongly suggest deciding which path before
public launch.**

---

## Domain Model

The hardest part of M2 isn't the API surface, it's the data model.
Let me walk through the entities and how they relate.

### Core entities

```
Vendor
  └─< Product
        ├── Category (tree, adjacency list)
        ├── Brand (optional)
        ├── ProductImage (1:N, ordered)
        ├── ProductAttribute (1:N, key/value pairs)
        └─< ProductVariant
              ├── price        (per-variant)
              ├── stock        (per-variant; null for made-to-order)
              ├── sku
              ├── barcode      (optional)
              └── variant attribute values (size, color, etc.)
```

### Schema sketch (decisions only — full migrations land per-phase)

**`vendors`**
- `id BIGSERIAL`
- `legacy_vendor_id INT NULL UNIQUE` (for future migration)
- `slug VARCHAR(100) UNIQUE` ("almas-fashion")
- `name VARCHAR(200)`
- `description TEXT NULL`
- `logo_url VARCHAR(500) NULL`
- `cover_image_url VARCHAR(500) NULL`
- `contact_email VARCHAR(255)`
- `contact_phone VARCHAR(20) NULL`
- `is_active BOOLEAN DEFAULT TRUE`
- `is_verified BOOLEAN DEFAULT FALSE`
- `commission_rate DECIMAL(5,2) DEFAULT 10.00` (vendor's cut, %)
- `created_at, updated_at`

Why M2 has Vendor: Q3 says multi-vendor from day 1. Even if you
populate one vendor (3bayti or your launch partner) at first,
the FK on products needs to point somewhere real. Adding it later
means a migration that updates every product row.

What's NOT here: vendor login/auth (M4 deliverable), vendor
dashboard (M4), commission calculation logic (M3 — order
post-processing).

**`categories`**
- `id BIGSERIAL`
- `parent_id BIGINT NULL` → `categories(id)`
- `slug VARCHAR(100) UNIQUE` ("abayas", "casual-wear")
- `name VARCHAR(150)` (English only per Q5)
- `description TEXT NULL`
- `display_order INT DEFAULT 0` (for sort within siblings)
- `image_url VARCHAR(500) NULL`
- `is_active BOOLEAN DEFAULT TRUE`
- `path VARCHAR(500)` (denormalised: "/clothing/womens/abayas")
- `created_at, updated_at`

Why adjacency list with denormalised path: queries like "show me all
products in /clothing/womens/abayas and below" otherwise need
recursive CTE. Path column lets us do `WHERE path LIKE '/clothing/womens/%'`
which is fast with a btree index. Path maintained by application code on
category create/move (rare operation).

**`brands`** (optional, simple)
- `id BIGSERIAL`
- `slug VARCHAR(100) UNIQUE`
- `name VARCHAR(150)`
- `logo_url VARCHAR(500) NULL`
- `is_active BOOLEAN DEFAULT TRUE`

**`products`**
- `id BIGSERIAL`
- `legacy_product_id INT NULL UNIQUE`
- `vendor_id BIGINT NOT NULL` → `vendors(id)`
- `category_id BIGINT NOT NULL` → `categories(id)`
- `brand_id BIGINT NULL` → `brands(id)`
- `slug VARCHAR(200) UNIQUE` ("formal-black-abaya-collection")
- `name VARCHAR(300)`
- `short_description TEXT NULL`
- `description TEXT NULL`
- `is_active BOOLEAN DEFAULT TRUE`
- `is_visible BOOLEAN DEFAULT TRUE` (vendor can hide without deleting)
- `is_featured BOOLEAN DEFAULT FALSE`
- `stock_mode VARCHAR(20) DEFAULT 'stock'` — **see "stock_mode" below**
- `meta_title VARCHAR(200) NULL` (SEO)
- `meta_description TEXT NULL`
- `view_count BIGINT DEFAULT 0` (denormalised; updated async)
- `created_at, updated_at`
- INDEX (vendor_id), (category_id), (slug), (is_active, is_visible)

**`stock_mode` is the critical Q2 lever:**
- `'stock'` — off-the-shelf. Variants have non-null `stock_quantity`. Order decrements stock.
- `'made_to_order'` — custom-tailored. Variants have null `stock_quantity` (no inventory). Order requires customer measurements (M1.7.3 wired).
- `'mixed'` — both modes available. Each variant individually has either a stock count OR is marked `is_made_to_order=TRUE`. Customer chooses on cart-add.

This is the heart of the Q2 trade-off. Without `stock_mode` we'd
either (a) lose the made-to-order path or (b) build it as a parallel
table tree. Folding it into product+variant flags keeps one entity
graph, which simplifies cart/order logic in M3.

**`product_images`**
- `id BIGSERIAL`
- `product_id BIGINT` → `products(id)` ON DELETE CASCADE
- `variant_id BIGINT NULL` → `product_variants(id)` (variant-specific images, e.g. "show me the red one")
- `url VARCHAR(500)`
- `alt_text VARCHAR(300) NULL`
- `display_order INT DEFAULT 0`
- `is_primary BOOLEAN DEFAULT FALSE`
- `created_at`
- INDEX (product_id, display_order), partial index where is_primary

Per Q9: `url` is a Spaces public URL. Upload happens via pre-signed
PUT (vendor admin in M4); reading is direct CDN fetch.

**`product_attributes`** — key/value pairs at the product level
- `id BIGSERIAL`
- `product_id BIGINT` → `products(id)` ON DELETE CASCADE
- `attribute_key VARCHAR(50)` ("material", "care_instructions")
- `attribute_value TEXT`
- `display_order INT DEFAULT 0`
- INDEX (product_id), (attribute_key)

These are FREE-FORM text, not validated against a schema. M2 ships
this loose; M5+ could introduce a `category_attribute_template`
table that defines required attributes per category (e.g., abayas
require `length_cm`, `material`, `closure_type`).

**`product_variants`**
- `id BIGSERIAL`
- `product_id BIGINT NOT NULL` → `products(id)` ON DELETE CASCADE
- `legacy_variant_id INT NULL UNIQUE`
- `sku VARCHAR(100) UNIQUE`
- `barcode VARCHAR(50) NULL`
- `price DECIMAL(10,2) NOT NULL`              -- AED, smallest unit fils (so 250.00 = 250.00 AED)
- `compare_at_price DECIMAL(10,2) NULL`        -- "was 300, now 250"
- `cost DECIMAL(10,2) NULL`                    -- internal; not exposed via public API
- `stock_quantity INT NULL`                    -- null = made-to-order; 0 = out of stock; positive = available
- `is_made_to_order BOOLEAN DEFAULT FALSE`
- `weight_grams INT NULL`                      -- shipping calc (M3)
- `is_active BOOLEAN DEFAULT TRUE`
- `created_at, updated_at`
- INDEX (product_id), UNIQUE (sku)

Variant attributes (size, color) live in a separate table because
size and color values are normalised — you want "Size M" to mean the
same across products.

**`product_variant_options`**
- `id BIGSERIAL`
- `variant_id BIGINT` → `product_variants(id)` ON DELETE CASCADE
- `option_key VARCHAR(50)` ("size", "color")
- `option_value VARCHAR(100)` ("M", "Black")
- INDEX (variant_id), (option_key, option_value)

Why this not a JSONB on the variant? Filtering. "Show me all
products with a Black variant in stock" hits this table directly:
```sql
SELECT DISTINCT p.id FROM products p
  JOIN product_variants v ON v.product_id = p.id
  JOIN product_variant_options o ON o.variant_id = v.id
  WHERE o.option_key = 'color' AND o.option_value = 'Black'
    AND v.stock_quantity > 0;
```
With JSONB you'd need a GIN index and clunkier WHERE clauses.

**`carts`** (stub in M2; M3 owns the controllers)
- `id BIGSERIAL`
- `user_id BIGINT` → `users(id)` ON DELETE CASCADE
- `created_at, updated_at`
- UNIQUE (user_id) — one active cart per user

**`cart_items`** (stub)
- `id BIGSERIAL`
- `cart_id BIGINT` → `carts(id)` ON DELETE CASCADE
- `variant_id BIGINT` → `product_variants(id)` ON DELETE RESTRICT
- `quantity INT NOT NULL DEFAULT 1`
- `is_made_to_order BOOLEAN DEFAULT FALSE`     — customer chose custom path
- `measurement_id BIGINT NULL` → `measurements(id)`  — set if made-to-order
- `created_at, updated_at`

Why M2 stubs cart but not orders: cart is referenced by frontend on
product browse pages ("add to cart" button needs the cart to exist).
Orders are pure M3 work — separate phase, separate session.

### What's NOT in M2

Things that sound catalog-related but explicitly DEFER:

- **Pricing rules** (BOGO, percentage off, member pricing) — M3+
- **Promotions / coupons** — M3+
- **Reviews and ratings** — M5
- **Wishlist** — M3 (cart-adjacent)
- **Recently viewed** — M5+
- **Personalised recommendations** — M5+
- **Vendor admin UI** — M4 (vendor logs in to manage their products)
- **Inventory reservations** during checkout — M3
- **Multi-warehouse** inventory — M5+ if we ever need it
- **Bundles** ("buy these 3 together") — M5+
- **Multi-currency** — M5+ (UAE-only at launch, AED everywhere)
- **Tax calculations** — M3 (orders handle tax; products are AED inclusive)

---

## API surface

M2 endpoints, organized by sub-phase. Counts are estimates.

### Public read endpoints (~15 endpoints)
For storefront browsing. No auth required.

```
GET  /v3/categories                    list root categories + nesting
GET  /v3/categories/{slug}             category detail + path
GET  /v3/categories/{slug}/products    paginated products in category

GET  /v3/products                      paginated, filterable, with facets
GET  /v3/products/{slug}               product detail (full info + variants)
GET  /v3/products/{slug}/related       suggested similar products

GET  /v3/brands                        list active brands
GET  /v3/brands/{slug}                 brand detail
GET  /v3/brands/{slug}/products        brand's products

GET  /v3/vendors                       list active vendors
GET  /v3/vendors/{slug}                vendor detail (storefront)
GET  /v3/vendors/{slug}/products       vendor's products

GET  /v3/search                        Meilisearch-backed full-text + facets
GET  /v3/search/suggest                autocomplete hints
GET  /v3/search/popular                trending queries
```

### Authenticated read endpoints (~3 endpoints)

```
GET  /v3/me/cart                       list user's cart items
POST /v3/me/cart/items                 (M3) add item to cart
DELETE /v3/me/cart/items/{id}          (M3) remove item
```

(M3 actually implements these; M2 just creates the cart entity on
register/login flow if missing.)

### Admin write endpoints (~15 endpoints, locked behind admin auth)

These are admin-only. M2 ships them through `/v3/admin/*`. M4 will
add separate vendor-facing equivalents under `/v3/vendor/*` with
narrower scope (vendor sees only their own products).

```
GET    /v3/admin/categories                  full tree, including inactive
POST   /v3/admin/categories
PUT    /v3/admin/categories/{id}
DELETE /v3/admin/categories/{id}             (rejects if has products)

GET    /v3/admin/brands
POST   /v3/admin/brands
PUT    /v3/admin/brands/{id}
DELETE /v3/admin/brands/{id}

GET    /v3/admin/vendors
POST   /v3/admin/vendors
PUT    /v3/admin/vendors/{id}
DELETE /v3/admin/vendors/{id}

GET    /v3/admin/products
POST   /v3/admin/products
GET    /v3/admin/products/{id}
PUT    /v3/admin/products/{id}
DELETE /v3/admin/products/{id}              (soft-delete; sets is_active=false)

POST   /v3/admin/products/{id}/variants
PUT    /v3/admin/products/{id}/variants/{vid}
DELETE /v3/admin/products/{id}/variants/{vid}

POST   /v3/admin/products/{id}/images        (returns Spaces presigned URL)
DELETE /v3/admin/products/{id}/images/{iid}
```

Total: ~33 endpoints. Three times the surface of M1.7. This is why
M2 is the largest milestone.

---

## Sub-phase breakdown

M2 ships in seven sub-phases. Each is testable independently, each
ships its own commit, each has its own CI cycle.

### M2.1 — Schema + Vendor + Category foundations

**Scope:**
- Migrations: vendors, categories, brands tables
- Entities + repositories for all three
- Path maintenance logic on category create/move
- Admin endpoints (CRUD) for all three: ~12 endpoints
- Public read endpoints: GET /categories (tree), GET /categories/{slug},
  GET /brands, GET /vendors

**Effort estimate:** 8-12 hours. Schema design is the time sink.

**What proves it shipped:**
- Production has audit_log entries from creating "Test Vendor",
  "Test Category" via admin endpoints
- /v3/categories returns a tree
- Frontend can list categories and brands

**Decision points within phase:** category path generation logic
(eager on save vs lazy on read), category delete behavior (soft vs
reject if has products vs cascade to children).

### M2.2 — Product entity + admin CRUD

**Scope:**
- products table + entity + repository
- product_attributes table + entity
- Admin endpoints: ~6 endpoints
- Slug generation (kebab-case from name, with collision handling)
- Soft-delete via is_active flag

**Effort estimate:** 6-8 hours.

**What proves it shipped:**
- Admin can create a product with category, brand, vendor refs
- Audit log captures product creates/updates
- Product list endpoint pages correctly

### M2.3 — Variants + variant options + pricing

**Scope:**
- product_variants + product_variant_options tables
- Variant CRUD endpoints (sub-resource of products)
- SKU generation strategy (vendor-prefix + product-id + variant-id?)
- "From X AED" display query (min price across active variants)
- stock_quantity decrement helper (used by M3 orders)

**Effort estimate:** 8-10 hours. The variant options indexing model
needs care.

**What proves it shipped:**
- Admin can create a product with 3 size variants × 2 color variants
- Variant pricing displays correctly
- Filtering by size + color works in subsequent M2.5 search work

### M2.4 — Image storage + DigitalOcean Spaces

**Scope:**
- DO Spaces account setup (you do this), bucket creation, IAM key
- product_images table + entity
- Pre-signed URL generation endpoint for admin upload
- Direct serving via Spaces public-read URLs
- Image cleanup on product/variant delete (orphan check)
- Image reordering endpoint

**Effort estimate:** 6-8 hours, mostly fighting Spaces signing
quirks.

**What proves it shipped:**
- Admin uploads an image, sees it on the product detail endpoint
- Image URL works in a browser

**Risk:** Spaces signing has subtle differences from real S3. If
this hits more than a couple of hours of debugging, swap to local
storage with a "TODO move to Spaces" flag and proceed.

### M2.5 — Search infrastructure (Meilisearch)

**Scope:**
- Install Meilisearch on the server (aaPanel App Store; if absent,
  via apt + systemd)
- meilisearch/meilisearch-php client added to composer.json
- Search index sync: every product create/update/delete pushes to
  Meilisearch via a Doctrine event listener (or explicit emitter,
  same pattern as audit log)
- /v3/search endpoint with q, filters, facets, sort
- /v3/search/suggest for autocomplete
- /v3/search/popular for trending (denormalised query log)
- Index rebuild CLI command (`bin/search-rebuild.php`) for ops

**Effort estimate:** 10-14 hours. Meilisearch itself is fast to set
up; the index sync logic + facet filter API design is the work.

**What proves it shipped:**
- /v3/search?q=abaya returns Meilisearch results
- /v3/search?filters=color:black,price<300 filters correctly
- Frontend search bar works

**Risks:**
- Meilisearch operational overhead (one more thing to keep running)
- Sync drift (DB and Meilisearch get out of sync). The rebuild CLI
  is the recovery path.

### M2.6 — Cart entity stub + integration points

**Scope:**
- carts and cart_items tables + entities
- One-cart-per-user invariant via UNIQUE(user_id)
- GET /v3/me/cart endpoint (list current items)
- Cart created on first cart-item-add (M3 deliverable but stub the
  creation logic now to keep the test seam clean)
- NO add/remove endpoints — that's M3

**Effort estimate:** 3-4 hours.

**What proves it shipped:**
- GET /v3/me/cart returns empty cart for new users
- A direct DB-inserted cart_item shows up in the response

### M2.7 — Public read endpoints + polish

**Scope:**
- GET /v3/products with pagination, filters, sort
- GET /v3/products/{slug} (full detail with variants + images)
- GET /v3/products/{slug}/related (same category, fallback to brand)
- GET /v3/categories/{slug}/products
- GET /v3/brands/{slug}/products
- GET /v3/vendors/{slug}/products
- Response shape consistency across all public endpoints
- Integration tests covering frontend's likely browse paths

**Effort estimate:** 6-8 hours.

**What proves it shipped:**
- Frontend can build a complete category-browse page from these
  endpoints
- Response shapes are stable (a `product_summary` shape used in
  lists, a `product_full` shape used in detail views)

### Total effort estimate

| Phase | Effort | Cumulative |
|---|---|---|
| M2.1 schema foundation | 8-12h | 8-12h |
| M2.2 product CRUD | 6-8h | 14-20h |
| M2.3 variants + pricing | 8-10h | 22-30h |
| M2.4 image storage | 6-8h | 28-38h |
| M2.5 search | 10-14h | 38-52h |
| M2.6 cart stub | 3-4h | 41-56h |
| M2.7 public read | 6-8h | 47-64h |

**Range: 47-64 hours.** ~6-9 focused work sessions, conservatively.
Realistically a 2-3 week milestone if working part-time on this.

---

## Decisions deferred to in-phase

These don't need answers tonight; they get decided as the phase
they affect comes up.

### M2.1
- Category path eager-update vs lazy-update: leaning eager (rare
  operation; simpler queries downstream)
- ✅ **Category delete: SOFT-DELETE** (set `is_active=false`).
  Decided 2026-05-10. Products stay reachable; admin can hide a
  whole category without breaking order history. Hard-delete via
  admin only when category is empty AND has been inactive >30 days
  (M3+ cleanup script).
- ✅ **AdminAuthMiddleware: separate class.** Decided 2026-05-10.
  Runs after `AuthMiddleware`, reads `User` from request attribute,
  checks `isAdmin()`. Future sub-admin permissions extend this
  middleware without touching auth.
- ✅ **Initial seed data: SHIP `bin/seed-catalog.php`.** Decided
  2026-05-10. Idempotent — re-runs are no-ops (find-or-create on
  every entity). Creates ~3 vendors, ~5 categories, ~10 brands,
  ~30 products to give frontend + QA real data to test against.

### M2.2
- Slug collision handling: leaning "append `-2`, `-3` etc."
- Product soft-delete vs hard-delete: leaning soft (audit log + orders
  reference products)

### M2.3
- SKU generation: vendor-slug + sequential? or vendor-supplied with
  fallback generation?
- Compare-at-price display rules: only show if compare_at_price >
  price (defensive)

### M2.4
- Spaces region: probably FRA1 or NYC3 (close to UAE — both reasonable,
  test latency)
- Image processing pipeline: client-side resize before upload, or
  server-side via gd/imagick? Leaning client-side for M2; defer
  server-side to M5+

### M2.5
- Meilisearch index update strategy: synchronous on save vs async
  via queue? Leaning synchronous for simplicity; revisit if writes
  become slow
- Search analytics: log queries to Meilisearch events table for
  /search/popular? Or denormalise into Postgres? Leaning Postgres
  for query simplicity

### M2.7
- Pagination style: page+pageSize or cursor-based? Leaning
  page+pageSize (simpler frontend code; cursor-based has weird UX
  when items are added mid-browse)

---

## Cross-cutting concerns

### Audit log

M1.6.1.C wired audit log infrastructure for User/Address/Measurement.
Every M2 mutating endpoint should emit audit events too. The
AuditEmitter API is general (works for any entity); we just need
discipline to call it from each new admin controller.

Audit-worthy events:
- Vendor create/update/delete
- Category create/update/delete/move
- Brand create/update/delete
- Product create/update/delete
- Variant create/update/delete (high-frequency; consider
  whether to audit every stock-quantity change or just material
  changes)
- Image add/remove

### Sentry + structured logging

Both already wired (M1.6.2.A + B). M2 controllers automatically
benefit. No M2-specific work needed.

### Authentication

Admin endpoints need an `is_admin` check. M1.7 already loads role
flags into JWT claims; we just need an `AdminAuthMiddleware` (or
extend AuthMiddleware to take a "required roles" parameter).
Implement in M2.1 since it's needed by all admin endpoints in this
milestone.

### Rate limiting

Public read endpoints need rate limiting (per-IP) before launch.
M1.6.1.A's KeyValueStore makes this easy:
```php
$count = $cache->incr("ratelimit:ip:{$ip}");
if ($count === 1) $cache->expire("...", 60);
if ($count > 100) throw RateLimited;
```
Defer to M2.7 polish phase as the last item.

### i18n followup

Per Q5, we ship English-only. Adding Arabic later means:
1. ALTER TABLE products ADD COLUMN name_ar VARCHAR(300) NULL,
   description_ar TEXT NULL, short_description_ar TEXT NULL;
2. Same for categories, brands.
3. Admin UI: side-by-side English/Arabic input fields.
4. Public API: respect user.locale or `Accept-Language` header,
   serve the right column with English fallback.

This is a one-migration ALTER, plus controller logic for locale
selection. Feasible as M2.x mini-phase or fold into M3.

---

## Open questions for next session

These get answered before the affected phase starts; not before
M2.1.

1. **Spaces region**: FRA1 vs NYC3 vs SGP1. Test latency from UAE.
   Decide before M2.4.

2. **Meilisearch self-hosted vs cloud**: Meilisearch Cloud has a
   free tier for small data. Self-hosted is one less external
   dependency. Decide before M2.5.

3. ~~AdminAuthMiddleware scope~~ ✅ **RESOLVED 2026-05-10**: separate
   class. See M2.1 section above.

4. **Image upload size cap**: 5MB? 10MB? UI compression vs
   server-side? Decide in M2.4.

5. **Vendor-supplied SKU vs generated**: hybrid (vendor supplies if
   they have one, otherwise we generate)? Decide in M2.3.

6. ~~Initial seed data~~ ✅ **RESOLVED 2026-05-10**: ship
   `bin/seed-catalog.php`. See M2.1 section above.

---

## Risks

### High

- **Schema-lock risk**: M2 schema is referenced by M3, M4, M5. Any
  miss requires migrations across multiple milestones. Mitigation:
  pause and review schema sketch with fresh eyes before each
  migration commit.

- **Performance at scale**: 10k+ products with full-text search is
  the workload Meilisearch handles. Postgres FTS would not. The
  Q4 decision (Meilisearch in M2) protects against this, but only
  if Meilisearch is wired correctly with proper indexes. Mitigation:
  budget a "performance soak" check between M2.5 and M2.6.

- **Image storage cost surprise**: vendors uploading 4K images
  unprocessed will balloon Spaces costs. Mitigation: enforce upload
  limits at the presigned URL step (max-size policy).

### Medium

- **Vendor model leaking into M2**: Q3 picks "multi-vendor from day 1"
  but vendor onboarding is M4. Risk of M2 building admin-only flows
  that need refactoring when vendors self-onboard. Mitigation: keep
  the vendor entity simple in M2 (just "data record"), defer all
  vendor-portal work to M4 cleanly.

- **English-only at launch**: Q5 trade-off. Real risk of slower
  GTM in UAE market. Mitigation: capture Arabic followup as
  blocking-for-public-launch, NOT blocking-for-M2.

### Low

- **Audit log volume**: vendor admin tools could create dozens of
  audit rows per vendor session. If retention isn't wired (M3
  platform_settings), audit_log grows unbounded. Mitigation: M3
  schedules cleanup before M4 vendor traffic arrives.

- **Search index sync drift**: Meilisearch and Postgres can disagree.
  Mitigation: nightly rebuild cron + manual rebuild command for ops.

---

## What ships first when work starts

When implementation begins (next session, fresh head):

1. Read this plan again.
2. Resolve any "decisions deferred to in-phase" for M2.1.
3. Write the M2.1 implementation plan in chat (no doc; the doc is
   this one).
4. Get approval, ship phase-by-phase per the existing pattern.

The first commit to expect: schema migrations for vendors,
categories, brands. Conservative — three new tables, no entity
relationships beyond the basic FKs. Should be a clean, single-CI-cycle
phase.

If M2.1 takes 8h smoothly: M2 is on track for a 2-week timeline.
If M2.1 hits surprises (likely — schema decisions always surface
new issues), recalibrate the timeline at the end of phase 1 before
committing to phase 2.
