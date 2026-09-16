<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * WhatsApp Commerce (Phase 4, feature 2).
 *
 * `users.whatsapp_phone` holds the E.164 WhatsApp number a customer has linked
 * to their account (OTP-verified) for the WhatsApp Commerce channel. The inbound
 * webhook resolves a message's sender to a user via this column, so it is
 * indexed. Nullable — most users never link one, and WhatsApp discovery works
 * for unlinked numbers too (linking only adds personalisation + attribution).
 *
 * The WhatsApp-link OTP purpose is a schema-free string on user_otp_attempts, so
 * no other column is needed.
 */
final class Version20260916000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.whatsapp_phone for the WhatsApp Commerce channel.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE users ADD COLUMN whatsapp_phone VARCHAR(25) DEFAULT NULL');
        // Partial UNIQUE: a WhatsApp number links to at most one live account, so
        // the webhook resolves a sender deterministically. NULLs are excluded
        // (most users never link one) and soft-deleted rows are ignored so a
        // number frees up for re-linking after an account is deleted.
        $this->addSql(
            'CREATE UNIQUE INDEX idx_users_whatsapp_phone ON users (whatsapp_phone) '
            . 'WHERE whatsapp_phone IS NOT NULL AND deleted_at IS NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_users_whatsapp_phone');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS whatsapp_phone');
    }
}
