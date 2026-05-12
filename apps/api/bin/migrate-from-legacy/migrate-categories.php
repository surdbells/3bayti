<?php

declare(strict_types=1);

/**
 * Migrate categories from legacy MySQL to v3 Postgres.
 *
 * Source: `category` (8 rows, flat — no tree)
 *  - category_id INT PRIMARY KEY
 *  - icon VARCHAR(50)      ('@tui.*' refs)
 *  - category_name VARCHAR(50)
 *  - is_active TINYINT(1)
 *
 * Target: `categories` (extended schema from M2.1.0 + M2.2.0)
 *  - legacy_category_id INT UNIQUE  (preserved for idempotency)
 *  - slug VARCHAR(100) UNIQUE       (generated from name)
 *  - name VARCHAR(150)
 *  - description TEXT NULL          (left null — legacy has none)
 *  - parent_id BIGINT NULL          (left null — legacy is flat)
 *  - path VARCHAR(500)              (just the slug for root categories)
 *  - display_order INT              (left 0 — legacy has none)
 *  - is_active BOOLEAN
 *  - icon VARCHAR(50) NULL          (preserved verbatim)
 *
 * Idempotency: skip rows whose legacy_category_id already exists.
 * Safe to re-run.
 *
 * Sequence reset: AFTER migration, reset categories_id_seq to MAX(id)+1
 * so newly-created (non-migrated) categories don't collide.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Migration\LegacyDb;
use Bayti\Api\Migration\MigrationLog;
use Bayti\Api\Migration\Slugger;
use Doctrine\DBAL\Connection;

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

// Reserve already-present slugs so we don't collide with anything left
// over from prior runs (idempotency).
foreach ($conn->fetchAllAssociative('SELECT slug FROM categories') as $row) {
    $slugger->reserve($row['slug']);
}

echo "===== migrate-categories =====\n";
echo "  run_id: " . $log->getRunId() . "\n\n";

$rows = $legacy->fetchAll('SELECT category_id, icon, category_name, is_active FROM category ORDER BY category_id');
echo "  Found " . count($rows) . " legacy categories.\n\n";

$conn->beginTransaction();
try {
    foreach ($rows as $row) {
        $legacyId = (int) $row['category_id'];

        $name = trim((string) $row['category_name']);
        if ($name === '') {
            $log->error('categories', $legacyId, 'Empty name — skipping');
            continue;
        }

        $icon = trim((string) ($row['icon'] ?? ''));
        $isActive = (bool) ((int) $row['is_active']);

        // Existing v3 row?
        $existing = $conn->fetchAssociative(
            'SELECT id, slug, name, icon, is_active FROM categories WHERE legacy_category_id = ?',
            [$legacyId]
        );

        if ($existing === false) {
            // First-time INSERT — generate slug
            $slug = $slugger->make($name, fallback: 'cat-' . $legacyId);
            if ($slug === null) {
                $log->error('categories', $legacyId, "Slug generation failed for name '{$name}'");
                continue;
            }

            $conn->executeStatement(
                "INSERT INTO categories
                    (legacy_category_id, slug, name, parent_id, path, display_order, is_active, icon, created_at, updated_at)
                 VALUES
                    (:legacy_id, :slug, :name, NULL, :path, 0, :is_active, :icon, date_trunc('second', NOW()), date_trunc('second', NOW()))",
                [
                    'legacy_id' => $legacyId,
                    'slug' => $slug,
                    'name' => $name,
                    'path' => $slug,
                    'is_active' => $isActive ? 'true' : 'false',
                    'icon' => $icon !== '' ? $icon : null,
                ]
            );

            $log->info('categories', $legacyId, "INSERT as '{$slug}' ({$name})");
        } else {
            // Re-sync — UPDATE everything except slug (stable per decision)
            $changes = [];
            if ($existing['name'] !== $name) {
                $changes['name'] = ['from' => $existing['name'], 'to' => $name];
            }
            if (((string) ($existing['icon'] ?? '')) !== $icon) {
                $changes['icon'] = ['from' => $existing['icon'], 'to' => $icon];
            }
            // Note: Postgres returns boolean as 't'/'f' string via DBAL fetch.
            $existingActive = ($existing['is_active'] === true) || ($existing['is_active'] === 't') || ($existing['is_active'] === '1') || ($existing['is_active'] === 1);
            if ($existingActive !== $isActive) {
                $changes['is_active'] = ['from' => $existingActive, 'to' => $isActive];
            }

            if (count($changes) === 0) {
                continue; // No drift — silent skip
            }

            $conn->executeStatement(
                "UPDATE categories
                 SET name = :name, icon = :icon, is_active = :is_active, updated_at = date_trunc('second', NOW())
                 WHERE legacy_category_id = :legacy_id",
                [
                    'legacy_id' => $legacyId,
                    'name' => $name,
                    'icon' => $icon !== '' ? $icon : null,
                    'is_active' => $isActive ? 'true' : 'false',
                ]
            );

            $log->info('categories', $legacyId, "UPDATE (slug stable: '{$existing['slug']}')", $changes);
        }
    }

    // Reset sequence to max(id) + 1 so admin-creates don't collide
    $conn->executeStatement(
        "SELECT setval('categories_id_seq', COALESCE((SELECT MAX(id) FROM categories), 0) + 1, false)"
    );

    $conn->commit();
    echo "\n";
    $log->summary('categories');
    echo "\nSequence reset complete.\n";
} catch (Throwable $e) {
    $conn->rollBack();
    fwrite(STDERR, "FATAL: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
} finally {
    $legacy->close();
}

exit(0);
