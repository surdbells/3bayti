<?php

declare(strict_types=1);

/**
 * Image migration — copies legacy product images and vendor logos
 * to local Flysystem storage and updates the URL columns in PostgreSQL.
 *
 * Source
 * ------
 * Legacy images live at https://api.3bayti.ae/vendors/products/{filename}
 * (or other paths if the full URL is already stored).
 * This script fetches each image via HTTP and writes it through
 * Flysystem (LocalFilesystemAdapter → apps/api/var/uploads/).
 *
 * Alternatively, if --ssh-copy is provided (see below), the script
 * reads files directly via the local filesystem path on the same
 * or mounted server — zero HTTP overhead.
 *
 * Idempotent
 * ----------
 * A product/vendor whose URL already starts with UPLOADS_PUBLIC_URL
 * is skipped. Re-running is safe at any time.
 *
 * Usage
 * -----
 *   php migrate-images.php                  # migrate all products + vendors
 *   php migrate-images.php --dry-run        # probe first 10, print plan
 *   php migrate-images.php --products-only  # skip vendor logos/covers
 *   php migrate-images.php --vendors-only   # skip products
 *   php migrate-images.php --limit=50       # cap total images processed
 *   php migrate-images.php --product-id=123 # single product test
 *   php migrate-images.php --vendor-id=5    # single vendor test
 *   php migrate-images.php --ssh-copy=/www/legacy/vendors/products
 *                                           # read from local disk path
 *                                           # instead of HTTP fetch
 *
 * Environment
 * -----------
 * Reads UPLOADS_PUBLIC_URL (or falls back to APP_URL + /uploads).
 * Reads LEGACY_PRODUCT_IMAGE_HOST for the legacy URL prefix.
 * These must be set in apps/api/.env before running.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Domain\Media\ImageStorageService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

// ── Parse CLI args ───────────────────────────────────────────────────────────

$dryRun      = in_array('--dry-run',       $argv, true);
$productsOnly= in_array('--products-only', $argv, true);
$vendorsOnly = in_array('--vendors-only',  $argv, true);

$limit     = null;
$productId = null;
$vendorId  = null;
$sshCopy   = null;

foreach ($argv as $arg) {
    if (str_starts_with($arg, '--limit='))      $limit     = (int) substr($arg, 8);
    if (str_starts_with($arg, '--product-id=')) $productId = (int) substr($arg, 13);
    if (str_starts_with($arg, '--vendor-id='))  $vendorId  = (int) substr($arg, 12);
    if (str_starts_with($arg, '--ssh-copy='))   $sshCopy   = substr($arg, 11);
}

echo "============================================================\n";
echo " 3bayti image migration → local Flysystem storage\n";
echo "============================================================\n\n";
if ($dryRun) echo "  [DRY RUN] — no writes will occur\n\n";

$start = microtime(true);

// ── Bootstrap ────────────────────────────────────────────────────────────────

try {
    $app       = Bootstrap::createApp();
    $container = $app->getContainer();
    if ($container === null) {
        fwrite(STDERR, "DI container not available\n");
        exit(1);
    }

    /** @var EntityManagerInterface $em */
    $em   = $container->get(EntityManagerInterface::class);
    $conn = $em->getConnection();

    /** @var ImageStorageService $storage */
    $storage = $container->get(ImageStorageService::class);

} catch (\Throwable $e) {
    fwrite(STDERR, "Bootstrap failed: {$e->getMessage()}\n");
    exit(1);
}

$uploadsBase   = rtrim(ImageStorageService::publicUrl(''), '/');
$legacyBase    = rtrim($_ENV['LEGACY_PRODUCT_IMAGE_HOST'] ?? 'https://api.3bayti.ae/vendors/products', '/');

echo "  Uploads base URL : {$uploadsBase}\n";
echo "  Legacy base URL  : {$legacyBase}\n";
if ($sshCopy) echo "  SSH/disk copy    : {$sshCopy}\n";
echo "\n";

