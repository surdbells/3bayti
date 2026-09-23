<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Store-follow new-product alerts (Phase 10).
 *
 * Adds publish tracking to products so the `stores:send-follower-alerts` cron
 * can push "new from a store you follow" to a vendor's followers exactly once
 * per newly-published product:
 *   - published_at            : stamped the first time a product goes active
 *                               (draft→active), by Product::setStatus().
 *   - followers_notified_at    : set by the cron once the followers have been
 *                               alerted (the idempotency marker).
 *
 * BACKFILL / SPAM GUARD (critical): every product that is ALREADY active at
 * migration time is stamped as already-published AND already-notified, so the
 * first cron run does NOT fan a "new product" push out for the entire existing
 * catalogue. Only products published AFTER this migration (published_at set,
 * followers_notified_at still null) are ever alerted. Existing DRAFTS are left
 * null on both, so publishing one later correctly notifies followers.
 */
final class Version20260923000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add products.published_at + followers_notified_at for follower new-product alerts; backfill existing active products as already-notified (spam guard).';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql('ALTER TABLE products ADD COLUMN published_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');
        $this->addSql('ALTER TABLE products ADD COLUMN followers_notified_at TIMESTAMP(0) WITH TIME ZONE DEFAULT NULL');

        // Spam guard: mark the entire EXISTING active catalogue as already
        // published + already notified, so the first cron run notifies nobody
        // for products that predate this feature. Drafts stay null on both.
        $this->addSql(
            "UPDATE products SET published_at = created_at, followers_notified_at = created_at WHERE status = 'active'"
        );

        // Partial index for the finder's hot predicate
        // (published_at IS NOT NULL AND followers_notified_at IS NULL): tiny,
        // since almost every row has followers_notified_at set post-backfill.
        $this->addSql(
            'CREATE INDEX idx_products_follower_alert ON products (published_at) WHERE followers_notified_at IS NULL'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IF EXISTS idx_products_follower_alert');
        $this->addSql('ALTER TABLE products DROP COLUMN IF EXISTS followers_notified_at');
        $this->addSql('ALTER TABLE products DROP COLUMN IF EXISTS published_at');
    }
}
