<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M1.7.0 — extend users with profile fields
 * =========================================
 *
 * Adds four columns to the existing `users` table:
 *
 *   gender    VARCHAR(20)   nullable    — male / female / other / prefer_not_to_say
 *   dob       DATE          nullable    — birthdate
 *   locale    VARCHAR(10)   not null    — BCP 47 locale, default 'en'
 *   timezone  VARCHAR(50)   not null    — IANA timezone, default 'Asia/Dubai'
 *
 * These power the M1.7.1 PATCH /v3/me/profile endpoint and are read by
 * downstream features (M3 birthday promotions, M3 localised emails,
 * order timestamps in user's local time).
 *
 * Why this migration is additive-only
 * -----------------------------------
 * Production already has user rows from M1.5 deploy. We can't
 * retroactively populate gender/dob (private user data we never
 * collected), and we don't want to invalidate sessions by changing
 * other columns. So:
 *
 *   - gender + dob are NULLABLE — existing rows have NULL, fine
 *   - locale + timezone get DEFAULT values that backfill at ALTER time
 *
 * The defaults are chosen to match our primary market (UAE) and our
 * primary launch locale (English UI; Arabic is M1.7.1.5+ work).
 *
 * Why CHECK constraint on gender instead of Postgres enum
 * --------------------------------------------------------
 * Postgres ENUM types are notoriously hard to alter. Adding a new value
 * is fine (`ALTER TYPE ... ADD VALUE ...`) but renaming or removing
 * values requires recreating the type and rewriting all dependent
 * tables. CHECK on a VARCHAR column gives us the same validation power
 * with much easier evolution.
 *
 * Why DATE not TIMESTAMP for dob
 * -------------------------------
 * A birthdate is a calendar date, not a moment in time. Storing as
 * TIMESTAMP introduces timezone ambiguity ("born at midnight UTC vs
 * local"). DATE is the right type — Doctrine maps it to PHP's
 * DateTimeImmutable but the time component is always 00:00:00.
 */
final class Version20260509000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.7.0: extend users with gender, dob, locale, timezone';
    }

    public function up(Schema $schema): void
    {
        // Add the four columns.
        // Postgres allows multiple ADD COLUMN in one ALTER TABLE; we
        // use that for a single transactional operation.
        $this->addSql(<<<'SQL'
            ALTER TABLE users
                ADD COLUMN gender VARCHAR(20),
                ADD COLUMN dob DATE,
                ADD COLUMN locale VARCHAR(10) NOT NULL DEFAULT 'en',
                ADD COLUMN timezone VARCHAR(50) NOT NULL DEFAULT 'Asia/Dubai'
        SQL);

        // Constrain gender to the allowed values. CHECK constraint
        // accepts NULL (nullable column) plus the four enum values.
        // 'prefer_not_to_say' lets users opt out of disclosing without
        // setting it to NULL (NULL means "never asked"; opt-out is a
        // distinct semantic).
        $this->addSql(<<<'SQL'
            ALTER TABLE users
                ADD CONSTRAINT users_gender_check
                CHECK (gender IS NULL OR gender IN ('male', 'female', 'other', 'prefer_not_to_say'))
        SQL);

        // No new indexes — these columns are read-on-profile-load,
        // not searchable. If we ever filter users by locale (e.g.,
        // for Arabic-speaking marketing campaigns), we add an index then.
    }

    public function down(Schema $schema): void
    {
        // Reverse order — drop constraint first, then columns.
        // Down migrations are mostly for local-dev rollback; in
        // production we don't run `migrations:execute --down` because
        // it would lose data. Documented here for completeness.
        $this->addSql('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_gender_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE users
                DROP COLUMN IF EXISTS timezone,
                DROP COLUMN IF EXISTS locale,
                DROP COLUMN IF EXISTS dob,
                DROP COLUMN IF EXISTS gender
        SQL);
    }
}
