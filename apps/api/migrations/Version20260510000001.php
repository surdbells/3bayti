<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M1.6.1.C, audit log infrastructure
 * ====================================
 *
 * Creates the `audit_log` table, single shared table for all
 * mutating-action records across the application.
 *
 * Why one shared table (not one-per-entity)
 * ------------------------------------------
 *   - Forensics queries are usually subject-agnostic ("what did this
 *     user touch in the last hour?"), a single table answers that
 *     in one query
 *   - Schema is identical: who, what, when, before/after. No reason
 *     to duplicate it per subject type
 *   - Retention/archival operates on one table not N
 *
 * Schema choices
 * --------------
 *
 *   user_id BIGINT NULL
 *     The actor, who initiated the change. Nullable for system-driven
 *     events (cron jobs, M3 order auto-cancellations). The actor's
 *     row in users may be deleted later; we preserve the id but
 *     don't FK because audit must outlive the actor (compliance).
 *
 *   subject_type VARCHAR(50) NOT NULL
 *     Class basename of the entity that changed: 'User', 'Address',
 *     'Measurement', 'Order' (M3+). Not a FK because subjects are
 *     polymorphic, Postgres polymorphic FK is awkward; the type
 *     column does the work.
 *
 *   subject_id BIGINT NOT NULL
 *     The primary key of the changed entity. Same lifecycle reasoning
 *     as user_id, no FK; the entity might be deleted later.
 *
 *   action VARCHAR(20) NOT NULL
 *     Short code: 'created' | 'updated' | 'deleted' | 'default'
 *     | 'consumed' (for OTPs in future) | etc. Free-form string
 *     bounded by application convention. CHECK constraint enforces
 *     a known set; we add to it via migration as new actions emerge.
 *
 *   changes JSONB NOT NULL DEFAULT '{}'
 *     Per Q4=A, structure is:
 *       create:  { "after": { fieldName: value, ... } }
 *       update:  { "before": { changedField: oldVal },
 *                  "after":  { changedField: newVal } }
 *       delete:  { "before": { fieldName: value, ... } }
 *     Only fields that changed are included for updates. Sensitive
 *     fields (per Q5=A: *_hash, *_token) are replaced with the
 *     literal string '[REDACTED]' before serialisation.
 *
 *   ip_address INET NULL
 *     Postgres has a native INET type, better than VARCHAR for
 *     range queries ("any audit from this /24?"). Nullable for
 *     non-HTTP-initiated events.
 *
 *   user_agent TEXT NULL
 *     Browser UA string. Free-form, length-unbounded by intent -
 *     UA strings can be long.
 *
 *   request_id VARCHAR(40) NULL
 *     Correlation id from RequestIdMiddleware (M1.6.2.B). Lets us
 *     join "all audit log rows from this one request" with logs
 *     from var/logs/ that carry the same id. Nullable because some
 *     events fire outside HTTP contexts.
 *
 *   created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
 *     UTC. Indexed for time-range queries.
 *
 * Indexes
 * -------
 *   - (subject_type, subject_id): the most common forensic query
 *     ("show me everything that happened to address #42")
 *   - (user_id, created_at DESC): "what has user X been doing
 *     recently"
 *
 * No index on (subject_type) alone, the (subject_type, subject_id)
 * composite covers single-type queries via leading-column rule.
 *
 * Retention
 * ---------
 * No automatic cleanup in this migration. M3's platform_settings
 * table will hold a configurable retention period (default 1y); a
 * cron job DELETEs rows older than the threshold. Until then, rows
 * accumulate. At our expected scale (~hundreds of mutations/day at
 * most early on) this is fine for ~years.
 */
final class Version20260510000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M1.6.1.C — create audit_log table for tracking entity mutations';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<SQL
            CREATE TABLE audit_log (
                id           BIGSERIAL PRIMARY KEY,
                user_id      BIGINT,
                subject_type VARCHAR(50)  NOT NULL,
                subject_id   BIGINT       NOT NULL,
                action       VARCHAR(20)  NOT NULL,
                changes      JSONB        NOT NULL DEFAULT '{}'::jsonb,
                ip_address   INET,
                user_agent   TEXT,
                request_id   VARCHAR(40),
                created_at   TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                CONSTRAINT audit_log_action_check CHECK (action IN (
                    'created',
                    'updated',
                    'deleted',
                    'default'
                ))
            )
            SQL);

        // Forensic query: "what happened to entity X?"
        $this->addSql(<<<SQL
            CREATE INDEX audit_log_subject_idx
                ON audit_log (subject_type, subject_id)
            SQL);

        // Forensic query: "what has user U been doing?", DESC because
        // we almost always want recent-first.
        $this->addSql(<<<SQL
            CREATE INDEX audit_log_user_created_idx
                ON audit_log (user_id, created_at DESC)
            SQL);

        // Forensic query: time-range searches across all subjects.
        // Cheap because BTREE on TIMESTAMPTZ.
        $this->addSql(<<<SQL
            CREATE INDEX audit_log_created_idx
                ON audit_log (created_at DESC)
            SQL);

        // Documentation comment on the table for any DBA looking
        // at schema directly via psql \d+ audit_log
        $this->addSql(<<<SQL
            COMMENT ON TABLE audit_log IS
                'M1.6.1.C — append-only record of mutating actions. Never updated or deleted by application code. Retention managed by platform_settings (M3+).'
            SQL);
    }

    public function down(Schema $schema): void
    {
        // Drop indexes first (Postgres handles via CASCADE on DROP
        // TABLE but explicit is clearer).
        $this->addSql('DROP INDEX IF EXISTS audit_log_created_idx');
        $this->addSql('DROP INDEX IF EXISTS audit_log_user_created_idx');
        $this->addSql('DROP INDEX IF EXISTS audit_log_subject_idx');
        $this->addSql('DROP TABLE IF EXISTS audit_log');
    }
}
