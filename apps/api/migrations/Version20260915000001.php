<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ain AI concierge — analytics ledger.
 *
 * `ai_interactions` records one row per concierge query (the parsed intent + the
 * products it surfaced) and is the anchor for revenue attribution. `ai_events`
 * is the event ledger (opened / recommendation shown / clicked / added to cart /
 * gift started …) written by a raw-DBAL logger, modelled on notification_logs.
 * Both keep nullable user/session so logged-out use is still measured.
 */
final class Version20260915000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ain AI: ai_interactions + ai_events analytics tables.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<SQL
            CREATE TABLE ai_interactions (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
                session_id VARCHAR(64) DEFAULT NULL,
                channel VARCHAR(16) DEFAULT NULL,
                feature VARCHAR(40) NOT NULL,
                query_text TEXT NOT NULL,
                intent JSONB DEFAULT NULL,
                result_product_ids JSONB DEFAULT NULL,
                locale VARCHAR(8) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_ai_interactions_user_created ON ai_interactions (user_id, created_at)');
        $this->addSql('CREATE INDEX idx_ai_interactions_feature_created ON ai_interactions (feature, created_at)');

        $this->addSql(<<<SQL
            CREATE TABLE ai_events (
                id BIGSERIAL PRIMARY KEY,
                interaction_id BIGINT DEFAULT NULL REFERENCES ai_interactions(id) ON DELETE CASCADE,
                user_id BIGINT DEFAULT NULL REFERENCES users(id) ON DELETE SET NULL,
                session_id VARCHAR(64) DEFAULT NULL,
                event VARCHAR(48) NOT NULL,
                product_id BIGINT DEFAULT NULL,
                vendor_id BIGINT DEFAULT NULL,
                metadata JSONB DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
            SQL);
        $this->addSql('CREATE INDEX idx_ai_events_event_created ON ai_events (event, created_at)');
        $this->addSql('CREATE INDEX idx_ai_events_interaction ON ai_events (interaction_id)');
        $this->addSql('CREATE INDEX idx_ai_events_product ON ai_events (product_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS ai_events');
        $this->addSql('DROP TABLE IF EXISTS ai_interactions');
    }
}
