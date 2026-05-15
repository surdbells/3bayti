<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.1.6a — schema for cart + customer orders + checkout (Noon Hosted Checkout).
 *
 * Creates the foundation for Stream B of the M3.1 migration:
 *
 *   1. carts          — server-side cart per user (with legacy_cart_code compat)
 *   2. cart_items     — line items with snapshotted price + variant attributes
 *   3. orders         — finalized orders (after successful payment)
 *   4. order_items    — order line items (cart items snapshotted at checkout)
 *   5. order_addresses — billing + shipping address per order (1:N via type enum)
 *   6. payment_transactions — Noon transactions (one or more per order — INITIATE, SALE, REFUND etc.)
 *   7. payment_webhook_events — append-only Noon webhook audit log
 *
 * Why these seven together in one migration
 * ==========================================
 * Single logical unit: M3.1.6's data foundation. Cart can't exist without
 * cart_items (FK); orders can't exist without order_items (FK); payment
 * tables can't exist without orders (FK). Splitting would force multiple
 * forward/reverse migrations for one logical phase change.
 *
 * Cart persistence model (per Q7=B locked decision)
 * ==================================================
 *   - Authenticated user: server-side cart belongs to user_id (FK NOT NULL
 *     for v3-native carts; NULL allowed only for legacy-migrated rows
 *     where the legacy cart_code didn't tie to a user).
 *   - Guest user: device-local cart in mobile; merged to server cart via
 *     POST /v3/cart/merge on sign-in (M3.1.6d). No anonymous-session
 *     cart token in v3 — device-local handles guest case.
 *   - One active cart per user (UNIQUE INDEX where status='active').
 *     Cart status transitions: 'active' → 'converted' (became an order)
 *     → archived. Stale-cart cleanup is a future cron job.
 *
 * Why legacy_cart_code is preserved
 * ==================================
 * Legacy mobile sends `cart_code: "PND"` in addToCart bodies. The legacy
 * code likely used this as a transaction grouping key. v3 doesn't need
 * it logically (cart_id replaces it), but we preserve it for migration
 * idempotency + compatibility shim if legacy carts need to be queried
 * by their original code.
 *
 * Order schema rationale
 * ======================
 *   - legacy_order_id BIGINT UNIQUE NULLABLE — same compat pattern as
 *     legacy_product_id / legacy_vendor_id / legacy_label_id / legacy_style_id
 *     established across M2-M3.1.5.5. Allows by-legacy-id endpoints
 *     during the strangler-fig window.
 *
 *   - order_reference VARCHAR(32) UNIQUE — the v3-internal canonical
 *     identifier, also used as Noon's `merchant_reference`. Format:
 *     "3BAY-{yyyymmdd}-{6char_random}" e.g. "3BAY-20260514-A7B3C2".
 *     Application-generated (not DB-sequence) so it's predictable +
 *     URL-safe + collision-resistant via random suffix.
 *
 *     NOTE: Noon's docs say "Please contact our support team to enable
 *     the uniqueness of the merchant order reference field" — by default
 *     Noon does NOT enforce uniqueness on the reference. Action item
 *     for the operator: email Noon support to enable it. Meanwhile,
 *     our UNIQUE constraint here enforces it at v3 level regardless.
 *
 *   - status VARCHAR(32) — string enum (not smallint) because the
 *     state machine has many states and humans inspect orders in psql
 *     frequently; readable values beat compact encoding. Constrained
 *     by CHECK (...). States locked:
 *       'pending_payment' — Noon INITIATE called, awaiting webview return
 *       'paid'            — Noon webhook confirmed + GET ORDER verified
 *       'fulfilling'      — at least one vendor accepted; some items in motion
 *                           (added in M3.1.7 vendor flows; declared now for
 *                           the CHECK constraint to be complete)
 *       'shipped'         — all items shipped
 *       'delivered'       — all items delivered
 *       'cancelled'       — order cancelled before fulfillment
 *       'refunded'        — full or partial refund processed
 *       'failed'          — Noon webhook returned failure / order expired
 *
 *   - subtotal + delivery_fee + discount + total: all DECIMAL(10,2) AED.
 *     We do NOT auto-recompute total from subtotal + delivery_fee - discount
 *     at the DB level (CHECK constraint or generated column) — small rounding
 *     differences in vendor settlement could cause spurious failures. Total
 *     is the source of truth, captured at checkout time.
 *
 *   - paid_at TIMESTAMPTZ NULL — set when status transitions to 'paid'.
 *     Distinct from updated_at (which bumps on every state change).
 *
 * order_addresses schema rationale
 * =================================
 * 1:N from orders via `type` enum ('billing', 'shipping'). At most 2 rows
 * per order in M3.1.6 (one billing, one shipping). UNIQUE (order_id, type)
 * enforces that.
 *
 * Why not denormalize onto orders table directly?
 *   - Future support for split shipments (multiple shipping addresses for
 *     one order — different items shipping to different addresses) is
 *     a natural extension if we keep order_addresses as a join.
 *   - Address snapshot at checkout (not FK to a user_addresses table)
 *     because user address book can change after the order; the order
 *     freezes what the customer chose at that moment.
 *
 * payment_transactions schema rationale
 * ======================================
 * Records every API interaction with the payment provider (Noon) for an
 * order. Multiple rows per order possible: INITIATE creates row 1, SALE
 * creates row 2 (or extends row 1 — TBD by Noon's flow), REFUND creates
 * row 3, etc.
 *
 *   - provider VARCHAR(32) — 'noon' for now; pluggable per C11 (Stripe,
 *     Tap, etc. would be additional values).
 *   - operation VARCHAR(32) — Noon API operations: 'INITIATE', 'SALE',
 *     'AUTHORIZE', 'CAPTURE', 'REVERSE', 'REFUND', 'CANCEL',
 *     'GET_ORDER', 'GET_ORDER_BY_REFERENCE'.
 *     Match Noon's apiOperation values directly.
 *   - provider_order_ref VARCHAR(64) — Noon's `orderId` (their internal
 *     12-digit number; can be 16-digit for KSA/EGY local endpoints).
 *     INDEXED for webhook → transaction lookup.
 *   - status VARCHAR(32) — Noon's order status string ('STARTED',
 *     'PENDING', 'AUTHORIZED', 'CAPTURED', 'FAILED', 'CANCELLED',
 *     'REVERSED', 'EXPIRED'). Distinct from orders.status which is
 *     v3's higher-level state.
 *   - amount + currency — what this specific transaction was for
 *     (full sale amount for SALE, refund amount for REFUND, etc.).
 *   - noon_result_code INTEGER — Noon's resultCode field (0 = success;
 *     19012 = duplicate reference; etc.). Stored as the integer Noon
 *     returns, so we can grep / analyze later.
 *   - request_payload + response_payload JSONB — exact request + response
 *     for audit / debugging. Privacy: card details are NOT in these
 *     (Noon's hosted checkout keeps card data on Noon's side; our
 *     request payload doesn't carry it; their response is the order
 *     confirmation, also card-free).
 *   - idempotency_key VARCHAR(128) UNIQUE — for v3-side dedup of
 *     duplicate INITIATE attempts. Format = order_reference + ':' +
 *     operation. Same order + same operation = same key = no duplicate
 *     transaction record.
 *
 * payment_webhook_events schema rationale
 * ========================================
 * Append-only audit log of every webhook Noon sends us. Critical for:
 *   1. Idempotent processing (same webhook can arrive multiple times)
 *   2. Forensics if a payment dispute later requires evidence
 *   3. Debugging the "signature unknown" gap M3.1.6 ships with
 *
 *   - idempotency_key VARCHAR(128) UNIQUE — Noon's eventId if present,
 *     otherwise hash(payload) — controller computes one or the other.
 *     UNIQUE means re-processing the same webhook is a no-op INSERT
 *     conflict, NOT a duplicate state transition.
 *   - signature_header TEXT — the raw signature header value Noon sent.
 *     Stored even when verification is logging-only (M3.1.6) so M3.1.7's
 *     empirical verification work has historical data to test against.
 *   - signature_verified BOOLEAN — false during M3.1.6 logging-only
 *     phase (every row); true/false based on actual verification once
 *     M3.1.7 binds HmacSha256SignatureVerifier.
 *   - payload JSONB — exact bytes Noon sent. Used by the retrieve-order
 *     safety net: even if the payload claims "paid", we still call
 *     Noon GET ORDER before transitioning state.
 *   - processed_at TIMESTAMPTZ — when our handler finished. NULL if
 *     processing failed (allows retry logic to find unprocessed events).
 *   - order_id BIGINT NULL — populated when we successfully match the
 *     webhook to a v3 order. NULL = orphan webhook (no matching order
 *     reference; logged as warning but not failure).
 *
 * Idempotency
 * ===========
 * Forward-only safe. No data backfill from legacy in this commit
 * (M3.1.6h handles that as a separate step).
 *
 * Reversibility
 * =============
 * down() drops everything in reverse FK order: payment_webhook_events,
 * payment_transactions, order_addresses, order_items, orders,
 * cart_items, carts. All operations IF EXISTS.
 */
