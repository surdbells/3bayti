<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Promote legacy-approved vendors stuck at status='pending' to 'approved'.
 *
 * The vendor import set is_store_approved from the legacy store_approved
 * flag but never set the lifecycle `status` column, so every migrated
 * vendor defaulted to 'pending'. Result: the admin stores list (which
 * reads `status`) showed "Approval pending" for operating stores, while
 * the manage-store detail page (which read is_store_approved) showed
 * "Approved" — the two surfaces disagreed.
 *
 * This aligns the data with the legacy truth: any migrated vendor whose
 * store was approved on the legacy platform (is_store_approved = TRUE) but
 * is still sitting at the default 'pending' becomes 'approved', with a
 * status_changed_at stamp so the admin timeline isn't blank.
 *
 * Safety: only PROMOTES pending → approved for legacy-approved rows. It
 * never touches suspended vendors or vendors an admin already actioned in
 * v3 (their status is no longer 'pending'), so it can't undo a suspension.
 * Idempotent — a second run matches nothing.
 *
 * Preview affected rows:
 *   SELECT count(*) FROM vendors
 *   WHERE legacy_vendor_id IS NOT NULL
 *     AND is_store_approved = TRUE AND status = 'pending';
 */
final class Version20260812000004 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Promote legacy-approved vendors from default pending to approved status.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE vendors
            SET status = 'approved',
                status_changed_at = COALESCE(status_changed_at, created_at, date_trunc('second', NOW())),
                updated_at = date_trunc('second', NOW())
            WHERE legacy_vendor_id IS NOT NULL
              AND is_store_approved = TRUE
              AND status = 'pending'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // One-way: we can't distinguish vendors promoted here from those
        // approved in v3 afterwards, so reverting could demote real
        // approvals. No-op.
    }
}
