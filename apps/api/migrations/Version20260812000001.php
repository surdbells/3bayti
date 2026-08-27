<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill order_items.product_image_snapshot with the localized product image.
 *
 * Orders migrated from the legacy platform snapshot the OLD image URL on the
 * now-decommissioned host (https://api.3bayti.ae/.../products_images/<file>),
 * so those thumbnails 404 in every order view. The product images themselves
 * were localized to v3 storage during migration (products.primary_image_url
 * points at api-v3.3bayti.ae/uploads/...), so we repoint each affected line
 * item at its product's current image.
 *
 * Only rows whose snapshot is empty or still points at the legacy host are
 * touched, and only when the product actually has a localized image, real v3
 * orders keep their own snapshot. Idempotent: a second run matches nothing.
 *
 * Pairs with OrderSerializer::itemShape, which applies the same fallback at
 * read time (so views are correct even before this backfill runs).
 */
final class Version20260812000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill legacy order_items.product_image_snapshot with the localized product image.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE order_items oi
            SET product_image_snapshot = p.primary_image_url
            FROM products p
            WHERE p.id = oi.product_id
              AND p.primary_image_url IS NOT NULL
              AND p.primary_image_url <> ''
              AND (
                    oi.product_image_snapshot IS NULL
                 OR oi.product_image_snapshot = ''
                 OR oi.product_image_snapshot LIKE '%api.3bayti.ae%'
              )
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Irreversible data backfill: the legacy URLs pointed at a
        // decommissioned host and were not preserved. No-op.
    }
}
