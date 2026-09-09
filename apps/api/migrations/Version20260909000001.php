<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Structured pickup/sender address on vendors, for courier (OTO) shipping.
 *
 * The existing free-text store_address is fine for display but a courier
 * aggregator needs a structured sender (contact + phone + city/area/street) to
 * schedule a pickup. All columns are nullable so existing vendors are
 * unaffected until they fill the pickup address in; Vendor::pickupAddressIsComplete()
 * gates whether a store can ship via OTO.
 */
final class Version20260909000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add structured pickup/sender address columns to vendors (for OTO courier shipping).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE vendors
                ADD COLUMN pickup_contact_name VARCHAR(120) DEFAULT NULL,
                ADD COLUMN pickup_phone        VARCHAR(32)  DEFAULT NULL,
                ADD COLUMN pickup_city         VARCHAR(100) DEFAULT NULL,
                ADD COLUMN pickup_area         VARCHAR(120) DEFAULT NULL,
                ADD COLUMN pickup_street       VARCHAR(255) DEFAULT NULL,
                ADD COLUMN pickup_building_no  VARCHAR(60)  DEFAULT NULL,
                ADD COLUMN pickup_postcode     VARCHAR(20)  DEFAULT NULL,
                ADD COLUMN pickup_lat          VARCHAR(32)  DEFAULT NULL,
                ADD COLUMN pickup_lon          VARCHAR(32)  DEFAULT NULL
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE vendors
                DROP COLUMN pickup_contact_name,
                DROP COLUMN pickup_phone,
                DROP COLUMN pickup_city,
                DROP COLUMN pickup_area,
                DROP COLUMN pickup_street,
                DROP COLUMN pickup_building_no,
                DROP COLUMN pickup_postcode,
                DROP COLUMN pickup_lat,
                DROP COLUMN pickup_lon
            SQL);
    }
}
