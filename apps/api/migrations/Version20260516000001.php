<?php

declare(strict_types=1);

namespace Bayti\Api\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * M3.2.X.2-A, Add is_featured column to vendors.
 *
 * Background
 * ==========
 * Apps/web home page renders a "Designer Spotlight" section that lists
 * 4 curated vendors with embedded product thumbnails. The legacy
 * /v2/featured-vendors endpoint that previously served this surface is
 * broken in production (returns 500), and v3 has no equivalent yet.
 *
 * M3.2.X.2 ships /v3/featured-vendors. This migration is sub-phase A:
 * adds the is_featured boolean to the vendors table so admin can curate
 * which vendors appear in the Spotlight.
 *
 * Why boolean (not enum / not a separate vendor_featured table)
 * --------------------------------------------------------------
 *  - Boolean is the minimum data needed for the launch: "is this vendor
 *    on the Spotlight list right now, yes/no". A separate table or enum
 *    is overkill for binary curation.
 *  - If business later needs richer curation (manual ordering, time-
 *    boxed features, A/B-tested feature groups), the boolean coexists
 *    with whatever joins/columns we add. No data migration cost.
 *
 * Default value rationale
 * -----------------------
 * DEFAULT FALSE so existing 60+ vendors retain "not featured" without
 * an explicit backfill. After this migration ships, operator picks
 * 3-4 vendors via PUT /v3/admin/vendors/{id} { is_featured: true }
 * (added in sub-phase D).
 *
 * Index decision
 * --------------
 * Featured vendors are FEW (4-12 typical). A plain index over is_featured
 * would be useless (one boolean column, tiny selectivity for false=most).
 * A partial index on `WHERE is_featured = true` is the right shape but
 * we skip it for the launch, at 60 total vendors, a full scan filtered
 * by is_active AND is_featured is faster than touching an index.
 *
 * If/when vendor count grows past ~5,000 we add:
 *   CREATE INDEX idx_vendors_featured_active
 *     ON vendors (name ASC)
 *     WHERE is_active = TRUE AND is_featured = TRUE;
 *
 * That stays in this docblock as a known-but-deferred optimization
 * rather than shipping it speculatively.
 *
 * Rollback safety
 * ---------------
 * down() drops the column. Any vendor that was flagged is_featured = true
 * before rollback loses that flag, accepted because rollback is an
 * exceptional operation and the curation can be re-applied via admin UI.
 */
final class Version20260516000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M3.2.X.2-A — Add is_featured column to vendors for Designer Spotlight curation.';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'This migration only supports PostgreSQL.'
        );

        // Single column add with default. PostgreSQL applies the default
        // to existing rows atomically (no rewrite needed for booleans
        // with DEFAULT FALSE on a non-huge table). Safe online.
        $this->addSql(<<<'SQL'
            ALTER TABLE vendors
                ADD COLUMN is_featured BOOLEAN NOT NULL DEFAULT FALSE
        SQL);

        // No index, see class docblock for rationale. If profiling
        // post-launch shows the featured-vendors query is slow, add:
        //   CREATE INDEX idx_vendors_featured_active
        //     ON vendors (name ASC)
        //     WHERE is_active = TRUE AND is_featured = TRUE;
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vendors DROP COLUMN IF EXISTS is_featured');
    }
}
