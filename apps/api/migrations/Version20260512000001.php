<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Day 1 of the 10-day rollout, Product, ProductImage, ProductReview schema.
 *
 * Designed against the legacy production data discovered on 12 May 2026
 * (see docs/discovery/legacy-mysql-schema.md). Notes specific to legacy:
 *
 * legacy_product_id (kept), int from legacy `products.product_id` (bigint
 *     unsigned). Stored in int because PHP int is 64-bit on production
 *     and we don't expect overflow before retirement.
 *
 * vendor_id / category_id, FK to OUR vendors / categories. Migration script
 *     translates legacy store_id / category_id via legacy_*_id columns.
 *
 * status enum, values from legacy: 'published', 'deleted', 'draft'. We
 *     standardize to 'active', 'draft', 'soft_deleted' in v3. Migration
 *     does the value mapping. Storing as string column (not Postgres enum)
 *     to make adding new values trivial (e.g., 'paused', 'pending_review').
 *
 * primary_image_url, single image, the "lead" image shown in cards.
 *     Migrated from legacy `image_1`. We point this URL at the legacy
 *     server's image directory (https://api.3bayti.ae/vendors/products/<file>)
 *     since the legacy filesystem keeps serving images through M5.
 *
 * available_sizes (jsonb), instead of 22 size_* boolean columns. Migration
 *     reads the booleans and emits an array like ["S","M","L","52","53"].
 *     jsonb (not json) so we can index on contains-element queries:
 *         WHERE available_sizes @> '["M"]'::jsonb
 *
 * available_colors (jsonb), array of strings. Legacy was a comma-separated
 *     varchar; migration splits it.
 *
 * images (jsonb), array of image URLs for the gallery. Legacy `images`
 *     LONGTEXT is a JSON array of paths. Migration parses it and emits a
 *     proper jsonb column.
 *
 * Flags as individual booleans not jsonb, we want indexes on each
 *     (is_featured, is_new, is_hot, is_sale, try_on_enabled) for fast
 *     filtering like /v3/products?filter=featured.
 *
 * delivery_info (jsonb), bundles legacy delivery_time, custom_delivery_time,
 *     delivery_note. Frontend renders if present.
 *
 * extra_msmt, kept as a comma-separated string for now per legacy; v3
 *     should redesign as a structured measurements_required array later.
 *
 * Stock model: we store quantity at product level (matches legacy schema -
 *     no per-size/color stock). When a vendor lists 5 sizes available, they
 *     mean "I have stock in any of these sizes"; the 'quantity' is the total
 *     across sizes.
 *
 * Indexes
 * -------
 * Primary purpose: enable /v3/products fast filters:
 *   - vendor_id, category_id for list-by-vendor / list-by-category
 *   - status for filtering out deleted/draft from public reads
 *   - is_active (computed from status; helper boolean) for cheap filter
 *   - (status, created_at DESC) for new-arrivals queries
 *   - (status, is_featured) for featured filter
 *   - slug unique for /v3/products/:slug
 *   - legacy_product_id unique for idempotent migration re-runs
 *
 * Reviews
 * -------
 * Reviews tie to product via product_id FK. Legacy `ec_reviews` has
 * product_name (string) and store_id, but NOT product_id, migration
 * resolves product_name → product_id via lookup. If lookup fails, review
 * is logged + skipped (we have only 27 reviews so manual cleanup is fine).
 */
