<?php

declare(strict_types=1);

/**
 * Migrate wishlist items (saved products) from legacy MySQL to v3 Postgres.
 *
 * Source: wishlist
 *   wishlist_id (PK), user_id, wishll_id (= label fk), product_id, created
 *
 * Target: wishlist (user_id, product_id, label_id, created_at)
 *   UNIQUE (user_id, product_id). No updated_at.
 *
 * Resolves BOTH user (users.legacy_user_id) AND product
 * (products.legacy_product_id); skip+log if EITHER missing. Optional
 * label resolved via wishlist_labels.legacy_wishlist_label_id = legacy
 * wishll_id (NULL if not found). Legacy duplicate (user,product) across
 * folders collapses to one v3 row; last folder seen wins; created_at
 * preserved on first insert.
 *
 * Depends on migrate-wishlist-labels having run first (for label_id
 * resolution) — though labels are optional, so wishlist still migrates
 * with label_id NULL if labels weren't migrated.
 *
 * Idempotent, transactional, re-runnable while legacy stays live.
 *
 * Usage (from apps/api):
 *   php bin/migrate-from-legacy/migrate-wishlist.php            # live
 *   php bin/migrate-from-legacy/migrate-wishlist.php --dry-run  # rolled back
 * Requires the LEGACY_MYSQL_* env vars (same as the other migrations).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Migration\LegacyDb;
use Bayti\Api\Migration\MigrationLog;
use Bayti\Api\Migration\MigrationSteps;
use Bayti\Api\Migration\Slugger;
use Doctrine\ORM\EntityManagerInterface;

$dryRun = in_array('--dry-run', $argv, true);

$app = Bootstrap::createApp();
$container = $app->getContainer();
if ($container === null) {
    fwrite(STDERR, "DI container not available\n");
    exit(1);
}

/** @var EntityManagerInterface $em */
$em = $container->get(EntityManagerInterface::class);
$conn = $em->getConnection();
$legacy = new LegacyDb();
$log = new MigrationLog($conn);
$slugger = new Slugger();
$steps = new MigrationSteps($conn, $legacy, $log, $slugger);
$steps->setDryRun($dryRun);

echo "===== migrate-wishlist =====\n";
echo "  run_id: " . $log->getRunId() . "\n";
echo "  mode:   " . ($dryRun ? "DRY RUN (no writes committed)" : "LIVE") . "\n\n";

try {
    $r = $steps->migrateWishlist();
} catch (Throwable $e) {
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    $legacy->close();
    exit(1);
}

echo "  migrated: {$r['migrated']}\n";
echo "  skipped:  {$r['skipped']}\n";
echo "  errors:   {$r['errors']}\n\n";
echo $dryRun
    ? "  DRY RUN — transaction rolled back, no changes persisted.\n"
    : "  Done — committed.\n";

$log->summary('wishlist');
$legacy->close();
exit(0);
