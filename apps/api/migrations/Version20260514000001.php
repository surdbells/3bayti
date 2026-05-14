<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.1.1d — create user_locations table.
 *
 * Backs the GET / PATCH /v3/me/location endpoints that are being
 * added in M3.1.1e. Single-row-per-user model: a UNIQUE constraint
 * on user_id enforces "one location row per user". If location
 * history becomes relevant in M4+, drop the unique index and add
 * a captured_at-keyed "most recent" pointer.
 *
 * Schema rationale
 * ----------------
 *   - latitude/longitude as DECIMAL(9,6): precision 9 supports
 *     ±90 / ±180 ranges; scale 6 gives ~11cm resolution, enough
 *     for marketplace UX. DECIMAL (not REAL/DOUBLE) avoids float-
 *     to-string conversion surprises like the Day 4 microsecond bug.
 *   - city/country_code: nullable; client may send neither, either,
 *     or both. Country is ISO 3166-1 alpha-2 (always uppercase).
 *   - permission_granted: NOT NULL, default false. Records the
 *     user's explicit OS-permission decision so the app doesn't
 *     re-prompt on every launch.
 *   - last_captured_at: distinct from updated_at. Bumps only when
 *     fresh lat/lng arrives, not when permission_granted toggles
 *     or city is manually typed.
 *
 * Why a new table and not columns on `users`
 * ------------------------------------------
 *   1. Location data is sparse — most users won't grant permission;
 *      sparse columns on the wide `users` table waste storage.
 *   2. The location capture cadence (first launch, on-demand) differs
 *      from User row updates (every API request via last_login_at).
 *      Splitting reduces write contention.
 *   3. Future-proofing for location history without disrupting User.
 *
 * Why a separate countryCode column from User.countryCode
 * -------------------------------------------------------
 * They mean different things:
 *   - User.countryCode = nationality / SMS country (stable)
 *   - UserLocation.countryCode = current physical location (mutates)
 * A UAE resident traveling shouldn't have their account-level country
 * flip; storing in separate tables prevents accidental conflation.
 *
 * Idempotency
 * -----------
 * The migration is forward-only safe — creates a new table that
 * doesn't depend on legacy data. No backfill from MySQL is needed
 * (legacy didn't have structured location data; mobile's
 * `customer/settings/update-location` stored only a free-form
 * string in `users.location` which doesn't migrate cleanly to this
 * schema). Legacy users will get UserLocation rows on first call
 * to PATCH /v3/me/location after the v3 endpoint lands.
 *
 * Reversibility
 * -------------
 * down() drops the table. Safe to reverse because no other tables
 * reference user_locations (it's a leaf in the FK graph).
 */
final class Version20260514000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.1.1d — create user_locations table for /v3/me/location endpoint';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE user_locations (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,

                latitude DECIMAL(9, 6),
                longitude DECIMAL(9, 6),
                city VARCHAR(100),
                country_code VARCHAR(2),

                permission_granted BOOLEAN NOT NULL DEFAULT FALSE,
                last_captured_at TIMESTAMPTZ,

                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL
            )
        SQL);

        // Index on user_id for FK lookups. Even though UNIQUE
        // implies an index, declare explicitly for clarity.
        $this->addSql('CREATE INDEX idx_user_locations_user ON user_locations (user_id)');

        // One row per user. Enforced at the DB level so the
        // application can't accidentally create duplicates via
        // a race. The repo's findForUser + save flow assumes
        // this invariant.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_user_locations_user
            ON user_locations (user_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // No dependent tables; safe to drop directly.
        $this->addSql('DROP TABLE IF EXISTS user_locations');
    }
}
