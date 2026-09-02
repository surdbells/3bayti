<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add users.pending_email for the OTP email-change "pending" model (mirrors
 * pending_phone). A requested new email is stashed here until an OTP sent to
 * it verifies; on success it's promoted to users.email and this is cleared.
 * Used by the flow that asks Apple private-relay / placeholder-email
 * customers to move to a deliverable address. See SetEmailController.
 */
final class Version20260902000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.pending_email for the OTP email-change pending model.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS pending_email VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS pending_email');
    }
}
