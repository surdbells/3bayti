<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add orders.deleted_at for customer soft-delete (hide a failed order from
 * the customer's own history). Nullable; existing orders stay visible. The
 * row is retained for records/audit and only filtered from the customer's
 * list + detail queries.
 */
final class Version20260902000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add orders.deleted_at for customer soft-delete of failed orders.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE orders ADD COLUMN IF NOT EXISTS deleted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_orders_deleted_at ON orders (deleted_at)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('DROP INDEX IF EXISTS idx_orders_deleted_at');
        $this->addSql('ALTER TABLE orders DROP COLUMN IF EXISTS deleted_at');
    }
}
