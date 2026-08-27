<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.Z.4-A, Push notifications: device_tokens table.
 *
 * Stores the per-device push registration tokens a user's mobile
 * app(s) hand us, so the backend can fan out push notifications at
 * the same lifecycle moments it already fans out email
 * (OrderNotificationService). Net-new for Stream Z push (Q-Z4=A:
 * FCM-only, FCM relays to APNs for iOS, so a single token type).
 *
 * Schema
 * ======
 * device_tokens
 *   id            BIGSERIAL PK
 *   user_id       BIGINT NOT NULL → users(id) ON DELETE CASCADE
 *                   (delete the user → drop their device tokens)
 *   token         TEXT NOT NULL
 *                   The FCM registration token. TEXT (not VARCHAR)
 *                   because FCM tokens are long (~160+ chars) and the
 *                   format is not contractually bounded.
 *   platform      VARCHAR(16) NOT NULL   (ios | android)
 *   is_active     BOOLEAN NOT NULL DEFAULT true
 *                   Set false when FCM reports the token is no longer
 *                   valid (UNREGISTERED / NOT_FOUND), so we stop
 *                   targeting dead devices without deleting history.
 *   created_at    TIMESTAMPTZ NOT NULL
 *   updated_at    TIMESTAMPTZ NOT NULL
 *   last_seen_at  TIMESTAMPTZ NULL
 *                   Refreshed each time the device re-registers (app
 *                   open), so stale tokens can be pruned later.
 *   UNIQUE (token)                , one row per physical device token;
 *                                    re-registration upserts this row.
 *   INDEX (user_id, is_active)    , the fan-out lookup: "active tokens
 *                                    for this user".
 *
 * Note on UNIQUE(token), not UNIQUE(user_id, token): a device token is
 * globally unique to a device+app install. If the same device later
 * signs in as a different user, the token row's user_id is reassigned
 * on re-registration (upsert on token), which correctly stops pushing
 * the previous user's notifications to a device they no longer own.
 */
final class Version20260520000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.Z.4-A — device_tokens table for push notifications';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE device_tokens (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                token TEXT NOT NULL,
                platform VARCHAR(16) NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT true,
                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,
                last_seen_at TIMESTAMPTZ NULL
            )
        SQL);

        $this->addSql('CREATE UNIQUE INDEX uq_device_tokens_token ON device_tokens (token)');
        $this->addSql('CREATE INDEX idx_device_tokens_user_active ON device_tokens (user_id, is_active)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_device_tokens_user_active');
        $this->addSql('DROP INDEX IF EXISTS uq_device_tokens_token');
        $this->addSql('DROP TABLE IF EXISTS device_tokens');
    }
}
