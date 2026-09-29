<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Live cart pricing: add cart_items.previous_unit_price.
 *
 * Cart lines are now re-synced to the product's current effective price on
 * every read and at checkout (CartPriceRefresher). When a re-sync changes a
 * line's price, the price the customer saw before is kept here so the apps can
 * show a "price updated" badge until the order is placed. Nullable, no
 * default: existing lines simply have no pending change.
 */
final class Version20260929000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Live cart pricing: add cart_items.previous_unit_price.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(
            'ALTER TABLE cart_items ADD COLUMN IF NOT EXISTS previous_unit_price NUMERIC(10, 2) DEFAULT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE cart_items DROP COLUMN IF EXISTS previous_unit_price');
    }
}
