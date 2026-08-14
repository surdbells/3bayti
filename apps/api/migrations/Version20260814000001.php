<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification broadcast history (Phase 1 of the admin notification system).
 *
 * Adds:
 *   - notification_broadcasts: one row per push broadcast execution (an
 *     immediate admin send today; a scheduled/recurring occurrence later).
 *     Holds the resolved message, the audience used, aggregate delivery
 *     counters (overall + per platform), and audit fields.
 *   - notification_broadcast_recipients: one row per targeted device, with
 *     its send status (pending/sent/failed) + failure reason. Backs the
 *     drill-down and the "resend failed" flow.
 *
 * `schedule_id` / `template_id` columns are created now (nullable, no FK
 * yet) so later phases (templates, scheduling) only add the FK + parent
 * tables rather than re-shaping this one.
 *
 * Delivery semantics: FCM v1 only confirms "accepted", so `status` = 'sent'
 * means accepted by FCM (not device-confirmed) and 'failed' carries the
 * rejection reason. There is no fabricated "delivered" state.
 */
final class Version20260814000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification broadcast history + per-recipient delivery tracking.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_broadcasts (
                id BIGSERIAL PRIMARY KEY,
                schedule_id BIGINT NULL,
                template_id BIGINT NULL,
                resent_from_broadcast_id BIGINT NULL REFERENCES notification_broadcasts(id) ON DELETE SET NULL,
                resend_mode VARCHAR(12) NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                image_url VARCHAR(1000) NULL,
                deep_link VARCHAR(1000) NULL,
                data JSONB NULL,
                audience JSONB NOT NULL DEFAULT '{"type":"all"}'::jsonb,
                status VARCHAR(24) NOT NULL DEFAULT 'queued',
                recipients_total INT NOT NULL DEFAULT 0,
                sent_count INT NOT NULL DEFAULT 0,
                failed_count INT NOT NULL DEFAULT 0,
                android_total INT NOT NULL DEFAULT 0,
                ios_total INT NOT NULL DEFAULT 0,
                android_sent INT NOT NULL DEFAULT 0,
                ios_sent INT NOT NULL DEFAULT 0,
                android_failed INT NOT NULL DEFAULT 0,
                ios_failed INT NOT NULL DEFAULT 0,
                failure_kinds JSONB NULL,
                error_sample TEXT NULL,
                sent_by_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                started_at TIMESTAMP(0) WITH TIME ZONE NULL,
                finished_at TIMESTAMP(0) WITH TIME ZONE NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
        SQL);

        $this->addSql("CREATE INDEX idx_notification_broadcasts_status ON notification_broadcasts (status)");
        $this->addSql("CREATE INDEX idx_notification_broadcasts_schedule ON notification_broadcasts (schedule_id)");
        $this->addSql("CREATE INDEX idx_notification_broadcasts_created ON notification_broadcasts (created_at DESC)");
        $this->addSql("CREATE INDEX idx_notification_broadcasts_status_created ON notification_broadcasts (status, created_at)");

        $this->addSql(<<<'SQL'
            CREATE TABLE notification_broadcast_recipients (
                id BIGSERIAL PRIMARY KEY,
                broadcast_id BIGINT NOT NULL REFERENCES notification_broadcasts(id) ON DELETE CASCADE,
                user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                device_token_id BIGINT NULL REFERENCES device_tokens(id) ON DELETE SET NULL,
                token_suffix VARCHAR(16) NULL,
                platform VARCHAR(12) NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                error_kind VARCHAR(24) NULL,
                error_message TEXT NULL,
                sent_at TIMESTAMP(0) WITH TIME ZONE NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
        SQL);

        $this->addSql("CREATE INDEX idx_nbr_broadcast_status ON notification_broadcast_recipients (broadcast_id, status)");
        $this->addSql("CREATE INDEX idx_nbr_broadcast_platform ON notification_broadcast_recipients (broadcast_id, platform)");
        $this->addSql("CREATE INDEX idx_nbr_user ON notification_broadcast_recipients (user_id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DROP TABLE IF EXISTS notification_broadcast_recipients");
        $this->addSql("DROP TABLE IF EXISTS notification_broadcasts");
    }
}
