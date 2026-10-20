<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add ota_bundles.max_native_version, the upper end of a bundle's native range.
 *
 * The update endpoint serves a bundle only to devices whose native app
 * (marketing) version is within [min_native_version, max_native_version],
 * inclusive. Before this column only the minimum was checked, so a production
 * bundle built for shell 1.8 was also served to a NEWER TestFlight / Android
 * test shell (1.8.1, 1.9) and replaced that build's own, newer web code.
 *
 * Nullable, no backfill: every write path now stores max explicitly
 * (defaulting to min), and a NULL on an existing row means max = min
 * (OtaBundle::getEffectiveMaxNativeVersion). Existing bundles therefore become
 * exact-version bundles from this deploy on; OTA-DEPLOY.md has the pre-deploy
 * query that lists which active bundles narrow (e.g. a min of 0.0.0 stops
 * serving every real shell).
 */
final class Version20261021000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ota_bundles.max_native_version (nullable; NULL = same as min_native_version).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE ota_bundles ADD COLUMN IF NOT EXISTS max_native_version VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE ota_bundles DROP COLUMN IF EXISTS max_native_version');
    }
}
