<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.X.7-A — Add preferred_locale columns to users + vendors.
 *
 * Background
 * ==========
 * Adds per-user and per-vendor preferred locale storage for the
 * email notification system. Routes between English and Arabic
 * email templates based on the recipient's stored preference.
 *
 * Schema additions
 * ================
 *
 *   users.preferred_locale VARCHAR(8) NULL
 *     User's preferred locale. 'en' / 'ar' / NULL.
 *     NULL = no preference; falls back to English (Q-FallbackBehavior
 *     = A locked in M3.2.X.7 plan).
 *
 *   vendors.preferred_locale VARCHAR(8) NULL
 *     Vendor's preferred locale for vendor-facing emails. Same
 *     value taxonomy as users.preferred_locale; same fallback
 *     semantics. Distinct from User.preferred_locale because a
 *     vendor business may want Arabic email confirmations even if
 *     individual staff members read English.
 *
 * Backfill
 * ========
 * NO BACKFILL. Existing users and vendors get NULL → fall back to
 * English at send time, preserving current behavior. Explicit
 * opt-in for Arabic via the PATCH /v3/me/profile or PUT
 * /v3/admin/{users,vendors}/{id} endpoints (added in M3.2.X.7-D).
 *
 * Constraints
 * ===========
 * CHECK constraints on both columns enforce the valid locale set
 * at the DB level. Defense in depth — application code already
 * validates via User/Vendor entity setters and SUPPORTED_LOCALES
 * arrays, but the DB constraint catches direct SQL writes.
 *
 * Indexes
 * =======
 * No new indexes. The preferred_locale columns are read alongside
 * the recipient lookup (by email or by vendor id), and the
 * existing primary key + email indexes already cover those access
 * patterns. Adding a separate index on preferred_locale alone
 * would be unused.
 *
 * Rollback safety
 * ---------------
 * down() drops both columns + their CHECK constraints. The locale
 * routing in OrderNotificationService falls back to English when
 * the column is absent (via the resolver's defensive null handling),
 * so rolling back gracefully degrades to the pre-phase behavior.
 */
final class Version20260518000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.X.7-A — Add preferred_locale columns to users and vendors for email locale routing.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // users.preferred_locale
        $this->addSql(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN preferred_locale VARCHAR(8) NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE users
                ADD CONSTRAINT chk_users_preferred_locale
                CHECK (preferred_locale IS NULL OR preferred_locale IN ('en', 'ar'))
        SQL);

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

        // No backfill — NULL is the intended initial state for all
        // existing rows per Q-FallbackBehavior = A locked.
    }

    public function down(Schema $schema): void
    {
        // Drop constraints first, then columns
        $this->addSql('ALTER TABLE vendors DROP CONSTRAINT IF EXISTS chk_vendors_preferred_locale');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS preferred_locale');
        $this->addSql('ALTER TABLE users DROP CONSTRAINT IF EXISTS chk_users_preferred_locale');
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS preferred_locale');
    }
}
