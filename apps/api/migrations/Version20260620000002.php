<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gift-card recipient delivery, add optional recipient contact +
 * per-channel delivery timestamps to gift_cards.
 *
 * New columns
 * ===========
 *   - recipient_email VARCHAR(255) NULL
 *       Optional recipient email. When set, the card is auto-delivered
 *       by email on activation (or by the scheduled-delivery cron).
 *       Null = buyer shares the code manually (today's behaviour).
 *
 *   - recipient_phone VARCHAR(32) NULL
 *       Optional recipient phone (E.164-ish, stored as given). When
 *       set, the card is auto-delivered by SMS on activation/cron.
 *
 *   - email_delivered_at TIMESTAMPTZ NULL
 *       When the delivery email was sent. Null until delivered. The
 *       per-channel timestamp is the idempotency guard, a non-null
 *       value means "already delivered, never send again".
 *
 *   - sms_delivered_at TIMESTAMPTZ NULL
 *       When the delivery SMS was sent. Same idempotency semantics.
 *
 * All four columns are additive + nullable, so this migration is safe
 * to apply online with no row rewrites blocking reads, and fully
 * backward-compatible (existing cards have no recipient contact, so
 * nothing is auto-delivered for them).
 *
 * Reversibility: down() drops the four columns (IF EXISTS).
 */
final class Version20260620000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gift-card delivery — add recipient_email/recipient_phone/email_delivered_at/sms_delivered_at to gift_cards';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE gift_cards
                ADD COLUMN recipient_email VARCHAR(255) DEFAULT NULL,
                ADD COLUMN recipient_phone VARCHAR(32) DEFAULT NULL,
                ADD COLUMN email_delivered_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                ADD COLUMN sms_delivered_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE gift_cards
                DROP COLUMN IF EXISTS recipient_email,
                DROP COLUMN IF EXISTS recipient_phone,
                DROP COLUMN IF EXISTS email_delivered_at,
                DROP COLUMN IF EXISTS sms_delivered_at
        SQL);
    }
}
