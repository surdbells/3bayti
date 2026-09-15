<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Gift Reminder Engine — the gift_reminders table.
 *
 * A v3-native table (NO legacy id): customers save a reminder (who, occasion,
 * date + optional note/budget/category-slug); a scheduled cron nudges them at
 * 14/7/2 days out. `last_notified_stage` is the idempotency marker — the day
 * count of the most-urgent nudge already sent (SMALLINT so numeric comparison
 * is correct; VARCHAR would make '2' > '14' lexicographically).
 */
final class Version20260916000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Gift reminders: customer-saved gift reminders for the nudge cron.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<SQL
            CREATE TABLE gift_reminders (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                recipient_name VARCHAR(200) NOT NULL,
                occasion VARCHAR(100) NOT NULL,
                remind_date DATE NOT NULL,
                note TEXT DEFAULT NULL,
                budget_max NUMERIC(10, 2) DEFAULT NULL,
                category_slug VARCHAR(160) DEFAULT NULL,
                last_notified_stage SMALLINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_gift_reminders_user ON gift_reminders (user_id)');
        $this->addSql('CREATE INDEX idx_gift_reminders_remind_date ON gift_reminders (remind_date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS gift_reminders');
    }
}
