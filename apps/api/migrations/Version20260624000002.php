<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Legacy-migration support — wishlist_labels.legacy_wishlist_label_id.
 *
 * The legacy->v3 wishlist-labels migrator (MigrationSteps::migrateWishlistLabels,
 * bin/migrate-from-legacy/migrate-wishlist-labels.php) needs a stable legacy id
 * column so it is idempotent + re-runnable while the legacy MySQL stays live.
 *
 * wishlist_labels has TWO idempotency keys it must respect:
 *   - the existing UNIQUE (user_id, name) — one label name per user
 *   - this new UNIQUE legacy_wishlist_label_id — the legacy customer_wishlist_label.wishll_id
 *
 * The migrator's check-then-branch resolves both: look up by
 * legacy_wishlist_label_id; if not found but a row already exists for
 * (user_id, name) it ADOPTS that row (stamps legacy_wishlist_label_id);
 * else it INSERTs. The partial UNIQUE index lets v3-native labels (no
 * legacy counterpart) keep NULL without colliding.
 *
 * Runs inside Doctrine's default per-migration transaction (no
 * CREATE EXTENSION / CONCURRENTLY here, so the transaction is safe).
 *
 * up():   ADD legacy_wishlist_label_id INT NULL + partial UNIQUE index.
 * down(): DROP the index + column.
 */
final class Version20260624000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wishlist_labels.legacy_wishlist_label_id (legacy migration idempotency key)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql('ALTER TABLE wishlist_labels ADD COLUMN legacy_wishlist_label_id INT NULL');

        // Partial UNIQUE: only legacy-sourced rows are constrained; v3-native
        // labels keep NULL and don't collide.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_wishlist_labels_legacy_id
            ON wishlist_labels (legacy_wishlist_label_id)
            WHERE legacy_wishlist_label_id IS NOT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql('DROP INDEX IF EXISTS uq_wishlist_labels_legacy_id');
        $this->addSql('ALTER TABLE wishlist_labels DROP COLUMN IF EXISTS legacy_wishlist_label_id');
    }
}
