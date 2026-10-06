<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin-curated collection membership (collection_products join table).
 *
 * The many-to-many the ProductCollection entity always assumed ("linked via
 * the CollectionProduct join table") but was never created. Lets an admin
 * curate many products into a collection (and a product into many
 * collections), independent of the legacy vendor-set products.collection_id
 * scalar. Drives the storefront "shop by collection" + products-by-collection.
 *
 * Both FKs cascade-delete so removing a collection or a product cleans up its
 * membership rows. sort_order is the curation order (position 0 = the "first
 * product", whose image fronts the collection on cards).
 */
final class Version20261002000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create collection_products (admin-curated product↔collection many-to-many).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE collection_products (
                id BIGSERIAL NOT NULL,
                collection_id BIGINT NOT NULL,
                product_id BIGINT NOT NULL,
                sort_order INT NOT NULL DEFAULT 0,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (id),
                CONSTRAINT fk_collection_products_collection FOREIGN KEY (collection_id)
                    REFERENCES product_collections (id) ON DELETE CASCADE,
                CONSTRAINT fk_collection_products_product FOREIGN KEY (product_id)
                    REFERENCES products (id) ON DELETE CASCADE
            )
            SQL);

        $this->addSql('CREATE UNIQUE INDEX uq_collection_products ON collection_products (collection_id, product_id)');
        $this->addSql('CREATE INDEX idx_collection_products_order ON collection_products (collection_id, sort_order)');
        $this->addSql('CREATE INDEX idx_collection_products_product ON collection_products (product_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('DROP TABLE collection_products');
    }
}
