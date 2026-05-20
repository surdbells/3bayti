<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.Z.3-API — Wishlist labels (Q-Z3=B).
 *
 * Extends the flat Y.6-C wishlist with user-defined labels (the
 * mobile "closets" / folders model), so the mobile wishlist can
 * migrate off its legacy label endpoints onto v3.
 *
 * Schema
 * ======
 * wishlist_labels
 *   id          BIGSERIAL PK
 *   user_id     BIGINT NOT NULL → users(id) ON DELETE CASCADE
 *   name        VARCHAR(80) NOT NULL
 *   created_at  TIMESTAMPTZ NOT NULL
 *   UNIQUE (user_id, name)  — one label name per user
 *   INDEX (user_id)         — list a user's labels
 *
 * wishlist.label_id  BIGINT NULL → wishlist_labels(id) ON DELETE SET NULL
 *   - NULL = uncategorized ("All saved" view).
 *   - ON DELETE SET NULL: deleting a label keeps the saved products,
 *     just moves them back to uncategorized (no data loss).
 *   - INDEX (user_id, label_id) — the label-filtered read path.
 *
 * The existing unique (user_id, product_id) is unchanged: a product is
 * still saved at most once per user. A saved product belongs to at
 * most one label (single-label model, matching mobile's closets).
 */
final class Version20260520000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.Z.3-API — wishlist_labels table + wishlist.label_id';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE wishlist_labels (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                name VARCHAR(80) NOT NULL,
                created_at TIMESTAMPTZ NOT NULL
            )
        SQL);

        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_wishlist_labels_user_name
            ON wishlist_labels (user_id, name)
        SQL);

        $this->addSql(<<<'SQL'
            CREATE INDEX idx_wishlist_labels_user
            ON wishlist_labels (user_id)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE wishlist
            ADD COLUMN label_id BIGINT NULL
            REFERENCES wishlist_labels(id) ON DELETE SET NULL
        SQL);

        // Label-filtered read path: a user's saved products in a label.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_wishlist_user_label
            ON wishlist (user_id, label_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_wishlist_user_label');
        $this->addSql('ALTER TABLE wishlist DROP COLUMN IF EXISTS label_id');
        $this->addSql('DROP TABLE IF EXISTS wishlist_labels');
    }
}
