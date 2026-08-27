<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * v3 customer Follow, the `vendor_follow` table.
 *
 * One row per (user, vendor) the user follows. Net-new; replaces the
 * legacy /customer/follow + /customer/unfollow endpoints.
 *
 * Schema
 * ======
 *   id          BIGSERIAL PRIMARY KEY
 *   user_id     BIGINT NOT NULL  → users(id)    ON DELETE CASCADE
 *   vendor_id   BIGINT NOT NULL  → vendors(id)  ON DELETE CASCADE
 *   created_at  TIMESTAMPTZ NOT NULL
 *
 * UNIQUE (user_id, vendor_id), a vendor is followed at most once per
 *   user; makes POST idempotent (controller checks then no-ops, the DB
 *   constraint guards against races).
 * INDEX (user_id, created_at), hot read: a user's followed vendors,
 *   newest first.
 * INDEX (vendor_id), follower lookups / counts per vendor.
 */
final class Version20260615000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'v3 customer Follow — create vendor_follow table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE vendor_follow (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                vendor_id BIGINT NOT NULL REFERENCES vendors(id) ON DELETE CASCADE,
                created_at TIMESTAMPTZ NOT NULL
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_vendor_follow_user_vendor
            ON vendor_follow (user_id, vendor_id)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_vendor_follow_user_created
            ON vendor_follow (user_id, created_at)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_vendor_follow_vendor
            ON vendor_follow (vendor_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS vendor_follow');
    }
}
