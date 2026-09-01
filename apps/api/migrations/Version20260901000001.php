<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add vendors.min_delivery_days / max_delivery_days for the customer-facing
 * delivery lead-time estimate ("X-Y days"). Defaults 7-14 so every existing
 * store starts with a conservative quote until an admin tunes it. On a
 * multi-store order the whole order is quoted the slowest store's range.
 */
final class Version20260901000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add vendors.min_delivery_days / max_delivery_days for delivery estimates.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE vendors ADD COLUMN IF NOT EXISTS min_delivery_days SMALLINT NOT NULL DEFAULT 7');
        $this->addSql('ALTER TABLE vendors ADD COLUMN IF NOT EXISTS max_delivery_days SMALLINT NOT NULL DEFAULT 14');
        $this->addSql('ALTER TABLE vendors ADD CONSTRAINT chk_vendors_delivery_days CHECK (min_delivery_days >= 0 AND max_delivery_days >= min_delivery_days)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE vendors DROP CONSTRAINT IF EXISTS chk_vendors_delivery_days');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS max_delivery_days');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS min_delivery_days');
    }
}
