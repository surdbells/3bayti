<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Day 4 of 10-day rollout, relax users schema for legacy data + create
 * migration_email_conflicts table.
 *
 * Why relax `users.phone`?
 * ------------------------
 *
 * The v3 schema (Version20260507000001) made phone NOT NULL UNIQUE
 * because the v3 product design assumes phone-first auth. Legacy data
 * doesn't match:
 *   - 4 users have NULL phone (likely admin/test accounts)
 *   - 69 users share phone numbers with another user (likely family
 *     accounts: same household phone, different emails)
 *
 * We could clean these up but that's customer-data work that requires
 * business input. For the demo, we preserve legacy state verbatim:
 *   - phone becomes NULLABLE
 *   - UNIQUE constraint dropped
 *
 * Future hardening (post-demo): tighten phone uniqueness only at the
 * application layer (validate on new signups, leave migrated rows
 * alone) OR run a one-off cleanup script with business approval.
 *
 * Why migration_email_conflicts?
 * ------------------------------
 *
 * 71 legacy users share emails (case-insensitive). v3 `users.email`
 * is UNIQUE. Migration must rename one of each pair. We append
 * `+legacy{userId}` to the email so:
 *   - The user record is preserved
 *   - The original email stays available for whoever wants to claim it
 *   - The renamed user CAN'T log in (their typed email won't match)
 *
 * The rename event is logged to `migration_email_conflicts` so post-
 * demo we have a clear list of accounts to manually merge.
 */
final class Version20260512000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M2.2 Day 4 — relax users phone constraints + migration_email_conflicts table';
    }

    public function up(Schema $schema): void
    {
        // ============================================================
        // Relax users.phone, allow NULL, drop UNIQUE
        // ============================================================

        // Drop the UNIQUE constraint. PostgreSQL named it
        // `users_phone_key` by default (constraint name on the UNIQUE
        // column). If the name differs we discover it from the catalog.
        $this->addSql(<<<SQL
            DO $$
            DECLARE
                cname TEXT;
            BEGIN
                SELECT conname INTO cname
                FROM pg_constraint
                WHERE conrelid = 'public.users'::regclass
                  AND contype = 'u'
                  AND conkey = (
                    SELECT array_agg(attnum ORDER BY attnum)
                    FROM pg_attribute
                    WHERE attrelid = 'public.users'::regclass
                      AND attname = 'phone'
                  )
                LIMIT 1;
                IF cname IS NOT NULL THEN
                    EXECUTE 'ALTER TABLE users DROP CONSTRAINT ' || quote_ident(cname);
                END IF;
            END
            $$;
        SQL);

        // Make phone nullable
        $this->addSql('ALTER TABLE users ALTER COLUMN phone DROP NOT NULL');

        // ============================================================
        // migration_email_conflicts, track legacy email duplicates
        // ============================================================

        $this->addSql(<<<SQL
            CREATE TABLE migration_email_conflicts (
                id                BIGSERIAL    PRIMARY KEY,
                legacy_user_id    INTEGER      NOT NULL,
                v3_user_id        BIGINT       NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                original_email    VARCHAR(255) NOT NULL,
                renamed_email     VARCHAR(255) NOT NULL,
                conflict_with_user_id BIGINT   NULL REFERENCES users(id) ON DELETE SET NULL,
                resolution_status VARCHAR(20)  NOT NULL DEFAULT 'pending',
                resolved_at       TIMESTAMPTZ  NULL,
                resolved_by_user_id BIGINT     NULL REFERENCES users(id) ON DELETE SET NULL,
                notes             TEXT         NULL,
                created_at        TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                UNIQUE (legacy_user_id)
            )
        SQL);

        $this->addSql('CREATE INDEX migration_email_conflicts_original_email_idx ON migration_email_conflicts (original_email)');
        $this->addSql('CREATE INDEX migration_email_conflicts_status_idx ON migration_email_conflicts (resolution_status) WHERE resolution_status = \'pending\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS migration_email_conflicts');
        // Re-tightening phone constraints could fail if NULL or duplicate
        // rows exist, we skip the reverse here. Manual cleanup if rollback needed.
    }
}
