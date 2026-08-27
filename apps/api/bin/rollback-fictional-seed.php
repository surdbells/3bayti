#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 3bayti, rollback fictional seed catalog data
 * =============================================
 *
 * Day 1 of 10-day rollout. Removes the fictional seed data created
 * by bin/seed-catalog.php so the migration on Day 4 has a clean slate.
 *
 * What gets deleted
 * -----------------
 *  - All rows from `brands` (entire table; parked entity)
 *  - All rows from `categories` (fictional 9-node tree)
 *  - All rows from `vendors` (fictional 3 vendors)
 *  - All rows from `audit_log` that reference these subject types
 *    (don't want orphaned audit entries pointing at deleted rows)
 *
 * What stays
 * ----------
 *  - Schema (no DROP TABLE)
 *  - Users (including admin user id=11)
 *  - Sessions, refresh tokens (auth still works)
 *  - Audit log entries for non-catalog subjects (User actions)
 *
 * Safety
 * ------
 *  - Confirmation prompt (skip with --yes)
 *  - Transaction wraps everything, all-or-nothing
 *  - Prints what will be deleted BEFORE doing it
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Doctrine\ORM\EntityManagerInterface;

$skipPrompt = in_array('--yes', $argv, true);

$app = Bootstrap::createApp();
$container = $app->getContainer();
if ($container === null) {
    fwrite(STDERR, "DI container not available\n");
    exit(1);
}

/** @var EntityManagerInterface $em */
$em = $container->get(EntityManagerInterface::class);
$conn = $em->getConnection();

echo "==========================================\n";
echo "Rollback fictional seed catalog data\n";
echo "==========================================\n\n";

// Pre-flight: show what we're about to delete
$brandCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM brands');
$categoryCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM categories');
$vendorCount = (int) $conn->fetchOne('SELECT COUNT(*) FROM vendors');
$auditCount = (int) $conn->fetchOne(
    "SELECT COUNT(*) FROM audit_log WHERE subject_type IN ('Brand', 'Vendor', 'Category')"
);

echo "Current state:\n";
printf("  brands:     %d rows\n", $brandCount);
printf("  categories: %d rows\n", $categoryCount);
printf("  vendors:    %d rows\n", $vendorCount);
printf("  audit_log entries for above: %d rows\n", $auditCount);
echo "\n";

if ($brandCount + $categoryCount + $vendorCount === 0) {
    echo "No fictional seed data to remove. Already clean.\n";
    exit(0);
}

if (!$skipPrompt) {
    echo "Proceed with deletion? [y/N]: ";
    $answer = trim(fgets(STDIN) ?: '');
    if (strtolower($answer) !== 'y') {
        echo "Cancelled.\n";
        exit(0);
    }
}

echo "\nDeleting...\n";

$conn->beginTransaction();
try {
    // Order matters because of FK constraints.
    // categories has self-reference (parent_id → categories.id), so
    // truncate via DELETE (not TRUNCATE, that would require disabling FKs).
    // The self-reference is ON DELETE SET NULL so order within doesn't matter.

    // 1. Audit log entries for catalog subjects (no FK, just dangling refs)
    $conn->executeStatement(
        "DELETE FROM audit_log WHERE subject_type IN ('Brand', 'Vendor', 'Category')"
    );
    echo "  audit_log catalog entries: deleted\n";

    // 2. Brands (no FKs into it yet, products won't reference it post-rollback)
    $conn->executeStatement('DELETE FROM brands');
    echo "  brands: deleted\n";

    // 3. Categories, self-referencing FK is ON DELETE SET NULL, so order
    //    within DELETE doesn't matter. PG handles cascading correctly.
    $conn->executeStatement('DELETE FROM categories');
    echo "  categories: deleted\n";

    // 4. Vendors
    $conn->executeStatement('DELETE FROM vendors');
    echo "  vendors: deleted\n";

    // 5. Reset sequences so newly migrated rows get id=1, 2, 3...
    //    instead of starting from the next-after-deleted-max.
    //    This makes the migrated data easier to reason about.
    $conn->executeStatement('ALTER SEQUENCE brands_id_seq RESTART WITH 1');
    $conn->executeStatement('ALTER SEQUENCE categories_id_seq RESTART WITH 1');
    $conn->executeStatement('ALTER SEQUENCE vendors_id_seq RESTART WITH 1');
    echo "  sequences reset to 1\n";

    $conn->commit();

    echo "\n✓ Rollback complete.\n\n";

    // Post-verification
    $b = (int) $conn->fetchOne('SELECT COUNT(*) FROM brands');
    $c = (int) $conn->fetchOne('SELECT COUNT(*) FROM categories');
    $v = (int) $conn->fetchOne('SELECT COUNT(*) FROM vendors');
    printf("Verification: brands=%d, categories=%d, vendors=%d (expected: 0/0/0)\n", $b, $c, $v);

    if ($b + $c + $v > 0) {
        fwrite(STDERR, "WARN: rollback did not result in empty tables\n");
        exit(1);
    }

} catch (Throwable $e) {
    $conn->rollBack();
    fwrite(STDERR, "ERROR: " . $e->getMessage() . "\n");
    fwrite(STDERR, "Transaction rolled back. Database unchanged.\n");
    exit(1);
}

exit(0);
