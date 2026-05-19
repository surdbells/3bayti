<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.X.12-A — Recommendations engine: product_recommendations table.
 *
 * Single denormalized lookup table holding pre-computed per-product
 * recommendations. Populated nightly by the X.12-E
 * BuildRecommendationsCommand cron; read-side queries become single
 * indexed lookups (Q-Caching = B locked: denormalized table not
 * Redis cache or per-request computation).
 *
 * Schema
 * ======
 *   id                       BIGSERIAL PRIMARY KEY
 *   product_id               INTEGER  source product (the one being
 *                                     viewed; FK to products)
 *   recommended_product_id   INTEGER  what to recommend with it
 *                                     (FK to products)
 *   score                    NUMERIC(8, 4)  algorithm-specific score
 *                                            (co-purchase count, category
 *                                            similarity, etc.) — 0.0000
 *                                            to 9999.9999. Larger = better.
 *   source                   VARCHAR(20)  'copurchase' | 'category' |
 *                                          'fallback_popular' — which
 *                                          algorithm produced this row
 *   rank                     INTEGER  1..N within (product_id, source);
 *                                     primary order key for the read path
 *   computed_at              TIMESTAMP WITH TIME ZONE  cron run timestamp
 *
 * UNIQUE (product_id, recommended_product_id) — exactly one row per
 *   (source, target) pair; the cron either inserts new or updates
 *   existing on rerun. A product can be recommended FOR multiple
 *   parents (one per parent product) but only once per parent.
 *
 * INDEX idx_product_recs_lookup ON (product_id, rank)
 *   — the hot read path: "give me top-N recommendations for product X
 *   ordered by rank". Composite index keyed on both columns is the
 *   single seek+range scan that satisfies this.
 *
 * INDEX idx_product_recs_source ON source
 *   — supports the X.12-G admin "explain why" endpoint, which filters
 *   rows by source to break down the score makeup.
 *
 * FK CASCADE behavior:
 *   - product_id ON DELETE CASCADE: deleting a product removes all
 *     recommendations FOR it (no longer being viewed → no longer
 *     needed)
 *   - recommended_product_id ON DELETE CASCADE: deleting a product
 *     also removes all rows where IT was the recommendation target
 *     (the source product page no longer needs to recommend a deleted
 *     item)
 *   Both CASCADES means a single product DELETE cleans both
 *   directions in the recommendations graph automatically.
 *
 * Q-Algorithm = B locked: co-purchase + same-category fallback.
 * Q-VendorScope = A locked: marketplace-wide, no vendor isolation.
 * Q-AdminTuning = A locked: fully algorithmic, no admin pin/unpin.
 *
 * The score column is intentionally wide enough (NUMERIC(8, 4)) to
 * hold raw co-purchase counts (e.g. "23.0000") AND decimal scores
 * from future ML algorithms ("0.8543"). The same column shape works
 * for v1 rule-based AND a future ML model. Operator follow-up #37
 * adds the ML pathway without schema changes.
 */
final class Version20260519000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.X.12-A — Recommendations engine: product_recommendations table.';
    }

    public function up(Schema $schema): void
    {
        $table = $schema->createTable('product_recommendations');

        $table->addColumn('id', 'bigint', [
            'autoincrement' => true,
            'notnull' => true,
        ]);
        $table->addColumn('product_id', 'integer', [
            'notnull' => true,
        ]);
        $table->addColumn('recommended_product_id', 'integer', [
            'notnull' => true,
        ]);
        $table->addColumn('score', 'decimal', [
            'precision' => 8,
            'scale' => 4,
            'notnull' => true,
        ]);
        $table->addColumn('source', 'string', [
            'length' => 20,
            'notnull' => true,
        ]);
        $table->addColumn('rank', 'integer', [
            'notnull' => true,
        ]);
        $table->addColumn('computed_at', 'datetimetz_immutable', [
            'notnull' => true,
        ]);

        $table->setPrimaryKey(['id']);
        $table->addUniqueIndex(
            ['product_id', 'recommended_product_id'],
            'uniq_product_recs_pair',
        );
        $table->addIndex(['product_id', 'rank'], 'idx_product_recs_lookup');
        $table->addIndex(['source'], 'idx_product_recs_source');

        $table->addForeignKeyConstraint(
            'products',
            ['product_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_product_recs_product',
        );
        $table->addForeignKeyConstraint(
            'products',
            ['recommended_product_id'],
            ['id'],
            ['onDelete' => 'CASCADE'],
            'fk_product_recs_recommended',
        );
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('product_recommendations');
    }
}
