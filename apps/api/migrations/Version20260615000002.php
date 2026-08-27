<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add `created_by_user_id` to `styles`, attribution for user-submitted
 * styles (Group B / B4 customer style creation).
 *
 * NULL for the editorial/community styles seeded by admins; set to the
 * creating customer for user-submitted styles so they're attributable
 * and removable on abuse. ON DELETE SET NULL so a style outlives its
 * author's account.
 */
final class Version20260615000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add styles.created_by_user_id (FK users, SET NULL) for user-submitted styles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE styles ADD COLUMN created_by_user_id BIGINT DEFAULT NULL');
        $this->addSql(
            'ALTER TABLE styles ADD CONSTRAINT fk_styles_created_by_user '
            . 'FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL'
        );
        $this->addSql('CREATE INDEX idx_styles_created_by_user ON styles (created_by_user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE styles DROP CONSTRAINT IF EXISTS fk_styles_created_by_user');
        $this->addSql('DROP INDEX IF EXISTS idx_styles_created_by_user');
        $this->addSql('ALTER TABLE styles DROP COLUMN created_by_user_id');
    }
}
