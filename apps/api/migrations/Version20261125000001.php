<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Per-collection "Open to vendors" switch.
 *
 *   - product_collections.vendor_contributions_enabled: when TRUE, stores may
 *     add / remove their own products in the collection from the vendor
 *     endpoints (/v3/vendor/collections...). DEFAULT TRUE so every existing
 *     collection stays open and live vendor behaviour does not change; admins
 *     switch it off per collection. Closing a collection keeps the products
 *     vendors already added.
 */
final class Version20261125000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Collections: product_collections.vendor_contributions_enabled (default true).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE product_collections ADD COLUMN vendor_contributions_enabled BOOLEAN NOT NULL DEFAULT TRUE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE product_collections DROP COLUMN vendor_contributions_enabled');
    }
}
