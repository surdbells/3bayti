<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Durable, admin-editable application settings store (app_settings).
 *
 * A tiny key -> JSON value table, read-through cached by SettingsService. The
 * first consumer is the multi-provider OTP router, which reads the enabled
 * providers + priority order from key 'otp.providers' on every send — so an
 * admin can re-order providers or disable one (failover) with no redeploy.
 *
 * setting_key is the PK (VARCHAR(190), well under the 191-char index limit).
 * value is JSONB so the router (and future settings) can read structured
 * config; defaults to an empty object so a freshly-inserted row is always
 * valid JSON. No seed row is needed: SettingsService returns a safe default
 * when the key is absent.
 */
final class Version20261001000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create app_settings (key -> JSONB) for admin-editable settings, first used by the OTP provider router.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE app_settings (
                setting_key VARCHAR(190) NOT NULL,
                value JSONB NOT NULL DEFAULT '{}'::jsonb,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (setting_key)
            )
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('DROP TABLE app_settings');
    }
}
