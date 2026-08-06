<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Self-hosted OTA (over-the-air) web-bundle registry.
 *
 * Backs the update endpoint the @capgo/capacitor-updater plugin polls
 * (POST /v3/ota/updates). Each row is one published web bundle (zipped Angular
 * www) for a platform + release channel:
 *   - `version`            semver of the JS bundle (must exceed the device's
 *                          current bundle for an update to be served)
 *   - `url` / `checksum`   public CDN URL of the .zip + its SHA256 (the plugin
 *                          verifies the download against this)
 *   - `min_native_version` lowest native app build this bundle is compatible
 *                          with — the compatibility gate that keeps a bundle
 *                          needing a newer native shell off older installs
 *   - `session_key`        non-null only for signed/encrypted bundles
 *   - `is_active`          flip false to retire/roll back a bundle; the endpoint
 *                          serves the newest ACTIVE bundle
 */
final class Version20260806000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add ota_bundles (self-hosted OTA web-bundle registry for POST /v3/ota/updates).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE ota_bundles (
                id BIGSERIAL NOT NULL,
                app_id VARCHAR(255) NOT NULL,
                platform VARCHAR(16) NOT NULL,
                channel VARCHAR(64) NOT NULL DEFAULT 'production',
                version VARCHAR(64) NOT NULL,
                url VARCHAR(1000) NOT NULL,
                checksum VARCHAR(128) NOT NULL,
                min_native_version VARCHAR(64) NOT NULL DEFAULT '0.0.0',
                session_key VARCHAR(255) DEFAULT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT ota_bundles_platform_check CHECK (platform IN ('android', 'ios')),
                CONSTRAINT uniq_ota_bundle_version UNIQUE (app_id, platform, channel, version)
            )
        SQL);

        // Lookup path for the update check: newest active bundle for an
        // app + platform + channel. Plain btree (matches the entity's
        // #[ORM\Index] so schema:validate stays clean) — Postgres scans it
        // backward for the ORDER BY created_at DESC LIMIT 1.
        $this->addSql(
            'CREATE INDEX idx_ota_bundles_lookup ON ota_bundles '
            . '(app_id, platform, channel, is_active, created_at)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE ota_bundles');
    }
}
