<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.X.7-A, Add preferred_locale column to vendors.
 *
 * Background
 * ==========
 * Email notification routing needs to know the recipient's preferred
 * language. For customers, the EXISTING users.locale column (added in
 * M1.7.0; docblocked as "Used to: Localise transactional emails (M3)")
 * is the source of truth, no new field needed.
 *
 * Vendors, however, have NO existing locale column. This migration
 * adds vendors.preferred_locale to close that gap. Distinct from
 * the vendor owner's User.locale because:
 *
 *   - A vendor business may want Arabic email confirmations even if
 *     the staff member who owns the account reads English (or vice
 *     versa)
 *   - The Vendor entity is shared across multiple staff users in
 *     larger businesses; using one staff member's locale to drive
 *     all vendor notifications would be wrong
 *
 * Schema additions
 * ================
 *
 *   vendors.preferred_locale VARCHAR(8) NULL
 *     Vendor's preferred locale for vendor-facing emails. 'en' / 'ar'
 *     / NULL. NULL = no preference; falls back to English at send
 *     time per Q-FallbackBehavior = A locked.
 *
 * What this migration is NOT doing
 * --------------------------------
 * Originally this migration also added users.preferred_locale, but
 * pre-flight in sub-phase D surfaced the existing users.locale field
 * already serves this purpose. Adding a second locale field on User
 * would have been architectural duplication. The migration was
 * refactored mid-flight (Q-Unification path locked) to keep only
 * the vendor side. The users.locale field remains the source of
 * truth for customer email locale.
 *
 * Backfill
 * ========
 * NO BACKFILL. Existing vendors get NULL → fall back to English at
 * send time, preserving current behavior. Explicit opt-in for Arabic
 * via the PUT /v3/admin/vendors/{id} endpoint (M3.2.X.7-D scope).
 *
 * Constraint
 * ==========
 * CHECK constraint on the column enforces the valid locale set at
 * the DB level. Defense in depth, application code already
 * validates via Vendor::SUPPORTED_LOCALES + the setter, but the
 * DB constraint catches direct SQL writes.
 *
 * Rollback safety
 * ---------------
 * down() drops the column + its CHECK constraint. The locale
 * routing in OrderNotificationService falls back to English when
 * the column is absent (via the resolver's defensive null handling),
 * so rolling back gracefully degrades to the pre-phase behavior
 * (vendor emails always English).
 */
final class Version20260518000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.X.7-A — Add preferred_locale column to vendors for email locale routing.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // vendors.preferred_locale
        $this->addSql(<<<'SQL'
            ALTER TABLE vendors
                ADD COLUMN preferred_locale VARCHAR(8) NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE vendors
                ADD CONSTRAINT chk_vendors_preferred_locale
                CHECK (preferred_locale IS NULL OR preferred_locale IN ('en', 'ar'))
        SQL);

        // No backfill, NULL is the intended initial state for all
        // existing rows per Q-FallbackBehavior = A locked.
        //
        // Customer-side locale routing reuses the existing
        // users.locale column (M1.7.0), see migration docblock
        // "What this migration is NOT doing" for rationale.
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendors DROP CONSTRAINT IF EXISTS chk_vendors_preferred_locale');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS preferred_locale');
    }
}
