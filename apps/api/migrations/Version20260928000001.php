<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add ota_bundles.rollback_of_version for the one-click OTA rollback.
 *
 * A rollback re-publishes an older bundle's file under a new, higher version
 * (devices only accept a strictly newer version, so re-activating the old row
 * alone never reaches a device that already moved past it). This column
 * records which version's content the new row carries, so the portal can
 * label it "Rollback of 1.6.3". NULL for every normally published bundle.
 */
final class Version20260928000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ota_bundles.rollback_of_version for one-click OTA rollback.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE ota_bundles ADD COLUMN IF NOT EXISTS rollback_of_version VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE ota_bundles DROP COLUMN IF EXISTS rollback_of_version');
    }
}
