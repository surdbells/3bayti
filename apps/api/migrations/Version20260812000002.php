<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Normalize legacy-migrated user phones to E.164 (+971…).
 *
 * New sign-ups store the phone in E.164 already ('+971542192976'), but the
 * legacy import stored a LOCAL number ('0506995999' / '506995999') alongside
 * country_code 'AE'. Two consequences:
 *   1. The admin customer list rendered "AE0506995999".
 *   2. OTP login broke: the app sends the number as '+971506995999', but
 *      UserRepository::findByPhone is an exact match, so it never matched the
 *      stored local form — the user "wasn't found", anti-enumeration returned
 *      a fake verification id, and no SMS was ever dispatched (nothing in the
 *      MessageCentral logs).
 *
 * This rewrites the stored phone to '+971' + the 9-digit national number for
 * rows that are unambiguously a UAE mobile in local form: country_code 'AE'
 * and a value matching ^0?5XXXXXXXX (an optional single leading zero, then a
 * 9-digit 5-prefixed mobile). E.164 rows (LIKE '+%'), landlines, and any other
 * shape are left untouched, so it is safe and idempotent (a second run matches
 * nothing).
 *
 * Preview before/after with:
 *   SELECT count(*) FROM users
 *   WHERE country_code = 'AE' AND phone ~ '^0?5[0-9]{8}$';
 */
final class Version20260812000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Normalize legacy UAE mobile numbers (local form) to E.164 (+971…).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE users
            SET phone = '+971' || regexp_replace(phone, '^0', '')
            WHERE country_code = 'AE'
              AND phone IS NOT NULL
              AND phone ~ '^0?5[0-9]{8}$'
        SQL);
    }

    public function down(Schema $schema): void
    {
        // One-way normalization: the original local form (and whether it had a
        // leading zero) is not preserved. No-op.
    }
}
