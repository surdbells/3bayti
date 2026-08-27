<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.X.4-A, Create notification_logs table.
 *
 * Background
 * ==========
 * M3.1.7-H shipped the full email infrastructure (MailerInterface,
 * OrderNotificationService, EmailTemplate enum, OrderEmailTemplateRenderer)
 * but explicitly deferred persistence:
 *
 *   "Idempotency... We do NOT track 'already sent' state, duplicate
 *    calls cause duplicate emails. Acceptable trade-off vs. introducing
 *    a notification_log table for M3.1.7. Future: add notification_log
 *    if we get reports of duplicate emails from genuine retries."
 *     , apps/api/src/Notification/OrderNotificationService.php:33-40
 *
 * The current state: emails go out, failures get logged to PSR-3 but
 * nothing is queryable. If a customer says "I never got my order
 * confirmation," ops has no way to verify whether the email was sent,
 * failed, or never attempted.
 *
 * This migration creates the notification_logs table; M3.2.X.4-B wires
 * OrderNotificationService::safeSend to write rows.
 *
 * Schema design choices
 * =====================
 *
 * order_id NULL + ON DELETE SET NULL
 *   The notification log is an audit trail. If an order is hard-
 *   deleted (rare; almost always soft-delete via status), we keep
 *   the notification history with order_id = null rather than
 *   losing the audit trail.
 *
 *   FK CASCADE would erase the audit; FK RESTRICT would block the
 *   delete. SET NULL preserves the audit while allowing deletes.
 *
 * No vendor_id column
 *   OrderNotificationService::sendToVendors sends one email per
 *   unique vendor, but the recipient column already captures the
 *   vendor's contact_email. The vendor_id is derivable from
 *   order_id + recipient if needed via:
 *     JOIN vendors v ON v.contact_email = nl.recipient
 *     WHERE nl.order_id = X
 *   Adding vendor_id would be NULL for customer + admin emails
 *   (sparse, anti-pattern) and force the lifecycle methods to know
 *   about vendor IDs.
 *
 * raw_event JSONB NULL (Q-RawEvent = A locked)
 *   Reserved for future ZeptoMail webhook events (bounce, complaint,
 *   delivered). M3.2.X.4 doesn't wire webhooks; the column is pre-
 *   allocated so future webhook handler is a code-only change with
 *   no schema migration.
 *
 * status taxonomy (Q-Status = A locked)
 *   Three values: 'sent', 'failed', 'skipped'. See NotificationLog.php
 *   class docblock for semantics. Stored as VARCHAR (not PostgreSQL
 *   enum) so adding values later doesn't require ALTER TYPE.
 *
 * Indexes
 *   6 indexes total. The composite (status, sent_at DESC) is the
 *   "give me failed sends in the last 24h" query. The single-column
 *   indexes serve the admin endpoint's filter combinations.
 *
 *   No partial indexes (could add WHERE status = 'failed' to make
 *   the failure index smaller); deferred until query patterns are
 *   observed in production.
 *
 * Rollback safety
 * ---------------
 * down() drops the table. Any logged rows are lost on rollback -
 * acceptable because rollback is an exceptional operation and the
 * pre-rollback observability data was already supplemental to PSR-3
 * logs (Q-Backfill = A locked: PSR-3 logs remain the pre-deploy
 * source of truth).
 */
final class Version20260516000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.X.4-A — Create notification_logs table for OrderNotificationService observability.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE notification_logs (
                id              BIGSERIAL    PRIMARY KEY,
                order_id        INTEGER      NULL REFERENCES orders(id) ON DELETE SET NULL,
                template        VARCHAR(64)  NOT NULL,
                recipient       VARCHAR(255) NOT NULL,
                status          VARCHAR(16)  NOT NULL,
                sent_at         TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                error_kind      VARCHAR(64)  NULL,
                error_message   TEXT         NULL,
                raw_event       JSONB        NULL,
                created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);

        // Single-column indexes for the admin endpoint's filter
        // combinations. Each filter is supported independently.
        $this->addSql('CREATE INDEX idx_notification_logs_order_id ON notification_logs (order_id)');
        $this->addSql('CREATE INDEX idx_notification_logs_template ON notification_logs (template)');
        $this->addSql('CREATE INDEX idx_notification_logs_status ON notification_logs (status)');
        $this->addSql('CREATE INDEX idx_notification_logs_sent_at ON notification_logs (sent_at DESC)');
        $this->addSql('CREATE INDEX idx_notification_logs_recipient ON notification_logs (recipient)');

        // Composite for the "failures in last 24h" hot query pattern.
        // PostgreSQL can use this for both:
        //   WHERE status = 'failed' ORDER BY sent_at DESC
        //   WHERE status = 'failed' AND sent_at >= NOW() - INTERVAL '24h'
        $this->addSql('CREATE INDEX idx_notification_logs_status_sent_at ON notification_logs (status, sent_at DESC)');
    }

    public function down(Schema $schema): void
    {
        // DROP TABLE cascades the indexes; no need for explicit
        // DROP INDEX statements.
        $this->addSql('DROP TABLE IF EXISTS notification_logs');
    }
}
