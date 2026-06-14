<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Order-scoped customer↔vendor chat: chat_conversations (one per order
 * item) and chat_messages (with moderation state for the no-PII policy).
 */
final class Version20260614000005 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create chat_conversations and chat_messages (order-scoped customer/vendor chat)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chat_conversations (
                id                    BIGSERIAL    PRIMARY KEY,
                uuid                  VARCHAR(36)  NOT NULL,
                customer_id           BIGINT       NOT NULL REFERENCES users(id)       ON DELETE CASCADE,
                vendor_id             BIGINT       NOT NULL REFERENCES vendors(id)     ON DELETE CASCADE,
                order_id              BIGINT       NOT NULL REFERENCES orders(id)      ON DELETE CASCADE,
                order_item_id         BIGINT       NOT NULL REFERENCES order_items(id) ON DELETE CASCADE,
                status                VARCHAR(16)  NOT NULL DEFAULT 'active',
                customer_unread_count INTEGER      NOT NULL DEFAULT 0,
                vendor_unread_count   INTEGER      NOT NULL DEFAULT 0,
                last_message_at       TIMESTAMPTZ  NULL,
                last_message_preview  VARCHAR(200) NULL,
                created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_chat_conv_uuid ON chat_conversations (uuid)');
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_chat_conv_order_item ON chat_conversations (order_item_id)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_chat_conv_vendor ON chat_conversations (vendor_id, last_message_at)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_chat_conv_customer ON chat_conversations (customer_id, last_message_at)');

        $this->addSql(<<<'SQL'
            CREATE TABLE IF NOT EXISTS chat_messages (
                id              BIGSERIAL    PRIMARY KEY,
                uuid            VARCHAR(36)  NOT NULL,
                conversation_id BIGINT       NOT NULL REFERENCES chat_conversations(id) ON DELETE CASCADE,
                sender_id       BIGINT       NULL REFERENCES users(id) ON DELETE SET NULL,
                sender_type     VARCHAR(16)  NOT NULL,
                type            VARCHAR(16)  NOT NULL DEFAULT 'text',
                content         TEXT         NOT NULL,
                content_ar      TEXT         NULL,
                is_flagged      BOOLEAN      NOT NULL DEFAULT FALSE,
                flag_type       VARCHAR(64)  NULL,
                status          VARCHAR(16)  NOT NULL DEFAULT 'sent',
                created_at      TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);
        $this->addSql('CREATE UNIQUE INDEX IF NOT EXISTS uniq_chat_msg_uuid ON chat_messages (uuid)');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_chat_msg_conversation ON chat_messages (conversation_id, id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('DROP TABLE IF EXISTS chat_messages');
        $this->addSql('DROP TABLE IF EXISTS chat_conversations');
    }
}
