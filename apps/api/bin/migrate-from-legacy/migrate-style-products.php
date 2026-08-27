<?php

declare(strict_types=1);

/**
 * Backfill `style_products` join rows from legacy MySQL into v3 Postgres,
 * then recompute `styles.total_price`.
 *
 * Why this exists
 * ===============
 * MigrationSteps::migrateStyles() imported the style ROWS only and
 * explicitly deferred product membership:
 *
 *   "style_products join rows NOT migrated by this method, the legacy
 *    schema for product membership is not yet known. Run a separate
 *    migrate-style-products step once the join table shape is identified
 *    (legacy candidate: 'stylist_products')."
 *
 * This IS that step. Until it runs, migrated styles render blank in the
 * Style Hub (0 items, no image, total 0.00) because they have no rows in
 * style_products. New (v3 user-created) styles are unaffected.
 *
 * Defensive probing
 * =================
 * The legacy join-table name/shape isn't known a priori, so we probe a set
 * of candidate tables + columns (same pattern as migrateStyles /
 * migrateVendorLabels). Two legacy shapes are handled:
 *   (A) a join table:  <style_fk>, <product_fk>, [display_order]
 *   (B) a CSV column on the legacy styles table: products = "12,34,56"
 * If neither matches we print exactly what we looked for and exit 0 WITHOUT
 * writing, share the diagnostics (+ `SHOW TABLES LIKE '%styl%'`) and the
 * candidate lists below get adjusted.
 *
 * Mapping
 * =======
 *   legacy style id   -> styles.legacy_style_id    -> styles.id
 *   legacy product id -> products.legacy_product_id -> products.id
 * Rows whose style or product never migrated are skipped + counted.
 *
 * total_price: after linking, recomputed per affected style as the SUM of
 * each ACTIVE linked product's effective price COALESCE(sale_price, price)
 *, matching CreateStyleController (getSalePrice() ?? getPrice()).
 *
 * Idempotent: an existing (style_id, product_id) link is left as-is.
 * Transactional. Safe to re-run. The legacy DB is never written.
 *
 * Note: CreateStyleController caps new styles at 4 products; this backfill
 * does NOT cap, it brings over whatever the legacy style contained.
 *
 * Usage (from apps/api):
 *   php bin/migrate-from-legacy/migrate-style-products.php            # live
 *   php bin/migrate-from-legacy/migrate-style-products.php --dry-run  # probe + counts, rolled back
 * Requires the LEGACY_MYSQL_* env vars (same as the other migrations).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Migration\LegacyDb;
use Bayti\Api\Migration\MigrationLog;

$dryRun = in_array('--dry-run', $argv, true);

$app = Bootstrap::createApp();
$container = $app->getContainer();
if ($container === null) {
    fwrite(STDERR, "DI container not available\n");
    exit(1);
}

/** @var \Doctrine\ORM\EntityManagerInterface $em */
$em = $container->get(\Doctrine\ORM\EntityManagerInterface::class);
$conn = $em->getConnection();
$legacy = new LegacyDb();
$log = new MigrationLog($conn);

echo "===== migrate-style-products =====\n";
echo "  run_id: " . $log->getRunId() . "\n";
echo "  mode:   " . ($dryRun ? "DRY RUN (no writes committed)" : "LIVE") . "\n\n";

// ---------------------------------------------------------------------
// v3 id maps (legacy id -> v3 id)
// ---------------------------------------------------------------------
$styleMap = [];
foreach ($conn->fetchAllAssociative(
    'SELECT id, legacy_style_id FROM styles WHERE legacy_style_id IS NOT NULL'
) as $r) {
    $styleMap[(int) $r['legacy_style_id']] = (int) $r['id'];
}

$productMap = [];
foreach ($conn->fetchAllAssociative(
    'SELECT id, legacy_product_id FROM products WHERE legacy_product_id IS NOT NULL'
) as $r) {
    $productMap[(int) $r['legacy_product_id']] = (int) $r['id'];
}

echo "  v3 styles with a legacy id:   " . count($styleMap) . "\n";
echo "  v3 products with a legacy id: " . count($productMap) . "\n\n";

if (count($styleMap) === 0) {
    echo "  No migrated styles (styles.legacy_style_id all NULL). Nothing to backfill.\n";
    $legacy->close();
    exit(0);
}

