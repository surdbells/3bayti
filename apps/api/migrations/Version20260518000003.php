<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.X.18-A, Create order_return_requests + child tables for the
 * customer-initiated returns flow.
 *
 * Background
 * ==========
 * Per the M3.2.X.18 plan, customers can request returns for items in
 * delivered orders. The customer-facing entity is OrderReturnRequest;
 * each request spans one or more OrderItems via OrderReturnRequestItem;
 * photo evidence is uploaded as OrderReturnRequestPhoto via Flysystem;
 * on terminal-refunded transition, the manual refund record is
 * persisted as OrderReturnRefund (off-Noon-API; ops records method +
 * amount + reference for compliance).
 *
 * Multi-vendor architecture (Q-VendorRole):
 *   A single return request can span items from multiple vendors.
 *   The vendor_id on OrderReturnRequestItem is denormalized from the
 *   parent OrderItem so vendor-facing endpoints can filter cheaply
 *   without joining through order_items every time.
 *
 *   Vendors do NOT approve/deny, admin does, based on photo evidence.
 *   Vendors only confirm physical receipt (status delivered_to_vendor).
 *
 * Status state machine on order_return_requests:
 *
 *                 ┌─→ denied (terminal)
 *   pending ──────┤
 *                 └─→ approved → picked_up → delivered_to_vendor → refunded (terminal)
 *                        ↑
 *                   cancelled (terminal, customer-initiated only, before approved)
 *
 * Schema design choices
 * =====================
 *
 * order_return_requests.status stored VARCHAR + CHECK constraint
 *   Matches the rest of the codebase (Order.status, OrderItem.item_status,
 *   PromoCode.discount_type, Vendor.status). Allows adding new states
 *   without ALTER TYPE.
 *
 * Reason stored VARCHAR + CHECK constraint
 *   Constrained list per Q-ReturnReasons = A. Codes:
 *     defective, wrong_item, damaged_in_transit, not_as_described,
 *     changed_mind, size_issue, other.
 *
 * order_return_requests.order_id ON DELETE CASCADE
 *   If an order is hard-deleted (very rare admin op), its returns
 *   should go with it, they're meaningless without the parent.
 *
 * order_return_requests.customer_user_id ON DELETE RESTRICT
 *   Customer deletion isn't a flow we support; belt-and-braces.
 *
 * order_return_requests.decided_by_admin_user_id ON DELETE SET NULL
 *   If the deciding admin's user row is removed, we lose the
 *   attribution but keep the decision history. Matches the audit
 *   trail philosophy.
 *
 * order_return_request_items.return_request_id ON DELETE CASCADE
 *   Children of a return request, go together.
 *
 * order_return_request_items.order_item_id ON DELETE RESTRICT
 *   Don't let an OrderItem be deleted while a return references it.
 *
 * order_return_request_items.vendor_id ON DELETE RESTRICT
 *   Denormalized from OrderItem.vendor_id at create time for fast
 *   vendor-side filtering. RESTRICT prevents orphaning.
 *
 * order_return_request_photos.return_request_id ON DELETE CASCADE
 *   Photos belong to the request lifecycle.
 *
 * order_return_request_photos.storage_path
 *   Flysystem path (relative to whatever adapter's root). No URL -
 *   that's computed at serialize time via a signed-URL or auth-gated
 *   serve endpoint. Decouples storage layer from API contract.
 *
 * order_return_refunds.return_request_id UNIQUE
 *   One refund per return request. The refund is the terminal record
 *   of the manual money-out event; if a return needs a correction,
 *   the operational path is a new return or admin override, not
 *   mutating this row.
 *
 * order_return_refunds.method stored VARCHAR + CHECK constraint
 *   Constrained list per Q-Refund: bank_transfer, store_credit,
 *   cash, other.
 *
 * Indexes
 * -------
 * order_return_requests:
 *   - (customer_user_id, status), customer's "my returns" filter
 *   - (status, requested_at DESC), admin "pending review" list
 *   - (order_id), order detail → returns reverse lookup
 *
 * order_return_request_items:
 *   - (vendor_id, return_request_id), vendor portal queries
 *   - (return_request_id), child-of-parent listing
 *   - (order_item_id), anti-overlap eligibility check
 *
 * order_return_request_photos:
 *   - (return_request_id), list photos for a request
 *
 * order_return_refunds:
 *   - UNIQUE (return_request_id), one-refund-per-request
 *
 * Postgres-only
 * -------------
 * Uses TIMESTAMPTZ + BIGSERIAL + DEFAULT NOW(). Same convention as
 * every other migration in the v3 codebase.
 */
final class Version20260518000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.X.18-A — Create order_return_requests + child tables for the customer-initiated returns flow.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // ---------- order_return_requests ----------
        $this->addSql(<<<'SQL'
            CREATE TABLE order_return_requests (
                id                       BIGSERIAL    PRIMARY KEY,
                order_id                 BIGINT       NOT NULL
                                            REFERENCES orders(id) ON DELETE CASCADE,
                customer_user_id         BIGINT       NOT NULL
                                            REFERENCES users(id) ON DELETE RESTRICT,
                status                   VARCHAR(32)  NOT NULL DEFAULT 'pending',
                reason                   VARCHAR(32)  NOT NULL,
                customer_notes           TEXT         NULL,
                admin_notes              TEXT         NULL,
                requested_at             TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                decided_at               TIMESTAMPTZ  NULL,
                decided_by_admin_user_id BIGINT       NULL
                                            REFERENCES users(id) ON DELETE SET NULL,
                picked_up_at             TIMESTAMPTZ  NULL,
                delivered_to_vendor_at   TIMESTAMPTZ  NULL,
                refunded_at              TIMESTAMPTZ  NULL,
                cancelled_at             TIMESTAMPTZ  NULL,
                created_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at               TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE order_return_requests
                ADD CONSTRAINT chk_order_return_requests_status
                CHECK (status IN (
                    'pending', 'approved', 'picked_up',
                    'delivered_to_vendor', 'refunded',
                    'denied', 'cancelled'
                ))
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE order_return_requests
                ADD CONSTRAINT chk_order_return_requests_reason
                CHECK (reason IN (
                    'defective', 'wrong_item', 'damaged_in_transit',
                    'not_as_described', 'changed_mind', 'size_issue', 'other'
                ))
        SQL);

        $this->addSql('CREATE INDEX idx_orr_customer_status ON order_return_requests (customer_user_id, status)');
        $this->addSql('CREATE INDEX idx_orr_status_requested ON order_return_requests (status, requested_at DESC)');
        $this->addSql('CREATE INDEX idx_orr_order ON order_return_requests (order_id)');

        // ---------- order_return_request_items ----------
        $this->addSql(<<<'SQL'
            CREATE TABLE order_return_request_items (
                id                  BIGSERIAL    PRIMARY KEY,
                return_request_id   BIGINT       NOT NULL
                                       REFERENCES order_return_requests(id) ON DELETE CASCADE,
                order_item_id       BIGINT       NOT NULL
                                       REFERENCES order_items(id) ON DELETE RESTRICT,
                vendor_id           BIGINT       NOT NULL
                                       REFERENCES vendors(id) ON DELETE RESTRICT,
                quantity            INTEGER      NOT NULL,
                unit_price_snapshot DECIMAL(10,2) NOT NULL,
                line_subtotal       DECIMAL(10,2) NOT NULL,
                created_at          TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE order_return_request_items
                ADD CONSTRAINT chk_orri_quantity_positive
                CHECK (quantity > 0)
        SQL);

        $this->addSql('CREATE INDEX idx_orri_vendor_request ON order_return_request_items (vendor_id, return_request_id)');
        $this->addSql('CREATE INDEX idx_orri_request ON order_return_request_items (return_request_id)');
        $this->addSql('CREATE INDEX idx_orri_order_item ON order_return_request_items (order_item_id)');

        // ---------- order_return_request_photos ----------
        $this->addSql(<<<'SQL'
            CREATE TABLE order_return_request_photos (
                id                BIGSERIAL    PRIMARY KEY,
                return_request_id BIGINT       NOT NULL
                                     REFERENCES order_return_requests(id) ON DELETE CASCADE,
                storage_path      VARCHAR(512) NOT NULL,
                mime_type         VARCHAR(64)  NOT NULL,
                size_bytes        INTEGER      NOT NULL,
                original_filename VARCHAR(255) NULL,
                uploaded_at       TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE order_return_request_photos
                ADD CONSTRAINT chk_orrp_size_positive
                CHECK (size_bytes > 0)
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE order_return_request_photos
                ADD CONSTRAINT chk_orrp_mime_type
                CHECK (mime_type IN ('image/jpeg', 'image/png', 'image/webp'))
        SQL);

        $this->addSql('CREATE INDEX idx_orrp_request ON order_return_request_photos (return_request_id)');

        // ---------- order_return_refunds ----------
        // One-refund-per-request via UNIQUE (return_request_id).
        // Records the manual off-Noon-API refund event.
        $this->addSql(<<<'SQL'
            CREATE TABLE order_return_refunds (
                id                       BIGSERIAL    PRIMARY KEY,
                return_request_id        BIGINT       NOT NULL UNIQUE
                                            REFERENCES order_return_requests(id) ON DELETE CASCADE,
                method                   VARCHAR(32)  NOT NULL,
                amount                   DECIMAL(10,2) NOT NULL,
                currency                 VARCHAR(3)   NOT NULL DEFAULT 'AED',
                reference                VARCHAR(128) NULL,
                notes                    TEXT         NULL,
                recorded_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                recorded_by_admin_user_id BIGINT      NULL
                                            REFERENCES users(id) ON DELETE SET NULL
            )
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE order_return_refunds
                ADD CONSTRAINT chk_orrf_method
                CHECK (method IN ('bank_transfer', 'store_credit', 'cash', 'other'))
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE order_return_refunds
                ADD CONSTRAINT chk_orrf_amount_positive
                CHECK (amount > 0)
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Drop in reverse-FK order so child tables go before parent.
        $this->addSql('DROP TABLE IF EXISTS order_return_refunds');
        $this->addSql('DROP TABLE IF EXISTS order_return_request_photos');
        $this->addSql('DROP TABLE IF EXISTS order_return_request_items');
        $this->addSql('DROP TABLE IF EXISTS order_return_requests');
    }
}
