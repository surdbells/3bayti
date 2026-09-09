<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * order_shipments: one courier shipment per vendor slice of an order.
 *
 * Holds the provider (OTO) id + tracking + local status. order_id is
 * ON DELETE CASCADE so a deleted order removes its shipments; vendor_id is
 * RESTRICT (vendors are deactivated, not deleted). Unique on (order_id,
 * vendor_id) — one shipment per store per order.
 */
final class Version20260909000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create order_shipments (per-vendor courier shipment: provider id, tracking, status).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE order_shipments (
                id                 BIGSERIAL NOT NULL,
                order_id           BIGINT NOT NULL,
                vendor_id          BIGINT NOT NULL,
                provider           VARCHAR(20) DEFAULT 'oto' NOT NULL,
                provider_order_id  VARCHAR(64) DEFAULT NULL,
                tracking_number    VARCHAR(120) DEFAULT NULL,
                dc_tracking_number VARCHAR(120) DEFAULT NULL,
                delivery_company   VARCHAR(120) DEFAULT NULL,
                delivery_option_id VARCHAR(120) DEFAULT NULL,
                status             VARCHAR(20) DEFAULT 'booked' NOT NULL,
                last_status_raw    VARCHAR(120) DEFAULT NULL,
                created_at         TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at         TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                PRIMARY KEY(id)
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uniq_order_vendor_shipment ON order_shipments (order_id, vendor_id)');
        $this->addSql('CREATE INDEX idx_shipment_provider_order_id ON order_shipments (provider_order_id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE order_shipments
                ADD CONSTRAINT fk_shipment_order FOREIGN KEY (order_id)
                    REFERENCES orders (id) ON DELETE CASCADE,
                ADD CONSTRAINT fk_shipment_vendor FOREIGN KEY (vendor_id)
                    REFERENCES vendors (id) ON DELETE RESTRICT
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE order_shipments');
    }
}