$stats = ['product_images' => 0, 'product_skipped' => 0,
          'vendor_images'  => 0, 'vendor_skipped'  => 0,
          'errors'         => 0];

// ── Helper: fetch image bytes ────────────────────────────────────────────────

function fetchBytes(string $url, ?string $diskBase): string|false
{
    if ($diskBase !== null) {
        // Extract filename from URL and read from local disk
        $filename = basename(parse_url($url, PHP_URL_PATH) ?? '');
        $path     = rtrim($diskBase, '/') . '/' . $filename;
        if (!file_exists($path)) {
            fwrite(STDERR, "    disk file not found: {$path}\n");
            return false;
        }
        return file_get_contents($path);
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 20,
            'user_agent' => '3bayti-image-migrator/1.0',
        ],
        'ssl' => ['verify_peer' => true],
    ]);
    $bytes = @file_get_contents($url, false, $ctx);
    if ($bytes === false) {
        fwrite(STDERR, "    HTTP fetch failed: {$url}\n");
        return false;
    }
    return $bytes;
}

// ── Helper: determine storage extension from URL or bytes ────────────────────

function extFromUrl(string $url): string
{
    $ext = strtolower(pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
    $map = ['jpg' => 'jpg', 'jpeg' => 'jpg', 'png' => 'png', 'webp' => 'webp', 'gif' => 'gif'];
    return $map[$ext] ?? 'jpg';
}

// ── Helper: migrate a single image URL ──────────────────────────────────────

function migrateUrl(
    string $url,
    string $storagePath,
    ImageStorageService $storage,
    string $uploadsBase,
    ?string $diskBase,
    bool $dryRun,
): string|false {
    // Already on new storage
    if (str_starts_with($url, $uploadsBase)) {
        return null; // null = skip (already done)
    }
    // Empty / placeholder
    if ($url === '' || str_contains($url, 'placeholder')) {
        return null;
    }

    if ($dryRun) {
        echo "    [DRY] would fetch {$url}\n";
        echo "          → {$storagePath}\n";
        return $uploadsBase . '/' . $storagePath;
    }

    $bytes = fetchBytes($url, $diskBase);
    if ($bytes === false || strlen($bytes) === 0) return false;

    try {
        $stored = $storage->storeRaw($bytes, $storagePath);
        return $stored->publicUrl();
    } catch (\Throwable $e) {
        fwrite(STDERR, "    Flysystem write failed ({$storagePath}): {$e->getMessage()}\n");
        return false;
    }
}

// ── PRODUCTS ─────────────────────────────────────────────────────────────────

if (!$vendorsOnly) {
    echo "----- Products -----\n";

    $where = 'WHERE (primary_image_url IS NOT NULL OR images IS NOT NULL)';
    $params = [];
    if ($productId !== null) {
        $where .= ' AND id = :pid';
        $params['pid'] = $productId;
    }
    $limitSql = $limit !== null ? "LIMIT {$limit}" : '';

    $rows = $conn->fetchAllAssociative(
        "SELECT id, slug, primary_image_url, images FROM products {$where} ORDER BY id {$limitSql}",
        $params
    );

    echo "  Found " . count($rows) . " products to check.\n\n";

    foreach ($rows as $row) {
        $pid      = (int) $row['id'];
        $slug     = preg_replace('/[^a-z0-9\-]/', '-', strtolower((string) ($row['slug'] ?? "product-{$pid}")));
        $vendorDir= 'unknown';

        // Try to get the vendor slug from vendor relation
        $vRow = $conn->fetchAssociative(
            "SELECT v.slug FROM products p
             LEFT JOIN vendors v ON v.id = p.vendor_id
             WHERE p.id = :pid",
            ['pid' => $pid]
        );
        if ($vRow && !empty($vRow['slug'])) {
            $vendorDir = preg_replace('/[^a-z0-9\-]/', '-', strtolower((string) $vRow['slug']));
        }

        $primaryUrl = (string) ($row['primary_image_url'] ?? '');
        $imagesRaw  = $row['images'];
        $images     = [];
        if (is_string($imagesRaw) && $imagesRaw !== '' && $imagesRaw !== 'null') {
            $images = json_decode($imagesRaw, true) ?? [];
        }

        $newPrimary = null;
        $newImages  = [];
        $changed    = false;

        // Primary image
        if ($primaryUrl !== '') {
            $ext   = extFromUrl($primaryUrl);
            $ulid  = strtolower(bin2hex(random_bytes(10)));
            $sPath = "products/{$vendorDir}/{$ulid}.{$ext}";

            $result = migrateUrl($primaryUrl, $sPath, $storage, $uploadsBase, $sshCopy, $dryRun);
            if ($result === null) {
                $newPrimary = $primaryUrl; // skip — already migrated
                $stats['product_skipped']++;
            } elseif ($result === false) {
                $stats['errors']++;
                $newPrimary = $primaryUrl;
            } else {
                $newPrimary = $result;
                $stats['product_images']++;
                $changed = true;
                echo "  ✓ product {$pid} primary → {$result}\n";
            }
        }

        // Gallery images
        foreach ($images as $imgUrl) {
            $imgUrl = (string) $imgUrl;
            if ($imgUrl === '') { continue; }
            $ext   = extFromUrl($imgUrl);
            $ulid  = strtolower(bin2hex(random_bytes(10)));
            $sPath = "products/{$vendorDir}/{$ulid}.{$ext}";
            $result = migrateUrl($imgUrl, $sPath, $storage, $uploadsBase, $sshCopy, $dryRun);
            if ($result === null) {
                $newImages[] = $imgUrl;
            } elseif ($result === false) {
                $stats['errors']++;
                $newImages[] = $imgUrl;
            } else {
                $newImages[] = $result;
                $stats['product_images']++;
                $changed = true;
            }
        }

        // Persist updated URLs
        if ($changed && !$dryRun) {
            $conn->executeStatement(
                "UPDATE products SET primary_image_url = :pri, images = :imgs::jsonb WHERE id = :id",
                [
                    'pri'  => $newPrimary,
                    'imgs' => json_encode($newImages, JSON_UNESCAPED_SLASHES),
                    'id'   => $pid,
                ]
            );
            // Also update product_images table rows
            foreach ($newImages as $i => $url) {
                $conn->executeStatement(
                    "UPDATE product_images SET url = :url
                     WHERE product_id = :pid AND display_order = :ord",
                    ['url' => $url, 'pid' => $pid, 'ord' => $i]
                );
            }
        }
    }

    echo "\n  Products: {$stats['product_images']} migrated, "
         . "{$stats['product_skipped']} skipped, "
         . "{$stats['errors']} errors\n\n";
}

// ── VENDORS ──────────────────────────────────────────────────────────────────

if (!$productsOnly) {
    echo "----- Vendors -----\n";

    $where  = 'WHERE (logo_url IS NOT NULL OR cover_image_url IS NOT NULL OR legacy_logo_data_url IS NOT NULL)';
    $params = [];
    if ($vendorId !== null) {
        $where .= ' AND id = :vid';
        $params['vid'] = $vendorId;
    }
    $limitSql = $limit !== null ? "LIMIT {$limit}" : '';

    $rows = $conn->fetchAllAssociative(
        "SELECT id, slug, logo_url, cover_image_url, legacy_logo_data_url
         FROM vendors {$where} ORDER BY id {$limitSql}",
        $params
    );

    echo "  Found " . count($rows) . " vendors to check.\n\n";

    foreach ($rows as $row) {
        $vid  = (int) $row['id'];
        $slug = preg_replace('/[^a-z0-9\-]/', '-', strtolower((string) ($row['slug'] ?? "vendor-{$vid}")));

        // ── Logo from legacy_logo_data_url (base64 blob) ──────────────────
        $blobCol = (string) ($row['legacy_logo_data_url'] ?? '');
        if ($blobCol !== '' && str_starts_with($blobCol, 'data:image/')) {
            $commaPos = strpos($blobCol, ',');
            if ($commaPos !== false) {
                $meta      = substr($blobCol, 0, $commaPos);
                $b64data   = substr($blobCol, $commaPos + 1);
                $bytes     = base64_decode($b64data, true);
                $ext       = str_contains($meta, 'png') ? 'png' : 'jpg';
                $sPath     = "vendors/{$slug}/logo.{$ext}";

                if ($bytes !== false && strlen($bytes) > 0 && !$dryRun) {
                    try {
                        $stored = $storage->storeRaw($bytes, $sPath);
                        $newLogoUrl = $stored->publicUrl();
                        $conn->executeStatement(
                            "UPDATE vendors SET logo_url = :url, legacy_logo_data_url = NULL WHERE id = :id",
                            ['url' => $newLogoUrl, 'id' => $vid]
                        );
                        echo "  ✓ vendor {$vid} logo (from blob) → {$newLogoUrl}\n";
                        $stats['vendor_images']++;
                    } catch (\Throwable $e) {
                        fwrite(STDERR, "  ✗ vendor {$vid} logo blob write failed: {$e->getMessage()}\n");
                        $stats['errors']++;
                    }
                } elseif ($dryRun) {
                    echo "  [DRY] vendor {$vid} logo blob → vendors/{$slug}/logo.{$ext}\n";
                }
            }
        }

        // ── Logo from legacy URL ──────────────────────────────────────────
        $logoUrl = (string) ($row['logo_url'] ?? '');
        if ($logoUrl !== '' && !str_starts_with($logoUrl, $uploadsBase)) {
            $ext   = extFromUrl($logoUrl);
            $sPath = "vendors/{$slug}/logo.{$ext}";
            $result = migrateUrl($logoUrl, $sPath, $storage, $uploadsBase, $sshCopy, $dryRun);
            if ($result !== null && $result !== false && !$dryRun) {
                $conn->executeStatement(
                    "UPDATE vendors SET logo_url = :url WHERE id = :id",
                    ['url' => $result, 'id' => $vid]
                );
                echo "  ✓ vendor {$vid} logo (from URL) → {$result}\n";
                $stats['vendor_images']++;
            } elseif ($result === null) {
                $stats['vendor_skipped']++;
            } else {
                $stats['errors']++;
            }
        }

        // ── Cover image from legacy URL ───────────────────────────────────
        $coverUrl = (string) ($row['cover_image_url'] ?? '');
        if ($coverUrl !== '' && !str_starts_with($coverUrl, $uploadsBase)) {
            $ext   = extFromUrl($coverUrl);
            $sPath = "vendors/{$slug}/cover.{$ext}";
            $result = migrateUrl($coverUrl, $sPath, $storage, $uploadsBase, $sshCopy, $dryRun);
            if ($result !== null && $result !== false && !$dryRun) {
                $conn->executeStatement(
                    "UPDATE vendors SET cover_image_url = :url WHERE id = :id",
                    ['url' => $result, 'id' => $vid]
                );
                echo "  ✓ vendor {$vid} cover → {$result}\n";
                $stats['vendor_images']++;
            } elseif ($result === null) {
                $stats['vendor_skipped']++;
            } else {
                $stats['errors']++;
            }
        }
    }

    echo "\n  Vendors: {$stats['vendor_images']} migrated, "
         . "{$stats['vendor_skipped']} skipped, "
         . "{$stats['errors']} errors\n\n";
}

// ── Summary ──────────────────────────────────────────────────────────────────

$elapsed = round(microtime(true) - $start, 1);
echo "============================================================\n";
echo " Image migration complete in {$elapsed}s\n";
echo "============================================================\n";
echo "  Product images : {$stats['product_images']} moved, {$stats['product_skipped']} skipped\n";
echo "  Vendor images  : {$stats['vendor_images']} moved, {$stats['vendor_skipped']} skipped\n";
echo "  Errors         : {$stats['errors']}\n";
if ($stats['errors'] > 0) {
    echo "\n  Check STDERR output above for error details.\n";
    echo "  Re-run to retry errored images — skips already-migrated URLs.\n";
}
