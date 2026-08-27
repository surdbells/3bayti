<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;

/**
 * Workstream C, profile pictures. Adds avatar_url to users (nullable; holds
 * the full public URL of the uploaded avatar, served via the public uploads
 * path). Null users fall back to a placeholder in the API response.
 */
final class Version20260614000007 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add avatar_url to users';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql('ALTER TABLE users ADD COLUMN IF NOT EXISTS avatar_url VARCHAR(500) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof PostgreSQLPlatform,
            'This migration targets PostgreSQL.',
        );

        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS avatar_url');
    }
}
