<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Phase 5, chat notification debounce anchors. Per-party "last notified"
 * timestamps on chat_conversations so unread email/push notifications can
 * be debounced (one ping per burst, re-armed when the recipient reads).
 */
final class Version20260614000006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add customer_last_notified_at / vendor_last_notified_at to chat_conversations';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql('ALTER TABLE chat_conversations ADD COLUMN IF NOT EXISTS customer_last_notified_at TIMESTAMPTZ DEFAULT NULL');
        $this->addSql('ALTER TABLE chat_conversations ADD COLUMN IF NOT EXISTS vendor_last_notified_at TIMESTAMPTZ DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql('ALTER TABLE chat_conversations DROP COLUMN IF EXISTS customer_last_notified_at');
        $this->addSql('ALTER TABLE chat_conversations DROP COLUMN IF EXISTS vendor_last_notified_at');
    }
}
