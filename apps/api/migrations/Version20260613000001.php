<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F2, Vendor size charts: the `vendor_size_charts` table.
 *
 * A vendor's published size chart, one row per (vendor, size), where
 * each row holds the garment dimensions for that size (bust, waist, hip,
 * length, neck, arm, armhole, shoulder). This is the STORE's size guide
 * (e.g. "our size M = bust 92cm"), distinct from a customer's own body
 * measurements (users → measurements).
 *
 * Mirrors the legacy per-(store,size) measurement table so the existing
 * vendor portal screen works unchanged.
 *
 * Schema
 * ======
 *   id          BIGSERIAL PRIMARY KEY
 *   vendor_id   BIGINT NOT NULL → vendors(id) ON DELETE CASCADE
 *   size        VARCHAR(40) NOT NULL  (e.g. 'S', 'M', '52')
 *   values      JSONB NOT NULL        (named numeric dimensions)
 *   created_at  TIMESTAMPTZ NOT NULL
 *   updated_at  TIMESTAMPTZ NOT NULL
 *
 * UNIQUE (vendor_id, size), a size appears once per store. This is what
 *   makes the legacy "Measurement already exists for your store" guard a
 *   DB-level invariant.
 */
final class Version20260613000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F2 — vendor_size_charts table (vendor size guide: one row per vendor+size)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE vendor_size_charts (
                id BIGSERIAL PRIMARY KEY,
                vendor_id BIGINT NOT NULL REFERENCES vendors(id) ON DELETE CASCADE,
                size VARCHAR(40) NOT NULL,
                values JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL
            )
        SQL);

        // One chart row per (vendor, size).
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_vendor_size_charts_vendor_size
            ON vendor_size_charts (vendor_id, size)
        SQL);

        // Hot read path: a vendor's size chart.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_vendor_size_charts_vendor
            ON vendor_size_charts (vendor_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS vendor_size_charts');
    }
}
