<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add orders.channel — the checkout channel the order was placed from
 * ('MOBILE' | 'WEB'). Nullable; legacy/migrated orders and anything created
 * before this was tracked stay NULL. Surfaced on the admin orders view.
 */
final class Version20260902000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add orders.channel (MOBILE/WEB) for order source reporting.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE orders ADD COLUMN IF NOT EXISTS channel VARCHAR(16) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE orders DROP COLUMN IF EXISTS channel');
    }
}
