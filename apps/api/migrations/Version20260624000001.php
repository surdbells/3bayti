<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove the support-tickets feature.
 *
 * The support-ticket domain (entities, repository, customer + admin
 * controllers, serializer and routes) has been removed from the
 * application — the only retained support channel is WhatsApp. This
 * migration drops the two tables (and their indexes) introduced by
 * Version20260523000001's M3.4-G changes.
 *
 * Runs inside Doctrine's default per-migration transaction (no
 * CREATE EXTENSION / CONCURRENTLY here, so the transaction is safe).
 *
 * up():   DROP support_ticket_messages (FK child) then support_tickets.
 * down(): faithfully recreate BOTH tables + all 4 indexes, copied from
 *         Version20260523000001 (BIGSERIAL PKs, FK ON DELETE CASCADE,
 *         defaults open/medium/false) so a rollback restores the schema.
 */
final class Version20260624000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove support-tickets feature — drop support_tickets + support_ticket_messages';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // Drop the FK child (messages) first, then the parent (tickets).

        // support_ticket_messages
        $this->addSql('DROP INDEX IF EXISTS idx_ticket_messages_ticket');
        $this->addSql('DROP TABLE IF EXISTS support_ticket_messages');

        // support_tickets
        $this->addSql('DROP INDEX IF EXISTS idx_support_tickets_vendor');
        $this->addSql('DROP INDEX IF EXISTS idx_support_tickets_priority');
        $this->addSql('DROP INDEX IF EXISTS idx_support_tickets_status');
        $this->addSql('DROP TABLE IF EXISTS support_tickets');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // Recreate parent (tickets) first, then the FK child (messages).
        // DDL copied faithfully from Version20260523000001.

        // support_tickets
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS support_tickets (
                id           BIGSERIAL PRIMARY KEY,
                vendor_id    BIGINT       NULL,
                user_id      BIGINT       NULL,
                subject      VARCHAR(255) NOT NULL,
                body         TEXT         NOT NULL,
                status       VARCHAR(20)  NOT NULL DEFAULT 'open',
                priority     VARCHAR(20)  NOT NULL DEFAULT 'medium',
                created_at   TIMESTAMPTZ  NOT NULL,
                updated_at   TIMESTAMPTZ  NOT NULL
            )
        SQL);
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_support_tickets_status    ON support_tickets (status)'
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_support_tickets_priority  ON support_tickets (priority)'
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_support_tickets_vendor    ON support_tickets (vendor_id) WHERE vendor_id IS NOT NULL'
        );

        // support_ticket_messages
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS support_ticket_messages (
                id             BIGSERIAL   PRIMARY KEY,
                ticket_id      BIGINT      NOT NULL REFERENCES support_tickets(id) ON DELETE CASCADE,
                user_id        BIGINT      NULL,
                author_name    VARCHAR(150) NULL,
                body           TEXT        NOT NULL,
                is_admin_reply BOOLEAN     NOT NULL DEFAULT false,
                created_at     TIMESTAMPTZ NOT NULL
            )
        SQL);
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_ticket_messages_ticket ON support_ticket_messages (ticket_id)'
        );
    }
}
