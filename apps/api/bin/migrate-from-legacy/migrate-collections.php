<?php

declare(strict_types=1);

/**
 * Migrate collections from legacy MySQL to v3 Postgres.
 *
 * Source: `collections`
 *  - collections_id   INT PRIMARY KEY
 *  - collection_name  VARCHAR
 *  - is_active        TINYINT(1)
 *
 * Target: `product_collections`
 *  - legacy_collection_id INT UNIQUE   (preserved for idempotency)
 *  - slug VARCHAR(220) UNIQUE          (generated from name)
 *  - name VARCHAR(200)
 *  - description TEXT NULL             (legacy has none)
 *  - cover_image_url VARCHAR NULL      (legacy has none)
 *  - display_order SMALLINT            (sequential, by legacy id)
 *  - is_active BOOLEAN
 *
 * Idempotency: rows are upserted by legacy_collection_id (INSERT first
 * time, UPDATE name/is_active on drift, slug stable). Safe to re-run.
 *
 * Sequence reset: AFTER migration, advance product_collections' id
 * sequence past MAX(id) so admin-created collections don't collide.
 *
 * Run from apps/api:  php bin/migrate-from-legacy/migrate-collections.php
 * Requires the LEGACY_MYSQL_* env vars (same as the other migrations).
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Migration\LegacyDb;
use Bayti\Api\Migration\MigrationLog;
use Bayti\Api\Migration\Slugger;

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

// Reserve already-present slugs so re-runs don't collide.
foreach ($conn->fetchAllAssociative('SELECT slug FROM product_collections') as $row) {
    $slugger->reserve($row['slug']);
}

echo "===== migrate-collections =====\n";
echo "  run_id: " . $log->getRunId() . "\n\n";

$rows = $legacy->fetchAll('SELECT collections_id, collection_name, is_active FROM collections ORDER BY collections_id');
echo "  Found " . count($rows) . " legacy collections.\n\n";

$conn->beginTransaction();
try {
    $order = 0;
    foreach ($rows as $row) {
        $legacyId = (int) $row['collections_id'];
        $order++;

        $name = trim((string) $row['collection_name']);
        if ($name === '') {
            $log->error('product_collections', $legacyId, 'Empty name — skipping');
            continue;
        }

        $isActive = (bool) ((int) $row['is_active']);

        $existing = $conn->fetchAssociative(
            'SELECT id, slug, name, is_active FROM product_collections WHERE legacy_collection_id = ?',
            [$legacyId]
        );

        if ($existing === false) {
            $slug = $slugger->make($name, fallback: 'collection-' . $legacyId);
            if ($slug === null) {
                $log->error('product_collections', $legacyId, "Slug generation failed for name '{$name}'");
                continue;
            }

            $conn->executeStatement(
                "INSERT INTO product_collections
                    (legacy_collection_id, slug, name, description, cover_image_url, display_order, is_active, created_at, updated_at)
                 VALUES
                    (:legacy_id, :slug, :name, NULL, NULL, :display_order, :is_active, date_trunc('second', NOW()), date_trunc('second', NOW()))",
                [
                    'legacy_id' => $legacyId,
                    'slug' => $slug,
                    'name' => $name,
                    'display_order' => $order,
                    'is_active' => $isActive ? 'true' : 'false',
                ]
            );

            $log->info('product_collections', $legacyId, "INSERT as '{$slug}' ({$name})");
        } else {
            $changes = [];
            if ($existing['name'] !== $name) {
                $changes['name'] = ['from' => $existing['name'], 'to' => $name];
            }
            $existingActive = ($existing['is_active'] === true) || ($existing['is_active'] === 't')
                || ($existing['is_active'] === '1') || ($existing['is_active'] === 1);
            if ($existingActive !== $isActive) {
                $changes['is_active'] = ['from' => $existingActive, 'to' => $isActive];
            }

            if (count($changes) === 0) {
                continue;
            }

            $conn->executeStatement(
                "UPDATE product_collections
                 SET name = :name, is_active = :is_active, updated_at = date_trunc('second', NOW())
                 WHERE legacy_collection_id = :legacy_id",
                [
                    'legacy_id' => $legacyId,
                    'name' => $name,
                    'is_active' => $isActive ? 'true' : 'false',
                ]
            );

            $log->info('product_collections', $legacyId, "UPDATE (slug stable: '{$existing['slug']}')", $changes);
        }
    }

    // Advance the id sequence past the current max (robust for SERIAL and
    // GENERATED-AS-IDENTITY columns).
    $conn->executeStatement(
        "SELECT setval(
            pg_get_serial_sequence('product_collections', 'id'),
            COALESCE((SELECT MAX(id) FROM product_collections), 0) + 1,
            false
        )"
    );

    $conn->commit();
    echo "\n";
    $log->summary('product_collections');
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
