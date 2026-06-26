<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Allow the 'viewed' + 'overridden' audit_log actions.
 *
 * The original audit_log_action_check (Version20260510000001) only
 * permitted action IN ('created','updated','deleted','default'), but
 * AuditEmitter::recordView() writes AuditLog::ACTION_VIEWED ('viewed')
 * and recordOverride() writes ACTION_OVERRIDDEN ('overridden'). Every
 * audited admin GET (e.g. GET /v3/admin/orders/{id}, which calls
 * recordView with context 'admin_order_detail') therefore threw
 * SQLSTATE 23514 at flush time — surfacing as a 500 on the admin order
 * screen (Sentry PHP-H, 61 occurrences). Widen the CHECK to the full
 * set of AuditLog::ACTION_* constants so audited reads/overrides persist.
 *
 * Fixes PHP-H.
 */
final class Version20260626000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Widen audit_log_action_check to allow 'viewed' + 'overridden' (recordView/recordOverride wrote actions the original CHECK rejected → 23514).";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT audit_log_action_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE audit_log ADD CONSTRAINT audit_log_action_check CHECK (action IN (
                'created',
                'updated',
                'deleted',
                'default',
                'viewed',
                'overridden'
            ))
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Reverting restores the original 4-value CHECK. This will FAIL
        // if any 'viewed'/'overridden' rows already exist — delete those
        // rows first if a rollback is genuinely required.
        $this->addSql('ALTER TABLE audit_log DROP CONSTRAINT audit_log_action_check');
        $this->addSql(<<<'SQL'
            ALTER TABLE audit_log ADD CONSTRAINT audit_log_action_check CHECK (action IN (
                'created',
                'updated',
                'deleted',
                'default'
            ))
        SQL);
    }
}
