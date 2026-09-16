<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ain AI — Personal Style Profile store.
 *
 * One row per customer holding a pre-computed affinity profile derived (offline,
 * by the `ai:build-style-profiles` command) from their wishlist, follows, paid
 * orders and product-view signals: ranked colour / style / category / vendor /
 * occasion / size tags, a budget band, and a few recent "seed" products. Powers
 * the personalised "For You / Your Style" rails (GET /v3/me/ai/for-you) without
 * any per-request LLM call — the rails are built from this profile + the existing
 * catalogue retrieval, and every surfaced product is re-validated against live
 * inventory. JSONB so it works on any Postgres; refreshed by a batch cron.
 */
final class Version20260916000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ain AI: customer_style_profiles personalisation store.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.'
        );

        $this->addSql(<<<SQL
            CREATE TABLE customer_style_profiles (
                user_id BIGINT PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                colours JSONB DEFAULT NULL,
                styles JSONB DEFAULT NULL,
                categories JSONB DEFAULT NULL,
                vendors JSONB DEFAULT NULL,
                occasions JSONB DEFAULT NULL,
                sizes JSONB DEFAULT NULL,
                budget_min NUMERIC(10, 2) DEFAULT NULL,
                budget_max NUMERIC(10, 2) DEFAULT NULL,
                seed_product_ids JSONB DEFAULT NULL,
                signal_counts JSONB DEFAULT NULL,
                computed_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                created_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL
            )
            SQL);
        // Freshness sweep: the cron can find the stalest profiles first.
        $this->addSql('CREATE INDEX idx_customer_style_profiles_computed_at ON customer_style_profiles (computed_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE IF EXISTS customer_style_profiles');
    }
}
