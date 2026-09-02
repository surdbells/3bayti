<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data cleanup: normalise non-positive product sale prices to NULL.
 *
 * Some vendors typed 0 into the sale-price field (meaning "no sale"), but 0 was
 * stored as 0.00 rather than NULL. Because the discounted listing/facet keyed
 * off "sale_price < price", those products surfaced in the discounted section
 * without any real markdown. Going forward Product::setSalePrice normalises 0
 * to NULL; this backfills existing rows so the storefront, facet counts, and
 * vendor edit form all agree immediately (independent of the read-side guards).
 *
 * Irreversible by design: a NULL "no sale" and a 0.00 "no sale" are equivalent,
 * so down() is a no-op — there is nothing meaningful to restore.
 */
final class Version20260902000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalise non-positive product sale_price values (0.00) to NULL (no sale).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('UPDATE products SET sale_price = NULL WHERE sale_price IS NOT NULL AND sale_price <= 0');
    }

    public function down(Schema $schema): void
    {
        // No-op: 0.00 and NULL both mean "no sale", so there is nothing to
        // restore. Doctrine requires the method to exist.
    }
}
