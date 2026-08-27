<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marketing-push foundation (PUSH-A).
 *
 * Three additive, backward-compatible schema changes that underpin the
 * new marketing-push fan-out + opt-out work:
 *
 *   1. orders.delivered_at (TIMESTAMPTZ NULL)
 *      ------------------------------------------------------------------
 *      Records the wall-clock moment an order first reached the
 *      DELIVERED status. Set once by Order::recomputeStatusFromItems
 *      (never overwritten on a re-delivery transition) and consumed by
 *      the post-delivery "review prompt" follow-up finder/cron (next
 *      agent). Nullable because every existing order pre-dates the
 *      column, and orders that have not been delivered legitimately
 *      have no value. NOT backfilled, the follow-up only targets
 *      orders delivered AFTER this column exists, so a NULL backfill is
 *      both correct and avoids guessing at historical delivery times.
 *
 *   2. notification_logs generalization
 *      ------------------------------------------------------------------
 *      The table was born order/email-only. Push sends (and other future
 *      channels) need to record against a *user* rather than an order,
 *      and may have no email recipient at all. So:
 *        - add user_id INTEGER NULL  (FK users(id) ON DELETE SET NULL,
 *          indexed), preserves the audit row if the user is hard-
 *          deleted, same posture as the existing order_id / cart_id FKs.
 *        - add channel VARCHAR(16) NOT NULL DEFAULT 'email', every
 *          existing row is an email send, so the default backfills them
 *          correctly; push rows will store 'push'.
 *        - make recipient NULLABLE, a push send targets device tokens,
 *          not an email address, so recipient is meaningless there.
 *          Existing rows keep their (non-null) email recipient.
 *
 *   3. users.marketing_push_opt_out (BOOLEAN NOT NULL DEFAULT false)
 *      ------------------------------------------------------------------
 *      Per-user opt-out for the THREE marketing pushes (cart.abandoned,
 *      order.review_prompt, re_engagement.nudge). Mirrors the existing
 *      users.marketing_emails_opt_out flag but scoped to the push
 *      channel. Default false = opt-in posture (consistent with the
 *      email flag). Order-lifecycle pushes IGNORE this flag.
 *
 * Transactional + safe to re-run
 * ------------------------------
 * All statements are plain ALTER TABLE ... ADD COLUMN / ALTER COLUMN,
 * which PostgreSQL runs inside the migration transaction. No CREATE
 * EXTENSION (project rule). down() reverses each change. The IF EXISTS /
 * IF NOT EXISTS guards keep both directions idempotent.
 *
 * recipient down() caveat
 * -----------------------
 * down() re-imposes NOT NULL on recipient. If any push rows (recipient
 * NULL) were written between up() and down(), the NOT NULL re-add would
 * fail. down() therefore first deletes rows where recipient IS NULL
 * before re-tightening, acceptable because rollback is exceptional and
 * those rows only exist post-up() (i.e. they are the very rows this
 * migration enabled). Email/audit rows always have a recipient and are
 * untouched.
 */
final class Version20260623000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Marketing-push foundation — orders.delivered_at, notification_logs generalization (user_id/channel/nullable recipient), users.marketing_push_opt_out.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // (1) orders.delivered_at, when the order first reached DELIVERED.
        $this->addSql('ALTER TABLE orders ADD COLUMN IF NOT EXISTS delivered_at TIMESTAMPTZ NULL');

        // (2) notification_logs generalization.
        $this->addSql(
            "ALTER TABLE notification_logs ADD COLUMN IF NOT EXISTS user_id INTEGER NULL "
            . "REFERENCES users(id) ON DELETE SET NULL"
        );
        $this->addSql(
            'CREATE INDEX IF NOT EXISTS idx_notification_logs_user_id ON notification_logs (user_id)'
        );
        $this->addSql(
            "ALTER TABLE notification_logs ADD COLUMN IF NOT EXISTS channel VARCHAR(16) NOT NULL DEFAULT 'email'"
        );
        // recipient was VARCHAR(255) NOT NULL; relax it for channels
        // (push) that have no email recipient.
        $this->addSql('ALTER TABLE notification_logs ALTER COLUMN recipient DROP NOT NULL');

        // (3) users.marketing_push_opt_out, push counterpart to the
        // existing marketing_emails_opt_out flag.
        $this->addSql(
            'ALTER TABLE users ADD COLUMN IF NOT EXISTS marketing_push_opt_out BOOLEAN NOT NULL DEFAULT false'
        );
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // (3) reverse
        $this->addSql('ALTER TABLE users DROP COLUMN IF EXISTS marketing_push_opt_out');

        // (2) reverse, drop rows that could not satisfy the re-tightened
        // NOT NULL (only post-up() push rows), then restore the constraint
        // and drop the added columns/index.
        $this->addSql('DELETE FROM notification_logs WHERE recipient IS NULL');
        $this->addSql('ALTER TABLE notification_logs ALTER COLUMN recipient SET NOT NULL');
        $this->addSql('ALTER TABLE notification_logs DROP COLUMN IF EXISTS channel');
        $this->addSql('DROP INDEX IF EXISTS idx_notification_logs_user_id');
        $this->addSql('ALTER TABLE notification_logs DROP COLUMN IF EXISTS user_id');

        // (1) reverse
        $this->addSql('ALTER TABLE orders DROP COLUMN IF EXISTS delivered_at');
    }
}
