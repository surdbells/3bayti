<?php

declare(strict_types=1);

/**
 * Migrate customer wishlist labels (folders/closets) from legacy MySQL
 * to v3 Postgres.
 *
 * Source: customer_wishlist_label
 *   wishll_id (PK), user_id (= CUSTOMER), label_name, created
 *
 * Target: wishlist_labels (user_id, name, created_at,
 *   legacy_wishlist_label_id). TWO unique keys handled via check-then-
 *   branch: by legacy_wishlist_label_id, then adopt-by (user_id, name),
 *   then INSERT. created_at preserved on first insert.
 *
 * User resolved via users.legacy_user_id = legacy user_id (skip+log if
 * missing). name defaults to 'Saved' if empty.
 *
 * NOTE: wishlist_labels.legacy_wishlist_label_id is added by Doctrine
 * migration Version20260624000002 — run migrations before this script.
 *
 * Idempotent, transactional, re-runnable while legacy stays live.
 *
 * Usage (from apps/api):
 *   php bin/migrate-from-legacy/migrate-wishlist-labels.php            # live
 *   php bin/migrate-from-legacy/migrate-wishlist-labels.php --dry-run  # rolled back
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

echo "===== migrate-wishlist-labels =====\n";
echo "  run_id: " . $log->getRunId() . "\n";
echo "  mode:   " . ($dryRun ? "DRY RUN (no writes committed)" : "LIVE") . "\n\n";

try {
    $r = $steps->migrateWishlistLabels();
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

$log->summary('wishlist_labels');
$legacy->close();
exit(0);
