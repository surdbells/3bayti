<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Day 1 of 10-day rollout — extend vendors + categories schema to carry
 * legacy data that doesn't have a v3 home yet.
 *
 * Strategy: add columns nullable, populate during migration, expose
 * through entity getters only where the demo needs them. The rest are
 * "warehoused" — present in DB for forensics + future use, not yet
 * surfaced through entity properties.
 *
 * This is intentional. The v3 Vendor entity stays focused on what the
 * v3 API exposes; legacy data lives in nullable columns until product
 * decisions are made about what to expose where.
 *
 * Vendor additions
 * ----------------
 * Legacy `users.store_*` columns mapped here:
 *   store_legal_name       → legal_name
 *   store_email            → store_email (might differ from contact_email)
 *   store_phone (TEXT)     → store_phone_raw (preserved as-is)
 *   store_address          → store_address
 *   store_bank_name        → store_bank_name
 *   store_account_name     → store_bank_account_name
 *   store_account_number   → store_bank_account_number
 *
 * Tax fields:
 *   vat_status             → vat_status
 *   trade_license_number   → trade_license_number
 *   licensing_authority    → licensing_authority
 *   tax_registration_number → tax_registration_number
 *   vat_registration_effective_date → vat_registration_effective_date
 *   registered_tax_address → registered_tax_address
 *   tax_contact_email      → tax_contact_email
 *
 * Status:
 *   store_status (tinyint) → is_store_active (boolean — legacy mapped 1→true)
 *   store_approved (tinyint) → is_store_approved (boolean)
 *
 * Logo/cover:
 *   store_logo (LONGBLOB base64)  → legacy_logo_data_url (LONGTEXT)
 *   store_cover (LONGBLOB base64) → legacy_cover_data_url (LONGTEXT)
 *
 *   These are LONGTEXT (matches PostgreSQL TEXT — unlimited) for the
 *   data-URL strings preserved verbatim from legacy. Once image migration
 *   lands (M5), we move these blobs to a Flysystem store and replace the
 *   logo_url / cover_image_url string columns with CDN URLs. For demo,
 *   the legacy data URLs sit dormant.
 *
 *   We DO NOT migrate them into the existing `logo_url` / `cover_image_url`
 *   varchar(500) columns because data URLs are far longer than 500 chars.
 *
 * Owner user:
 *   The user record that has is_vendor=1 — preserved as `owner_user_id`
 *   so we can join back to the user for things like login, billing
 *   address (which lives on the user, not the store).
 *
 * Category additions
 * ------------------
 *   legacy_category_id → INT (already exists? add if not)
 *   icon (legacy uses @tui.* refs) → varchar(50) on category
 *
 *   Legacy categories have no slug. Migration generates one. Legacy
 *   categories are flat (no parent_id) — our category table supports
 *   tree, we just leave parent_id NULL for all 8 migrated categories.
 */
final class Version20260512000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M2.2.0 — extend vendors + categories for legacy store_*/icon fields';
    }

    public function up(Schema $schema): void
    {
        // ============================================================
        // Vendor expansion
        // ============================================================

        // Owner user reference
        $this->addSql(<<<SQL
            ALTER TABLE vendors
            ADD COLUMN owner_user_id BIGINT NULL REFERENCES users(id) ON DELETE SET NULL
        SQL);
        $this->addSql('CREATE INDEX vendors_owner_user_idx ON vendors (owner_user_id)');

        // Store identity
        $this->addSql('ALTER TABLE vendors ADD COLUMN legal_name VARCHAR(255) NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN store_email VARCHAR(255) NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN store_phone_raw TEXT NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN store_address VARCHAR(500) NULL');

        // Bank / payout
        $this->addSql('ALTER TABLE vendors ADD COLUMN store_bank_name VARCHAR(255) NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN store_bank_account_name VARCHAR(255) NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN store_bank_account_number VARCHAR(40) NULL');

        // Tax compliance (UAE-specific)
        $this->addSql('ALTER TABLE vendors ADD COLUMN vat_status VARCHAR(50) NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN trade_license_number VARCHAR(255) NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN licensing_authority VARCHAR(50) NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN tax_registration_number VARCHAR(50) NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN vat_registration_effective_date DATE NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN registered_tax_address VARCHAR(500) NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN tax_contact_email VARCHAR(255) NULL');

        // Store status (separate from is_active — admin approval flow)
        $this->addSql('ALTER TABLE vendors ADD COLUMN is_store_approved BOOLEAN NOT NULL DEFAULT FALSE');

        // Legacy logo + cover preserved as data-URLs until M5
        $this->addSql('ALTER TABLE vendors ADD COLUMN legacy_logo_data_url TEXT NULL');
        $this->addSql('ALTER TABLE vendors ADD COLUMN legacy_cover_data_url TEXT NULL');

        // ============================================================
        // Category expansion
        // ============================================================
        // Add `legacy_category_id` if not already present, and `icon` for
        // the @tui.* refs from legacy. Both nullable.

        $this->addSql('ALTER TABLE categories ADD COLUMN legacy_category_id INTEGER NULL UNIQUE');
        $this->addSql('ALTER TABLE categories ADD COLUMN icon VARCHAR(50) NULL');
    }

    public function down(Schema $schema): void
    {
        // Vendor rollback
        $this->addSql('DROP INDEX IF EXISTS vendors_owner_user_idx');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS owner_user_id');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS legal_name');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS store_email');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS store_phone_raw');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS store_address');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS store_bank_name');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS store_bank_account_name');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS store_bank_account_number');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS vat_status');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS trade_license_number');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS licensing_authority');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS tax_registration_number');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS vat_registration_effective_date');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS registered_tax_address');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS tax_contact_email');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS is_store_approved');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS legacy_logo_data_url');
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS legacy_cover_data_url');

        // Category rollback
        $this->addSql('ALTER TABLE categories DROP COLUMN IF EXISTS legacy_category_id');
        $this->addSql('ALTER TABLE categories DROP COLUMN IF EXISTS icon');
    }
}
