<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.X.11-A — Cart abandonment reminder infrastructure: extend
 * notification_logs to link cart-scoped notifications, and add the
 * marketing-opt-out flag to users.
 *
 * Two-column migration, both additive, both nullable/defaulted.
 * No backfill needed. Reversible.
 *
 * notification_logs.cart_id
 * =========================
 * The existing notification_logs table is order-centric: every row
 * carries order_id (nullable per its docblock anticipating "future
 * non-order notifications"). For cart abandonment reminders we need
 * a parallel association — the notification belongs to a cart, not
 * an order.
 *
 * Choice: add cart_id as a sibling column rather than overloading
 * order_id or adding a polymorphic 'subject_type+subject_id' pair.
 * Rationale:
 *   - The polymorphic option breaks FK integrity (you can't have
 *     a single FK column point to two different tables)
 *   - Overloading order_id is a lie — a cart is not an order
 *   - A sibling column is honest, queryable, and FK-enforceable
 *
 * ON DELETE SET NULL on the FK preserves the notification audit
 * trail even if the cart is hard-deleted (rare admin op). Same
 * posture as notification_logs.order_id.
 *
 * Partial index excludes the (overwhelming) order-only rows from
 * the cart-id index, keeping it small + fast.
 *
 * users.marketing_emails_opt_out
 * ===============================
 * Boolean DEFAULT FALSE — opt-IN by default, opt-OUT via the
 * unsubscribe link.
 *
 * Q-OptOutHandling = A locked: minimum viable opt-out at the
 * user level. UAE PDPL Article 13 (right to withdraw consent
 * must be as simple as giving consent) requires this; we cannot
 * ship marketing emails without it.
 *
 * Transactional emails (order confirmations, shipping updates,
 * refunds, etc.) IGNORE this flag — those are required for the
 * service to function and aren't marketing under PDPL. The flag
 * only gates cart.abandoned.customer and future marketing-class
 * templates.
 *
 * No index needed: checked per-row at email send time on the
 * already-loaded User entity, not as a filter on bulk queries.
 */
final class Version20260518000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.X.11-A — Add notification_logs.cart_id + users.marketing_emails_opt_out for cart abandonment reminders.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // notification_logs.cart_id
        $this->addSql(<<<'SQL'
            ALTER TABLE notification_logs
                ADD COLUMN cart_id INTEGER NULL
                    REFERENCES carts(id) ON DELETE SET NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_notification_logs_cart_id
                ON notification_logs (cart_id)
                WHERE cart_id IS NOT NULL
        SQL);

        // users.marketing_emails_opt_out
        $this->addSql(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN marketing_emails_opt_out BOOLEAN NOT NULL DEFAULT FALSE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS marketing_emails_opt_out');
        $this->addSql('DROP INDEX IF EXISTS idx_notification_logs_cart_id');
        $this->addSql('ALTER TABLE notification_logs DROP COLUMN IF EXISTS cart_id');
    }
}
