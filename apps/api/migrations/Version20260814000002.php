<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Notification message templates (Phase 2).
 *
 * A reusable message definition (title + body with {{variables}}, optional
 * image / deep link). Composing a broadcast can start from a template; the
 * raw template text (variables unresolved) is copied onto the broadcast and
 * resolved per-recipient at send time.
 *
 * Also wires the FK from notification_broadcasts.template_id (the column was
 * created nullable in Phase 1) to this table.
 */
final class Version20260814000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Notification message templates + broadcasts.template_id FK.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE notification_templates (
                id BIGSERIAL PRIMARY KEY,
                name VARCHAR(200) NOT NULL,
                title VARCHAR(255) NOT NULL,
                body TEXT NOT NULL,
                image_url VARCHAR(1000) NULL,
                deep_link VARCHAR(1000) NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                created_by_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
        SQL);

        $this->addSql("CREATE INDEX idx_notification_templates_status ON notification_templates (status)");
        $this->addSql("CREATE INDEX idx_notification_templates_name ON notification_templates (LOWER(name))");

        $this->addSql(<<<'SQL'
            ALTER TABLE notification_broadcasts
            ADD CONSTRAINT fk_notification_broadcasts_template
            FOREIGN KEY (template_id) REFERENCES notification_templates(id) ON DELETE SET NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE notification_broadcasts DROP CONSTRAINT IF EXISTS fk_notification_broadcasts_template");
        $this->addSql("DROP TABLE IF EXISTS notification_templates");
    }
}
