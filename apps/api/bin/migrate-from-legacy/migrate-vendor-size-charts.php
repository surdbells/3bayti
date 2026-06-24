<?php

declare(strict_types=1);

/**
 * Migrate vendor published size charts from legacy MySQL to v3 Postgres.
 *
 * Source: store_sizes_measure (only is_deleted=0 rows)
 *   ssm_id, store_id (= vendor owner user id), size,
 *   m_bust, m_waist, m_hip, m_length, m_neck, m_arm, armhole, shoulder,
 *   is_deleted, last_updated, delete_date, created
 *
 * Target: vendor_size_charts (vendor_id, size, values jsonb, created_at,
 *   updated_at) UNIQUE (vendor_id, size). The 8 legacy measurement doubles
 *   map into the `values` JSONB map (bust/waist/hip/length/neck/arm/
 *   armhole/shoulder); NULLs pass through (key omitted).
 *
 * Vendor resolved via vendors.legacy_vendor_id = legacy store_id
 * (skip+log if missing). Multiple LIVE rows per (store_id, size) are
 * deduped oldest-first into an idempotent UPSERT so the newest wins.
 *
 * Idempotent, transactional, re-runnable while legacy stays live. The
 * legacy DB is never written.
 *
 * Usage (from apps/api):
 *   php bin/migrate-from-legacy/migrate-vendor-size-charts.php            # live
 *   php bin/migrate-from-legacy/migrate-vendor-size-charts.php --dry-run  # rolled back
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

echo "===== migrate-vendor-size-charts =====\n";
echo "  run_id: " . $log->getRunId() . "\n";
echo "  mode:   " . ($dryRun ? "DRY RUN (no writes committed)" : "LIVE") . "\n\n";

try {
    $r = $steps->migrateVendorSizeCharts();
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

$log->summary('vendor_size_charts');
$legacy->close();
exit(0);
