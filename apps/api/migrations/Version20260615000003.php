<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * HP-BE1 — campaigns + campaign_items schema.
 *
 * Backs the time-boxed merchandising campaigns that drive the storefront
 * homepage's "Anniversary Deals" and "Flash Sale" sections:
 *
 *   GET /v3/campaigns/active   (public read — the live anniversary + flash)
 *   GET /v3/campaigns          (public list by type)
 *   /v3/admin/campaigns*       (admin CRUD)
 *
 * campaigns
 * =========
 *   - type VARCHAR(20): 'anniversary' | 'flash' (validated in the entity).
 *   - discount_percent SMALLINT default 0: campaign-wide default discount,
 *     applied to items that don't carry their own override. The campaign
 *     never stores a per-product money amount — the discount is applied to
 *     the product's live price at read time, so it stays correct as prices
 *     change.
 *   - starts_at / ends_at: the active window. A campaign is "live" when
 *     is_active AND now in [starts_at, ends_at]. Indexed on (type, is_active)
 *     for the homepage's per-type active lookup.
 *   - priority SMALLINT nullable: lower = higher priority when several
 *     campaigns of one type are live simultaneously.
 *
 * campaign_items
 * ==============
 *   - (campaign_id, product_id) UNIQUE: a product appears at most once per
 *     campaign. Both FKs ON DELETE CASCADE — removing a campaign or product
 *     cleans up the join.
 *   - discount_percent SMALLINT nullable: per-item override of the campaign
 *     default (null -> use the campaign default).
 *   - stock_total / stock_remaining INT nullable: campaign-scoped stock for
 *     flash-sale "X left" bars. Both null -> unlimited (no bar). Admin-managed
 *     for now; live decrement-on-purchase is a tracked follow-up.
 *   - sort_order SMALLINT nullable: render order within the section.
 *
 * Reversibility: down() drops campaign_items then campaigns (reverse FK
 * order); both IF EXISTS.
 */
final class Version20260615000003 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'HP-BE1 — campaigns + campaign_items schema (homepage deals / flash sale)';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql(<<<'SQL'
            CREATE TABLE campaigns (
                id BIGSERIAL PRIMARY KEY,
                slug VARCHAR(220) NOT NULL,
                type VARCHAR(20) NOT NULL,
                title VARCHAR(200) NOT NULL,
                subtitle VARCHAR(300),
                discount_percent SMALLINT NOT NULL DEFAULT 0,
                starts_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                ends_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                priority SMALLINT,
                created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL,
                CONSTRAINT uniq_campaigns_slug UNIQUE (slug)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_campaigns_type_active ON campaigns (type, is_active)');

        $this->addSql(<<<'SQL'
            CREATE TABLE campaign_items (
                id BIGSERIAL PRIMARY KEY,
                campaign_id BIGINT NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
                product_id BIGINT NOT NULL REFERENCES products(id) ON DELETE CASCADE,
                discount_percent SMALLINT,
                stock_total INT,
                stock_remaining INT,
                sort_order SMALLINT,
                CONSTRAINT uniq_campaign_product UNIQUE (campaign_id, product_id)
            )
        SQL);

        $this->addSql('CREATE INDEX idx_campaign_items_campaign ON campaign_items (campaign_id)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        $this->addSql('DROP TABLE IF EXISTS campaign_items');
        $this->addSql('DROP TABLE IF EXISTS campaigns');
    }
}
