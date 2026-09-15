<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ain AI concierge — product enrichment store.
 *
 * One row per product holding AI-derived retrieval metadata: occasion / colour /
 * style tags, a short search_text, and a semantic embedding (stored as a JSONB
 * float array so it works on any Postgres). Populated by the
 * `ai:build-product-attributes` command; source_hash makes the build
 * incremental. The optional pgvector column + index are added by a separate,
 * superuser-gated migration once the extension is enabled (see docs).
 */
final class Version20260915000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ain AI: product_ai_attributes enrichment + embedding store.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<SQL
            CREATE TABLE product_ai_attributes (
                product_id BIGINT PRIMARY KEY REFERENCES products(id) ON DELETE CASCADE,
                occasions JSONB DEFAULT NULL,
                colours JSONB DEFAULT NULL,
                styles JSONB DEFAULT NULL,
                search_text TEXT DEFAULT NULL,
                embedding JSONB DEFAULT NULL,
                embed_model VARCHAR(64) DEFAULT NULL,
                source_hash VARCHAR(64) NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
            SQL);
        // Fast "does this product have an embedding" check for the retrieval gate.
        $this->addSql('CREATE INDEX idx_product_ai_attributes_has_embedding ON product_ai_attributes ((embedding IS NOT NULL))');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS product_ai_attributes');
    }
}
