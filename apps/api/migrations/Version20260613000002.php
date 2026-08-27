<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * F3, Admin↔vendor direct messages: the `vendor_messages` table.
 *
 * A lightweight one-directional message from platform admins to a vendor
 * (the vendor's "inbox"). The admin send-side (SendVendorMessageController,
 * previously a stub) now persists here; the vendor read-side lists + marks
 * read. Distinct from support tickets (which are vendor/customer-initiated,
 * threaded, and status-tracked).
 *
 * Schema
 * ======
 *   id            BIGSERIAL PRIMARY KEY
 *   vendor_id     BIGINT NOT NULL → vendors(id) ON DELETE CASCADE
 *   sender_user_id BIGINT NULL    → users(id)   ON DELETE SET NULL  (the admin)
 *   subject       VARCHAR(200) NULL
 *   body          TEXT NOT NULL
 *   is_read       BOOLEAN NOT NULL DEFAULT FALSE
 *   read_at       TIMESTAMPTZ NULL
 *   created_at    TIMESTAMPTZ NOT NULL
 */
final class Version20260613000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'F3 — vendor_messages table (admin → vendor direct messages / vendor inbox)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE vendor_messages (
                id BIGSERIAL PRIMARY KEY,
                vendor_id BIGINT NOT NULL REFERENCES vendors(id) ON DELETE CASCADE,
                sender_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                subject VARCHAR(200) NULL,
                body TEXT NOT NULL,
                is_read BOOLEAN NOT NULL DEFAULT FALSE,
                read_at TIMESTAMPTZ NULL,
                created_at TIMESTAMPTZ NOT NULL
            )
        SQL);

        // Hot read path: a vendor's inbox, newest first.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_vendor_messages_vendor_created
            ON vendor_messages (vendor_id, created_at DESC)
        SQL);

        // Unread badge count.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_vendor_messages_vendor_unread
            ON vendor_messages (vendor_id) WHERE is_read = FALSE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS vendor_messages');
    }
}
