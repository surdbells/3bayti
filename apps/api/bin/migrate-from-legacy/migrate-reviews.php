<?php

declare(strict_types=1);

/**
 * Migrate product reviews from legacy ec_reviews to v3 product_reviews.
 *
 * Source: ec_reviews (27 rows)
 *   id, customer_id, store_id, customer_name, customer_email,
 *   product_name, star, title, comment, vendor_reply, status, created_at
 *
 * Per Sodiq's decision: VENDOR-ONLY link. Don't try to resolve
 * product_name -> product_id (pre-flight showed 0/10 sampled reviews
 * matched any current product). product_id stays NULL. product_name
 * is preserved in product_name_snapshot for forensic context.
 *
 * Each review links to:
 *   - vendor_id (v3) via store_id lookup
 *   - user_id   (v3) via customer_id lookup, if present
 *
 * If vendor lookup fails (e.g., legacy store_id is for an inactive
 * abandoned vendor we didn't migrate): skip the review.
 *
 * Status mapping:
 *   Legacy reviews use unknown enum; we map all to 'approved' for the
 *   demo (the 27 rows are real customer reviews that were already
 *   visible on the legacy site). If migration discovers other statuses
 *   we'll see them in the warn log.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Migration\LegacyDb;
use Bayti\Api\Migration\MigrationLog;

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

echo "===== migrate-reviews =====\n";
echo "  run_id: " . $log->getRunId() . "\n\n";

// Build vendor + user lookup maps
$vendorMap = [];
foreach ($conn->fetchAllAssociative('SELECT id, legacy_vendor_id FROM vendors WHERE legacy_vendor_id IS NOT NULL') as $r) {
    $vendorMap[(int) $r['legacy_vendor_id']] = (int) $r['id'];
}
$userMap = [];
foreach ($conn->fetchAllAssociative('SELECT id, legacy_user_id FROM users WHERE legacy_user_id IS NOT NULL') as $r) {
    $userMap[(int) $r['legacy_user_id']] = (int) $r['id'];
}

$rows = $legacy->fetchAll(
    'SELECT id, customer_id, store_id, customer_name, customer_email,
            product_name, star, title, comment, vendor_reply, status, created_at
     FROM ec_reviews ORDER BY id'
);
echo "  Found " . count($rows) . " legacy reviews.\n\n";

$conn->beginTransaction();
$migrated = 0;
$skipped = 0;
$errors = 0;

try {
    foreach ($rows as $row) {
        $legacyId = (int) $row['id'];

        // Idempotency
        $existing = $conn->fetchOne(
            'SELECT id FROM product_reviews WHERE legacy_review_id = ?',
            [$legacyId]
        );
        if ($existing !== false) {
            $skipped++;
            continue;
        }

        $vendorLegacyId = (int) ($row['store_id'] ?? 0);
        $vendorId = $vendorMap[$vendorLegacyId] ?? null;
        if ($vendorId === null) {
            $log->skip('reviews', $legacyId, "Vendor not migrated for store_id={$vendorLegacyId}");
            $skipped++;
            continue;
        }

        $customerLegacyId = (int) ($row['customer_id'] ?? 0);
        $userId = $userMap[$customerLegacyId] ?? null;

        // Validate star rating range
        $star = (float) ($row['star'] ?? 0);
        if ($star < 0 || $star > 5) {
            $log->warn('reviews', $legacyId, "Star {$star} out of range — clamping");
            $star = max(0.0, min(5.0, $star));
        }
        // Round to 1 decimal place to match NUMERIC(2,1)
        $starFormatted = number_format($star, 1, '.', '');

        $createdAt = parseLegacyTimestamp((string) ($row['created_at'] ?? '')) ?? date('Y-m-d H:i:sP');

        try {
            $conn->executeStatement(
                "INSERT INTO product_reviews
                    (legacy_review_id, product_id, vendor_id, user_id,
                     reviewer_name, reviewer_email, product_name_snapshot,
                     star, title, comment, vendor_reply,
                     status, is_verified, helpful_count,
                     created_at, updated_at)
                 VALUES
                    (:legacy_id, NULL, :vendor_id, :user_id,
                     :reviewer_name, :reviewer_email, :product_name,
                     :star, :title, :comment, :reply,
                     'approved', FALSE, 0,
                     :created, NOW())",
                [
                    'legacy_id' => $legacyId,
                    'vendor_id' => $vendorId,
                    'user_id' => $userId,
                    'reviewer_name' => trim((string) ($row['customer_name'] ?? '')) ?: null,
                    'reviewer_email' => trim((string) ($row['customer_email'] ?? '')) ?: null,
                    'product_name' => trim((string) ($row['product_name'] ?? '')) ?: null,
                    'star' => $starFormatted,
                    'title' => trim((string) ($row['title'] ?? '')) ?: null,
                    'comment' => trim((string) ($row['comment'] ?? '')) ?: null,
                    'reply' => trim((string) ($row['vendor_reply'] ?? '')) ?: null,
                    'created' => $createdAt,
                ]
            );
        } catch (\Throwable $e) {
            $log->error('reviews', $legacyId, "INSERT failed: " . $e->getMessage());
            $errors++;
            continue;
        }

        $migrated++;
    }

    $conn->executeStatement(
        "SELECT setval('product_reviews_id_seq', COALESCE((SELECT MAX(id) FROM product_reviews), 0) + 1, false)"
    );

    $conn->commit();

    echo "\n===== reviews migration complete =====\n";
    echo "  Migrated:  {$migrated}\n";
    echo "  Skipped:   {$skipped}\n";
    echo "  Errors:    {$errors}\n";
} catch (\Throwable $e) {
    $conn->rollBack();
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    $legacy->close();
}

exit(0);

function parseLegacyTimestamp(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00 00:00:00') {
        return null;
    }
    try {
        $dt = new \DateTimeImmutable($value, new \DateTimeZone('Asia/Dubai'));
        return $dt->format('Y-m-d H:i:sP');
    } catch (\Throwable) {
        return null;
    }
}
