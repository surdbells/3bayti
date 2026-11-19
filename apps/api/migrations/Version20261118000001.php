<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Bayti\Api\Domain\Authz\PermissionCatalog;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cashback programme (paid out as gift cards).
 *
 *   - cashback_rules / cashback_rule_targets: admin-defined promotions and
 *     their scope (vendor / product / category v3 ids, with exclusions).
 *   - cashback_awards: ONE row per order (UNIQUE order_id makes accrual
 *     idempotent), pending → issued | cancelled → reversed; gift_card_id is
 *     UNIQUE (one card per award).
 *   - cashback_award_lines: per-rule, per-order-item contributions (budget,
 *     per-customer limit and per-rule reporting).
 *   - gift_cards.source (purchase | admin | cashback, backfilled) and
 *     gift_cards.cashback_award_id.
 *   - orders.used_cashback_credit: set at checkout when a cashback card is
 *     debited (cheap "no cashback on a cashbacked sale" guard).
 *   - cashback.* permissions + super_admin grant.
 */
final class Version20261118000001 extends AbstractMigration
{
    private const PERMISSIONS = [
        'cashback.view',
        'cashback.create',
        'cashback.edit',
        'cashback.delete',
        'cashback.manage',
    ];

    public function getDescription(): string
    {
        return 'Cashback programme: rules, targets, awards, award lines, gift_cards.source, orders.used_cashback_credit, cashback.* permissions.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE cashback_rules (
                id BIGSERIAL NOT NULL,
                name VARCHAR(120) NOT NULL,
                description TEXT DEFAULT NULL,
                is_active BOOLEAN NOT NULL DEFAULT FALSE,
                priority INT NOT NULL DEFAULT 0,
                reward_type VARCHAR(16) NOT NULL,
                reward_value NUMERIC(10, 2) NOT NULL,
                max_reward_per_order NUMERIC(10, 2) DEFAULT NULL,
                min_eligible_amount NUMERIC(10, 2) DEFAULT NULL,
                valid_from TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                valid_until TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL,
                scope VARCHAR(16) NOT NULL DEFAULT 'all',
                channel VARCHAR(16) NOT NULL DEFAULT 'all',
                first_order_only BOOLEAN NOT NULL DEFAULT FALSE,
                per_customer_limit INT DEFAULT NULL,
                budget_total NUMERIC(12, 2) DEFAULT NULL,
                allow_with_promo BOOLEAN NOT NULL DEFAULT FALSE,
                hold_days INT NOT NULL DEFAULT 14,
                gift_card_validity_days INT NOT NULL DEFAULT 365,
                created_by BIGINT DEFAULT NULL,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT now(),
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (id),
                CONSTRAINT chk_cashback_rules_reward_type CHECK (reward_type IN ('percentage', 'fixed')),
                CONSTRAINT chk_cashback_rules_scope CHECK (scope IN ('all', 'stores', 'products', 'categories')),
                CONSTRAINT chk_cashback_rules_channel CHECK (channel IN ('all', 'web', 'mobile')),
                CONSTRAINT chk_cashback_rules_reward_value CHECK (reward_value > 0)
            )
            SQL);
        $this->addSql('CREATE INDEX idx_cashback_rules_active ON cashback_rules (is_active, priority)');

        $this->addSql(<<<'SQL'
            CREATE TABLE cashback_rule_targets (
                id BIGSERIAL NOT NULL,
                rule_id BIGINT NOT NULL,
                target_type VARCHAR(16) NOT NULL,
                target_id BIGINT NOT NULL,
                is_exclusion BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY (id),
                CONSTRAINT fk_cashback_rule_targets_rule FOREIGN KEY (rule_id)
                    REFERENCES cashback_rules (id) ON DELETE CASCADE,
                CONSTRAINT chk_cashback_rule_targets_type CHECK (target_type IN ('vendor', 'product', 'category'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_cashback_rule_targets ON cashback_rule_targets (rule_id, target_type, target_id, is_exclusion)');
        $this->addSql('CREATE INDEX idx_cashback_rule_targets_target ON cashback_rule_targets (target_type, target_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE cashback_awards (
                id BIGSERIAL NOT NULL,
                order_id BIGINT NOT NULL,
                user_id BIGINT NOT NULL,
                amount NUMERIC(10, 2) NOT NULL,
                accrued_amount NUMERIC(10, 2) NOT NULL,
                reversed_amount NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
                currency VARCHAR(3) NOT NULL DEFAULT 'AED',
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                hold_days INT NOT NULL DEFAULT 14,
                gift_card_validity_days INT NOT NULL DEFAULT 365,
                release_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                gift_card_id BIGINT DEFAULT NULL,
                status_reason VARCHAR(255) DEFAULT NULL,
                snapshot JSONB NOT NULL DEFAULT '{}'::jsonb,
                cancelled_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                reversed_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL DEFAULT now(),
                PRIMARY KEY (id),
                CONSTRAINT fk_cashback_awards_order FOREIGN KEY (order_id)
                    REFERENCES orders (id) ON DELETE CASCADE,
                CONSTRAINT fk_cashback_awards_user FOREIGN KEY (user_id)
                    REFERENCES users (id) ON DELETE CASCADE,
                CONSTRAINT fk_cashback_awards_gift_card FOREIGN KEY (gift_card_id)
                    REFERENCES gift_cards (id) ON DELETE SET NULL,
                CONSTRAINT chk_cashback_awards_status CHECK (status IN ('pending', 'issued', 'cancelled', 'reversed'))
            )
            SQL);
        $this->addSql('CREATE UNIQUE INDEX uq_cashback_awards_order ON cashback_awards (order_id)');
        $this->addSql('CREATE UNIQUE INDEX uq_cashback_awards_gift_card ON cashback_awards (gift_card_id)');
        $this->addSql('CREATE INDEX idx_cashback_awards_status ON cashback_awards (status)');
        $this->addSql('CREATE INDEX idx_cashback_awards_user ON cashback_awards (user_id)');

        $this->addSql(<<<'SQL'
            CREATE TABLE cashback_award_lines (
                id BIGSERIAL NOT NULL,
                award_id BIGINT NOT NULL,
                rule_id BIGINT NOT NULL,
                order_item_id BIGINT DEFAULT NULL,
                base_amount NUMERIC(10, 2) NOT NULL,
                amount NUMERIC(10, 2) NOT NULL,
                PRIMARY KEY (id),
                CONSTRAINT fk_cashback_award_lines_award FOREIGN KEY (award_id)
                    REFERENCES cashback_awards (id) ON DELETE CASCADE,
                CONSTRAINT fk_cashback_award_lines_rule FOREIGN KEY (rule_id)
                    REFERENCES cashback_rules (id) ON DELETE RESTRICT
            )
            SQL);
        $this->addSql('CREATE INDEX idx_cashback_award_lines_award ON cashback_award_lines (award_id)');
        $this->addSql('CREATE INDEX idx_cashback_award_lines_rule ON cashback_award_lines (rule_id)');

        // gift_cards.source + backfill. Admin-issued cards (issueByAdmin) are
        // born active with no purchase order and open their ledger with an
        // un-tagged CREDIT row; everything else was bought through checkout.
        $this->addSql("ALTER TABLE gift_cards ADD COLUMN source VARCHAR(16) NOT NULL DEFAULT 'purchase'");
        $this->addSql(<<<'SQL'
            UPDATE gift_cards g SET source = 'admin'
             WHERE g.purchase_order_reference IS NULL
               AND g.status <> 'pending_payment'
               AND EXISTS (
                    SELECT 1 FROM gift_card_transactions t
                     WHERE t.gift_card_id = g.id
                       AND t.type = 'credit'
                       AND t.order_reference IS NULL
                       AND t.order_id IS NULL
                       AND t.id = (SELECT MIN(t2.id) FROM gift_card_transactions t2 WHERE t2.gift_card_id = g.id)
               )
            SQL);
        $this->addSql('ALTER TABLE gift_cards ADD COLUMN cashback_award_id BIGINT DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uq_gift_cards_cashback_award ON gift_cards (cashback_award_id)');
        $this->addSql('CREATE INDEX idx_gift_cards_source ON gift_cards (source)');
        $this->addSql(
            'ALTER TABLE gift_cards ADD CONSTRAINT fk_gift_cards_cashback_award FOREIGN KEY (cashback_award_id)'
            . ' REFERENCES cashback_awards (id) ON DELETE SET NULL'
        );

        $this->addSql('ALTER TABLE orders ADD COLUMN used_cashback_credit BOOLEAN NOT NULL DEFAULT FALSE');

        // Permissions (idempotent) + super_admin grant.
        $labels = PermissionCatalog::flat();
        foreach (self::PERMISSIONS as $key) {
            $this->addSql(
                'INSERT INTO permissions (permission_key, module, label) VALUES (?, ?, ?)
                 ON CONFLICT (permission_key) DO NOTHING',
                [$key, PermissionCatalog::moduleOf($key), $labels[$key] ?? $key],
            );
            $this->addSql(
                'INSERT INTO role_permission (role_id, permission_id)
                 SELECT r.id, p.id FROM roles r, permissions p
                 WHERE r.slug = ? AND p.permission_key = ?
                 ON CONFLICT DO NOTHING',
                ['super_admin', $key],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        foreach (self::PERMISSIONS as $key) {
            $this->addSql(
                'DELETE FROM role_permission WHERE permission_id IN (SELECT id FROM permissions WHERE permission_key = ?)',
                [$key],
            );
            $this->addSql('DELETE FROM permissions WHERE permission_key = ?', [$key]);
        }

        $this->addSql('ALTER TABLE orders DROP COLUMN used_cashback_credit');
        $this->addSql('ALTER TABLE gift_cards DROP CONSTRAINT fk_gift_cards_cashback_award');
        $this->addSql('DROP INDEX IF EXISTS uq_gift_cards_cashback_award');
        $this->addSql('DROP INDEX IF EXISTS idx_gift_cards_source');
        $this->addSql('ALTER TABLE gift_cards DROP COLUMN cashback_award_id');
        $this->addSql('ALTER TABLE gift_cards DROP COLUMN source');
        $this->addSql('DROP TABLE cashback_award_lines');
        $this->addSql('DROP TABLE cashback_awards');
        $this->addSql('DROP TABLE cashback_rule_targets');
        $this->addSql('DROP TABLE cashback_rules');
    }
}
