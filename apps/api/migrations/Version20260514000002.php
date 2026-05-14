<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.1.5.5a — schema for deferred mobile catalog endpoints.
 *
 * Creates four schema artefacts to back the v3 endpoints that M3.1.5
 * couldn't flip because v3 had no equivalent:
 *
 *   1. products.search_tsv (generated tsvector column + GIN index)
 *      → backs GET /v3/products?q=<text> fulltext search
 *
 *   2. vendor_labels table
 *      → backs GET /v3/vendors/{slug}/labels (and by-legacy-id variant)
 *      → backs GET /v3/products?label_id=<legacy_id> filter
 *
 *   3. styles table
 *      → backs GET /v3/styles?type=<community|editorial>
 *
 *   4. style_products join table
 *      → embeds product references for the styles listing response
 *
 * Why these four together in one migration
 * =========================================
 * They're a single logical unit: the M3.1.5.5 phase's data foundation.
 * Splitting into four separate Doctrine migrations would mean four
 * descriptive entries in the version table for one phase, and would
 * force four `down()` reversals if rolled back. One migration = one
 * unit of forward/reverse motion.
 *
 * Generated tsvector vs trigger vs application-managed
 * =====================================================
 * Three ways to keep a tsvector in sync with row mutations:
 *
 *   (a) PostgreSQL generated column (STORED) — declarative, no trigger
 *       code, no chance of drift. Cost: every UPDATE recomputes the
 *       tsvector. For our write rate (product edits are rare) this
 *       cost is negligible.
 *
 *   (b) Trigger-maintained — flexible (can include data from other
 *       tables) but needs careful BEFORE INSERT/UPDATE OF handling.
 *
 *   (c) Application-managed — adapter or repo updates the tsvector on
 *       every write. Fragile (one missing call = stale index).
 *
 * Locked: (a) generated column. Tradeoff: can't include vendor.name
 * because generated columns can't reference other tables. Vendor-name
 * search is a follow-up enhancement (would use either a trigger or a
 * denormalised products.vendor_name_cache column).
 *
 * GIN vs GiST for the tsvector index
 * ===================================
 * GIN is faster for queries, slower for updates. GiST is the reverse.
 * For our read-heavy workload (search >> product edits), GIN wins.
 * Defaults: GIN's `fastupdate=on` mode batches updates into a pending
 * list and flushes periodically — fine for our profile.
 *
 * vendor_labels schema rationale
 * ===============================
 *   - legacy_label_id: BIGINT nullable + UNIQUE — preserves the original
 *     legacy id from the legacy WordPress/CodeIgniter schema so M3.1.5
 *     mobile compat can resolve `label: 5` → v3 label via the existing
 *     by-legacy-id pattern.
 *   - vendor_id: FK to vendors(id) ON DELETE CASCADE — labels are
 *     per-vendor-only (Q3 default locked); a vendor delete cleans up
 *     their labels.
 *   - slug: per-vendor unique (UNIQUE on vendor_id+slug). Two vendors
 *     can each have an "Eid Collection" label; same vendor can't have
 *     two with the same slug.
 *   - display_order: smallint nullable — defaults to legacy ordering
 *     when migrated; vendors/admins can re-sort later.
 *   - is_active: boolean — soft-disable a label without losing its
 *     product associations.
 *
 * Why no labels-products join table
 * ==================================
 * v3's products table already has `label_id INT NULLABLE` (added in
 * the M2 Day 2 product schema). One product belongs to at most one
 * label. If a vendor wants a product in multiple labels, that's a
 * future schema evolution (drop label_id, add product_labels join);
 * mobile UX today only uses single-label assignment so we match.
 *
 * The FK from products.label_id to vendor_labels.id is added in this
 * migration so the existing column gains referential integrity.
 *
 * styles schema rationale
 * ========================
 *   - legacy_style_id: BIGINT nullable + UNIQUE — same pattern as
 *     legacy_label_id; supports legacy-id-keyed reads if needed.
 *   - style_type: smallint enum (1=community, 2=editorial). Mobile
 *     sends `type: "community"` in the body — the request transform
 *     in M3.1.5.5g maps the string to the int. Why smallint not
 *     varchar? Cheaper, plus a CHECK constraint catches typos.
 *   - cover_image_url: 500 chars (matches products.primary_image_url).
 *   - total_price: DECIMAL(10,2) — denormalised sum of contained
 *     products' prices. Computed at style-creation time (admin UI);
 *     not auto-recomputed when product prices change. Acceptable
 *     because mobile uses total_price as a display-only "outfit
 *     total" hint, not a checkout amount.
 *   - is_active + display_order: same semantics as vendor_labels.
 *
 * style_products schema rationale
 * ================================
 *   - Composite PK (style_id, product_id) — a product appears in a
 *     given style at most once.
 *   - display_order: smallint NOT NULL — controls render order in the
 *     style's product strip. Mobile reads `style.products[0]`,
 *     `style.products[1]`, etc. — order matters.
 *   - Both FKs ON DELETE CASCADE — deleting a style or product
 *     cleans up the join.
 *
 * Idempotency
 * ===========
 * The migration is forward-only safe. Generated column expression
 * uses COALESCE so NULL name/description don't NPE the tsvector. The
 * GIN index build is fast on an empty (or small) products table; for
 * a populated production table this migration will pause writes
 * briefly during the index build — acceptable maintenance window.
 *
 * Reversibility
 * =============
 * down() drops everything in reverse FK order: style_products →
 * styles → (FK from products to vendor_labels) → vendor_labels →
 * (index on products.search_tsv) → (column products.search_tsv).
 * All operations are idempotent (IF EXISTS).
 */
