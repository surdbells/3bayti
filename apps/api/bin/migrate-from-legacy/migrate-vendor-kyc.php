<?php

declare(strict_types=1);

/**
 * Backfill vendor KYC documents from the legacy MySQL `users` table.
 *
 * The legacy platform stored a store owner's identity documents on the USER
 * row (users.id_front / id_back / license_doc, base64 data URLs). The v3
 * user/vendor migration copied profile + registration fields but NOT these
 * documents, and the earlier Postgres-only backfill (Version20260826000001)
 * found nothing because the data lives in legacy MySQL. So the compliance
 * screens showed every migrated vendor's ID/licence as "missing".
 *
 * This copies each document into the v3 vendors row, mapping
 *   legacy users.user_id  ->  v3 users.legacy_user_id  ->  vendors.owner_user_id
 * and only filling a v3 column that's still empty (never clobbering a doc the
 * vendor re-uploaded in v3). Read-only against legacy MySQL; writes only v3
 * vendors. Idempotent, re-running only fills blanks.
 *
 * The copied values keep the legacy shape (usually a base64 data URL); the
 * compliance serve path already renders those. Run compliance:localize-documents
 * afterwards to move the base64 blobs into private storage.
 *
 * Requires the LEGACY_MYSQL_* env vars (same as the other migrate scripts).
 *
 * Usage:
 *   php migrate-vendor-kyc.php            # copy where the v3 column is empty
 *   php migrate-vendor-kyc.php --dry-run  # report counts, no writes
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Migration\LegacyDb;
use Doctrine\ORM\EntityManagerInterface;

$dryRun = in_array('--dry-run', $argv, true);

echo "============================================================\n";
echo " Vendor KYC document backfill (legacy MySQL -> v3 vendors)\n";
echo "============================================================\n\n";

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

    echo "Mode: " . ($dryRun ? "DRY RUN (no writes)" : "IMPORT") . "\n\n";

    // v3 vendor column => legacy users column. Keep only the legacy columns
    // that actually exist, so an unexpected schema doesn't hard-fail.
    $candidates = [
        'id_front'    => 'id_front',
        'id_back'     => 'id_back',
        'license_doc' => 'license_doc',
    ];
    $cols = [];
    foreach ($candidates as $v3col => $legacyCol) {
        if ($legacy->columnExists('users', $legacyCol)) {
            $cols[$v3col] = $legacyCol;
        } else {
            echo "  ! legacy users.{$legacyCol} not found — that document is skipped\n";
        }
    }
    if ($cols === []) {
        fwrite(STDERR, "No known KYC columns on legacy users. Run `SHOW COLUMNS FROM users`\n");
        fwrite(STDERR, "on the legacy DB and update the \$candidates map in this script.\n");
        exit(1);
    }

    $selectCols = implode(', ', array_map(static fn (string $c) => "`{$c}`", array_values($cols)));
    $whereAny = implode(' OR ', array_map(
        static fn (string $c) => "(`{$c}` IS NOT NULL AND `{$c}` <> '')",
        array_values($cols),
    ));
    $sql = "SELECT user_id, {$selectCols} FROM users WHERE {$whereAny} ORDER BY user_id";

    $scanned = 0;
    $vendorsUpdated = 0;
    $docsCopied = 0;
    $noVendor = 0;

    foreach ($legacy->iterate($sql) as $row) {
        $scanned++;
        $legacyUserId = (int) $row['user_id'];

        // Resolve the v3 vendor whose owner user came from this legacy user.
        $vendor = $conn->fetchAssociative(
            'SELECT v.id, v.id_front, v.id_back, v.license_doc
             FROM vendors v
             JOIN users u ON u.id = v.owner_user_id
             WHERE u.legacy_user_id = ?',
            [$legacyUserId],
        );
        if ($vendor === false) {
            $noVendor++;
            continue;
        }

        $sets = [];
        $params = [];
        foreach ($cols as $v3col => $legacyCol) {
            $legacyVal = trim((string) ($row[$legacyCol] ?? ''));
            $v3Val = trim((string) ($vendor[$v3col] ?? ''));
            if ($legacyVal !== '' && $v3Val === '') {
                $sets[] = "{$v3col} = ?";
                $params[] = $legacyVal;
                $docsCopied++;
            }
        }
        if ($sets === []) {
            continue; // vendor already has (or shares) all docs
        }

        if (!$dryRun) {
            $params[] = (int) $vendor['id'];
            $conn->executeStatement(
                'UPDATE vendors SET ' . implode(', ', $sets) . ' WHERE id = ?',
                $params,
            );
        }
        $vendorsUpdated++;
    }

    echo "\n" . ($dryRun ? "DRY RUN — " : "") . "Done.\n";
    echo "  Legacy users with documents scanned:      {$scanned}\n";
    echo "  Vendors " . ($dryRun ? "that WOULD be updated:" : "updated:            ") . " {$vendorsUpdated}\n";
    echo "  Documents " . ($dryRun ? "that WOULD be copied:" : "copied:            ") . " {$docsCopied}\n";
    echo "  Legacy users with docs but NO v3 vendor:  {$noVendor}\n";

    $legacy->close();
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, "FAILED: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
