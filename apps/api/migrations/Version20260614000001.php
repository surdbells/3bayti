<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `legacy_collection_id` to `product_collections` so the legacy →
 * v3 collection migration is idempotent (upsert keyed by legacy id) and
 * legacy collection ids can be mapped to v3 ids for product assignment.
 *
 * Schema
 * ======
 *   product_collections.legacy_collection_id INTEGER NULL UNIQUE
 */
final class Version20260614000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add legacy_collection_id to product_collections (legacy collection migration idempotency)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE product_collections
                ADD COLUMN IF NOT EXISTS legacy_collection_id INTEGER DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS uniq_product_collections_legacy_id
                ON product_collections (legacy_collection_id)
                WHERE legacy_collection_id IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('DROP INDEX IF EXISTS uniq_product_collections_legacy_id');
        $this->addSql('ALTER TABLE product_collections DROP COLUMN IF EXISTS legacy_collection_id');
    }
}
