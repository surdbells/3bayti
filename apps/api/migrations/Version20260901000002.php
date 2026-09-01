<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add ota_bundles.notes, the "what's new" release summary shown to customers
 * after an OTA update applies. Nullable; existing bundles carry no notes.
 */
final class Version20260901000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ota_bundles.notes for the customer-facing update summary.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE ota_bundles ADD COLUMN IF NOT EXISTS notes TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE ota_bundles DROP COLUMN IF EXISTS notes');
    }
}
