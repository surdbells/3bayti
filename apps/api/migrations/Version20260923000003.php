<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Marketplace P5 — Bespoke Customization: create customization_requests, a
 * customer→vendor request→quote→accept→pay→complete workflow scoped to a
 * single product. Mirrors the order_return_requests DDL conventions
 * (VARCHAR status + CHECK constraint, TIMESTAMPTZ, DECIMAL(10,2) money,
 * denormalized vendor_id, explicit indexes).
 */
final class Version20260923000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'P5 — Create customization_requests for the bespoke customization workflow.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE customization_requests (
                id                      BIGSERIAL     PRIMARY KEY,
                product_id              BIGINT        NOT NULL
                                            REFERENCES products(id) ON DELETE RESTRICT,
                vendor_id               BIGINT        NOT NULL
                                            REFERENCES vendors(id) ON DELETE RESTRICT,
                customer_user_id        BIGINT        NOT NULL
                                            REFERENCES users(id) ON DELETE RESTRICT,
                status                  VARCHAR(32)   NOT NULL DEFAULT 'pending',
                customer_notes          TEXT          NOT NULL,
                measurement_snapshot    JSONB         NULL,
                quote_amount            DECIMAL(10,2) NULL,
                quote_currency          VARCHAR(3)    NULL,
                quote_lead_time_days    INTEGER       NULL,
                vendor_notes            TEXT          NULL,
                payment_order_reference VARCHAR(64)   NULL,
                requested_at            TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                quoted_at               TIMESTAMPTZ   NULL,
                accepted_at             TIMESTAMPTZ   NULL,
                paid_at                 TIMESTAMPTZ   NULL,
                completed_at            TIMESTAMPTZ   NULL,
                declined_at             TIMESTAMPTZ   NULL,
                rejected_at             TIMESTAMPTZ   NULL,
                cancelled_at            TIMESTAMPTZ   NULL,
                created_at              TIMESTAMPTZ   NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMPTZ   NOT NULL DEFAULT NOW()
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE customization_requests
                ADD CONSTRAINT chk_customization_requests_status
                CHECK (status IN (
                    'pending', 'quoted', 'accepted', 'paid', 'completed',
                    'declined', 'rejected', 'cancelled'
                ))
        SQL);

        // Vendor "incoming requests" queue (their products, by status, newest first).
        $this->addSql('CREATE INDEX idx_cr_vendor_status ON customization_requests (vendor_id, status)');
        // Customer "my requests" list.
        $this->addSql('CREATE INDEX idx_cr_customer_status ON customization_requests (customer_user_id, status)');
        // PDP "do I already have one" checks.
        $this->addSql('CREATE INDEX idx_cr_product ON customization_requests (product_id)');
        // Dup guard — ENFORCE one in-flight (non-terminal) request per
        // (product, customer) at the DB, so the controller's COUNT-then-insert
        // check can't be raced past. The controller catches the resulting
        // unique violation and returns the same 409.
        $this->addSql(
            'CREATE UNIQUE INDEX uq_cr_active_per_product_customer '
            . 'ON customization_requests (product_id, customer_user_id) '
            . "WHERE status IN ('pending', 'quoted', 'accepted', 'paid')"
        );
        // Webhook back-reference lookup (unique, partial — most rows are NULL).
        $this->addSql(
            'CREATE UNIQUE INDEX idx_cr_payment_order_ref ON customization_requests (payment_order_reference) '
            . 'WHERE payment_order_reference IS NOT NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS customization_requests');
    }
}
