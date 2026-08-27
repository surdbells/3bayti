<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Admin gift-card surface (M5), add audit fields to the gift-card
 * transaction ledger so manual admin entries (adjust / void / issue)
 * carry the WHY and the WHO.
 *
 * New columns on gift_card_transactions
 * =====================================
 *   - reason VARCHAR(255) NULL
 *       Admin-supplied rationale for a manual adjustment, void or
 *       issue. Null for ordinary checkout debits / cancellation
 *       credits (those are explained by order_reference).
 *
 *   - actor_user_id BIGINT NULL
 *       The admin user id who performed a manual entry. Null for
 *       customer-driven checkout / cancellation movements.
 *
 * Both columns are additive + nullable, so this migration applies online
 * with no row rewrites. The new ledger 'void' type is a string value, so
 * it needs no schema change (type is already VARCHAR(10)).
 *
 * Reversibility: down() drops both columns (IF EXISTS).
 */
final class Version20260622000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Admin gift-card ledger — add reason + actor_user_id to gift_card_transactions';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE gift_card_transactions
                ADD COLUMN reason VARCHAR(255) DEFAULT NULL,
                ADD COLUMN actor_user_id BIGINT DEFAULT NULL
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            ALTER TABLE gift_card_transactions
                DROP COLUMN IF EXISTS reason,
                DROP COLUMN IF EXISTS actor_user_id
        SQL);
    }
}