final class Version20260512000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M2.2.0 — Product + ProductImage + ProductReview schema for legacy catalog migration';
    }

    public function up(Schema $schema): void
    {
        // -----------------------------------------------------------
        // products
        // -----------------------------------------------------------
        $this->addSql(<<<SQL
            CREATE TABLE products (
                id                  BIGSERIAL    PRIMARY KEY,
                legacy_product_id   INTEGER      NULL UNIQUE,
                vendor_id           BIGINT       NOT NULL REFERENCES vendors(id) ON DELETE RESTRICT,
                category_id         BIGINT       NULL     REFERENCES categories(id) ON DELETE SET NULL,
                slug                VARCHAR(200) NOT NULL UNIQUE,
                name                VARCHAR(255) NOT NULL,
                description         TEXT         NULL,
                status              VARCHAR(20)  NOT NULL DEFAULT 'draft',
                is_active           BOOLEAN      NOT NULL DEFAULT TRUE,

                -- Pricing
                price               NUMERIC(10,2) NOT NULL DEFAULT 0,
                sale_price          NUMERIC(10,2) NULL,
                cost_per_item       NUMERIC(10,2) NULL,

                -- Stock
                stock_quantity      INTEGER      NOT NULL DEFAULT 0,
                allow_oversell      BOOLEAN      NOT NULL DEFAULT FALSE,
                stock_status        VARCHAR(20)  NOT NULL DEFAULT 'in_stock',
                min_order_qty       INTEGER      NULL,
                max_order_qty       INTEGER      NULL,

                -- Images (primary + gallery)
                primary_image_url   VARCHAR(500) NULL,
                images              JSONB        NOT NULL DEFAULT '[]'::jsonb,

                -- Variants (encoded from legacy 22 size_* booleans + colors varchar)
                available_sizes     JSONB        NOT NULL DEFAULT '[]'::jsonb,
                available_colors    JSONB        NOT NULL DEFAULT '[]'::jsonb,

                -- Display flags
                is_featured         BOOLEAN      NOT NULL DEFAULT FALSE,
                is_new              BOOLEAN      NOT NULL DEFAULT FALSE,
                is_hot              BOOLEAN      NOT NULL DEFAULT FALSE,
                is_sale             BOOLEAN      NOT NULL DEFAULT FALSE,
                try_on_enabled      BOOLEAN      NOT NULL DEFAULT FALSE,

                -- Measurements
                requires_extra_msmt BOOLEAN      NOT NULL DEFAULT FALSE,
                extra_msmt          VARCHAR(255) NULL,

                -- Delivery
                delivery_info       JSONB        NULL,

                -- Collection / labeling
                collection_id       BIGINT       NULL,
                label_id            INTEGER      NULL,

                -- Timestamps
                created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);

        // Indexes, designed for the /v3/products filter+sort hot paths
        $this->addSql('CREATE INDEX products_vendor_idx ON products (vendor_id)');
        $this->addSql('CREATE INDEX products_category_idx ON products (category_id)');
        $this->addSql('CREATE INDEX products_status_idx ON products (status)');
        $this->addSql('CREATE INDEX products_active_idx ON products (is_active) WHERE is_active = TRUE');

        // Composite for "new arrivals", "best sellers", "featured" listings
        // (each filters active products by a flag, sorted by created_at)
        $this->addSql('CREATE INDEX products_active_created_idx ON products (is_active, created_at DESC) WHERE is_active = TRUE');
        $this->addSql('CREATE INDEX products_featured_idx ON products (is_featured) WHERE is_active = TRUE AND is_featured = TRUE');
        $this->addSql('CREATE INDEX products_new_idx ON products (is_new) WHERE is_active = TRUE AND is_new = TRUE');

        // jsonb sizes/colors, GIN for "find products available in size M"
        $this->addSql('CREATE INDEX products_sizes_idx ON products USING GIN (available_sizes)');
        $this->addSql('CREATE INDEX products_colors_idx ON products USING GIN (available_colors)');

        // -----------------------------------------------------------
        // product_images, separate table for richer per-image metadata
        //                  (legacy only had a JSON array; v3 supports per-
        //                  image alt text, dimensions, ordering)
        // -----------------------------------------------------------
        $this->addSql(<<<SQL
            CREATE TABLE product_images (
                id           BIGSERIAL    PRIMARY KEY,
                product_id   BIGINT       NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                url          VARCHAR(500) NOT NULL,
                alt_text     VARCHAR(255) NULL,
                width        INTEGER      NULL,
                height       INTEGER      NULL,
                display_order INTEGER     NOT NULL DEFAULT 0,
                is_primary   BOOLEAN      NOT NULL DEFAULT FALSE,
                created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);

        $this->addSql('CREATE INDEX product_images_product_idx ON product_images (product_id, display_order)');
        // Postgres partial unique index, only one is_primary per product.
        $this->addSql('CREATE UNIQUE INDEX product_images_primary_unique ON product_images (product_id) WHERE is_primary = TRUE');

        // -----------------------------------------------------------
        // product_reviews
        // -----------------------------------------------------------
        $this->addSql(<<<SQL
            CREATE TABLE product_reviews (
                id              BIGSERIAL    PRIMARY KEY,
                legacy_review_id INTEGER     NULL UNIQUE,
                product_id      BIGINT       NULL REFERENCES products(id) ON DELETE SET NULL,
                vendor_id       BIGINT       NULL REFERENCES vendors(id) ON DELETE SET NULL,
                user_id         BIGINT       NULL REFERENCES users(id) ON DELETE SET NULL,

                -- Denorm fields for archival (in case the source rows are
                -- deleted/anonymised later)
                reviewer_name   VARCHAR(191) NULL,
                reviewer_email  VARCHAR(191) NULL,
                product_name_snapshot VARCHAR(255) NULL,

                star            NUMERIC(2,1) NOT NULL CHECK (star >= 0 AND star <= 5),
                title           TEXT         NULL,
                comment         TEXT         NULL,
                vendor_reply    TEXT         NULL,
                status          VARCHAR(20)  NOT NULL DEFAULT 'pending',
                is_verified     BOOLEAN      NOT NULL DEFAULT FALSE,
                helpful_count   INTEGER      NOT NULL DEFAULT 0,

                created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);

        $this->addSql('CREATE INDEX product_reviews_product_idx ON product_reviews (product_id, status) WHERE status = \'approved\'');
        $this->addSql('CREATE INDEX product_reviews_vendor_idx ON product_reviews (vendor_id, status)');
        $this->addSql('CREATE INDEX product_reviews_created_idx ON product_reviews (created_at DESC)');
    }

    public function down(Schema $schema): void
    {
        // Drop in reverse FK order
        $this->addSql('DROP TABLE IF EXISTS product_reviews');
        $this->addSql('DROP TABLE IF EXISTS product_images');
        $this->addSql('DROP TABLE IF EXISTS products');
    }
}
