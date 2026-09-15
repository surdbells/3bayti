<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ain AI Style Hub (restyle) — style provenance columns.
 *
 * ADDITIVE: adds nullable `source` (default 'user'), `prompt`, `rationale` to
 * the styles table so an Ain-rebuilt look, once saved, is self-describing.
 * Deliberately a DEDICATED `source` column — NOT a new style_type value (that
 * would require dropping/re-adding the chk_styles_type CHECK constraint).
 * Existing rows default to 'user'.
 */
final class Version20260916000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ain Style Hub: add styles.source / prompt / rationale (AI provenance).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql("ALTER TABLE styles ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'user'");
        $this->addSql('ALTER TABLE styles ADD COLUMN prompt TEXT DEFAULT NULL');
        $this->addSql('ALTER TABLE styles ADD COLUMN rationale TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE styles DROP COLUMN IF EXISTS rationale');
        $this->addSql('ALTER TABLE styles DROP COLUMN IF EXISTS prompt');
        $this->addSql('ALTER TABLE styles DROP COLUMN IF EXISTS source');
    }
}
