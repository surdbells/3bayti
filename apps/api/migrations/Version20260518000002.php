<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.X.8-A, Create promo_codes + promo_redemptions tables, plus
 * the inverse FK on orders.
 *
 * Background
 * ==========
 * The checkout currently accepts a client-supplied `discount` decimal
 * which the server trusts as-is (see InitiateCheckoutController:88-91).
 * The promo code engine (M3.2.X.8) replaces that with a server-
 * authoritative resolution path: admin defines codes, customers
 * redeem by name, server computes the discount amount.
 *
 * This migration ships three table-level operations:
 *
 *   1. CREATE TABLE promo_codes, admin-managed catalog of codes
 *   2. CREATE TABLE promo_redemptions, one row per successful redemption
 *   3. ALTER TABLE orders ADD COLUMN promo_redemption_id, nullable FK
 *      back into promo_redemptions for serializer reverse-lookup
 *
 * Schema design choices
 * =====================
 *
 * promo_codes.code stored UPPER form + functional unique index
 *   We normalize at the application layer (PromoCode::normalizeCode
 *   trims + upper-cases). The DB-level functional UNIQUE index on
 *   UPPER(code) is defense in depth, direct SQL writes can't bypass
 *   the case-insensitive uniqueness guarantee.
 *
 * CHECK constraints
 *   - discount_type IN ('percentage', 'fixed_amount'), Q-DiscountTypes
 *     = A locked. New types in future phases will ALTER the constraint.
 *   - discount_value >= 0, application asserts the same; DB catches
 *     direct SQL writes.
 *   - usage_limit_global / usage_limit_per_user >= 0, same pattern.
 *
 * NO Postgres ENUM types
 *   Same rationale as notification_logs.status, VARCHAR + CHECK
 *   constraint avoids the ALTER TYPE friction of true Postgres enums
 *   when we add discount types in a future phase.
 *
 * promo_redemptions.order_id UNIQUE
 *   Q-ConflictPolicy = A locked: at most one redemption per order.
 *   The application layer rejects a second different code at quote
 *   time; the UNIQUE constraint is the DB-level guarantee against
 *   any race or direct SQL writes.
 *
 * FK ON DELETE behavior matrix
 * ----------------------------
 *
 * promo_redemptions.promo_code_id ON DELETE RESTRICT
 *   Admin cannot hard-delete a promo code with historical redemptions.
 *   Soft-delete via is_active=false is the supported path. RESTRICT
 *   over CASCADE preserves the historical attribution.
 *
 * promo_redemptions.user_id ON DELETE RESTRICT
 *   User deletion is not a flow we support in v3 anyway; this is
 *   belt-and-braces.
 *
 * promo_redemptions.order_id ON DELETE CASCADE
 *   If an order is hard-deleted (very rare), the redemption goes
 *   with it. Order's inverse (orders.promo_redemption_id) uses
 *   ON DELETE SET NULL on the OTHER side so deleting a redemption
 *   doesn't drop the order's discount line.
 *
 * orders.promo_redemption_id ON DELETE SET NULL
 *   The order's reverse pointer. Deleting a redemption (admin
 *   override) decouples the order without breaking it; the order's
 *   `discount` column already holds the snapshotted amount.
 *
 * No backfill
 * -----------
 * Existing orders have NO promo applied (the client-supplied discount
 * model never wrote into promo_redemptions; it only set orders.discount).
 * Their promo_redemption_id stays NULL, the serializer treats that as
 * "no applied_promo block" in the response. The historical discount
 * attribution is lost, but it was never captured in the first place
 * (the legacy field is just a number with no provenance), so there's
 * nothing to backfill from.
 *
 * Rollback safety
 * ---------------
 * down() drops both new tables (cascades indexes/FKs) and drops the
 * column on orders. Any redemption rows are lost on rollback -
 * acceptable because rollback is exceptional and the legacy raw-
 * discount path on Order::discount is preserved (the column itself
 * is NOT touched; only promo_redemption_id is added/dropped).
 *
 * Indexes
 * -------
 * promo_codes:
 *   - UNIQUE on UPPER(code), case-insensitive lookup
 *   - (is_active, valid_until), admin "list currently-valid codes"
 *
 * promo_redemptions:
 *   - UNIQUE on order_id, one-promo-per-order enforcement
 *   - (promo_code_id), usage_limit_global counting hot path
 *   - (user_id, promo_code_id), usage_limit_per_user counting hot path
 *
 * orders:
 *   - (promo_redemption_id), reverse lookup from order to redemption
 */
