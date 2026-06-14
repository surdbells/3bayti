<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add read-tracking to `notification_logs` so the sent-notification audit
 * can double as the vendor's in-app notification feed (the top-bar bell),
 * replacing the legacy /vendors/common/notifications calls.
 *
 *   is_read  BOOLEAN NOT NULL DEFAULT FALSE
 *   read_at  TIMESTAMPTZ NULL
 */
final class Version20260614000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_read/read_at to notification_logs (vendor notification feed)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE notification_logs ADD COLUMN IF NOT EXISTS is_read BOOLEAN NOT NULL DEFAULT FALSE');
        $this->addSql('ALTER TABLE notification_logs ADD COLUMN IF NOT EXISTS read_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        // Feed query is (recipient, status, is_read, sent_at) — index the hot path.
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_notification_logs_recipient_sent
                ON notification_logs (recipient, sent_at)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('DROP INDEX IF EXISTS idx_notification_logs_recipient_sent');
        $this->addSql('ALTER TABLE notification_logs DROP COLUMN IF EXISTS read_at');
        $this->addSql('ALTER TABLE notification_logs DROP COLUMN IF EXISTS is_read');
    }
}
