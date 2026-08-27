<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M2.1, Catalog foundations: vendors, categories, brands
 * ========================================================
 *
 * Three new tables that together form the static skeleton of the
 * catalog. Products (M2.2) hang off these via FKs. Variants and
 * everything else come later.
 *
 * Design rationale lives in docs/plans/m2-catalog.md. Highlights:
 *
 *   - vendors: multi-vendor from day 1 (Q3). Even if we only
 *     populate one vendor at launch, the FK on products needs to
 *     point somewhere real. Vendor authentication / dashboard is
 *     M4 work; M2 vendors are read-only data records managed by
 *     admin.
 *
 *   - categories: adjacency list (parent_id) + denormalised path
 *     (Q6). Path lets us answer "products in /clothing/womens/abayas
 *     and below" via a simple LIKE, no recursive CTE needed.
 *
 *   - brands: simple lookup table. Not all products have a brand
 *     (made-to-order custom abayas often don't), so brand_id on
 *     products is nullable.
 *
 *   - All three soft-delete via is_active flag (D1 decision).
 *     Hard-delete is forbidden once any product references the row.
 *
 * What's NOT here
 * ---------------
 *   - Vendor onboarding fields (bank account, KYC docs, settlement
 *     details), M4
 *   - Vendor-product relationships beyond the FK, products table
 *     itself ships M2.2
 *   - Category-attribute templates (per-category required attribute
 *     schemas), M5+ if we ever need them
 *   - Brand-category many-to-many, not needed; products link to
 *     both directly
 */
final class Version20260510000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M2.1 — create vendors, categories, brands tables';
    }

    public function up(Schema $schema): void
    {
        // ----- vendors --------------------------------------------------
        $this->addSql(<<<SQL
            CREATE TABLE vendors (
                id                 BIGSERIAL    PRIMARY KEY,
                legacy_vendor_id   INTEGER      UNIQUE,
                slug               VARCHAR(100) NOT NULL UNIQUE,
                name               VARCHAR(200) NOT NULL,
                description        TEXT,
                logo_url           VARCHAR(500),
                cover_image_url    VARCHAR(500),
                contact_email      VARCHAR(255) NOT NULL,
                contact_phone      VARCHAR(20),
                is_active          BOOLEAN      NOT NULL DEFAULT TRUE,
                is_verified        BOOLEAN      NOT NULL DEFAULT FALSE,
                -- Commission as percent. 10.00 means we keep 10% of order
                -- value. Capped 0-100 via CHECK.
                commission_rate    NUMERIC(5,2) NOT NULL DEFAULT 10.00,
                created_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT vendors_commission_check
                    CHECK (commission_rate >= 0 AND commission_rate <= 100)
            )
            SQL);

        // Slug lookup is the common case (vendor storefront URLs use
        // the slug, not the id). PK already indexes id; we add slug.
        // legacy_vendor_id already has a unique constraint = index.
        $this->addSql('CREATE INDEX vendors_active_idx ON vendors (is_active)');

        $this->addSql(<<<SQL
            COMMENT ON TABLE vendors IS
                'M2.1 — multi-vendor marketplace participants. Vendor auth/dashboard is M4.'
            SQL);

        // ----- categories -----------------------------------------------
        // Adjacency list with denormalised path for fast subtree queries.
        // The 'path' column is maintained by application code on
        // category create + move (rare operations).
        $this->addSql(<<<SQL
            CREATE TABLE categories (
                id             BIGSERIAL    PRIMARY KEY,
                parent_id      BIGINT,
                slug           VARCHAR(100) NOT NULL UNIQUE,
                name           VARCHAR(150) NOT NULL,
                description    TEXT,
                display_order  INTEGER      NOT NULL DEFAULT 0,
                image_url      VARCHAR(500),
                is_active      BOOLEAN      NOT NULL DEFAULT TRUE,
                -- Denormalised full path: /clothing/womens/abayas
                -- Built from slugs of self + all ancestors.
                -- Length 500 = ~15 levels deep at 30 chars/segment.
                -- We'll never exceed that for a retail catalog.
                path           VARCHAR(500) NOT NULL,
                created_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT categories_parent_fkey
                    FOREIGN KEY (parent_id) REFERENCES categories(id)
                    ON DELETE RESTRICT
            )
            SQL);

        // parent_id queries: "what are X's children?"
        $this->addSql('CREATE INDEX categories_parent_idx ON categories (parent_id)');
        // Path-prefix queries: "everything under /clothing/womens/%"
        $this->addSql('CREATE INDEX categories_path_idx ON categories (path)');
        // Active filter joined with display_order for menu rendering
        $this->addSql('CREATE INDEX categories_active_order_idx ON categories (is_active, display_order)');

        $this->addSql(<<<SQL
            COMMENT ON TABLE categories IS
                'M2.1 — adjacency list tree of product categories. path column denormalised for subtree queries.'
            SQL);

        // ----- brands ---------------------------------------------------
        $this->addSql(<<<SQL
            CREATE TABLE brands (
                id          BIGSERIAL    PRIMARY KEY,
                slug        VARCHAR(100) NOT NULL UNIQUE,
                name        VARCHAR(150) NOT NULL,
                logo_url    VARCHAR(500),
                is_active   BOOLEAN      NOT NULL DEFAULT TRUE,
                created_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
            SQL);

        $this->addSql('CREATE INDEX brands_active_idx ON brands (is_active)');

        $this->addSql(<<<SQL
            COMMENT ON TABLE brands IS
                'M2.1 — product brand reference (nullable FK from products).'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Reverse order, brands and categories can have no dependents
        // yet (products table doesn't exist until M2.2). Vendors same.
        // If down() is run after M2.2 ships, the products table's FKs
        // would prevent these drops, which is fine: catastrophic rollback
        // should be explicit, not silent CASCADE.
        $this->addSql('DROP INDEX IF EXISTS brands_active_idx');
        $this->addSql('DROP TABLE IF EXISTS brands');

        $this->addSql('DROP INDEX IF EXISTS categories_active_order_idx');
        $this->addSql('DROP INDEX IF EXISTS categories_path_idx');
        $this->addSql('DROP INDEX IF EXISTS categories_parent_idx');
        $this->addSql('DROP TABLE IF EXISTS categories');

        $this->addSql('DROP INDEX IF EXISTS vendors_active_idx');
        $this->addSql('DROP TABLE IF EXISTS vendors');
    }
}
