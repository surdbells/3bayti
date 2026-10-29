<?php

declare(strict_types=1);

namespace Bayti\Api\Tests\Migrations;

use Bayti\Api\Migrations\Version20261029000001;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\Exception\AbortMigration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

require_once dirname(__DIR__, 2) . '/migrations/Version20261029000001.php';

/**
 * Pins the planned SQL of the session-family + heal migration (CI has no
 * PostgreSQL, so the statements are checked for shape, not executed):
 *
 *   - FIRST, rotated rows that predate a historical logout-all are retired
 *     (the old logout-all left them 'rotated', i.e. re-issuable now);
 *   - the merge heal re-points rows to an existing, active, non-deleted
 *     target parsed from 'account_merged_into_<id>';
 *   - the cascade heal relabels ONLY the historical session-killing reasons
 *     on unexpired rows, and spares users who later chose logout-all;
 *   - LAST, the DDL under a lock_timeout: family_id column + index, with NO
 *     full-table backfill (a NULL family already means "own family");
 *   - down() drops only the column and index (the heal is not reversed).
 */
#[CoversClass(Version20261029000001::class)]
final class Version20261029000001Test extends TestCase
{
    #[Test]
    public function dataStepsRunFirstAndTheDdlLastWithoutABackfill(): void
    {
        $sql = $this->plannedUp();

        self::assertCount(7, $sql);
        self::assertStringStartsWith('WITH logout_all AS', $this->squash($sql[0]));
        self::assertStringStartsWith('WITH merges AS', $this->squash($sql[1]));
        self::assertStringStartsWith('UPDATE user_refresh_tokens t SET revoked_reason = \'rotated\'', $this->squash($sql[2]));
        self::assertSame([
            "SET LOCAL lock_timeout = '5s'",
            'ALTER TABLE user_refresh_tokens ADD COLUMN IF NOT EXISTS family_id VARCHAR(36) DEFAULT NULL',
            'CREATE INDEX IF NOT EXISTS idx_refresh_tokens_family ON user_refresh_tokens (family_id)',
            'SET LOCAL lock_timeout TO DEFAULT',
        ], array_slice($sql, 3));

        // No statement rewrites every row (the old family_id = jti backfill
        // held the table lock for the whole rewrite).
        foreach ($sql as $statement) {
            self::assertStringNotContainsString('family_id = jti', $statement);
        }
    }

    #[Test]
    public function rotatedRowsThatPredateAHistoricalLogoutAllAreRetiredFirst(): void
    {
        $retire = $this->squash($this->plannedUp()[0]);

        // Per user: the last logout_all row id and timestamp.
        self::assertStringContainsString(
            "SELECT l.user_id, MAX(l.id) AS last_id, MAX(l.revoked_at) AS last_at FROM user_refresh_tokens l WHERE l.revoked_reason = 'logout_all' GROUP BY l.user_id",
            $retire,
        );
        // Relabelled, revoked_at kept (same semantics as revokeAllForUser).
        self::assertStringContainsString("UPDATE user_refresh_tokens t SET revoked_reason = 'logout_all' FROM logout_all la", $retire);
        self::assertStringNotContainsString('revoked_at =', $retire);
        // Only re-issuable, unexpired rows of that same user.
        self::assertStringContainsString(
            "WHERE t.user_id = la.user_id AND t.revoked_reason = 'rotated' AND t.expires_at > now()",
            $retire,
        );
        // Older than a live-at-the-time logout_all row (exact, clock-free),
        // or rotated more than a day before it (margin for the old
        // statement's time-zone-less timestamp; never retires a later
        // sign-in's tokens).
        self::assertStringContainsString(
            "AND (t.id < la.last_id OR t.revoked_at <= la.last_at - INTERVAL '1 day')",
            $retire,
        );
    }

    #[Test]
    public function cascadeHealRelabelsOnlyHistoricalReasonsOnUnexpiredRows(): void
    {
        $heal = $this->squash($this->plannedUp()[2]);

        self::assertStringStartsWith('UPDATE user_refresh_tokens t SET revoked_reason = \'rotated\'', $heal);
        self::assertStringContainsString(
            "WHERE t.revoked_reason IN ('reuse_detected', 'password_changed', 'admin_password_reset')",
            $heal,
        );
        self::assertStringContainsString('AND t.expires_at > now()', $heal);
        // Explicit logouts are never resurrected.
        self::assertStringNotContainsString("'logout'", $heal);
        // A later "log out of all devices" is respected.
        self::assertStringContainsString("l.revoked_reason = 'logout_all' AND l.revoked_at >= t.revoked_at", $heal);
    }

    #[Test]
    public function mergeHealRepointsToAnActiveExistingTarget(): void
    {
        $heal = $this->squash($this->plannedUp()[1]);

        self::assertStringStartsWith('WITH merges AS', $heal);
        self::assertStringContainsString(
            "CAST(substring(t.revoked_reason FROM '^account_merged_into_([0-9]+)$') AS BIGINT)",
            $heal,
        );
        self::assertStringContainsString('HAVING COUNT(DISTINCT t.revoked_reason) = 1', $heal);
        self::assertStringContainsString('UPDATE user_refresh_tokens r SET user_id = m.target_id', $heal);
        self::assertStringContainsString("THEN 'rotated'", $heal);
        self::assertStringContainsString('JOIN users u ON u.id = m.target_id', $heal);
        self::assertStringContainsString('AND u.is_active = TRUE AND u.deleted_at IS NULL', $heal);
        self::assertStringContainsString('WHERE r.user_id = m.source_id AND r.expires_at > now()', $heal);
        self::assertStringContainsString("l.user_id = m.target_id AND l.revoked_reason = 'logout_all'", $heal);
    }

    #[Test]
    public function downDropsOnlyTheIndexAndColumn(): void
    {
        $migration = new Version20261029000001($this->connection(new PostgreSQLPlatform()), new NullLogger());
        $migration->down(new Schema());
        $sql = array_map(static fn ($q): string => $q->getStatement(), $migration->getSql());

        self::assertSame([
            'DROP INDEX IF EXISTS idx_refresh_tokens_family',
            'ALTER TABLE user_refresh_tokens DROP COLUMN IF EXISTS family_id',
        ], $sql);
    }

    #[Test]
    public function abortsOnNonPostgres(): void
    {
        $platform = $this->createMock(AbstractPlatform::class);
        $migration = new Version20261029000001($this->connection($platform), new NullLogger());

        $this->expectException(AbortMigration::class);
        $migration->up(new Schema());
    }

    // ── harness ─────────────────────────────────────────────────────

    /** @return list<string> */
    private function plannedUp(): array
    {
        $migration = new Version20261029000001($this->connection(new PostgreSQLPlatform()), new NullLogger());
        $migration->up(new Schema());
        return array_map(static fn ($q): string => $q->getStatement(), $migration->getSql());
    }

    private function connection(AbstractPlatform $platform): Connection
    {
        $conn = $this->createMock(Connection::class);
        $conn->method('getDatabasePlatform')->willReturn($platform);
        $conn->method('createSchemaManager')->willReturn($this->createMock(AbstractSchemaManager::class));
        return $conn;
    }

    private function squash(string $sql): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $sql));
    }
}
