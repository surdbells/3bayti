<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill legacy users.is_vendor / is_store_approved / is_store_active from
 * the Vendor record (source of truth).
 *
 * The vendor lifecycle controllers (approve/reactivate/suspend) now keep these
 * legacy user columns in sync going forward via User::setStoreState(). This
 * one-off migration corrects already-existing vendor owners (e.g. stores that
 * were approved BEFORE the sync wiring landed, including the operator's own
 * store) so their user columns match the Vendor record.
 *
 * Mapping (verified against the entity column annotations):
 *   users.is_vendor          <- true (the user owns a vendor record)
 *   users.is_store_approved  <- vendors.is_store_approved
 *   users.is_store_active    <- vendors.is_active
 *   join: vendors.owner_user_id = users.id
 *
 * Idempotent: re-running yields the same column values (pure assignment from
 * the source of truth, no accumulation). Runs inside Doctrine's default
 * per-migration transaction (plain UPDATE, no CREATE EXTENSION / CONCURRENTLY).
 *
 * down(): no-op, this is a data backfill; there is no meaningful inverse
 * (the prior, drifted values are not worth restoring).
 */
final class Version20260625000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill users.is_vendor/is_store_approved/is_store_active from vendors (source of truth)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            UPDATE users u
            SET is_vendor = true,
                is_store_approved = v.is_store_approved,
                is_store_active = v.is_active
            FROM vendors v
            WHERE v.owner_user_id = u.id
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No-op: data backfill has no meaningful inverse.
    }
}
