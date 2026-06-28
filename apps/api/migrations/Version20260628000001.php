<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Social sign-in foundation — `social_identities` table.
 *
 * Backs Google + Apple federated login (via Firebase ID tokens). Each
 * row links a verified provider account (provider + provider_uid) to a
 * platform user. A user may have many social identities (e.g. both
 * Google and Apple) but a given (provider, provider_uid) pair maps to
 * at most one user — enforced by the UNIQUE(provider, provider_uid)
 * constraint, which is also what makes the "find or create" login flow
 * race-safe.
 *
 * ON DELETE CASCADE: when a user is hard-deleted, their social links go
 * with them. (Soft-delete leaves the rows in place — the app filters by
 * users.deleted_at, not by removing identity rows.)
 *
 * Provider CHECK: only 'google' / 'apple' for now. Widening to more
 * providers later is a CHECK-rewrite migration like the audit_log one.
 */
final class Version20260628000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create social_identities (Google/Apple federated login) linking provider accounts to platform users.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE social_identities (
                id BIGSERIAL NOT NULL,
                user_id BIGINT NOT NULL,
                provider VARCHAR(16) NOT NULL,
                provider_uid VARCHAR(255) NOT NULL,
                email VARCHAR(255) DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT NOW(),
                PRIMARY KEY (id),
                CONSTRAINT social_identities_provider_check CHECK (provider IN ('google', 'apple')),
                CONSTRAINT uniq_social_identity_provider_uid UNIQUE (provider, provider_uid),
                CONSTRAINT fk_social_identities_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE
            )
        SQL);

        $this->addSql('CREATE INDEX idx_social_identities_user ON social_identities (user_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE social_identities');
    }
}