final class Version20260518000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.X.8-A — Create promo_codes + promo_redemptions tables for the promo code engine.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // ---------- promo_codes ----------
        $this->addSql(<<<'SQL'
            CREATE TABLE promo_codes (
                id                    BIGSERIAL    PRIMARY KEY,
                code                  VARCHAR(64)  NOT NULL,
                description           TEXT         NULL,
                discount_type         VARCHAR(16)  NOT NULL,
                discount_value        DECIMAL(10,2) NOT NULL,
                currency              VARCHAR(3)   NOT NULL DEFAULT 'AED',
                min_subtotal          DECIMAL(10,2) NULL,
                max_discount_amount   DECIMAL(10,2) NULL,
                usage_limit_global    INTEGER      NULL,
                usage_limit_per_user  INTEGER      NULL,
                valid_from            TIMESTAMPTZ  NULL,
                valid_until           TIMESTAMPTZ  NULL,
                is_active             BOOLEAN      NOT NULL DEFAULT TRUE,
                created_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);

        // Application-layer normalization stores codes in UPPER form,
        // but the functional UNIQUE index makes that contract DB-
        // level: no two rows can share a case-insensitive code.
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uq_promo_codes_code_upper
                ON promo_codes (UPPER(code))
        SQL);

        // CHECK constraints, defense in depth against direct SQL writes.
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_codes
                ADD CONSTRAINT chk_promo_codes_discount_type
                CHECK (discount_type IN ('percentage', 'fixed_amount'))
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_codes
                ADD CONSTRAINT chk_promo_codes_discount_value_nonneg
                CHECK (discount_value >= 0)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_codes
                ADD CONSTRAINT chk_promo_codes_percentage_range
                CHECK (discount_type <> 'percentage' OR (discount_value > 0 AND discount_value <= 100))
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_codes
                ADD CONSTRAINT chk_promo_codes_min_subtotal_nonneg
                CHECK (min_subtotal IS NULL OR min_subtotal >= 0)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_codes
                ADD CONSTRAINT chk_promo_codes_max_discount_nonneg
                CHECK (max_discount_amount IS NULL OR max_discount_amount >= 0)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_codes
                ADD CONSTRAINT chk_promo_codes_usage_global_nonneg
                CHECK (usage_limit_global IS NULL OR usage_limit_global >= 0)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_codes
                ADD CONSTRAINT chk_promo_codes_usage_per_user_nonneg
                CHECK (usage_limit_per_user IS NULL OR usage_limit_per_user >= 0)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_codes
                ADD CONSTRAINT chk_promo_codes_valid_window
                CHECK (valid_from IS NULL OR valid_until IS NULL OR valid_from <= valid_until)
        SQL);

        // Admin "currently-valid" filter hot path.
        $this->addSql('CREATE INDEX idx_promo_codes_is_active_valid_until ON promo_codes (is_active, valid_until)');

        // ---------- promo_redemptions ----------
        $this->addSql(<<<'SQL'
            CREATE TABLE promo_redemptions (
                id                       BIGSERIAL    PRIMARY KEY,
                promo_code_id            BIGINT       NOT NULL REFERENCES promo_codes(id) ON DELETE RESTRICT,
                user_id                  BIGINT       NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                order_id                 BIGINT       NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
                discount_amount          DECIMAL(10,2) NOT NULL,
                code_snapshot            VARCHAR(64)  NOT NULL,
                discount_type_snapshot   VARCHAR(16)  NOT NULL,
                discount_value_snapshot  DECIMAL(10,2) NOT NULL,
                redeemed_at              TIMESTAMPTZ  NOT NULL DEFAULT NOW()
            )
        SQL);

        // One-promo-per-order enforcement (Q-ConflictPolicy = A).
        $this->addSql('CREATE UNIQUE INDEX uq_promo_redemptions_order_id ON promo_redemptions (order_id)');

        // usage_limit_global counting hot path.
        $this->addSql('CREATE INDEX idx_promo_redemptions_promo_code_id ON promo_redemptions (promo_code_id)');

        // usage_limit_per_user counting hot path.
        $this->addSql('CREATE INDEX idx_promo_redemptions_user_promo ON promo_redemptions (user_id, promo_code_id)');

        // CHECK constraints mirror the application-level validation.
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_redemptions
                ADD CONSTRAINT chk_promo_redemptions_discount_amount_nonneg
                CHECK (discount_amount >= 0)
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE promo_redemptions
                ADD CONSTRAINT chk_promo_redemptions_discount_type_snapshot
                CHECK (discount_type_snapshot IN ('percentage', 'fixed_amount'))
        SQL);

        // ---------- orders.promo_redemption_id ----------
        $this->addSql(<<<'SQL'
            ALTER TABLE orders
                ADD COLUMN promo_redemption_id BIGINT NULL
                REFERENCES promo_redemptions(id) ON DELETE SET NULL
        SQL);
        $this->addSql('CREATE INDEX idx_orders_promo_redemption_id ON orders (promo_redemption_id)');
    }

    public function down(Schema $schema): void
    {
        // Drop the order column first (it FKs into promo_redemptions).
        $this->addSql('DROP INDEX IF EXISTS idx_orders_promo_redemption_id');
        $this->addSql('ALTER TABLE orders DROP COLUMN IF EXISTS promo_redemption_id');

        // DROP TABLE cascades indexes + CHECK constraints + FKs.
        $this->addSql('DROP TABLE IF EXISTS promo_redemptions');
        $this->addSql('DROP TABLE IF EXISTS promo_codes');
    }
}
