<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marketplace P9 — Store Hotlinks & Style-Me codes. Creates the canonical
 * hotlinks table (one short code per store/style target) and the
 * hotlink_clicks ledger that drives click + conversion analytics.
 */
final class Version20260923000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P9 — hotlinks (canonical short links) + hotlink_clicks ledger.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE hotlinks (
                id                  BIGSERIAL    PRIMARY KEY,
                code                VARCHAR(24)  NOT NULL UNIQUE,
                target_type         VARCHAR(16)  NOT NULL,
                target_slug         VARCHAR(200) NOT NULL,
                created_by_user_id  BIGINT       NULL
                                        REFERENCES users(id) ON DELETE SET NULL,
                click_count         INTEGER      NOT NULL DEFAULT 0,
                created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);
        $this->addSql(
            'ALTER TABLE hotlinks ADD CONSTRAINT chk_hotlink_target_type '
            . "CHECK (target_type IN ('store', 'style'))"
        );
        // Canonical: one hotlink per (target_type, target_slug).
        $this->addSql('CREATE UNIQUE INDEX uniq_hotlink_target ON hotlinks (target_type, target_slug)');

        $this->addSql(<<<'SQL'
            CREATE TABLE hotlink_clicks (
                id          BIGSERIAL   PRIMARY KEY,
                hotlink_id  BIGINT      NOT NULL
                                REFERENCES hotlinks(id) ON DELETE CASCADE,
                user_id     BIGINT      NULL
                                REFERENCES users(id) ON DELETE SET NULL,
                session_id  VARCHAR(64) NULL,
                created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        SQL);
        // Per-hotlink time series (clicks-over-time + top links).
        $this->addSql('CREATE INDEX idx_hotlink_clicks_hotlink ON hotlink_clicks (hotlink_id, created_at)');
        // Conversion attribution join (a click's user → orders within the window).
        $this->addSql('CREATE INDEX idx_hotlink_clicks_user ON hotlink_clicks (user_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS hotlink_clicks');
        $this->addSql('DROP TABLE IF EXISTS hotlinks');
    }
}
