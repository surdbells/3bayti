<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Backfill vendor KYC documents from the legacy owner-user columns.
 *
 * The legacy platform stored a store owner's identity documents on the USER
 * row (users.id_front / id_back / license_doc, base64 data URLs). The v3
 * migration Version20260614000003 added vendors.id_front/id_back/license_doc
 * but never copied the data across, so the admin + vendor compliance screens
 * showed every legacy vendor's ID (front/back) and licence as "missing".
 *
 * This copies each document from the vendor's owner user into the vendor,
 * but ONLY where the vendor's own column is still empty (so a doc the vendor
 * re-uploaded post-migration is never clobbered). Guarded per-column via
 * information_schema so it is a safe no-op on installs where the legacy
 * users columns don't exist. Idempotent, re-running only fills blanks.
 */
final class Version20260826000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Backfill vendors.id_front/id_back/license_doc from legacy users.* columns.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        // The UPDATE lives inside an IF EXISTS guard so PL/pgSQL only plans it
        // when the legacy column is present (unplanned branches don't error on
        // a missing column). Nowdoc keeps the $$ dollar-quotes literal.
        $template = <<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_name = 'users' AND column_name = 'COL'
                ) THEN
                    UPDATE vendors v
                    SET COL = u.COL
                    FROM users u
                    WHERE v.owner_user_id = u.id
                      AND COALESCE(v.COL, '') = ''
                      AND COALESCE(u.COL, '') <> '';
                END IF;
            END $$;
            SQL;

        foreach (['id_front', 'id_back', 'license_doc'] as $col) {
            $this->addSql(str_replace('COL', $col, $template));
        }
    }

    public function down(Schema $schema): void
    {
        // No-op: a data backfill isn't safely reversible, once copied we can't
        // distinguish backfilled docs from ones the vendor uploaded afterwards.
    }
}
