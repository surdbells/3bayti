<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Align audit_log.ip_address with its entity mapping (INET -> VARCHAR(45)).
 *
 * The column was created as INET (Version20260510000001) but AuditLog maps it
 * as string(45). Reads/writes work (PDO coerces string<->inet), but the admin
 * audit-log SEARCH runs LOWER(ip_address) → PostgreSQL "function lower(inet)
 * does not exist" → 500 on GET /v3/admin/audit-logs (Sentry PHP-2J). Converting
 * the column to VARCHAR(45) matches the mapping and lets text ops (LOWER/LIKE)
 * work, with no DQL/code change needed.
 *
 * host() drops any netmask; audit rows store plain host IPs or NULL, so the
 * conversion is lossless. This ALTER rewrites the table (brief lock) — audit
 * writes queue during it; acceptable for a one-time correctness fix.
 */
final class Version20260930000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'audit_log.ip_address INET -> VARCHAR(45) to match the entity mapping and fix the LOWER() search (PHP-2J).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(
            'ALTER TABLE audit_log ALTER COLUMN ip_address TYPE VARCHAR(45) USING host(ip_address)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(
            "ALTER TABLE audit_log ALTER COLUMN ip_address TYPE INET USING NULLIF(ip_address, '')::inet"
        );
    }
}
