<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Session families for refresh tokens + one-off heal of sessions the old
 * reuse-detection cascade killed.
 *
 * Product rule: a customer stays signed in until they explicitly log out.
 *
 * 1. RETIRE rotated tokens that predate a historical "log out of all
 *    devices". The old logout-all revoked only still-active rows, so every
 *    token the user had already ROTATED kept revoked_reason 'rotated'. The
 *    new RefreshController re-issues a rotated token of any age, which
 *    would let such a token undo that explicit logout-all. Those rows are
 *    relabelled 'logout_all' (revoked_at kept), the same thing
 *    RefreshTokenRepository::revokeAllForUser now does. A rotated row
 *    predates a logout-all when either:
 *      - its id is lower than a 'logout_all' row of the same user: that
 *        row was live when the logout-all ran, so this one already existed,
 *        and since the logout-all did not revoke it, it was already
 *        rotated. Ids come from one sequence, so this is exact and needs
 *        no clock; or
 *      - it was rotated more than a day before the user's last logout-all.
 *        The old logout-all bound its timestamp without a time zone (the
 *        PHP/Postgres session time-zone difference can shift it by hours),
 *        while rotations were written with one; the 1-day margin keeps
 *        that skew from ever retiring a token rotated AFTER the logout-all
 *        (a later sign-in), which would sign that device out.
 *    Runs FIRST, so a merge source's own earlier logout-all is honoured
 *    before step 2 moves its rows, and steps 2-3 never see these rows as
 *    'rotated'.
 *
 * 2. HEAL phone-claim merges. The merge revoked the merged-away (source)
 *    account's sessions as 'account_merged_into_<target id>'. Now the merge
 *    re-points the sessions to the target instead (reassignUser). For past
 *    merges, every unexpired row of the source is re-pointed to the target
 *    and the merge reason becomes 'rotated', so those devices continue as
 *    the target account. Only when the source merged into exactly one
 *    target, the target exists, is active and not soft-deleted
 *    (users.is_active / users.deleted_at), and the target has not logged out
 *    of all devices since the merge. RefreshController re-issues for the
 *    row's user, not the JWT sub, so re-pointing the row is enough.
 *
 * 3. HEAL the cascade. Until this release, presenting a non-rotated revoked
 *    token (or a rotated one older than 30 days) revoked EVERY session of
 *    the user as 'reuse_detected'; each other device that later presented
 *    one of those rows re-triggered it, a self-feeding cascade. Password
 *    change/reset and admin resets ('password_changed';
 *    'admin_password_reset', written by both AdminResetPasswordController
 *    and ResetVendorPasswordController's former REVOKE_REASON) started it.
 *    Those reasons are relabelled 'rotated' on unexpired rows, so a device
 *    that still holds such a token is re-issued a pair on its next refresh
 *    instead of being signed out. Rows of a user who later chose "log out
 *    of all devices" (a 'logout_all' revoked at or after the row; both
 *    timestamps were written by the same old statement, so they compare
 *    like for like) are left dead: that was an explicit request.
 *
 * 4. user_refresh_tokens.family_id (+ index). One family = one signed-in
 *    device: a sign-in starts it (family_id = that token's jti), every
 *    rotation copies it to the replacement. Logout now ends the device's
 *    family, so an already-rotated token can't bring a logged-out session
 *    back. Existing rows are NOT backfilled: a NULL family_id already means
 *    "own family" everywhere (RefreshToken::getFamilyId(),
 *    RefreshTokenRepository::revokeFamily() and the rotation fall back to
 *    the jti), and rewriting every row would hold the table lock for as
 *    long as the rewrite takes.
 *
 * Locking (deploy note)
 * ---------------------
 * This is the hottest auth table: every /refresh, sign-in and logout uses
 * it. Steps 1-3 are plain UPDATEs (row locks on the rows they change, all
 * of them dead or re-pointed tokens), so they run FIRST while traffic
 * continues. The DDL comes LAST: ALTER TABLE takes an ACCESS EXCLUSIVE lock
 * that is held to COMMIT, so the window in which auth requests queue is the
 * metadata-only ALTER plus the index build, roughly one sequential scan of
 * user_refresh_tokens. Check it before go-live:
 *   SELECT count(*), pg_size_pretty(pg_total_relation_size('user_refresh_tokens'))
 *   FROM user_refresh_tokens;
 * lock_timeout makes the ALTER give up after 5 s instead of queueing every
 * auth request behind a long-running transaction; the whole run then rolls
 * back and can simply be re-run.
 * Ship the web BFF and mobile OTA session fixes BEFORE running this: older
 * clients sign customers out on a slow or failed refresh. And deploy the
 * API code and run this migration back to back (the standard deploy hook):
 * the new code needs the column, and the old code would treat a healed
 * row that was revoked more than 30 days ago as reuse and wipe that user's
 * sessions again.
 *
 * down() drops the column and index only. The retire/heal steps are a data
 * fix and are NOT reversed: the old reason strings can't be reconstructed
 * and putting them back would sign those customers out again.
 */
final class Version20261029000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add user_refresh_tokens.family_id (session families) and heal sessions killed by the old reuse-detection cascade.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        // (1) Rotated tokens that predate a historical logout-all stay dead.
        $this->addSql(<<<'SQL'
            WITH logout_all AS (
                SELECT l.user_id,
                       MAX(l.id) AS last_id,
                       MAX(l.revoked_at) AS last_at
                FROM user_refresh_tokens l
                WHERE l.revoked_reason = 'logout_all'
                GROUP BY l.user_id
            )
            UPDATE user_refresh_tokens t
            SET revoked_reason = 'logout_all'
            FROM logout_all la
            WHERE t.user_id = la.user_id
              AND t.revoked_reason = 'rotated'
              AND t.expires_at > now()
              AND (t.id < la.last_id OR t.revoked_at <= la.last_at - INTERVAL '1 day')
        SQL);

        // (2) Phone-claim merges: move every unexpired row of the merged-away
        //     account to the surviving one; the merge rows become 'rotated'.
        $this->addSql(<<<'SQL'
            WITH merges AS (
                SELECT t.user_id AS source_id,
                       MIN(CAST(substring(t.revoked_reason FROM '^account_merged_into_([0-9]+)$') AS BIGINT)) AS target_id,
                       MAX(t.revoked_at) AS merged_at
                FROM user_refresh_tokens t
                WHERE t.revoked_reason ~ '^account_merged_into_[0-9]+$'
                GROUP BY t.user_id
                HAVING COUNT(DISTINCT t.revoked_reason) = 1
            )
            UPDATE user_refresh_tokens r
            SET user_id = m.target_id,
                revoked_reason = CASE
                    WHEN r.revoked_reason ~ '^account_merged_into_[0-9]+$' THEN 'rotated'
                    ELSE r.revoked_reason
                END
            FROM merges m
            JOIN users u ON u.id = m.target_id
            WHERE r.user_id = m.source_id
              AND r.expires_at > now()
              AND u.is_active = TRUE
              AND u.deleted_at IS NULL
              AND NOT EXISTS (
                  SELECT 1 FROM user_refresh_tokens l
                  WHERE l.user_id = m.target_id
                    AND l.revoked_reason = 'logout_all'
                    AND l.revoked_at >= m.merged_at
              )
        SQL);

        // (3) Cascade + password/admin-reset revocations become re-issuable.
        $this->addSql(<<<'SQL'
            UPDATE user_refresh_tokens t
            SET revoked_reason = 'rotated'
            WHERE t.revoked_reason IN ('reuse_detected', 'password_changed', 'admin_password_reset')
              AND t.expires_at > now()
              AND NOT EXISTS (
                  SELECT 1 FROM user_refresh_tokens l
                  WHERE l.user_id = t.user_id
                    AND l.revoked_reason = 'logout_all'
                    AND l.revoked_at >= t.revoked_at
              )
        SQL);

        // (4) Session families. DDL last and fail-fast on the lock (see the
        //     class docblock); no backfill, NULL already means "own family".
        $this->addSql("SET LOCAL lock_timeout = '5s'");
        $this->addSql('ALTER TABLE user_refresh_tokens ADD COLUMN IF NOT EXISTS family_id VARCHAR(36) DEFAULT NULL');
        $this->addSql('CREATE INDEX IF NOT EXISTS idx_refresh_tokens_family ON user_refresh_tokens (family_id)');
        // Don't leak the timeout into migrations that run after this one in
        // the same all-or-nothing transaction.
        $this->addSql('SET LOCAL lock_timeout TO DEFAULT');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        // The retire/heal steps in up() are a one-off data fix and are
        // deliberately not reversed (see class docblock).
        $this->addSql('DROP INDEX IF EXISTS idx_refresh_tokens_family');
        $this->addSql('ALTER TABLE user_refresh_tokens DROP COLUMN IF EXISTS family_id');
    }
}