final class Version20260514000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.1.6a — carts + cart_items + orders + order_items + order_addresses '
            . '+ payment_transactions + payment_webhook_events schema';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // -----------------------------------------------------------------
        // 1) carts
        // -----------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE carts (
                id BIGSERIAL PRIMARY KEY,
                user_id BIGINT REFERENCES users(id) ON DELETE CASCADE,

                legacy_cart_code VARCHAR(32),

                status VARCHAR(32) NOT NULL DEFAULT 'active',
                currency CHAR(3) NOT NULL DEFAULT 'AED',

                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT chk_carts_status CHECK (status IN ('active', 'converted', 'archived'))
            )
        SQL);

        // One active cart per user (partial unique index).
        // user_id NULL = legacy-migrated cart with no v3 user mapping;
        // these are allowed and not subject to the uniqueness rule.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_carts_user_active
            ON carts (user_id)
            WHERE status = 'active' AND user_id IS NOT NULL
        SQL);

        $this->addSql('CREATE INDEX idx_carts_legacy_code ON carts (legacy_cart_code) WHERE legacy_cart_code IS NOT NULL');

        // -----------------------------------------------------------------
        // 2) cart_items
        // -----------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE cart_items (
                id BIGSERIAL PRIMARY KEY,
                cart_id BIGINT NOT NULL REFERENCES carts(id) ON DELETE CASCADE,
                product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE RESTRICT,

                quantity SMALLINT NOT NULL CHECK (quantity >= 1),
                unit_price_snapshot DECIMAL(10, 2) NOT NULL,

                size VARCHAR(50),
                color VARCHAR(50),
                is_custom BOOLEAN NOT NULL DEFAULT FALSE,
                measurement TEXT,
                extra_measurement TEXT,
                note TEXT,

                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL
            )
        SQL);

        $this->addSql('CREATE INDEX idx_cart_items_cart ON cart_items (cart_id)');
        $this->addSql('CREATE INDEX idx_cart_items_product ON cart_items (product_id)');

        // -----------------------------------------------------------------
        // 3) orders
        // -----------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE orders (
                id BIGSERIAL PRIMARY KEY,
                legacy_order_id BIGINT UNIQUE,
                user_id BIGINT NOT NULL REFERENCES users(id) ON DELETE RESTRICT,

                order_reference VARCHAR(32) NOT NULL UNIQUE,

                status VARCHAR(32) NOT NULL DEFAULT 'pending_payment',

                subtotal DECIMAL(10, 2) NOT NULL,
                delivery_fee DECIMAL(10, 2) NOT NULL DEFAULT 0,
                discount DECIMAL(10, 2) NOT NULL DEFAULT 0,
                total DECIMAL(10, 2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'AED',

                paid_at TIMESTAMPTZ,

                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT chk_orders_status CHECK (
                    status IN (
                        'pending_payment', 'paid', 'fulfilling', 'shipped',
                        'delivered', 'cancelled', 'refunded', 'failed'
                    )
                ),
                CONSTRAINT chk_orders_amounts_nonneg CHECK (
                    subtotal >= 0 AND delivery_fee >= 0 AND discount >= 0 AND total >= 0
                )
            )
        SQL);

        $this->addSql('CREATE INDEX idx_orders_user_created ON orders (user_id, created_at DESC)');
        $this->addSql('CREATE INDEX idx_orders_status ON orders (status)');
        $this->addSql('CREATE INDEX idx_orders_legacy ON orders (legacy_order_id) WHERE legacy_order_id IS NOT NULL');

        // -----------------------------------------------------------------
        // 4) order_items
        // -----------------------------------------------------------------
        //
        // Snapshotted from cart_items at checkout. vendor_id is FROZEN
        // here even though products.vendor_id can technically be updated
        // — once an order is placed, the vendor relationship for THAT
        // line item is set in stone for fulfillment + commission.
        $this->addSql(<<<'SQL'
            CREATE TABLE order_items (
                id BIGSERIAL PRIMARY KEY,
                order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE RESTRICT,
                vendor_id BIGINT NOT NULL REFERENCES vendors(id) ON DELETE RESTRICT,

                quantity SMALLINT NOT NULL CHECK (quantity >= 1),
                unit_price DECIMAL(10, 2) NOT NULL,
                subtotal DECIMAL(10, 2) NOT NULL,

                product_name_snapshot VARCHAR(255) NOT NULL,
                product_image_snapshot VARCHAR(500),

                size VARCHAR(50),
                color VARCHAR(50),
                is_custom BOOLEAN NOT NULL DEFAULT FALSE,
                measurement TEXT,
                extra_measurement TEXT,
                note TEXT,

                item_status VARCHAR(32) NOT NULL DEFAULT 'pending',

                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT chk_order_items_status CHECK (
                    item_status IN (
                        'pending', 'accepted', 'rejected', 'preparing',
                        'shipped', 'delivered', 'cancelled', 'returned', 'refunded'
                    )
                )
            )
        SQL);

        $this->addSql('CREATE INDEX idx_order_items_order ON order_items (order_id)');
        $this->addSql('CREATE INDEX idx_order_items_vendor ON order_items (vendor_id, item_status)');
        $this->addSql('CREATE INDEX idx_order_items_product ON order_items (product_id)');

        // -----------------------------------------------------------------
        // 5) order_addresses
        // -----------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE order_addresses (
                id BIGSERIAL PRIMARY KEY,
                order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE CASCADE,

                type VARCHAR(16) NOT NULL,

                first_name VARCHAR(100) NOT NULL,
                last_name VARCHAR(100),
                phone VARCHAR(20) NOT NULL,
                email VARCHAR(255) NOT NULL,

                street VARCHAR(255) NOT NULL,
                city VARCHAR(100) NOT NULL,
                state_province VARCHAR(100),
                country_code CHAR(2) NOT NULL DEFAULT 'AE',
                postal_code VARCHAR(20),

                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT chk_order_addresses_type CHECK (type IN ('billing', 'shipping')),
                CONSTRAINT uq_order_addresses_order_type UNIQUE (order_id, type)
            )
        SQL);

        // -----------------------------------------------------------------
        // 6) payment_transactions
        // -----------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_transactions (
                id BIGSERIAL PRIMARY KEY,
                order_id BIGINT NOT NULL REFERENCES orders(id) ON DELETE RESTRICT,

                provider VARCHAR(32) NOT NULL DEFAULT 'noon',
                operation VARCHAR(32) NOT NULL,

                provider_order_ref VARCHAR(64),
                status VARCHAR(32) NOT NULL,

                amount DECIMAL(10, 2) NOT NULL,
                currency CHAR(3) NOT NULL DEFAULT 'AED',

                noon_result_code INTEGER,

                request_payload JSONB,
                response_payload JSONB,

                idempotency_key VARCHAR(128) NOT NULL UNIQUE,

                created_at TIMESTAMPTZ NOT NULL,
                updated_at TIMESTAMPTZ NOT NULL,

                CONSTRAINT chk_payment_tx_operation CHECK (
                    operation IN (
                        'INITIATE', 'SALE', 'AUTHORIZE', 'CAPTURE',
                        'REVERSE', 'REFUND', 'CANCEL',
                        'GET_ORDER', 'GET_ORDER_BY_REFERENCE'
                    )
                ),
                CONSTRAINT chk_payment_tx_amount_nonneg CHECK (amount >= 0)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_payment_tx_order ON payment_transactions (order_id)');
        $this->addSql('CREATE INDEX idx_payment_tx_provider_ref ON payment_transactions (provider_order_ref) WHERE provider_order_ref IS NOT NULL');

        // -----------------------------------------------------------------
        // 7) payment_webhook_events
        // -----------------------------------------------------------------
        $this->addSql(<<<'SQL'
            CREATE TABLE payment_webhook_events (
                id BIGSERIAL PRIMARY KEY,

                provider VARCHAR(32) NOT NULL DEFAULT 'noon',
                idempotency_key VARCHAR(128) NOT NULL UNIQUE,

                provider_order_ref VARCHAR(64),
                event_type VARCHAR(64),

                signature_header TEXT,
                signature_verified BOOLEAN NOT NULL DEFAULT FALSE,

                payload JSONB NOT NULL,

                order_id BIGINT REFERENCES orders(id) ON DELETE SET NULL,

                received_at TIMESTAMPTZ NOT NULL,
                processed_at TIMESTAMPTZ
            )
        SQL);

        $this->addSql('CREATE INDEX idx_payment_webhook_order ON payment_webhook_events (order_id) WHERE order_id IS NOT NULL');
        // Correlation lookup: "find the webhook event(s) that updated this Noon order ref".
        // Used by reconciliation + the dead-letter retry cron in M3.1.7.
        $this->addSql('CREATE INDEX idx_payment_webhook_provider_ref ON payment_webhook_events (provider_order_ref) WHERE provider_order_ref IS NOT NULL');
        // Unprocessed-events lookup: for retry / dead-letter cron in M3.1.7.
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_payment_webhook_unprocessed
            ON payment_webhook_events (received_at)
            WHERE processed_at IS NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        // Drop in reverse FK dependency order.
        $this->addSql('DROP TABLE IF EXISTS payment_webhook_events');
        $this->addSql('DROP TABLE IF EXISTS payment_transactions');
        $this->addSql('DROP TABLE IF EXISTS order_addresses');
        $this->addSql('DROP TABLE IF EXISTS order_items');
        $this->addSql('DROP TABLE IF EXISTS orders');
        $this->addSql('DROP TABLE IF EXISTS cart_items');
        $this->addSql('DROP TABLE IF EXISTS carts');
    }
}
