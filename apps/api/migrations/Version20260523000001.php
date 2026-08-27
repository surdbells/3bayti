<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.4, Five schema additions introduced across M3.4-A through M3.4-H.
 *
 * All changes are additive (no column removal, no destructive ALTER).
 * Safe to apply to a live database with zero downtime, each statement
 * adds a new nullable column or a new table; no existing row is modified.
 *
 * Changes
 * =======
 *
 * 1. vendors.notification_prefs  JSONB NULL           (M3.4-B)
 *    Vendor notification preference flags (11 boolean keys). NULL means
 *    "all defaults" (treated as all-true on read). Stored as JSON so
 *    new preference keys can be added without ALTER TABLE.
 *
 * 2. promo_codes.vendor_id  BIGINT NULL              (M3.3.1-F)
 *    Optional vendor scope for promo codes. NULL = sitewide (admin-
 *    created); non-null = vendor-owned coupon. Existing rows are
 *    unaffected (NULL = sitewide, backward-compatible).
 *    Index added for O(1) lookups of a vendor's own codes.
 *
 * 3. support_tickets                                  (M3.4-G)
 *    Support ticket table. vendor_id and user_id are nullable to allow
 *    tickets opened by either party or by the system.
 *
 * 4. support_ticket_messages                          (M3.4-G)
 *    Per-ticket message thread. ON DELETE CASCADE from support_tickets.
 *    is_admin_reply distinguishes vendor messages from admin replies.
 *
 * 5. product_collections                              (M3.4-H)
 *    Curated product collections (e.g. "Summer Sale", "New Arrivals").
 *    Slug is unique; display_order drives listing sort.
 *
 * Rollback (down): drops new tables + new columns. Because promo_codes
 * rows with a non-null vendor_id are data created by vendors via M3.4,
 * a rollback would logically lose that coupon ownership information -
 * document this clearly in the runbook before rolling back.
 */
final class Version20260523000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.4 — notification_prefs, promo_codes.vendor_id, support_tickets/messages, product_collections';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // ── 1. vendors.notification_prefs ──────────────────────────────
        $this->addSql(<<<'SQL'
            ALTER TABLE vendors
                ADD COLUMN IF NOT EXISTS notification_prefs JSONB NULL
        SQL);

        // ── 2. promo_codes.vendor_id ───────────────────────────────────
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_codes
                ADD COLUMN IF NOT EXISTS vendor_id BIGINT NULL
        SQL);
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_promo_codes_vendor_id ON promo_codes (vendor_id) WHERE vendor_id IS NOT NULL'
        );

        // ── 3. support_tickets ─────────────────────────────────────────
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

        // ── 4. support_ticket_messages ─────────────────────────────────
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

        // ── 5. product_collections ─────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS product_collections (
                id              BIGSERIAL    PRIMARY KEY,
                name            VARCHAR(200) NOT NULL,
                slug            VARCHAR(220) NOT NULL,
                description     TEXT         NULL,
                cover_image_url VARCHAR(500) NULL,
                is_active       BOOLEAN      NOT NULL DEFAULT true,
                display_order   SMALLINT     NULL,
                created_at      TIMESTAMPTZ  NOT NULL,
                updated_at      TIMESTAMPTZ  NOT NULL
            )
        SQL);
        $this->addSql(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_product_collections_slug ON product_collections (slug)'
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_product_collections_active ON product_collections (is_active, display_order)'
        );
    }

    public function down(Schema $schema): void
    {
        // Drop in reverse-dependency order.

        // 5. product_collections
        $this->addSql('DROP INDEX IF EXISTS idx_product_collections_active');
        $this->addSql('DROP INDEX IF EXISTS uq_product_collections_slug');
        $this->addSql('DROP TABLE IF EXISTS product_collections');

        // 4. support_ticket_messages (FK to support_tickets, drop first)
        $this->addSql('DROP INDEX IF EXISTS idx_ticket_messages_ticket');
        $this->addSql('DROP TABLE IF EXISTS support_ticket_messages');

        // 3. support_tickets
        $this->addSql('DROP INDEX IF EXISTS idx_support_tickets_vendor');
        $this->addSql('DROP INDEX IF EXISTS idx_support_tickets_priority');
        $this->addSql('DROP INDEX IF EXISTS idx_support_tickets_status');
        $this->addSql('DROP TABLE IF EXISTS support_tickets');

        // 2. promo_codes.vendor_id
        $this->addSql('DROP INDEX IF EXISTS idx_promo_codes_vendor_id');
        $this->addSql('ALTER TABLE promo_codes DROP COLUMN IF EXISTS vendor_id');

        // 1. vendors.notification_prefs
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS notification_prefs');
    }
}
