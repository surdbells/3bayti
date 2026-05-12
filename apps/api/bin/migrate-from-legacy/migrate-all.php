<?php

declare(strict_types=1);

/**
 * Orchestrator — runs the full migration in correct dependency order.
 *
 * Usage:
 *   php bin/migrate-from-legacy/migrate-all.php              # default: skip seed rollback
 *   php bin/migrate-from-legacy/migrate-all.php --wipe-seed  # also wipe fictional seed first
 *
 * Re-runnable safely. Each migration script uses UPSERT semantics:
 *   - First run: INSERT all rows
 *   - Subsequent runs: pick up new rows + apply edits to existing rows
 *   - Stable fields preserved on re-sync: product slugs, vendor slugs,
 *     user emails (per Sodiq's decision; see day-4-migration.md §Re-sync semantics)
 *
 * Flags:
 *   --wipe-seed   Run rollback-fictional-seed.php first. Use on the
 *                 INITIAL migration only. Subsequent re-syncs should
 *                 NOT use this flag — it would delete real migrated
 *                 data (rollback script targets all rows in vendors,
 *                 categories, brands, not just the seed).
 *                 NOTE: Currently the rollback wipes brands+categories+vendors
 *                 INCLUDING migrated ones. We rely on the runbook
 *                 explicitly stating this flag is "first run only".
 *
 *   --dry-run     Not yet implemented.
 *
 * Order matters
 * =============
 *
 *   1. (optional) Rollback fictional seed (categories, vendors, brands)
 *   2. Migrate categories  (no dependencies)
 *   3. Migrate users       (no dependencies; produces legacy_user_id map)
 *   4. Migrate vendors     (depends on users via owner_user_id FK)
 *   5. Migrate products    (depends on vendors AND categories)
 *   6. Migrate reviews     (depends on vendors AND users)
 */

$wipeSeed = in_array('--wipe-seed', $argv, true);
// --skip-seed retained for backward compatibility — now the default
$skipSeedFlag = in_array('--skip-seed', $argv, true);
$dryRun = in_array('--dry-run', $argv, true);

if ($dryRun) {
    fwrite(STDERR, "--dry-run is not yet implemented. Each script wraps writes in a transaction; cancel between scripts to bail out.\n");
    exit(1);
}

$binDir = __DIR__;
$apiRoot = dirname($binDir, 2);

echo "============================================================\n";
echo " 3bayti legacy data migration\n";
echo "============================================================\n\n";

$start = microtime(true);
$step = 0;

if ($wipeSeed) {
    runStep(++$step, 'rollback fictional seed', $apiRoot . '/bin/rollback-fictional-seed.php', ['--yes']);
} else {
    echo "[step " . (++$step) . "] SKIPPED rollback-fictional-seed (pass --wipe-seed to run; only use on INITIAL migration)\n\n";
}

runStep(++$step, 'migrate-categories',  $binDir . '/migrate-categories.php');
runStep(++$step, 'migrate-users',       $binDir . '/migrate-users.php');
runStep(++$step, 'migrate-vendors',     $binDir . '/migrate-vendors.php');
runStep(++$step, 'migrate-products',    $binDir . '/migrate-products.php');
runStep(++$step, 'migrate-reviews',     $binDir . '/migrate-reviews.php');

$elapsed = microtime(true) - $start;
printf("\n============================================================\n");
printf(" All steps complete in %.1fs\n", $elapsed);
printf("============================================================\n");

function runStep(int $step, string $name, string $script, array $extra = []): void
{
    $cmd = sprintf(
        'php %s %s',
        escapeshellarg($script),
        implode(' ', array_map('escapeshellarg', $extra))
    );

    echo "============================================================\n";
    echo "[step {$step}] {$name}\n";
    echo "  cmd: {$cmd}\n";
    echo "============================================================\n";

    $stepStart = microtime(true);
    passthru($cmd, $exitCode);
    $stepElapsed = microtime(true) - $stepStart;

    if ($exitCode !== 0) {
        fwrite(STDERR, "\n[step {$step}] FAILED with exit code {$exitCode}\n");
        fwrite(STDERR, "Stopping orchestrator. Fix the issue, then re-run with --skip-seed\n");
        fwrite(STDERR, "to skip the rollback step (the migrated rows will be detected as\n");
        fwrite(STDERR, "already-present and skipped).\n");
        exit($exitCode);
    }

    printf("[step %d] DONE in %.1fs\n\n", $step, $stepElapsed);
}
