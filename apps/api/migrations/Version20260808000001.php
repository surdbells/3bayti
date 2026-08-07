<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Widen ota_bundles.checksum and .session_key for SIGNED (encrypted) bundles.
 *
 * The original columns were sized for a plain SHA256 (checksum VARCHAR(128))
 * and a short key (session_key VARCHAR(255)). A signed bundle from
 * `@capgo/cli bundle encrypt` supplies an RSA-encrypted checksum (512 hex for
 * RSA-2048) and an RSA-wrapped session key (~370 chars), both of which overflow
 * those columns — the insert failed with "value too long", surfacing as a 500
 * from POST /v3/admin/ota/bundles. Widen both to 2048 (room for RSA-4096 too).
 */
final class Version20260808000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Widen ota_bundles.checksum / session_key for signed (encrypted) bundles.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE ota_bundles ALTER COLUMN checksum TYPE VARCHAR(2048)');
        $this->addSql('ALTER TABLE ota_bundles ALTER COLUMN session_key TYPE VARCHAR(2048)');
    }

    public function down(Schema $schema): void
    {
        // Narrowing back can truncate signed values; only safe if no signed
        // bundles are stored. Provided for symmetry.
        $this->addSql('ALTER TABLE ota_bundles ALTER COLUMN checksum TYPE VARCHAR(128)');
        $this->addSql('ALTER TABLE ota_bundles ALTER COLUMN session_key TYPE VARCHAR(255)');
    }
}
