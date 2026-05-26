<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.5 Gift Cards — three schema additions.
 *
 * All additive (no column removal, no destructive ALTER).
 * Safe to apply to a live database with zero downtime.
 *
 * Changes
 * =======
 *
 * 1. gift_cards                                    (M3.5)
 *    Core gift card table. Tracks denomination, balance, status,
 *    theme, buyer, recipient, personalisation fields, and expiry.
 *
 * 2. gift_card_transactions                        (M3.5)
 *    Append-only ledger: one row per debit (checkout spend) or
 *    credit (order cancellation refund back to card).
 *    FK ON DELETE CASCADE from gift_cards.
 *
 * 3. orders.gift_card_amount + orders.gift_card_code_snapshot  (M3.5)
 *    Records the portion of an order paid via gift card.
 *    gift_card_amount defaults to '0.00' — backward-compatible.
 *    The remainder (total - gift_card_amount) is the gateway charge.
 */
final class Version20260526000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.5 — gift_cards, gift_card_transactions, orders.gift_card_amount';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // ── 1. gift_cards ──────────────────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS gift_cards (
                id                       BIGSERIAL       PRIMARY KEY,
                code                     VARCHAR(16)     NOT NULL,
                denomination             DECIMAL(10,2)   NOT NULL,
                balance                  DECIMAL(10,2)   NOT NULL,
                currency                 VARCHAR(3)      NOT NULL DEFAULT 'AED',
                status                   VARCHAR(20)     NOT NULL DEFAULT 'pending_payment',
                theme                    VARCHAR(20)     NOT NULL,
                buyer_user_id            BIGINT          NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                recipient_user_id        BIGINT          NULL     REFERENCES users(id) ON DELETE SET NULL,
                recipient_name           VARCHAR(100)    NULL,
                recipient_message        VARCHAR(150)    NULL,
                recipient_photo_url      VARCHAR(500)    NULL,
                scheduled_delivery_at    TIMESTAMPTZ     NULL,
                purchase_order_reference VARCHAR(32)     NULL,
                activated_at             TIMESTAMPTZ     NULL,
                expires_at               TIMESTAMPTZ     NULL,
                created_at               TIMESTAMPTZ     NOT NULL,
                updated_at               TIMESTAMPTZ     NOT NULL
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uq_gift_cards_code ON gift_cards (code)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uq_gift_cards_purchase_order_ref ON gift_cards (purchase_order_reference) WHERE purchase_order_reference IS NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_gift_cards_buyer ON gift_cards (buyer_user_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_gift_cards_recipient ON gift_cards (recipient_user_id) WHERE recipient_user_id IS NOT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_gift_cards_status ON gift_cards (status)');

        // ── 2. gift_card_transactions ──────────────────────────────────
        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS gift_card_transactions (
                id               BIGSERIAL       PRIMARY KEY,
                gift_card_id     BIGINT          NOT NULL REFERENCES gift_cards(id) ON DELETE CASCADE,
                type             VARCHAR(10)     NOT NULL,
                amount           DECIMAL(10,2)   NOT NULL,
                balance_after    DECIMAL(10,2)   NOT NULL,
                order_reference  VARCHAR(32)     NULL,
                order_id         BIGINT          NULL,
                created_at       TIMESTAMPTZ     NOT NULL
            )
        SQL);

        $this->addSql('CREATE INDEX IF NOT EXISTS idx_gift_card_txn_card ON gift_card_transactions (gift_card_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_gift_card_txn_order ON gift_card_transactions (order_id) WHERE order_id IS NOT NULL');

        // ── 3. orders.gift_card_amount + code snapshot ─────────────────
        $this->addSql("ALTER TABLE orders ADD COLUMN IF NOT EXISTS gift_card_amount DECIMAL(10,2) NOT NULL DEFAULT '0.00'");
        $this->addSql('ALTER TABLE orders ADD COLUMN IF NOT EXISTS gift_card_code_snapshot VARCHAR(16) NULL');
    }

    public function down(Schema $schema): void
    {
        // Drop in reverse-dependency order.
        $this->addSql('ALTER TABLE orders DROP COLUMN IF EXISTS gift_card_code_snapshot');
        $this->addSql('ALTER TABLE orders DROP COLUMN IF EXISTS gift_card_amount');

        $this->addSql('DROP INDEX IF EXISTS idx_gift_card_txn_order');
        $this->addSql('DROP INDEX IF EXISTS idx_gift_card_txn_card');
        $this->addSql('DROP TABLE IF EXISTS gift_card_transactions');

        $this->addSql('DROP INDEX IF EXISTS idx_gift_cards_status');
        $this->addSql('DROP INDEX IF EXISTS idx_gift_cards_recipient');
        $this->addSql('DROP INDEX IF EXISTS idx_gift_cards_buyer');
        $this->addSql('DROP INDEX IF EXISTS uq_gift_cards_purchase_order_ref');
        $this->addSql('DROP INDEX IF EXISTS uq_gift_cards_code');
        $this->addSql('DROP TABLE IF EXISTS gift_cards');
    }
}
