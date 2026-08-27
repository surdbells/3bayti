<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Create the vendor_applications table, the "apply → admin approves"
 * seller intake flow that replaces self-serve vendor creation.
 *
 * Columns mirror the VendorApplication entity attribute mapping:
 *   - identity:   first_name, last_name, email (lowercased), phone,
 *                 country_code
 *   - business:   business_name, license_number (nullable),
 *                 category (nullable), message (nullable)
 *   - lifecycle:  status (pending|approved|rejected, default pending),
 *                 reject_reason (nullable)
 *   - review:     vendor_id (FK -> vendors SET NULL), reviewed_by_user_id
 *                 (FK -> users SET NULL), reviewed_at (nullable)
 *   - timestamps: created_at, updated_at (TIMESTAMPTZ)
 *
 * FKs are ON DELETE SET NULL so deleting a vendor / user leaves the
 * historical application record intact (just unlinked).
 */
final class Version20260625000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create vendor_applications table (seller apply -> admin approve flow)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE vendor_applications (
                id BIGSERIAL NOT NULL,
                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100) NOT NULL,
                email VARCHAR(255) NOT NULL,
                phone VARCHAR(25) NOT NULL,
                country_code VARCHAR(2) NOT NULL DEFAULT 'AE',
                business_name VARCHAR(255) NOT NULL,
                license_number VARCHAR(100) DEFAULT NULL,
                category VARCHAR(120) DEFAULT NULL,
                message TEXT DEFAULT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                reject_reason TEXT DEFAULT NULL,
                vendor_id BIGINT DEFAULT NULL,
                reviewed_by_user_id BIGINT DEFAULT NULL,
                reviewed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_vendor_applications_email ON vendor_applications (email)');
        $this->addSql('CREATE INDEX idx_vendor_applications_status ON vendor_applications (status)');

        $this->addSql(<<<'SQL'
            ALTER TABLE vendor_applications
                ADD CONSTRAINT fk_vendor_applications_vendor
                FOREIGN KEY (vendor_id) REFERENCES vendors (id)
                ON DELETE SET NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vendor_applications
                ADD CONSTRAINT fk_vendor_applications_reviewed_by
                FOREIGN KEY (reviewed_by_user_id) REFERENCES users (id)
                ON DELETE SET NULL
        SQL);
        $this->addSql('CREATE INDEX idx_vendor_applications_vendor_id ON vendor_applications (vendor_id)');
        $this->addSql('CREATE INDEX idx_vendor_applications_reviewed_by ON vendor_applications (reviewed_by_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql('DROP TABLE vendor_applications');
    }
}
