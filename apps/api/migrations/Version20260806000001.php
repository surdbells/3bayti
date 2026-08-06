<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Link an order to the cart it was created from (orders.cart_id).
 *
 * Previously the cart was marked 'converted' at checkout *initiate* (before
 * payment), so a customer who cancelled out of the payment page lost their
 * cart. We now keep the cart active through the pending-payment window and
 * only convert it once payment actually succeeds — the paid-transition handlers
 * convert `order.cart`. The link is nullable: gift-card *purchase* orders have
 * no cart, so their cart_id stays NULL and nothing is converted for them.
 *
 * ON DELETE SET NULL: carts are archived/converted, not hard-deleted, but if
 * one ever is, the historical order simply loses the (now irrelevant) link.
 */
final class Version20260806000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add orders.cart_id (link to the source cart) so carts convert on payment success, not at checkout initiate.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders ADD COLUMN cart_id BIGINT DEFAULT NULL');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT fk_orders_cart_id FOREIGN KEY (cart_id) REFERENCES carts (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_orders_cart_id ON orders (cart_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP CONSTRAINT fk_orders_cart_id');
        $this->addSql('DROP INDEX idx_orders_cart_id');
        $this->addSql('ALTER TABLE orders DROP COLUMN cart_id');
    }
}
