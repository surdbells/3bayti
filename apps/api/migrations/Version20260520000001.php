<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.Y.6-C, Wishlist: the `wishlist` table.
 *
 * One row per (user, product) the user has saved. Net-new feature
 * (Y.6); products-only (Q6.4, the read endpoint returns saved
 * products via the existing ProductSerializer list shape).
 *
 * Schema
 * ======
 *   id          BIGSERIAL PRIMARY KEY
 *   user_id     BIGINT NOT NULL  → users(id)    ON DELETE CASCADE
 *   product_id  BIGINT NOT NULL  → products(id) ON DELETE CASCADE
 *   created_at  TIMESTAMPTZ NOT NULL
 *
 * UNIQUE (user_id, product_id), a product is saved at most once per
 *   user. This is what makes POST idempotent (Q6.3): the controller
 *   checks for an existing row and no-ops instead of inserting a
 *   duplicate (and the DB constraint is the backstop against races).
 *
 * INDEX (user_id, created_at), the hot read path: "list a user's
 *   saved products, newest first", a single seek+range scan.
 *
 * FK CASCADE both ways:
 *   - user_id CASCADE: deleting a user removes their saved rows.
 *   - product_id CASCADE: a delisted product drops out of every
 *     wishlist automatically (no orphan rows pointing at gone
 *     products).
 */
final class Version20260520000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.Y.6-C — create wishlist table';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE wishlist (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                created_at TIMESTAMPTZ NOT NULL
            )
        SQL);

        // One save per (user, product). Enforced at the DB level so a
        // race can't create duplicates; the controller's check-then-
        // insert relies on this invariant for idempotent POST.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_wishlist_user_product
            ON wishlist (user_id, product_id)
        SQL);

        // Hot read path: a user's saved products, newest first.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_wishlist_user_created
            ON wishlist (user_id, created_at)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS wishlist');
    }
}
