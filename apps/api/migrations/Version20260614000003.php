<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Vendor compliance (KYC) documents — the v3 home for the ID front/back +
 * trade-license documents the compliance page manages. Stored as text on
 * the vendor (base64 data URLs, parity with legacy users.id_front/…),
 * which keeps the sensitive documents in the DB rather than on the public
 * uploads path. Returned only to the authenticated owner.
 *
 *   id_front          TEXT NULL
 *   id_back           TEXT NULL
 *   license_doc       TEXT NULL
 *   compliance_status VARCHAR(20) NOT NULL DEFAULT 'pending'
 *                       ('pending' | 'submitted' | 'approved' | 'rejected')
 */
final class Version20260614000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add KYC compliance document fields to vendors (v3 compliance page)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE vendors ADD COLUMN IF NOT EXISTS id_front TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN IF NOT EXISTS id_back TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN IF NOT EXISTS license_doc TEXT DEFAULT NULL');
        $this->addSql("ALTER TABLE vendors ADD COLUMN IF NOT EXISTS compliance_status VARCHAR(20) NOT NULL DEFAULT 'pending'");
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS compliance_status');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS license_doc');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS id_back');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS id_front');
    }
}
