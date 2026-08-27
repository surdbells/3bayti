<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin compliance review metadata on vendors, who reviewed a KYC
 * submission, when, and (for rejections) why.
 *
 *   compliance_reviewed_at  TIMESTAMPTZ NULL
 *   compliance_reviewed_by  BIGINT NULL   (admin user id; no FK, audit-style)
 *   compliance_review_note  TEXT NULL     (rejection reason / reviewer note)
 */
final class Version20260614000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add compliance review metadata to vendors (admin KYC review)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE vendors ADD COLUMN IF NOT EXISTS compliance_reviewed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN IF NOT EXISTS compliance_reviewed_by BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN IF NOT EXISTS compliance_review_note TEXT DEFAULT NULL');
        // Review queue lists by status, index it.
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_vendors_compliance_status ON vendors (compliance_status)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('DROP INDEX IF EXISTS idx_vendors_compliance_status');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS compliance_review_note');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS compliance_reviewed_by');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS compliance_reviewed_at');
    }
}
