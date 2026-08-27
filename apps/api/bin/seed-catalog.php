#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 3bayti API, catalog seed data
 * ==============================
 *
 * Populates vendors, brands, and categories with fixtures for QA +
 * frontend development. M2.1.B deliverable.
 *
 * Idempotent
 * ----------
 * Safe to run multiple times. Each entity is checked by slug; if
 * present, the record is updated to match the fixture (so re-running
 * pulls in any edits to this file). If missing, the record is
 * created.
 *
 * Run on the server post-deploy:
 *
 *   cd /www/wwwroot/3bayti/apps/api
 *   /www/server/php/83/bin/php bin/seed-catalog.php
 *
 * The script prints a summary at the end:
 *
 *   Vendors:    3 created, 0 updated
 *   Brands:    10 created, 0 updated
 *   Categories: 9 created, 0 updated
 *
 * Locale
 * ------
 * All copy is English per Q5. When Arabic is added (M2.x mini-phase),
 * this file can be extended with name_ar / description_ar fixtures.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Domain\Catalog\Brand;
use Bayti\Api\Domain\Catalog\BrandRepository;
use Bayti\Api\Domain\Catalog\Category;
use Bayti\Api\Domain\Catalog\CategoryRepository;
use Bayti\Api\Domain\Catalog\Vendor;
use Bayti\Api\Domain\Catalog\VendorRepository;
use Doctrine\ORM\EntityManagerInterface;

$app = Bootstrap::createApp();
$container = $app->getContainer();
if ($container === null) {
    fwrite(STDERR, "DI container not available\n");
    exit(1);
}

/** @var EntityManagerInterface $em */
$em = $container->get(EntityManagerInterface::class);

// ============================================================
// Fixtures
// ============================================================

$vendors = [
    [
        'slug' => 'almas-fashion',
        'name' => 'Almas Fashion',
        'contact_email' => 'hello@almas-fashion.example',
        'description' => 'Modern Emirati fashion blending tradition with contemporary design.',
        'is_active' => true,
        'is_verified' => true,
        'commission_rate' => 12.50,
    ],
    [
        'slug' => 'bayti-originals',
        'name' => 'Bayti Originals',
        'contact_email' => 'partners@bayti.example',
        'description' => 'Curated original designs from the 3bayti platform team.',
        'is_active' => true,
        'is_verified' => true,
        'commission_rate' => 5.00,
    ],
    [
        'slug' => 'test-vendor',
        'name' => 'Test Vendor',
        'contact_email' => 'qa@example.test',
        'description' => 'Used for QA / staging tests. Safe to ignore in production traffic.',
        'is_active' => true,
        'is_verified' => false,
        'commission_rate' => 10.00,
    ],
];

$brands = [
    ['slug' => 'almas', 'name' => 'Almas'],
    ['slug' => 'zaina', 'name' => 'Zaina'],
    ['slug' => 'lumiere', 'name' => 'Lumière'],
    ['slug' => 'mirage', 'name' => 'Mirage'],
    ['slug' => 'bayti', 'name' => 'Bayti'],
    ['slug' => 'brand-1', 'name' => 'Brand 1'],
    ['slug' => 'brand-2', 'name' => 'Brand 2'],
    ['slug' => 'brand-3', 'name' => 'Brand 3'],
    ['slug' => 'brand-4', 'name' => 'Brand 4'],
    ['slug' => 'brand-5', 'name' => 'Brand 5'],
];

// Category tree, order matters: parents before children so the
// path-computation chain works. The seeder validates this.
$categories = [
    // Roots
    [
        'slug' => 'clothing',
        'name' => 'Clothing',
        'parent_slug' => null,
        'description' => 'Fashion apparel for women and men.',
        'display_order' => 10,
    ],
    [
        'slug' => 'accessories',
        'name' => 'Accessories',
        'parent_slug' => null,
        'description' => 'Bags, scarves, jewelry.',
        'display_order' => 20,
    ],
    // /clothing
    [
        'slug' => 'womens',
        'name' => "Women's",
        'parent_slug' => 'clothing',
        'display_order' => 10,
    ],
    [
        'slug' => 'mens',
        'name' => "Men's",
        'parent_slug' => 'clothing',
        'display_order' => 20,
    ],
    // /clothing/womens
    [
        'slug' => 'abayas',
        'name' => 'Abayas',
        'parent_slug' => 'womens',
        'description' => 'Traditional and modern abaya designs.',
        'display_order' => 10,
    ],
    [
        'slug' => 'casual-wear',
        'name' => 'Casual Wear',
        'parent_slug' => 'womens',
        'display_order' => 20,
    ],
    // /clothing/mens
    [
        'slug' => 'kanduras',
        'name' => 'Kanduras',
        'parent_slug' => 'mens',
        'display_order' => 10,
    ],
    // /accessories
    [
        'slug' => 'scarves',
        'name' => 'Scarves',
        'parent_slug' => 'accessories',
        'display_order' => 10,
    ],
    [
        'slug' => 'bags',
        'name' => 'Bags',
        'parent_slug' => 'accessories',
        'display_order' => 20,
    ],
];

// ============================================================
// Seed: Vendors
// ============================================================

/** @var VendorRepository $vendorRepo */
$vendorRepo = $em->getRepository(Vendor::class);
$vendorCreated = 0;
$vendorUpdated = 0;

