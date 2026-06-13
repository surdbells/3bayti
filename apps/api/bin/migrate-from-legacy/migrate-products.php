<?php

declare(strict_types=1);

/**
 * Migrate products from legacy MySQL to v3 Postgres.
 *
 * Source: `products` (2,165 rows: 1928 published + 235 deleted + 2 draft)
 *
 * Major transformations
 * =====================
 *
 * Sizes (22 boolean columns -> JSON array)
 *   Legacy:  size_xs..size_xxl + size_50..size_64 + size_custom (22 cols)
 *   v3:      available_sizes JSONB array of strings, e.g. ["S","M","L","52"]
 *
 *   Mapping: size_xs=1 -> "XS", size_50=1 -> "50", etc.
 *   size_custom=1 -> "CUSTOM".
 *
 * Colors (comma-separated varchar -> JSON array)
 *   Legacy:  varchar(500) "black, red, blue"
 *   v3:      available_colors JSONB ["black","red","blue"]
 *
 *   Trim each, drop empties.
 *
 * Status mapping
 *   Legacy 'published' -> v3 'active'   (1928 rows)
 *   Legacy 'deleted'   -> v3 'soft_deleted' (235 rows)
 *   Legacy 'draft'     -> v3 'draft'    (2 rows)
 *   is_active = (status == 'active')
 *
 * Images
 *   primary_image_url <- legacy image_1 (a file path under products_images/)
 *   images JSONB <- parsed from legacy.images (LONGTEXT JSON array)
 *
 *   Image URLs are built by prepending legacy host:
 *     'products_images/abc.jpg' -> 'https://api.3bayti.ae/vendors/products/abc.jpg'
 *
 *   For now we keep just the URLs in JSONB. Per-image metadata records
 *   in product_images table are deferred to M5 image migration; for the
 *   demo, Product.images JSONB suffices.
 *
 * Slug generation
 *   Legacy has no slug column. We generate from name with collision suffix.
 *   Collisions tracked in-memory; product names like "Abaya" with 30 vendors
 *   will produce "abaya", "abaya-2", "abaya-3", etc.
 *
 * Vendor + Category resolution
 *   Legacy store_id -> v3 vendor_id via vendors.legacy_vendor_id lookup.
 *   Legacy category_id -> v3 category_id via categories.legacy_category_id.
 *
 *   If vendor lookup fails (product owned by a user we couldn't migrate
 *   as vendor): SKIP that product.
 *   If category lookup fails: warn + set category_id=NULL.
 *
 * Delivery info
 *   Bundle legacy delivery_time + custom_delivery_time + delivery_note
 *   into JSONB delivery_info object.
 *
 * Flags + stock
 *   is_featured, is_new, is_hot, is_sale -> direct boolean copy
 *   stock_quantity <- legacy quantity (with min 0 clamp)
 *   allow_oversell <- allow_checkout_when_out_of_stock
 *   stock_status <- verbatim (in_stock/out_of_stock/on_backorder)
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Migration\LegacyDb;
use Bayti\Api\Migration\MigrationLog;
use Bayti\Api\Migration\Slugger;

const LEGACY_PRODUCT_IMAGE_HOST = 'https://api.3bayti.ae/vendors/products/';

const SIZE_COLUMN_MAP = [
    'size_xs' => 'XS', 'size_s' => 'S', 'size_m' => 'M', 'size_l' => 'L',
    'size_xl' => 'XL', 'size_xxl' => 'XXL',
    'size_50' => '50', 'size_51' => '51', 'size_52' => '52', 'size_53' => '53',
    'size_54' => '54', 'size_55' => '55', 'size_56' => '56', 'size_57' => '57',
    'size_58' => '58', 'size_59' => '59', 'size_60' => '60', 'size_61' => '61',
    'size_62' => '62', 'size_63' => '63', 'size_64' => '64',
    'size_custom' => 'CUSTOM',
];

const STATUS_MAP = [
    'published' => 'active',
    'deleted' => 'soft_deleted',
    'draft' => 'draft',
];

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
$slugger = new Slugger();

// Reserve existing product slugs
foreach ($conn->fetchAllAssociative('SELECT slug FROM products') as $row) {
    $slugger->reserve($row['slug']);
}

echo "===== migrate-products =====\n";
echo "  run_id: " . $log->getRunId() . "\n\n";

// Pre-build vendor + category lookup maps (one query each, fits in memory)
echo "Building lookup maps...\n";
$vendorMap = [];
foreach ($conn->fetchAllAssociative('SELECT id, legacy_vendor_id FROM vendors WHERE legacy_vendor_id IS NOT NULL') as $row) {
    $vendorMap[(int) $row['legacy_vendor_id']] = (int) $row['id'];
}
echo "  Vendors: " . count($vendorMap) . " mapped\n";

$categoryMap = [];
foreach ($conn->fetchAllAssociative('SELECT id, legacy_category_id FROM categories WHERE legacy_category_id IS NOT NULL') as $row) {
    $categoryMap[(int) $row['legacy_category_id']] = (int) $row['id'];
}
echo "  Categories: " . count($categoryMap) . " mapped\n\n";

$totalRows = $legacy->count('products');
echo "Migrating {$totalRows} products...\n\n";

$conn->beginTransaction();
$migrated = 0;
$skipped = 0;
$errors = 0;

try {
    // Build the column list — products has 50+ cols, we name them explicitly
    $sizeCols = implode(', ', array_keys(SIZE_COLUMN_MAP));
    $sql = "
        SELECT
            product_id, store_id, category_id, name, description, status,
            image_1, images, quantity, allow_checkout_when_out_of_stock,
            stock_status, is_featured, price, sale_price, cost_per_item,
            minimum_order_quantity, maximum_order_quantity,
            created_at, updated_at,
            delivery_time, custom_delivery_time, delivery_note,
            require_extra_msmt, extra_msmt,
            is_hot, is_new, is_sale, try_on_active,
            label, collection,
            colors,
            {$sizeCols}
        FROM products
        ORDER BY product_id
    ";

    foreach ($legacy->iterate($sql) as $row) {
        $legacyId = (int) $row['product_id'];

        // Read existing v3 product if any
        $existingProduct = $conn->fetchAssociative(
            'SELECT id, slug FROM products WHERE legacy_product_id = ?',
            [$legacyId]
        );

        // Resolve vendor (REQUIRED — product can't exist without vendor)
        $legacyStoreId = (int) ($row['store_id'] ?? 0);
        $vendorId = $vendorMap[$legacyStoreId] ?? null;
        if ($vendorId === null) {
            $log->skip('products', $legacyId, "Vendor not found for store_id={$legacyStoreId}");
            $skipped++;
            continue;
        }

        // Resolve category (optional)
        $legacyCategoryId = (int) ($row['category_id'] ?? 0);
        $categoryId = $categoryMap[$legacyCategoryId] ?? null;
        if ($categoryId === null && $legacyCategoryId !== 0) {
            $log->warn('products', $legacyId, "Category not found for category_id={$legacyCategoryId} — set NULL");
        }

        // Generate slug
        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $log->skip('products', $legacyId, 'Empty name');
            $skipped++;
            continue;
        }
        // Slug: reuse existing on UPDATE, generate on INSERT (per stable-slug decision)
        if ($existingProduct !== false) {
            $slug = $existingProduct['slug'];
        } else {
            $slug = $slugger->make($name, fallback: 'product-' . $legacyId);
            if ($slug === null) {
                $log->error('products', $legacyId, "Slug generation failed for name '{$name}'");
                $errors++;
                continue;
            }
        }

        // Status mapping
        $legacyStatus = (string) ($row['status'] ?? 'draft');
        $v3Status = STATUS_MAP[$legacyStatus] ?? 'draft';
        $isActive = $v3Status === 'active';

        // Sizes: collect 1-valued size_* columns
        $availableSizes = [];
        foreach (SIZE_COLUMN_MAP as $col => $label) {
            if ((int) ($row[$col] ?? 0) === 1) {
                $availableSizes[] = $label;
            }
        }

        // Colors: split comma-separated, trim, drop empties
        $colorsRaw = (string) ($row['colors'] ?? '');
        $availableColors = array_values(array_filter(
            array_map('trim', explode(',', $colorsRaw)),
            static fn (string $c) => $c !== ''
        ));

        // Primary image URL
        $primaryImage = trim((string) ($row['image_1'] ?? ''));
        $primaryImageUrl = null;
        if ($primaryImage !== '' && !str_starts_with($primaryImage, 'assets/img/placeholder')) {
            // Legacy stores a relative path like 'products_images/<file>'.
            // The host base is '…/vendors/products/', which does NOT contain
            // 'products_images', so the segment must be KEPT (a prior version
            // wrongly stripped it, producing 404 URLs).
            $primaryImageUrl = isAbsolute($primaryImage)
                ? $primaryImage
                : LEGACY_PRODUCT_IMAGE_HOST . ltrim($primaryImage, '/');
        }

        // Gallery images
        $imagesRaw = $row['images'] ?? null;
        $imageUrls = [];
        if (is_string($imagesRaw) && $imagesRaw !== '') {
            $parsed = json_decode($imagesRaw, true);
            if (is_array($parsed)) {
                foreach ($parsed as $path) {
                    if (!is_string($path) || $path === '') {
                        continue;
                    }
                    if (str_starts_with($path, 'assets/img/placeholder')) {
                        continue;
                    }
                    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                        $imageUrls[] = $path;
                    } else {
                        // Keep the relative path as-is (incl. any
                        // 'products_images/' segment) under the host base.
                        $imageUrls[] = LEGACY_PRODUCT_IMAGE_HOST . ltrim($path, '/');
                    }
                }
            }
        }

        // Delivery info bundle
        $deliveryInfo = null;
        $deliveryTime = trim((string) ($row['delivery_time'] ?? ''));
        $customDeliveryTime = trim((string) ($row['custom_delivery_time'] ?? ''));
        $deliveryNote = trim((string) ($row['delivery_note'] ?? ''));
        if ($deliveryTime !== '' || $customDeliveryTime !== '' || $deliveryNote !== '') {
            $deliveryInfo = json_encode([
                'time' => $deliveryTime ?: null,
                'custom_time' => $customDeliveryTime ?: null,
                'note' => $deliveryNote ?: null,
            ], JSON_UNESCAPED_UNICODE);
        }

        // Prices — legacy double unsigned, our v3 NUMERIC(10,2)
        $price = $row['price'] !== null ? number_format((float) $row['price'], 2, '.', '') : '0.00';
        $salePrice = $row['sale_price'] !== null
            ? number_format((float) $row['sale_price'], 2, '.', '')
            : null;
        $cost = $row['cost_per_item'] !== null
            ? number_format((float) $row['cost_per_item'], 2, '.', '')
            : null;

        // Stock
        $stockQuantity = max(0, (int) ($row['quantity'] ?? 0));
        $allowOversell = (int) ($row['allow_checkout_when_out_of_stock'] ?? 0) === 1;
        $stockStatus = (string) ($row['stock_status'] ?? 'in_stock');
        if (!in_array($stockStatus, ['in_stock', 'out_of_stock', 'limited', 'on_backorder'], true)) {
            $stockStatus = 'in_stock';
        }

        // Timestamps
        $createdAt = parseLegacyTimestamp((string) ($row['created_at'] ?? '')) ?? date('Y-m-d H:i:sP');
        $updatedAt = parseLegacyTimestamp((string) ($row['updated_at'] ?? '')) ?? $createdAt;

        try {
            // Postgres UPSERT — on conflict of legacy_product_id, update
            // every column EXCEPT slug + created_at (stable per decision).
            $conn->executeStatement(
                "INSERT INTO products
                    (legacy_product_id, vendor_id, category_id, slug, name, description,
                     status, is_active,
                     price, sale_price, cost_per_item,
                     stock_quantity, allow_oversell, stock_status,
                     min_order_qty, max_order_qty,
                     primary_image_url, images,
                     available_sizes, available_colors,
                     is_featured, is_new, is_hot, is_sale, try_on_enabled,
                     requires_extra_msmt, extra_msmt,
                     delivery_info,
                     label_id,
                     created_at, updated_at)
                 VALUES
                    (:legacy_id, :vendor_id, :category_id, :slug, :name, :description,
                     :status, :is_active,
                     :price, :sale_price, :cost,
                     :stock_qty, :allow_oversell, :stock_status,
                     :min_qty, :max_qty,
                     :primary_img, :images::jsonb,
                     :sizes::jsonb, :colors::jsonb,
                     :is_featured, :is_new, :is_hot, :is_sale, :try_on,
                     :req_extra, :extra_msmt,
                     :delivery::jsonb,
                     :label_id,
                     :created_at, :updated_at)
                 ON CONFLICT (legacy_product_id) DO UPDATE SET
                     vendor_id = EXCLUDED.vendor_id,
                     category_id = EXCLUDED.category_id,
                     name = EXCLUDED.name,
                     description = EXCLUDED.description,
                     status = EXCLUDED.status,
                     is_active = EXCLUDED.is_active,
                     price = EXCLUDED.price,
                     sale_price = EXCLUDED.sale_price,
                     cost_per_item = EXCLUDED.cost_per_item,
                     stock_quantity = EXCLUDED.stock_quantity,
                     allow_oversell = EXCLUDED.allow_oversell,
                     stock_status = EXCLUDED.stock_status,
                     min_order_qty = EXCLUDED.min_order_qty,
                     max_order_qty = EXCLUDED.max_order_qty,
                     primary_image_url = EXCLUDED.primary_image_url,
                     images = EXCLUDED.images,
                     available_sizes = EXCLUDED.available_sizes,
                     available_colors = EXCLUDED.available_colors,
                     is_featured = EXCLUDED.is_featured,
                     is_new = EXCLUDED.is_new,
                     is_hot = EXCLUDED.is_hot,
                     is_sale = EXCLUDED.is_sale,
                     try_on_enabled = EXCLUDED.try_on_enabled,
                     requires_extra_msmt = EXCLUDED.requires_extra_msmt,
                     extra_msmt = EXCLUDED.extra_msmt,
                     delivery_info = EXCLUDED.delivery_info,
                     label_id = EXCLUDED.label_id,
                     updated_at = date_trunc('second', NOW())",
                [
                    'legacy_id' => $legacyId,
                    'vendor_id' => $vendorId,
                    'category_id' => $categoryId,
                    'slug' => $slug,
                    'name' => $name,
                    'description' => trim((string) ($row['description'] ?? '')) ?: null,
                    'status' => $v3Status,
                    'is_active' => $isActive ? 'true' : 'false',
                    'price' => $price,
                    'sale_price' => $salePrice,
                    'cost' => $cost,
                    'stock_qty' => $stockQuantity,
                    'allow_oversell' => $allowOversell ? 'true' : 'false',
                    'stock_status' => $stockStatus,
                    'min_qty' => $row['minimum_order_quantity'] !== null
                        ? (int) $row['minimum_order_quantity']
                        : null,
                    'max_qty' => $row['maximum_order_quantity'] !== null
                        && (int) $row['maximum_order_quantity'] > 0
                        ? (int) $row['maximum_order_quantity']
                        : null,
                    'primary_img' => $primaryImageUrl,
                    'images' => json_encode($imageUrls, JSON_UNESCAPED_SLASHES),
                    'sizes' => json_encode($availableSizes, JSON_UNESCAPED_UNICODE),
                    'colors' => json_encode($availableColors, JSON_UNESCAPED_UNICODE),
                    'is_featured' => (int) ($row['is_featured'] ?? 0) === 1 ? 'true' : 'false',
                    'is_new' => (int) ($row['is_new'] ?? 0) === 1 ? 'true' : 'false',
                    'is_hot' => (int) ($row['is_hot'] ?? 0) === 1 ? 'true' : 'false',
                    'is_sale' => (int) ($row['is_sale'] ?? 0) === 1 ? 'true' : 'false',
                    'try_on' => (int) ($row['try_on_active'] ?? 0) === 1 ? 'true' : 'false',
                    'req_extra' => (int) ($row['require_extra_msmt'] ?? 0) === 1 ? 'true' : 'false',
                    'extra_msmt' => trim((string) ($row['extra_msmt'] ?? '')) ?: null,
                    'delivery' => $deliveryInfo,
                    'label_id' => $row['label'] !== null ? (int) $row['label'] : null,
                    'created_at' => $createdAt,
                    'updated_at' => $updatedAt,
                ]
            );
        } catch (\Throwable $e) {
            $log->error('products', $legacyId, "INSERT failed: " . $e->getMessage(), [
                'slug' => $slug,
                'name' => substr($name, 0, 60),
            ]);
            $errors++;
            continue;
        }

        $migrated++;
        if ($migrated % 200 === 0) {
            echo "  ... {$migrated} processed (INSERT or UPDATE)\n";
        }
    }

    // Reset sequence
    $conn->executeStatement(
        "SELECT setval('products_id_seq', COALESCE((SELECT MAX(id) FROM products), 0) + 1, false)"
    );

    $conn->commit();

    echo "\n===== products migration complete =====\n";
    echo "  Processed (INSERT or UPDATE): {$migrated}\n";
    echo "  Skipped:   {$skipped}\n";
    echo "  Errors:    {$errors}\n";

    // Quick verification queries
    $byStatus = $conn->fetchAllAssociative(
        "SELECT status, COUNT(*) AS c FROM products GROUP BY status ORDER BY c DESC"
    );
    echo "\n  Status distribution in v3:\n";
    foreach ($byStatus as $r) {
        printf("    %-15s %d\n", $r['status'], $r['c']);
    }
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

function isAbsolute(string $path): bool
{
    return str_starts_with($path, 'http://') || str_starts_with($path, 'https://');
}
