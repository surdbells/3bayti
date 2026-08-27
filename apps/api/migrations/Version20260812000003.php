<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Mark legacy-migrated users as phone-verified so they can log in.
 *
 * The legacy import inserts every migrated user with is_phone_verified =
 * FALSE (there was no verified flag to carry over). But OTP login gates on
 * is_phone_verified for BOTH channels: OtpLoginSendController returns a
 * FAKE verification id and dispatches NOTHING when the resolved user is not
 * phone-verified. So every migrated customer hit a silent dead end -
 * the app showed the "code sent" screen, but no SMS/email was ever sent
 * (nothing in the MessageCentral / ZeptoMail logs), while brand-new v3
 * sign-ups (verified during registration) logged in fine.
 *
 * Migrated accounts are established legacy customers, the legacy platform
 * authenticated them by phone OTP (same MessageCentral flow), so their
 * numbers are real and trusted. We flip is_phone_verified = TRUE for them
 * so login dispatches a real code.
 *
 * Scope: legacy_user_id IS NOT NULL, migrated rows only. Native v3 sign-ups
 * (legacy_user_id NULL) keep their real verification state. is_email_verified
 * is deliberately left untouched: login does not depend on it (it checks
 * is_phone_verified), and we don't assert email ownership we can't
 * substantiate. Idempotent, a second run matches nothing.
 *
 * Preview affected rows:
 *   SELECT count(*) FROM users
 *   WHERE legacy_user_id IS NOT NULL AND is_phone_verified = FALSE;
 */
final class Version20260812000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Mark legacy-migrated users phone-verified so OTP login dispatches a code.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE users
            SET is_phone_verified = TRUE,
                updated_at = date_trunc('second', NOW())
            WHERE legacy_user_id IS NOT NULL
              AND is_phone_verified = FALSE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // One-way: we don't know which migrated users were unverified before
        // this ran, so we can't safely restore the prior state. No-op.
    }
}