foreach ($vendors as $fixture) {
    $existing = $vendorRepo->findBySlug($fixture['slug']);
    if ($existing === null) {
        $vendor = new Vendor(
            slug: $fixture['slug'],
            name: $fixture['name'],
            contactEmail: $fixture['contact_email'],
        );
        $vendor->setDescription($fixture['description'] ?? null);
        $vendor->setActive($fixture['is_active']);
        $vendor->setVerified($fixture['is_verified']);
        $vendor->setCommissionRate($fixture['commission_rate']);

        $em->persist($vendor);
        $vendorCreated++;
    } else {
        // Update existing, keeps fixture file as source of truth.
        $existing->setName($fixture['name']);
        $existing->setContactEmail($fixture['contact_email']);
        $existing->setDescription($fixture['description'] ?? null);
        $existing->setActive($fixture['is_active']);
        $existing->setVerified($fixture['is_verified']);
        $existing->setCommissionRate($fixture['commission_rate']);
        $vendorUpdated++;
    }
}
$em->flush();

// ============================================================
// Seed: Brands
// ============================================================

/** @var BrandRepository $brandRepo */
$brandRepo = $em->getRepository(Brand::class);
$brandCreated = 0;
$brandUpdated = 0;

foreach ($brands as $fixture) {
    $existing = $brandRepo->findBySlug($fixture['slug']);
    if ($existing === null) {
        $brand = new Brand(slug: $fixture['slug'], name: $fixture['name']);
        $em->persist($brand);
        $brandCreated++;
    } else {
        $existing->setName($fixture['name']);
        $existing->setActive(true);
        $brandUpdated++;
    }
}
$em->flush();

// ============================================================
// Seed: Categories
// ============================================================

/** @var CategoryRepository $categoryRepo */
$categoryRepo = $em->getRepository(Category::class);
$categoryCreated = 0;
$categoryUpdated = 0;

// Two-pass: roots first (no parent dependency), then children. Within
// each pass, the fixture order is preserved which is alphabetical
// at each level, fine for display_order tie-breaking.
// We use the slug-to-entity map to resolve parent_slug -> Category.
$resolved = [];

// Pass 1: roots
foreach ($categories as $fixture) {
    if ($fixture['parent_slug'] !== null) {
        continue;
    }
    $existing = $categoryRepo->findBySlug($fixture['slug']);
    if ($existing === null) {
        $cat = new Category(slug: $fixture['slug'], name: $fixture['name']);
        $cat->setDescription($fixture['description'] ?? null);
        $cat->setDisplayOrder($fixture['display_order'] ?? 0);
        $em->persist($cat);
        $resolved[$fixture['slug']] = $cat;
        $categoryCreated++;
    } else {
        $existing->setName($fixture['name']);
        $existing->setDescription($fixture['description'] ?? null);
        $existing->setDisplayOrder($fixture['display_order'] ?? 0);
        $existing->setActive(true);
        $resolved[$fixture['slug']] = $existing;
        $categoryUpdated++;
    }
}
$em->flush();

// Pass 2: children, multiple iterations to handle arbitrary depth.
// We loop until no progress; defensive against tree-order surprises.
$remaining = array_filter($categories, static fn ($f) => $f['parent_slug'] !== null);
$maxPasses = 10;
$pass = 0;

while (!empty($remaining) && $pass < $maxPasses) {
    $pass++;
    $progressed = false;
    foreach ($remaining as $key => $fixture) {
        $parentSlug = $fixture['parent_slug'];
        // Parent must be resolved already
        $parent = $resolved[$parentSlug] ?? $categoryRepo->findBySlug($parentSlug);
        if ($parent === null) {
            // Try again next pass, its parent might not be seeded yet
            continue;
        }

        $existing = $categoryRepo->findBySlug($fixture['slug']);
        if ($existing === null) {
            $cat = new Category(
                slug: $fixture['slug'],
                name: $fixture['name'],
                parent: $parent,
            );
            $cat->setDescription($fixture['description'] ?? null);
            $cat->setDisplayOrder($fixture['display_order'] ?? 0);
            $em->persist($cat);
            $resolved[$fixture['slug']] = $cat;
            $categoryCreated++;
        } else {
            $existing->setName($fixture['name']);
            $existing->setDescription($fixture['description'] ?? null);
            $existing->setDisplayOrder($fixture['display_order'] ?? 0);
            $existing->setActive(true);
            // If parent changed, reparent + refresh path
            if ($existing->getParent()?->getId() !== $parent->getId()) {
                $existing->setParent($parent);
                $categoryRepo->rebuildSubtreePaths($existing);
            }
            $resolved[$fixture['slug']] = $existing;
            $categoryUpdated++;
        }

        unset($remaining[$key]);
        $progressed = true;
    }

    if (!$progressed) {
        // No fixture could be placed this pass, parent_slug refs
        // are broken or out of order. Bail loudly.
        $orphans = array_column($remaining, 'slug');
        fwrite(STDERR, sprintf(
            "Could not resolve parent for categories: %s\n",
            implode(', ', $orphans),
        ));
        exit(1);
    }
}

$em->flush();

// ============================================================
// Summary
// ============================================================

echo str_repeat('-', 60) . "\n";
echo "Catalog seed complete\n";
echo str_repeat('-', 60) . "\n";
printf("Vendors:    %d created, %d updated\n", $vendorCreated, $vendorUpdated);
printf("Brands:     %d created, %d updated\n", $brandCreated, $brandUpdated);
printf("Categories: %d created, %d updated\n", $categoryCreated, $categoryUpdated);
echo str_repeat('-', 60) . "\n";

exit(0);
