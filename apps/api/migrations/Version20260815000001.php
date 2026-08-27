<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification schedules (Phase 3), scheduled + recurring notifications.
 *
 * A schedule holds the message (optionally from a template, raw {{variables}}
 * kept unresolved), the audience config, and a recurrence rule. The
 * notifications:dispatch-scheduled command creates a queued broadcast for
 * each due occurrence (the broadcast dispatcher then sends it, resolving
 * variables + the current audience at that moment).
 *
 * Also wires notification_broadcasts.schedule_id (created nullable in
 * Phase 1) to this table, so a broadcast can be traced back to its schedule
 * and a schedule's occurrences listed.
 */
final class Version20260815000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification schedules (recurring) + broadcasts.schedule_id FK.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_schedules (
                id BIGSERIAL PRIMARY KEY,
                template_id BIGINT NULL REFERENCES notification_templates(id) ON DELETE SET NULL,
                name VARCHAR(200) NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                image_url VARCHAR(1000) NULL,
                deep_link VARCHAR(1000) NULL,
                data JSONB NULL,
                audience JSONB NOT NULL DEFAULT '{"type":"all"}'::jsonb,
                audience_mode VARCHAR(12) NOT NULL DEFAULT 'dynamic',
                timezone VARCHAR(64) NOT NULL DEFAULT 'Asia/Dubai',
                frequency VARCHAR(12) NOT NULL,
                start_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                end_at TIMESTAMP(0) WITH TIME ZONE NULL,
                next_run_at TIMESTAMP(0) WITH TIME ZONE NULL,
                last_run_at TIMESTAMP(0) WITH TIME ZONE NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'scheduled',
                created_by_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
        SQL);

        $this->addSql("CREATE INDEX idx_notification_schedules_status ON notification_schedules (status)");
        $this->addSql("CREATE INDEX idx_notification_schedules_due ON notification_schedules (status, next_run_at)");

        $this->addSql(<<<'SQL'
            ALTER TABLE notification_broadcasts
            ADD CONSTRAINT fk_notification_broadcasts_schedule
            FOREIGN KEY (schedule_id) REFERENCES notification_schedules(id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE notification_broadcasts DROP CONSTRAINT IF EXISTS fk_notification_broadcasts_schedule");
        $this->addSql("DROP TABLE IF EXISTS notification_schedules");
    }
}
