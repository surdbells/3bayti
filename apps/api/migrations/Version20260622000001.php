<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vendor location, add emirate + country to vendors, for the admin
 * Stores screen's location filter + the store editor's location fields.
 *
 * New columns
 * ===========
 *   - emirate VARCHAR(60) NULL
 *       The emirate the store operates from (UAE-only platform). One of
 *       the seven emirates. Best-effort backfilled below from the
 *       free-text store_address; null where no emirate name is found.
 *
 *   - country VARCHAR(60) NULL
 *       The store's country. UAE-only platform, so every existing row is
 *       backfilled to 'United Arab Emirates'. Kept nullable + explicit so
 *       the admin UI can surface/correct it and a future multi-country
 *       expansion has the column in place.
 *
 * Both columns are additive + nullable, so this migration applies online
 * with no row rewrites blocking reads.
 *
 * Backfill
 * ========
 * emirate: case-insensitive match of store_address against each of the
 *   seven emirate names (longest/most-specific names first so e.g.
 *   "Ras Al Khaimah" wins over a stray "Al"). Only the FIRST matching
 *   UPDATE touches a row (the `emirate IS NULL` guard), so a store whose
 *   address mentions two emirates keeps the most-specific match.
 * country: set to 'United Arab Emirates' for every existing row.
 *
 * Reversibility: down() drops both columns (IF EXISTS).
 */
final class Version20260622000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Vendor location — add emirate + country to vendors (best-effort backfill from store_address; country = United Arab Emirates)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE vendors
                ADD COLUMN emirate VARCHAR(60) DEFAULT NULL,
                ADD COLUMN country VARCHAR(60) DEFAULT NULL
        SQL);

        // Backfill country for every existing row (UAE-only platform).
        $this->addSql(<<<'SQL'
            UPDATE vendors SET country = 'United Arab Emirates'
        SQL);

        // Backfill emirate by case-insensitive substring match against
        // store_address. Ordered most-specific (longest) name first;
        // each UPDATE only fills rows still NULL so the first match wins.
        $emirates = [
            'Ras Al Khaimah',
            'Umm Al Quwain',
            'Abu Dhabi',
            'Fujairah',
            'Sharjah',
            'Ajman',
            'Dubai',
        ];

        foreach ($emirates as $name) {
            // Single-quote escaping: none of the names contain quotes,
            // but addslashes keeps this robust if the list ever changes.
            $escaped = str_replace("'", "''", $name);
            $this->addSql(
                "UPDATE vendors SET emirate = '{$escaped}' "
                . "WHERE emirate IS NULL "
                . "AND store_address IS NOT NULL "
                . "AND store_address ILIKE '%{$escaped}%'"
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE vendors
                DROP COLUMN IF EXISTS emirate,
                DROP COLUMN IF EXISTS country
        SQL);
    }
}
