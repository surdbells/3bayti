<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Storefront search overhaul (SEARCH #4) — make GET /v3/products `q`
 * respond to short / prefix queries and widen its scope.
 *
 * Why
 * ===
 * The previous search relied solely on `products.search_tsv`
 * (to_tsvector over name + description) matched with
 * `websearch_to_tsquery`. Two problems for a consumer search box:
 *
 *   1. Lexeme-only matching. tsquery matches whole stemmed lexemes,
 *      so a 2-character query ("ab", "si") matches nothing — the
 *      term never becomes a complete lexeme. Mobile fires search from
 *      2 chars, so the common case returned an empty list.
 *
 *   2. Narrow scope. Only product name + description were indexed.
 *      Searching a store/vendor name ("almas") or a category name
 *      ("abaya") returned nothing even when obviously-relevant
 *      products existed.
 *
 * Fix
 * ===
 * Switch the storefront `q` clause to case-insensitive substring
 * (ILIKE '%term%') across four columns:
 *   - products.name
 *   - products.description
 *   - vendors.name        (store / vendor name)
 *   - categories.name     (category name)
 *
 * Substring match naturally satisfies prefix + infix + 2-char queries.
 * To keep it indexed and fast we enable the `pg_trgm` extension and add
 * trigram GIN indexes on each searched column. PostgreSQL's planner can
 * use a trigram GIN index to accelerate `col ILIKE '%term%'` (and
 * `LOWER(col) LIKE '%term%'`) once the term is >= 3 chars; for 1-2 char
 * terms it falls back to a scan, which is acceptable at catalog scale
 * (~2k products) and still correct.
 *
 * The old search_tsv column + idx_products_search_tsv GIN index are
 * left in place (still used by TSRANK for the `sort=relevance` ranking
 * ORDER BY). Only the WHERE-clause matching strategy changes, in
 * ProductRepository::findActivePaginated.
 *
 * Indexes use `gin_trgm_ops`. CREATE INDEX (non-concurrent) briefly
 * locks writes; acceptable at current table sizes and consistent with
 * the original idx_products_search_tsv build (Version20260514000002).
 *
 * Reversibility: down() drops the four trigram indexes. It does NOT
 * drop the pg_trgm extension (other objects may come to depend on it,
 * and dropping an extension that is already present elsewhere would be
 * destructive). Idempotent guards (IF NOT EXISTS / IF EXISTS) keep both
 * directions safe to re-run.
 */
final class Version20260622000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Storefront search — pg_trgm + trigram GIN indexes on product/vendor/category names for short & wide ILIKE search';
    }

    /**
     * Run WITHOUT the transaction wrapper. We probe for / attempt to enable
     * pg_trgm and must tolerate a missing CREATE privilege (managed/shared
     * Postgres where the app role can't create extensions). A failed
     * CREATE EXTENSION inside a transaction poisons it (every later
     * statement then errors with "current transaction is aborted"), so we
     * opt out of the wrapper and degrade gracefully instead — skipping the
     * trigram indexes while keeping the migration green. The ILIKE search
     * still works unindexed; the indexes are a pure performance accelerator.
     */
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // Trigram extension powers index-accelerated ILIKE '%term%'. Probe
        // first (so an already-present extension never triggers a needless
        // privileged CREATE), then attempt to create it, tolerating a
        // missing CREATE privilege.
        $hasTrgm = (bool) $this->connection->fetchOne(
            "SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'"
        );
        if (!$hasTrgm) {
            try {
                $this->connection->executeStatement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
                $hasTrgm = (bool) $this->connection->fetchOne(
                    "SELECT 1 FROM pg_extension WHERE extname = 'pg_trgm'"
                );
            } catch (\Throwable) {
                $hasTrgm = false;
            }
        }

        if (!$hasTrgm) {
            $this->warnIf(
                true,
                'pg_trgm is not enabled and the DB role cannot create it — skipping trigram '
                . 'GIN indexes. Storefront search still works (unindexed scan). To enable '
                . 'index acceleration, have a DB superuser run "CREATE EXTENSION pg_trgm;" then '
                . 'create idx_{products_name,products_description,vendors_name,categories_name}_trgm '
                . '(GIN on LOWER(col) gin_trgm_ops).'
            );

            return;
        }

        // Index LOWER(col) so the planner can use the index for the
        // case-insensitive `LOWER(col) LIKE '%term%'` form emitted by
        // the repository (DQL-portable LOWER(...) LIKE), and ILIKE alike.
        $this->addSql(
            "CREATE INDEX IF NOT EXISTS idx_products_name_trgm "
            . "ON products USING GIN (LOWER(name) gin_trgm_ops)"
        );
        $this->addSql(
            "CREATE INDEX IF NOT EXISTS idx_products_description_trgm "
            . "ON products USING GIN (LOWER(description) gin_trgm_ops)"
        );
        $this->addSql(
            "CREATE INDEX IF NOT EXISTS idx_vendors_name_trgm "
            . "ON vendors USING GIN (LOWER(name) gin_trgm_ops)"
        );
        $this->addSql(
            "CREATE INDEX IF NOT EXISTS idx_categories_name_trgm "
            . "ON categories USING GIN (LOWER(name) gin_trgm_ops)"
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql('DROP INDEX IF EXISTS idx_products_name_trgm');
        $this->addSql('DROP INDEX IF EXISTS idx_products_description_trgm');
        $this->addSql('DROP INDEX IF EXISTS idx_vendors_name_trgm');
        $this->addSql('DROP INDEX IF EXISTS idx_categories_name_trgm');

        // Intentionally NOT dropping the pg_trgm extension — see docblock.
    }
}
