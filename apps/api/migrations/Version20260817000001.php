<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * users.must_change_password, force a password change on next sign-in.
 *
 * Set when an account is provisioned with a temporary password we handed the
 * holder (admin approving a seller application, or resending credentials), so
 * they must replace it before using the account. Cleared automatically the
 * moment they set their own password (User::setPasswordHash).
 */
final class Version20260817000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add users.must_change_password (force password change on first sign-in for provisioned accounts).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            "ALTER TABLE users ADD COLUMN IF NOT EXISTS must_change_password BOOLEAN NOT NULL DEFAULT false",
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS must_change_password');
    }
}
