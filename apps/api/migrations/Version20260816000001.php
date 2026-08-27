<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Partial index supporting the payment-reminder cron.
 *
 * SendPendingOrderRemindersCommand / PendingOrderReminderFinder scan orders
 * WHERE status IN ('pending_payment','failed') within a created_at window,
 * every 30–60 minutes. A partial index on created_at restricted to just those
 * two statuses keeps that range scan cheap as the orders table grows -
 * without indexing the (vast) majority of paid/shipped/delivered rows.
 *
 * IF NOT EXISTS so re-running against an already-patched DB is safe.
 */
final class Version20260816000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Partial index on orders(created_at) WHERE status IN (pending_payment, failed) for the payment-reminder cron.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_orders_payment_reminder
                ON orders (created_at)
                WHERE status IN ('pending_payment', 'failed')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_orders_payment_reminder');
    }
}
