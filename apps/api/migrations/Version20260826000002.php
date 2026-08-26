<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add users.pending_phone for the OTP phone-change "pending" model.
 *
 * A requested new phone number is stored here (NOT applied to users.phone)
 * until the SMS OTP is verified; on success it's promoted to the active phone
 * and this column is cleared. So an abandoned change never touches — or
 * un-verifies — the user's current phone. See SetPhoneController +
 * VerifyPhoneController.
 */
final class Version20260826000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.pending_phone for the OTP phone-change pending model.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS pending_phone VARCHAR(25) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS pending_phone');
    }
}
