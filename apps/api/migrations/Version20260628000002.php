<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Make users.password_hash nullable — social-only accounts have no
 * password.
 *
 * A user who signs up via Google/Apple never sets a password, so the
 * NOT NULL constraint on password_hash would force us to invent a fake
 * hash (a footgun: a fake-but-real-looking bcrypt hash could in theory
 * be matched). Instead we store NULL and the application layer treats a
 * null hash as "no password set — cannot authenticate by password"
 * (User::hasPassword() + the guarded password_verify call sites).
 *
 * Existing rows keep their hashes; this only relaxes the constraint.
 */
final class Version20260628000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Make users.password_hash nullable (social-only accounts have no password).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ALTER COLUMN password_hash DROP NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Reverting requires every row to have a non-null password_hash.
        // Social-only accounts (password_hash IS NULL) would block this;
        // they must be removed or assigned a hash before rollback.
        $this->addSql('ALTER TABLE users ALTER COLUMN password_hash SET NOT NULL');
    }
}