// ---------------------------------------------------------------------
// Discover the legacy style->product membership source
// ---------------------------------------------------------------------
$joinCandidates    = ['stylist_products', 'style_products', 'styles_products', 'style_product', 'stylist_product', 'style_items', 'collection_products'];
$styleFkCandidates = ['style_id', 'stylist_id', 'styles_id', 'collections_id', 'collection_id'];
$productFkCandidates = ['product_id', 'products_id', 'prod_id'];
$orderCandidates   = ['display_order', 'sort_order', 'position', 'sequence', 'sort', 'ordering'];

$joinTable = null;
foreach ($joinCandidates as $t) {
    if ($legacy->tableExists($t)) {
        $joinTable = $t;
        break;
    }
}

/** @var list<array{0:int,1:int,2:?int}> $membership  [legacyStyleId, legacyProductId, legacyOrder|null] */
$membership = [];

if ($joinTable !== null) {
    $styleFk = null;
    foreach ($styleFkCandidates as $c) {
        if ($legacy->columnExists($joinTable, $c)) { $styleFk = $c; break; }
    }
    $productFk = null;
    foreach ($productFkCandidates as $c) {
        if ($legacy->columnExists($joinTable, $c)) { $productFk = $c; break; }
    }
    $orderCol = null;
    foreach ($orderCandidates as $c) {
        if ($legacy->columnExists($joinTable, $c)) { $orderCol = $c; break; }
    }

    if ($styleFk === null || $productFk === null) {
        echo "  Found join table '{$joinTable}' but could not identify its FK columns.\n";
        echo "    style_fk=" . ($styleFk ?? 'NOT FOUND') . "  product_fk=" . ($productFk ?? 'NOT FOUND') . "\n";
        echo "    Share `SHOW COLUMNS FROM {$joinTable}` and the candidate lists get adjusted.\n\n";
        $legacy->close();
        exit(0);
    }

    $sel = "{$styleFk} AS sid, {$productFk} AS pid" . ($orderCol !== null ? ", {$orderCol} AS ord" : "");
    $orderBy = $orderCol !== null ? "{$styleFk}, {$orderCol}" : "{$styleFk}";
    foreach ($legacy->fetchAll("SELECT {$sel} FROM `{$joinTable}` ORDER BY {$orderBy}") as $r) {
        $membership[] = [(int) $r['sid'], (int) $r['pid'], isset($r['ord']) ? (int) $r['ord'] : null];
    }
    echo "  Source: join table '{$joinTable}' "
        . "(style_fk={$styleFk}, product_fk={$productFk}, order=" . ($orderCol ?? 'n/a') . ") "
        . "— " . count($membership) . " legacy links.\n\n";
} else {
    // Shape B: CSV column on the legacy styles/stylist/collections table.
    $styleTableCandidates = ['styles', 'stylist', 'collections'];
    $csvColCandidates     = ['products', 'product_ids', 'items', 'product_list'];
    $idColCandidates      = ['id', 'style_id', 'styles_id', 'collections_id'];

    $srcTable = null;
    foreach ($styleTableCandidates as $t) {
        if ($legacy->tableExists($t)) { $srcTable = $t; break; }
    }
    $idCol = null;
    $csvCol = null;
    if ($srcTable !== null) {
        foreach ($idColCandidates as $c) {
            if ($legacy->columnExists($srcTable, $c)) { $idCol = $c; break; }
        }
        foreach ($csvColCandidates as $c) {
            if ($legacy->columnExists($srcTable, $c)) { $csvCol = $c; break; }
        }
    }

    if ($srcTable === null || $idCol === null || $csvCol === null) {
        echo "  Could not locate a legacy style->product source.\n";
        echo "    Probed join tables : " . implode(', ', $joinCandidates) . "\n";
        echo "    Probed CSV column  : on '" . ($srcTable ?? 'no styles table found') . "' tried "
            . implode(', ', $csvColCandidates) . "\n\n";
        echo "  Next step: on the legacy MySQL run\n";
        echo "    SHOW TABLES LIKE '%styl%';   SHOW TABLES LIKE '%collection%';\n";
        echo "  share the output, and the candidate lists at the top of this script get set.\n\n";
        $legacy->close();
        exit(0);
    }

    foreach ($legacy->fetchAll("SELECT {$idCol} AS sid, {$csvCol} AS csv FROM `{$srcTable}` ORDER BY {$idCol}") as $r) {
        $sid = (int) $r['sid'];
        $csv = (string) ($r['csv'] ?? '');
        $ord = 0;
        foreach (preg_split('/[,\s]+/', $csv, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $pidStr) {
            $pid = (int) trim((string) $pidStr);
            if ($pid > 0) {
                $membership[] = [$sid, $pid, $ord++];
            }
        }
    }
    echo "  Source: CSV column '{$srcTable}.{$csvCol}' — " . count($membership) . " links parsed.\n\n";
}

if (count($membership) === 0) {
    echo "  No membership rows found in the legacy source. Nothing to backfill.\n";
    $legacy->close();
    exit(0);
}

// ---------------------------------------------------------------------
// Backfill style_products (idempotent) then recompute total_price
// ---------------------------------------------------------------------
$inserted = 0;
$already = 0;
$skippedStyle = 0;
$skippedProduct = 0;
$nextOrder = [];        // v3 styleId => next display_order to assign when legacy order absent
$affectedStyles = [];   // set of touched v3 styleIds

$conn->beginTransaction();
try {
    foreach ($membership as [$legacyStyleId, $legacyProductId, $legacyOrder]) {
        if (!isset($styleMap[$legacyStyleId])) {
            $skippedStyle++;
            continue;
        }
        if (!isset($productMap[$legacyProductId])) {
            $skippedProduct++;
            $log->error('style_products', $legacyStyleId, "Legacy product {$legacyProductId} not migrated — link skipped");
            continue;
        }

        $styleId = $styleMap[$legacyStyleId];
        $productId = $productMap[$legacyProductId];
        $affectedStyles[$styleId] = true;

        $exists = $conn->fetchOne(
            'SELECT 1 FROM style_products WHERE style_id = ? AND product_id = ?',
            [$styleId, $productId]
        );
        if ($exists !== false) {
            $already++;
            continue;
        }

        if ($legacyOrder !== null) {
            $order = $legacyOrder;
        } else {
            if (!isset($nextOrder[$styleId])) {
                $maxOrder = $conn->fetchOne(
                    'SELECT COALESCE(MAX(display_order), -1) FROM style_products WHERE style_id = ?',
                    [$styleId]
                );
                $nextOrder[$styleId] = ((int) $maxOrder) + 1;
            }
            $order = $nextOrder[$styleId]++;
        }

        $conn->executeStatement(
            'INSERT INTO style_products (style_id, product_id, display_order) VALUES (?, ?, ?)',
            [$styleId, $productId, $order]
        );
        $inserted++;
    }

    // Recompute total_price for every touched style from v3 product prices.
    $repriced = 0;
    foreach (array_keys($affectedStyles) as $styleId) {
        $row = $conn->fetchAssociative(
            "SELECT COALESCE(SUM(COALESCE(p.sale_price, p.price)), 0)::numeric(10,2) AS total
               FROM style_products sp
               JOIN products p ON p.id = sp.product_id
              WHERE sp.style_id = ? AND p.is_active = TRUE",
            [$styleId]
        );
        $total = (string) ($row['total'] ?? '0.00');
        $conn->executeStatement(
            "UPDATE styles SET total_price = :total, updated_at = date_trunc('second', NOW()) WHERE id = :id",
            ['total' => $total, 'id' => $styleId]
        );
        $repriced++;
    }

    if ($dryRun) {
        $conn->rollBack();
    } else {
        $conn->commit();
    }
} catch (Throwable $e) {
    if ($conn->isTransactionActive()) {
        $conn->rollBack();
    }
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    $legacy->close();
    exit(1);
}

echo "  links inserted:           {$inserted}\n";
echo "  links already present:    {$already}\n";
echo "  skipped (style not in v3):   {$skippedStyle}\n";
echo "  skipped (product not in v3): {$skippedProduct}\n";
echo "  styles repriced:          {$repriced}\n";
echo "  affected styles:          " . count($affectedStyles) . "\n\n";
echo $dryRun
    ? "  DRY RUN — transaction rolled back, no changes persisted.\n"
    : "  Done — committed.\n";

$log->summary('style_products');
$legacy->close();
exit(0);
