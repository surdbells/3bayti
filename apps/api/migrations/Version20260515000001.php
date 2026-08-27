<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.1.7-G, order_disputes table for chargeback / dispute persistence.
 *
 * Why this table
 * ==============
 * Noon emits webhook events when a customer files a dispute (e.g.
 * chargeback through their issuing bank). Without persistence, those
 * events disappear into the webhook event log and operators have no
 * structured surface to triage them, they'd have to query raw_payload
 * blobs out of payment_webhook_events to know what's pending.
 *
 * This table captures one row per dispute lifecycle (open → resolved),
 * enriches it with admin resolution notes, and links it back to the
 * order. Admin endpoints under /v3/admin/disputes/* read/mutate these
 * rows; the webhook handler creates them.
 *
 * Idempotency
 * -----------
 * provider_dispute_id is UNIQUE. If Noon re-delivers the same dispute
 * webhook (network retry, our 5xx response, etc.), we look up by this
 * key and either update or no-op. Without the unique constraint, retry
 * storms would create duplicate dispute rows.
 *
 * Status semantics
 * ----------------
 *   open          , newly arrived from webhook; no admin action yet
 *   in_review     , admin opened the dispute, gathering evidence
 *   resolved_won  , we won the dispute (customer's claim rejected)
 *   resolved_lost , we lost the dispute (refund issued by Noon)
 *   withdrawn     , customer withdrew the dispute before resolution
 *
 * order_id nullability
 * --------------------
 * Most disputes link to a known order_id. Edge case: a dispute might
 * arrive referencing a provider_order_ref we don't recognize (data
 * import gap, partial migration state). We persist these orphan
 * disputes with order_id=NULL so they're visible in the admin UI;
 * operators can manually associate them later.
 */
final class Version20260515000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.1.7-G — order_disputes table for chargeback/dispute persistence.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE order_disputes (
                id BIGSERIAL PRIMARY KEY,

                -- Linked order — nullable for orphan disputes that arrive
                -- referencing an unknown provider_order_ref. ON DELETE SET NULL
                -- preserves dispute history even if the order is later removed.
                order_id BIGINT REFERENCES orders(id) ON DELETE SET NULL,

                -- Always populated; the only stable cross-reference to Noon's
                -- order. Even when order_id is NULL we have this.
                provider_order_ref VARCHAR(64) NOT NULL,

                -- Noon's dispute identifier. NULL only for pre-correlation
                -- edge cases. UNIQUE so webhook retries don't create dupes.
                provider_dispute_id VARCHAR(128),

                -- Raw Noon eventType string (e.g. CHARGEBACK_OPENED).
                -- Kept verbatim for forensic mapping back to webhook events.
                event_type VARCHAR(64) NOT NULL,

                -- Current state of the dispute lifecycle (see class docblock).
                status VARCHAR(32) NOT NULL DEFAULT 'open',

                -- Amount under dispute (2dp; may differ from order total
                -- for partial disputes).
                amount DECIMAL(10, 2),
                currency VARCHAR(3),

                -- Customer-reported reason from the disputing bank's notes.
                reason TEXT,

                -- Admin-recorded rationale on resolution.
                resolution_note TEXT,

                -- Forensic trail for who resolved + when. NOT a FK because
                -- admin users may be removed later but the audit must survive.
                resolved_by_user_id BIGINT,
                resolved_at TIMESTAMP WITH TIME ZONE,

                -- Full webhook payload at the time of dispute creation.
                -- Schema-agnostic; lets us reconstruct context if Noon's
                -- payload format evolves.
                raw_event JSONB NOT NULL,

                created_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP WITH TIME ZONE NOT NULL DEFAULT NOW()
            )
        SQL);

        // Listing recent disputes for an order (admin order detail view)
        $this->addSql('CREATE INDEX idx_order_disputes_order_created
                       ON order_disputes (order_id, created_at DESC)');

        // Admin disputes list filtered by status
        $this->addSql('CREATE INDEX idx_order_disputes_status
                       ON order_disputes (status, created_at DESC)');

        // Webhook idempotency lookup
        $this->addSql('CREATE UNIQUE INDEX idx_order_disputes_provider_dispute
                       ON order_disputes (provider_dispute_id)
                       WHERE provider_dispute_id IS NOT NULL');

        // Lookup by provider order ref (for the webhook flow when
        // provider_dispute_id is absent / for backfill scripts)
        $this->addSql('CREATE INDEX idx_order_disputes_provider_order
                       ON order_disputes (provider_order_ref)');

        // Status-only filter restricted to active (non-resolved) disputes -
        // most admin queries are "show me the pending ones"
        $this->addSql("CREATE INDEX idx_order_disputes_active
                       ON order_disputes (created_at DESC)
                       WHERE status IN ('open', 'in_review')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS order_disputes');
    }
}