final class Version20260514000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.1.5.5a — products.search_tsv + vendor_labels + styles + style_products schema';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // -----------------------------------------------------------------
        // 1) products.search_tsv — generated tsvector column + GIN index
        // -----------------------------------------------------------------
        //
        // Generated column uses simple_text concatenation with COALESCE
        // guards. Locale-aware searches use the 'english' config — fine
        // for the current English-first catalog. Arabic/multilingual
        // requires a config-per-row approach (out of scope here).
        //
        // ALTER TABLE ADD COLUMN GENERATED ALWAYS AS ... STORED is
        // supported on PG 12+. We're on PG 15+ (per the M1 Docker
        // baseline) so no compatibility concern.
        $this->addSql(<<<'SQL'
            ALTER TABLE products
            ADD COLUMN search_tsv tsvector
            GENERATED ALWAYS AS (
                to_tsvector(
                    'english',
                    COALESCE(name, '') || ' ' || COALESCE(description, '')
                )
            ) STORED
        SQL);

        // GIN index for `@@` operator queries. The build is fast on
        // small tables; for a populated production table this will
        // briefly pause INSERTs on products. Concurrent INDEX BUILD
        // would avoid that but requires a CONCURRENTLY block which
        // Doctrine migrations doesn't support within a transaction.
        // Accepting the brief block.
        $this->addSql('CREATE INDEX idx_products_search_tsv ON products USING GIN (search_tsv)');

        // -----------------------------------------------------------------
        // 2) vendor_labels — per-vendor merchandising collections
        // -----------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE vendor_labels (
                id BIGSERIAL PRIMARY KEY,
                legacy_label_id BIGINT UNIQUE,
                vendor_id BIGINT NOT NULL REFERENCES vendors(id) ON DELETE CASCADE,

                slug VARCHAR(120) NOT NULL,
                name VARCHAR(150) NOT NULL,

                display_order SMALLINT,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,

                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT uq_vendor_labels_vendor_slug UNIQUE (vendor_id, slug)
            )
        SQL);

        // Lookups: by legacy id (mobile compat), by vendor (storefront).
        $this->addSql('CREATE INDEX idx_vendor_labels_vendor ON vendor_labels (vendor_id)');
        $this->addSql('CREATE INDEX idx_vendor_labels_legacy ON vendor_labels (legacy_label_id) WHERE legacy_label_id IS NOT NULL');

        // -----------------------------------------------------------------
        // 3) products.label_id → vendor_labels.id FK
        // -----------------------------------------------------------------
        //
        // The column already exists (added in M2 Day 2 product schema
        // as INTEGER NULLABLE; populated from legacy products.label by
        // the migration step). We add the FK now that vendor_labels
        // exists. ON DELETE SET NULL: deleting a label de-tags its
        // products rather than cascading their deletion.
        //
        // NOTE: existing label_id values are LEGACY ids, not v3 ids,
        // until M3.1.5.5c migrates the labels and re-maps. The FK
        // can't be added with values pointing at non-existent rows.
        // So we add the FK as NOT VALID first; M3.1.5.5c validates it
        // after the labels migration runs. This lets us split the
        // schema and data migrations cleanly.
        $this->addSql(<<<'SQL'
            ALTER TABLE products
            ADD CONSTRAINT fk_products_label
            FOREIGN KEY (label_id) REFERENCES vendor_labels(id) ON DELETE SET NULL
            NOT VALID
        SQL);

        // -----------------------------------------------------------------
        // 4) styles — community/editorial curated outfits
        // -----------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE styles (
                id BIGSERIAL PRIMARY KEY,
                legacy_style_id BIGINT UNIQUE,

                slug VARCHAR(150) NOT NULL UNIQUE,
                name VARCHAR(200) NOT NULL,
                description TEXT,
                cover_image_url VARCHAR(500),

                style_type SMALLINT NOT NULL DEFAULT 1,
                total_price DECIMAL(10, 2) NOT NULL DEFAULT 0,

                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                display_order SMALLINT,

                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT chk_styles_type CHECK (style_type IN (1, 2))
            )
        SQL);

        $this->addSql('CREATE INDEX idx_styles_type_active ON styles (style_type, is_active)');
        $this->addSql('CREATE INDEX idx_styles_legacy ON styles (legacy_style_id) WHERE legacy_style_id IS NOT NULL');

        // -----------------------------------------------------------------
        // 5) style_products — many-to-many between styles and products
        // -----------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE style_products (
                style_id BIGINT NOT NULL REFERENCES styles(id) ON DELETE CASCADE,
                product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                display_order SMALLINT NOT NULL DEFAULT 0,

                PRIMARY KEY (style_id, product_id)
            )
        SQL);

        // Reverse-lookup index: "what styles contain this product?"
        // Useful for product-page cross-promotion in a future enhancement.
        $this->addSql('CREATE INDEX idx_style_products_product ON style_products (product_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop in reverse FK dependency order.
        $this->addSql('DROP TABLE IF EXISTS style_products');
        $this->addSql('DROP TABLE IF EXISTS styles');

        // Drop the FK before dropping the labels table.
        $this->addSql('ALTER TABLE products DROP CONSTRAINT IF EXISTS fk_products_label');
        $this->addSql('DROP TABLE IF EXISTS vendor_labels');

        // tsvector index + column.
        $this->addSql('DROP INDEX IF EXISTS idx_products_search_tsv');
        $this->addSql('ALTER TABLE products DROP COLUMN IF EXISTS search_tsv');
    }
}
