<?php

declare(strict_types=1);

/**
 * Orchestrator, runs the full migration in correct dependency order.
 *
 * All migration logic lives in Bayti\Api\Migration\MigrationSteps.
 * This script is a thin wrapper: bootstrap, instantiate, run steps,
 * print summary.
 *
 * Single-process design (no passthru/exec)
 * ----------------------------------------
 * aaPanel disables passthru, exec, shell_exec, system, popen on the
 * production CLI. We can't fork sub-scripts. Instead, all steps run
 * sequentially in this PHP process, sharing the DI container,
 * EntityManager, LegacyDb, and MigrationLog instances.
 *
 * Each step still wraps its writes in its own DB transaction, so a
 * failure in step N doesn't affect the writes from steps 1..N-1.
 *
 * Usage
 * -----
 *   php migrate-all.php              # default, INSERT new + UPDATE drift
 *   php migrate-all.php --wipe-seed  # also wipe fictional M2.1.B seed first
 *
 * Use --wipe-seed ONLY on the very first migration. After that, real
 * migrated data lives in those tables and wiping it would be destructive.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Migration\LegacyDb;
use Bayti\Api\Migration\MigrationLog;
use Bayti\Api\Migration\MigrationSteps;
use Bayti\Api\Migration\Slugger;
use Doctrine\ORM\EntityManagerInterface;

$wipeSeed = in_array('--wipe-seed', $argv, true);
$includeOrders = in_array('--include-orders', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

echo "============================================================\n";
echo " 3bayti legacy data migration\n";
echo "============================================================\n\n";

$start = microtime(true);

try {
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

    echo "Run ID: " . $log->getRunId() . "\n";
    echo "Mode:   " . ($dryRun
        ? "DRY RUN — order-migration preview only (no writes)"
        : ($wipeSeed ? "WIPE-SEED + MIGRATE" : "MIGRATE (UPSERT)")) . "\n\n";

    $stepNum = 0;
    $results = [];

    if ($dryRun) {
        // Dry-run is scoped to ORDER migration. The catalog steps
        // (categories…wishlist) do not support rollback and would write for
        // real, so they are skipped here. migrateOrders resolves the
        // already-migrated users/products/vendors, inserts orders + items +
        // addresses inside a transaction, then rolls back, exact counts,
        // zero writes. If those catalog tables are empty, run a normal
        // (non-dry) migration first, then dry-run the orders.
        echo "----- DRY RUN: order-migration preview only -----\n";
        echo "       Catalog steps skipped; orders validated then rolled back.\n\n";
        $results['orders'] = $steps->migrateOrders();
    } else {
    if ($wipeSeed) {
        $stepNum++;
        echo "----- step {$stepNum}: wipe fictional seed -----\n";
        $results['wipe'] = $steps->wipeFictionalSeed();
    } else {
        echo "----- skip: wipe-fictional-seed (pass --wipe-seed to run) -----\n\n";
    }

    $stepNum++;
    echo "----- step {$stepNum}: categories -----\n";
    $results['categories'] = $steps->migrateCategories();

    $stepNum++;
    echo "----- step {$stepNum}: users -----\n";
    $results['users'] = $steps->migrateUsers();

    $stepNum++;
    echo "----- step {$stepNum}: vendors -----\n";
    $results['vendors'] = $steps->migrateVendors();

    $stepNum++;
    echo "----- step {$stepNum}: products -----\n";
    $results['products'] = $steps->migrateProducts();

    $stepNum++;
    echo "----- step {$stepNum}: reviews -----\n";
    $results['reviews'] = $steps->migrateReviews();

    // M3.1.5.5c, vendor_labels + styles
    //
    // These come AFTER products because vendor_labels needs the
    // products.label_id FK validated against existing products,
    // and migrateVendorLabels remaps products.label_id values from
    // legacy to v3 ids as part of its transaction.
    //
    // Both methods are defensive, if the legacy table isn't found,
    // they log-and-skip rather than failing. Operator can extend the
    // candidate name list in MigrationSteps if the legacy tables
    // use names not in the default probe list.
    $stepNum++;
    echo "----- step {$stepNum}: vendor_labels -----\n";
    $results['vendor_labels'] = $steps->migrateVendorLabels();

    $stepNum++;
    echo "----- step {$stepNum}: styles -----\n";
    $results['styles'] = $steps->migrateStyles();

    // vendor_size_charts, needs vendors (resolves vendor_id via
    // vendors.legacy_vendor_id = legacy store_id). Independent of
    // products/styles, but placed here after the catalog steps.
    $stepNum++;
    echo "----- step {$stepNum}: vendor_size_charts -----\n";
    $results['vendor_size_charts'] = $steps->migrateVendorSizeCharts();

    // wishlist_labels -> wishlist, labels need users; items need users
    // AND products AND (optionally) the just-migrated labels for label_id
    // resolution. Order matters: labels before items.
    $stepNum++;
    echo "----- step {$stepNum}: wishlist_labels -----\n";
    $results['wishlist_labels'] = $steps->migrateWishlistLabels();

    $stepNum++;
    echo "----- step {$stepNum}: wishlist -----\n";
    $results['wishlist'] = $steps->migrateWishlist();

    // ---------- M3.1.6h: order migration (opt-in) ----------
    // Off by default. Opt in with --include-orders (preview first with
    // --dry-run). migrateOrders is a single step: it reads placed orders
    // from ec_cart_items (grouped by TRN cart_code), enriches each with the
    // payment_attempts header (address, delivery fee, total, paid status),
    // and writes orders + items + addresses in one per-order transaction.
    //
    // Carts (cart_code='PND') are NOT migrated (transient state, active
    // legacy carts are lost on cutover; users re-add items in v3).
    if ($includeOrders) {
        $stepNum++;
        echo "----- step {$stepNum}: orders + items + addresses (M3.1.6h, opt-in) -----\n";
        $results['orders'] = $steps->migrateOrders();
    } else {
        echo "----- skip: orders (pass --include-orders to run, --dry-run to preview) -----\n\n";
    }
    } // end: non-dry-run catalog + order flow

    $elapsed = microtime(true) - $start;

    // ---------- final summary ----------

    echo "============================================================\n";
    printf(" Migration complete in %.1fs\n", $elapsed);
    echo "============================================================\n\n";

    if (isset($results['wipe'])) {
        printf("  Seed wiped:  brands=%d  categories=%d  vendors=%d\n\n",
            $results['wipe']['brands'],
            $results['wipe']['categories'],
            $results['wipe']['vendors']
        );
    }

    printf("  %-15s  %-9s  %-9s  %-9s\n", 'Phase', 'Processed', 'Skipped', 'Errors');
    printf("  %-15s  %-9s  %-9s  %-9s\n",
        '---------------', '---------', '---------', '---------');
    foreach (['categories', 'users', 'vendors', 'products', 'reviews',
              'vendor_size_charts', 'wishlist_labels', 'wishlist', 'orders'] as $phase) {
        if (!isset($results[$phase])) {
            continue;
        }
        $r = $results[$phase];
        printf("  %-15s  %9d  %9d  %9d\n", $phase, $r['migrated'], $r['skipped'], $r['errors']);
    }
    // M3.1.5.5c phases, print only if they ran (status field
    // distinguishes 'completed' vs 'skipped_no_legacy_table' etc).
    foreach (['vendor_labels', 'styles'] as $phase) {
        if (!isset($results[$phase])) {
            continue;
        }
        $r = $results[$phase];
        $status = $r['status'] ?? 'completed';
        if ($status === 'completed') {
            printf("  %-15s  %9d  %9d  %9d\n", $phase, $r['migrated'], $r['skipped'], $r['errors']);
        } else {
            printf("  %-15s  %s\n", $phase, $status);
        }
    }
    echo "\n";

    if (isset($results['users']['conflicts'])) {
        printf("  Email conflicts logged: %d\n", $results['users']['conflicts']);
        echo "    Inspect: psql -d bayti_v3 -c \"SELECT * FROM migration_email_conflicts WHERE resolution_status='pending' LIMIT 20\"\n\n";
    }

    // Quick sanity check on v3 data
    echo "  Verification:\n";
    $cats = (int) $conn->fetchOne("SELECT COUNT(*) FROM categories");
    $usr  = (int) $conn->fetchOne("SELECT COUNT(*) FROM users WHERE legacy_user_id IS NOT NULL");
    $vnd  = (int) $conn->fetchOne("SELECT COUNT(*) FROM vendors WHERE legacy_vendor_id IS NOT NULL");
    $prd  = (int) $conn->fetchOne("SELECT COUNT(*) FROM products WHERE legacy_product_id IS NOT NULL AND status='active'");
    $rev  = (int) $conn->fetchOne("SELECT COUNT(*) FROM product_reviews WHERE legacy_review_id IS NOT NULL");
    $lbl  = (int) $conn->fetchOne("SELECT COUNT(*) FROM vendor_labels WHERE legacy_label_id IS NOT NULL");
    $sty  = (int) $conn->fetchOne("SELECT COUNT(*) FROM styles WHERE legacy_style_id IS NOT NULL");
    $vsc  = (int) $conn->fetchOne("SELECT COUNT(*) FROM vendor_size_charts");
    $wll  = (int) $conn->fetchOne("SELECT COUNT(*) FROM wishlist_labels WHERE legacy_wishlist_label_id IS NOT NULL");
    $wsh  = (int) $conn->fetchOne("SELECT COUNT(*) FROM wishlist");
    $ord  = (int) $conn->fetchOne("SELECT COUNT(*) FROM orders");
    $oit  = (int) $conn->fetchOne("SELECT COUNT(*) FROM order_items");
    $oad  = (int) $conn->fetchOne("SELECT COUNT(*) FROM order_addresses");
    echo "    categories:        {$cats}\n";
    echo "    users (legacy):    {$usr}\n";
    echo "    vendors (legacy):  {$vnd}\n";
    echo "    products active:   {$prd}\n";
    echo "    reviews:           {$rev}\n";
    echo "    vendor_labels:     {$lbl}\n";
    echo "    styles:            {$sty}\n";
    echo "    vendor_size_charts: {$vsc}\n";
    echo "    wishlist_labels:   {$wll}\n";
    echo "    wishlist:          {$wsh}\n";
    echo "    orders:            {$ord}\n";
    echo "    order_items:       {$oit}\n";
    echo "    order_addresses:   {$oad}\n";

    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "\n!!! FATAL: " . $e->getMessage() . "\n");
    fwrite(STDERR, "    File: " . $e->getFile() . ":" . $e->getLine() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
