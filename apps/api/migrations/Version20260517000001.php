<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.X.6-A — Vendor lifecycle status columns + backfill.
 *
 * Background
 * ==========
 * Adds the formal vendor lifecycle (pending / approved / suspended)
 * to the Vendor entity. Today Vendor has four overlapping boolean
 * status fields (is_active, is_verified, is_store_approved,
 * is_featured) but no first-class lifecycle. This blocks:
 *
 *   - Self-serve onboarding (vendors today are admin-created only)
 *   - Temporary suspension (no distinction from soft-delete)
 *   - Status as audit subject
 *
 * The new `status` column is the source of truth for public
 * visibility; the legacy booleans remain in place for backwards
 * compatibility (Q-LegacyFlags = A locked in M3.2.X.6 plan).
 *
 * Schema additions
 * ================
 *
 *   status VARCHAR(16) NOT NULL DEFAULT 'pending'
 *     Three values: pending, approved, suspended. VARCHAR not
 *     PostgreSQL ENUM so adding states later (e.g. 'rejected')
 *     doesn't require ALTER TYPE.
 *
 *   status_changed_at TIMESTAMPTZ NULL
 *     Timestamp of the most recent status transition. NULL for
 *     vendors that have never transitioned (i.e. still pending
 *     in their initial state).
 *
 *   status_reason TEXT NULL
 *     Free-text reason for the most recent transition. Populated
 *     when admin provides one during approve/suspend/reactivate;
 *     NULL otherwise.
 *
 * Backfill semantics (Q-Backfill = A locked)
 * ==========================================
 * Existing vendors are mapped from the legacy boolean flags:
 *
 *   is_store_approved=true AND is_active=true → status='approved'
 *     The "operating normally" case. These vendors are admin-
 *     approved and not soft-deleted.
 *
 *   is_store_approved=true AND is_active=false → status='suspended'
 *     Was admin-approved but is now soft-deleted. Functionally a
 *     suspension. Captured as suspended so an operator review pass
 *     can identify and properly handle them.
 *
 *   All other rows → status='pending'
 *     Never admin-approved (is_store_approved=false). The default
 *     value handles these without needing an explicit UPDATE.
 *
 * status_changed_at is set to NOW() for all non-pending rows so
 * forensic queries ("when was this vendor approved?") have a
 * non-null starting point. Pending rows keep NULL — semantically
 * correct since they haven't transitioned.
 *
 * Operator follow-up post-migration
 * ----------------------------------
 * The backfill is best-effort. An operator review pass should:
 *   1. Check vendors mapped to 'suspended' that should actually be
 *      hard-removed (vs. paused for policy reasons)
 *   2. Check vendors mapped to 'pending' that have live products
 *      (these may need to be approved retroactively)
 *
 * Constraint
 * ==========
 * CHECK constraint enforces the valid status values at the DB
 * level. Defense in depth — application code already validates
 * via Vendor::transitionTo and Vendor::ALL_STATUSES, but the DB
 * constraint catches any direct SQL writes that bypass the entity.
 *
 * Index
 * =====
 * Composite (status, owner_user_id) covers the hot query pattern
 * from VendorAuthMiddleware (M3.2.X.6-B):
 *   SELECT 1 FROM vendors WHERE status = 'approved' AND owner_user_id = ?
 *
 * Rollback safety
 * ---------------
 * down() drops the three columns + index + constraint. The legacy
 * booleans are preserved end-to-end, so rolling back doesn't lose
 * any visibility data — the system reverts to the boolean-flag
 * semantics it had before this migration.
 */
final class Version20260517000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.X.6-A — Add vendor lifecycle status columns + backfill from legacy booleans.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // Add the three columns. status defaults to 'pending' — applies
        // to existing rows atomically (no rewrite for varchar defaults).
        $this->addSql(<<<'SQL'
            ALTER TABLE vendors
                ADD COLUMN status VARCHAR(16) NOT NULL DEFAULT 'pending',
                ADD COLUMN status_changed_at TIMESTAMPTZ NULL,
                ADD COLUMN status_reason TEXT NULL
        SQL);

        // Backfill: map legacy booleans to lifecycle status.
        // Order matters — pending is the default, so we only need
        // to UPDATE the rows that should be approved or suspended.
        $this->addSql(<<<'SQL'
            UPDATE vendors
            SET status = 'approved',
                status_changed_at = NOW()
            WHERE is_store_approved = TRUE AND is_active = TRUE
        SQL);

        $this->addSql(<<<'SQL'
            UPDATE vendors
            SET status = 'suspended',
                status_changed_at = NOW()
            WHERE is_store_approved = TRUE AND is_active = FALSE
        SQL);

        // CHECK constraint: defense in depth against direct SQL writes.
        // The CHECK constraint name follows the chk_<table>_<column>
        // convention.
        $this->addSql(<<<'SQL'
            ALTER TABLE vendors
                ADD CONSTRAINT chk_vendors_status
                CHECK (status IN ('pending', 'approved', 'suspended'))
        SQL);

        // Composite index for VendorAuthMiddleware's hot query pattern.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_vendors_status_owner ON vendors (status, owner_user_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Drop in reverse dependency order.
        $this->addSql('DROP INDEX IF EXISTS idx_vendors_status_owner');
        $this->addSql('ALTER TABLE vendors DROP CONSTRAINT IF EXISTS chk_vendors_status');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS status_reason');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS status_changed_at');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS status');
    }
}
