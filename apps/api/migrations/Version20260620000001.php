<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Email-OTP support — extend user_otp_attempts for a locally-generated,
 * locally-verified email channel alongside the existing
 * MessageCentral-delegated SMS channel.
 *
 * New columns
 * ===========
 *   - channel VARCHAR(10) NOT NULL DEFAULT 'sms'
 *       Delivery channel: 'sms' (MessageCentral) or 'email' (local).
 *       Defaults to 'sms' so every pre-existing row + the legacy SMS
 *       path keep working with no backfill.
 *
 *   - code_hash VARCHAR(255) NULL
 *       password_hash() of the 6-digit code, for the email channel
 *       ONLY. Null for SMS (MessageCentral holds the code). The
 *       PLAINTEXT code is never stored — we compare with
 *       password_verify() at verify time.
 *
 *   - email VARCHAR(255) NULL
 *       Recipient address for the email channel; null for SMS (the
 *       destination there is `phone`). Lets us rate-limit + audit
 *       email-OTP sends by address.
 *
 *   - attempts SMALLINT NOT NULL DEFAULT 0
 *       Count of failed LOCAL verify attempts (email channel). Capped
 *       at 5 (OtpAttempt::MAX_EMAIL_ATTEMPTS); once reached the row is
 *       burned. Always 0 for SMS (MessageCentral tracks its own).
 *
 * All four columns are additive + nullable-or-defaulted, so this
 * migration is safe to apply online with no row rewrites blocking
 * reads.
 *
 * Reversibility: down() drops the four columns (IF EXISTS).
 */
final class Version20260620000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Email-OTP — add channel/code_hash/email/attempts to user_otp_attempts';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE user_otp_attempts
                ADD COLUMN channel VARCHAR(10) NOT NULL DEFAULT 'sms',
                ADD COLUMN code_hash VARCHAR(255) DEFAULT NULL,
                ADD COLUMN email VARCHAR(255) DEFAULT NULL,
                ADD COLUMN attempts SMALLINT NOT NULL DEFAULT 0
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE user_otp_attempts
                DROP COLUMN IF EXISTS channel,
                DROP COLUMN IF EXISTS code_hash,
                DROP COLUMN IF EXISTS email,
                DROP COLUMN IF EXISTS attempts
        SQL);
    }
}
