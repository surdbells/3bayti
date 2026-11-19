<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cashback review fixes.
 *
 *   - cashback_awards.uncollected_amount: clawed-back cashback the customer
 *     had already spent (written off at reversal) and that has not been
 *     recovered yet. When that spend comes back to the cashback card
 *     (GiftCardOrderReversal restores a cancelled/failed/refunded order),
 *     it is debited straight back up to this amount. It also keeps the
 *     written-off money counted against the rule budget.
 *   - cashback_award_lines.accrued_amount: the line's value at accrual.
 *     `amount` is rewritten at release, so post-release settlement
 *     (partial refunds after issue) recomputes from this. Backfilled from
 *     `amount`.
 */
final class Version20261119000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cashback: cashback_awards.uncollected_amount + cashback_award_lines.accrued_amount.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE cashback_awards ADD COLUMN uncollected_amount NUMERIC(10, 2) NOT NULL DEFAULT 0.00');
        $this->addSql('ALTER TABLE cashback_award_lines ADD COLUMN accrued_amount NUMERIC(10, 2) DEFAULT NULL');
        $this->addSql('UPDATE cashback_award_lines SET accrued_amount = amount WHERE accrued_amount IS NULL');
        $this->addSql('ALTER TABLE cashback_award_lines ALTER COLUMN accrued_amount SET NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE cashback_award_lines DROP COLUMN accrued_amount');
        $this->addSql('ALTER TABLE cashback_awards DROP COLUMN uncollected_amount');
    }
}
