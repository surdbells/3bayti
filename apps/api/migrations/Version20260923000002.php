<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Enhanced customer data (P4): explicit style preferences + data-collection
 * consent, collected at onboarding.
 *
 *   - style_preferences        : customer-DECLARED style aesthetics (JSONB
 *                                array), distinct from the behaviour-derived
 *                                AI style profile.
 *   - data_consent_granted_at  : durable PDPL consent record (nullable
 *                                timestamp, mirrors tryon_consent_granted_at).
 *   - data_consent_version      : the consent-copy version the user agreed to.
 *
 * All nullable — existing users are simply "never asked" until they complete
 * the onboarding prompt.
 */
final class Version20260923000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.style_preferences (JSONB) + data_consent_granted_at + data_consent_version for onboarding (P4).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE users ADD COLUMN style_preferences JSONB DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN data_consent_granted_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE users ADD COLUMN data_consent_version VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS data_consent_version');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS data_consent_granted_at');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS style_preferences');
    }
}
