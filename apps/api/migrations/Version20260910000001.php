<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * OTO shipping: add vendors.pickup_location_code.
 *
 * The store's predefined OTO pickup/sender location code (already registered in
 * the OTO portal). When set, the courier push sends `pickupLocationCode` and OTO
 * resolves the sender address itself — so the structured pickup_* columns become
 * optional for that store. Nullable; existing vendors are unaffected.
 */
final class Version20260910000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add vendors.pickup_location_code (OTO predefined pickup location).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE vendors ADD COLUMN pickup_location_code VARCHAR(100) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendors DROP COLUMN pickup_location_code');
    }
}
