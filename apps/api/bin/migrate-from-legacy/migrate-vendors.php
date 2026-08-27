<?php

declare(strict_types=1);

/**
 * Migrate vendors from legacy users.is_vendor=1 into v3 vendors table.
 *
 * Source criteria, "real vendor" = legacy users row where:
 *   - is_vendor = 1
 *   - store_name IS NOT NULL AND store_name != ''
 *
 * Discovered count: 101 named vendors. Plus we auto-promote any user who
 * owns a product but isn't a "real vendor", these are the 15 is_vendor=0
 * product owners + the 24 nameless product owners. Total: ~140 vendors.
 *
 * Target columns
 * --------------
 *
 * Required (non-nullable in v3):
 *   - slug                       generated from store_name (or fallback)
 *   - name                       legacy store_name (or synthetic)
 *   - contact_email              legacy store_email OR fall back to user email
 *   - commission_rate            10.00 default
 *   - is_active, is_verified, is_store_approved
 *
 * Optional (preserved verbatim from legacy):
 *   - legal_name, store_email, store_phone_raw, store_address
 *   - store_bank_name, store_bank_account_name, store_bank_account_number
 *   - vat_status, trade_license_number, licensing_authority,
 *     tax_registration_number, vat_registration_effective_date,
 *     registered_tax_address, tax_contact_email
 *   - legacy_logo_data_url, legacy_cover_data_url (base64 LONGBLOBs)
 *
 * Linking:
 *   - owner_user_id           → v3 users.id (looked up by legacy_user_id)
 *   - legacy_vendor_id        → legacy users.user_id (preserved for idempotency)
 *
 * NOTE: vendor.legacy_vendor_id IS the user.legacy_user_id of the vendor's
 *       owner. There's no separate legacy "vendor" table, vendors live as
 *       users with is_vendor=1. So a vendor's "legacy id" is just the user_id
 *       of its owner. This is intentional.
 *
 * Idempotency: skip if a vendor row already has legacy_vendor_id = $userId.
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

// Reserve existing vendor slugs so we don't collide
foreach ($conn->fetchAllAssociative('SELECT slug FROM vendors') as $row) {
    $slugger->reserve($row['slug']);
}

echo "===== migrate-vendors =====\n";
echo "  run_id: " . $log->getRunId() . "\n\n";

// Find user_ids that should become vendors:
//   A. real vendors: is_vendor=1 AND store_name populated
//   B. auto-promoted: anyone who owns a published product (catches the
//      24 nameless + 15 is_vendor=0 owners)
//
// UNION + DISTINCT to dedupe (most A entries also overlap with B).

$candidateUserIds = $legacy->fetchAll("
    SELECT DISTINCT user_id FROM (
        SELECT user_id FROM users
        WHERE is_vendor = 1 AND store_name IS NOT NULL AND store_name != ''
        UNION
        SELECT u.user_id FROM users u
        INNER JOIN products p ON p.store_id = u.user_id
        WHERE p.status IN ('published', 'draft')
    ) AS combined
");

echo "  Found " . count($candidateUserIds) . " candidate vendor user_ids.\n\n";

$conn->beginTransaction();
$migrated = 0;
$skipped = 0;
$errors = 0;

try {
    foreach ($candidateUserIds as $candidate) {
        $userId = (int) $candidate['user_id'];

        // Read existing v3 vendor row if any
        $existingVendor = $conn->fetchAssociative(
            'SELECT id, slug, name, description, contact_email, contact_phone,
                    is_active, is_verified, is_store_approved,
                    legal_name, store_email, store_phone_raw, store_address,
                    store_bank_name, store_bank_account_name, store_bank_account_number,
                    vat_status, trade_license_number, licensing_authority,
                    tax_registration_number, registered_tax_address, tax_contact_email,
                    legacy_logo_data_url, legacy_cover_data_url
             FROM vendors WHERE legacy_vendor_id = ?',
            [$userId]
        );

        // Resolve v3 user_id from legacy_user_id (users migration must run first)
        $ownerV3Id = $conn->fetchOne(
            'SELECT id, email FROM users WHERE legacy_user_id = ?',
            [$userId]
        );
        if ($ownerV3Id === false) {
            $log->skip('vendors', $userId, 'Owner user not migrated yet — skipping');
            $skipped++;
            continue;
        }

        // Fetch full legacy user record for vendor fields
        $u = $legacy->fetchOne("
            SELECT user_id, email, phone, countryCode, is_vendor,
                   store_name, store_legal_name, store_email, store_phone,
                   store_description, store_logo, store_cover, store_address,
                   store_bank_name, store_account_name, store_account_number,
                   store_status, store_approved,
                   vat_status, trade_license_number, licensing_authority,
                   tax_registration_number, vat_registration_effective_date,
                   registered_tax_address, tax_contact_email,
                   created, approved
            FROM users WHERE user_id = {$userId}
        ");

        if ($u === null) {
            $log->error('vendors', $userId, 'Legacy user disappeared between query and fetch');
            $errors++;
            continue;
        }

        // Synthesize a name if store_name is empty
        $storeName = trim((string) ($u['store_name'] ?? ''));
        $isSynthetic = false;
        if ($storeName === '') {
            // Auto-promoted vendor, synthesize name from email local-part
            $emailLocal = explode('@', (string) $u['email'])[0] ?? 'store';
            // Capitalize first letter, replace dots/underscores with spaces
            $clean = ucwords(str_replace(['.', '_', '-'], ' ', $emailLocal));
            $storeName = 'Store - ' . $clean;
            // Cap length to fit varchar(200)
            if (strlen($storeName) > 180) {
                $storeName = substr($storeName, 0, 180);
            }
            $isSynthetic = true;
        }

        // Slug: reuse existing on UPDATE, generate on INSERT
        if ($existingVendor !== false) {
            $slug = $existingVendor['slug']; // STABLE
        } else {
            $slug = $slugger->make($storeName, fallback: 'vendor-' . $userId);
            if ($slug === null) {
                $log->error('vendors', $userId, "Could not generate slug from name '{$storeName}'");
                $errors++;
                continue;
            }
        }

        // Contact email: prefer store_email, fall back to user email
        $contactEmail = trim((string) ($u['store_email'] ?? ''));
        if ($contactEmail === '') {
            $contactEmail = trim((string) ($u['email'] ?? ''));
        }
        if ($contactEmail === '') {
            $log->error('vendors', $userId, 'No email available for vendor (legacy email also empty)');
            $errors++;
            continue;
        }

        // Status flags
        $isStoreApproved = (int) ($u['store_approved'] ?? 0) === 1;
        $isStoreActive = (int) ($u['store_status'] ?? 0) === 1;
        $isActive = true;

        // Tax fields
        $vatRegDate = parseLegacyDate((string) ($u['vat_registration_effective_date'] ?? ''));

        // Logo / cover, keep base64 verbatim in legacy_*_data_url columns.
        $logoBlob = $u['store_logo'] ?? null;
        $coverBlob = $u['store_cover'] ?? null;
        $logoDataUrl = is_string($logoBlob) && $logoBlob !== '' ? $logoBlob : null;
        $coverDataUrl = is_string($coverBlob) && $coverBlob !== '' ? $coverBlob : null;

        $created = parseLegacyTimestamp((string) ($u['created'] ?? '')) ?? date('Y-m-d H:i:sP');

        // Common values for INSERT / UPDATE
        $contactPhone = trim((string) ($u['phone'] ?? '')) ?: null;
        $description = trim((string) ($u['store_description'] ?? '')) ?: null;
        $legalName = trim((string) ($u['store_legal_name'] ?? '')) ?: null;
        $storeEmailVal = trim((string) ($u['store_email'] ?? '')) ?: null;
        $storePhoneRaw = trim((string) ($u['store_phone'] ?? '')) ?: null;
        $storeAddress = trim((string) ($u['store_address'] ?? '')) ?: null;
        $bankName = trim((string) ($u['store_bank_name'] ?? '')) ?: null;
        $bankAcctName = trim((string) ($u['store_account_name'] ?? '')) ?: null;
        $bankAcctNum = trim((string) ($u['store_account_number'] ?? '')) ?: null;
        $vatStatusVal = trim((string) ($u['vat_status'] ?? '')) ?: null;
        $tradeLicNum = trim((string) ($u['trade_license_number'] ?? '')) ?: null;
        $licAuth = trim((string) ($u['licensing_authority'] ?? '')) ?: null;
        $taxRegNum = trim((string) ($u['tax_registration_number'] ?? '')) ?: null;
        $taxAddr = trim((string) ($u['registered_tax_address'] ?? '')) ?: null;
        $taxEmailVal = trim((string) ($u['tax_contact_email'] ?? '')) ?: null;

        if ($existingVendor === false) {
            // -------- INSERT --------
            try {
                $conn->executeStatement(
                    "INSERT INTO vendors
                        (legacy_vendor_id, slug, name, description,
                         contact_email, contact_phone,
                         logo_url, cover_image_url,
                         is_active, is_verified, is_store_approved,
                         commission_rate,
                         owner_user_id,
                         legal_name, store_email, store_phone_raw, store_address,
                         store_bank_name, store_bank_account_name, store_bank_account_number,
                         vat_status, trade_license_number, licensing_authority,
                         tax_registration_number, vat_registration_effective_date,
                         registered_tax_address, tax_contact_email,
                         legacy_logo_data_url, legacy_cover_data_url,
                         created_at, updated_at)
                     VALUES
                        (:legacy_id, :slug, :name, :description,
                         :contact_email, :contact_phone,
                         NULL, NULL,
                         :is_active, :is_verified, :is_approved,
                         '10.00',
                         :owner_user_id,
                         :legal_name, :store_email, :store_phone_raw, :store_address,
                         :bank_name, :bank_acct_name, :bank_acct_num,
                         :vat_status, :trade_lic, :lic_auth,
                         :tax_reg_num, :vat_reg_date,
                         :tax_addr, :tax_email,
                         :legacy_logo, :legacy_cover,
                         :created, date_trunc('second', NOW()))",
                    [
                        'legacy_id' => $userId,
                        'slug' => $slug,
                        'name' => $storeName,
                        'description' => $description,
                        'contact_email' => $contactEmail,
                        'contact_phone' => $contactPhone,
                        'is_active' => $isActive ? 'true' : 'false',
                        'is_verified' => $isStoreApproved ? 'true' : 'false',
                        'is_approved' => $isStoreApproved ? 'true' : 'false',
                        'owner_user_id' => $ownerV3Id,
                        'legal_name' => $legalName,
                        'store_email' => $storeEmailVal,
                        'store_phone_raw' => $storePhoneRaw,
                        'store_address' => $storeAddress,
                        'bank_name' => $bankName,
                        'bank_acct_name' => $bankAcctName,
                        'bank_acct_num' => $bankAcctNum,
                        'vat_status' => $vatStatusVal,
                        'trade_lic' => $tradeLicNum,
                        'lic_auth' => $licAuth,
                        'tax_reg_num' => $taxRegNum,
                        'vat_reg_date' => $vatRegDate,
                        'tax_addr' => $taxAddr,
                        'tax_email' => $taxEmailVal,
                        'legacy_logo' => $logoDataUrl,
                        'legacy_cover' => $coverDataUrl,
                        'created' => $created,
                    ]
                );
            } catch (\Throwable $e) {
                $log->error('vendors', $userId, "INSERT failed: " . $e->getMessage(), [
                    'name' => $storeName,
                    'slug' => $slug,
                ]);
                $errors++;
                continue;
            }

            $log->info('vendors', $userId, "INSERT as '{$slug}' ({$storeName})" . ($isSynthetic ? ' [SYNTHETIC NAME]' : ''));
            $migrated++;
        } else {
            // -------- UPDATE, slug stable, owner stable, all else updated --------
            $changes = [];
            if ($existingVendor['name'] !== $storeName) {
                $changes['name'] = ['from' => $existingVendor['name'], 'to' => $storeName];
            }
            if ($existingVendor['description'] !== $description) {
                $changes['description'] = ['from' => $existingVendor['description'], 'to' => $description];
            }
            if ($existingVendor['contact_email'] !== $contactEmail) {
                $changes['contact_email'] = ['from' => $existingVendor['contact_email'], 'to' => $contactEmail];
            }
            if (($existingVendor['contact_phone'] ?? null) !== $contactPhone) {
                $changes['contact_phone'] = ['from' => $existingVendor['contact_phone'], 'to' => $contactPhone];
            }
            if (($existingVendor['legal_name'] ?? null) !== $legalName) {
                $changes['legal_name'] = ['from' => $existingVendor['legal_name'], 'to' => $legalName];
            }
            if (($existingVendor['store_address'] ?? null) !== $storeAddress) {
                $changes['store_address'] = ['from' => $existingVendor['store_address'], 'to' => $storeAddress];
            }
            if (($existingVendor['vat_status'] ?? null) !== $vatStatusVal) {
                $changes['vat_status'] = ['from' => $existingVendor['vat_status'], 'to' => $vatStatusVal];
            }
            if (($existingVendor['trade_license_number'] ?? null) !== $tradeLicNum) {
                $changes['trade_license_number'] = ['from' => $existingVendor['trade_license_number'], 'to' => $tradeLicNum];
            }
            if (($existingVendor['tax_registration_number'] ?? null) !== $taxRegNum) {
                $changes['tax_registration_number'] = ['from' => $existingVendor['tax_registration_number'], 'to' => $taxRegNum];
            }

            // is_store_approved tracks legacy store_approved
            $cmpBool = static fn ($v): bool => $v === true || $v === 't' || $v === '1' || $v === 1;
            if ($cmpBool($existingVendor['is_store_approved']) !== $isStoreApproved) {
                $changes['is_store_approved'] = ['from' => $existingVendor['is_store_approved'], 'to' => $isStoreApproved];
            }

            if (count($changes) === 0) {
                continue; // silent no-drift
            }

            try {
                $conn->executeStatement(
                    "UPDATE vendors SET
                        name = :name,
                        description = :description,
                        contact_email = :contact_email,
                        contact_phone = :contact_phone,
                        legal_name = :legal_name,
                        store_email = :store_email,
                        store_phone_raw = :store_phone_raw,
                        store_address = :store_address,
                        store_bank_name = :bank_name,
                        store_bank_account_name = :bank_acct_name,
                        store_bank_account_number = :bank_acct_num,
                        vat_status = :vat_status,
                        trade_license_number = :trade_lic,
                        licensing_authority = :lic_auth,
                        tax_registration_number = :tax_reg_num,
                        vat_registration_effective_date = :vat_reg_date,
                        registered_tax_address = :tax_addr,
                        tax_contact_email = :tax_email,
                        is_store_approved = :is_approved,
                        is_verified = :is_verified,
                        updated_at = date_trunc('second', NOW())
                     WHERE legacy_vendor_id = :legacy_id",
                    [
                        'legacy_id' => $userId,
                        'name' => $storeName,
                        'description' => $description,
                        'contact_email' => $contactEmail,
                        'contact_phone' => $contactPhone,
                        'legal_name' => $legalName,
                        'store_email' => $storeEmailVal,
                        'store_phone_raw' => $storePhoneRaw,
                        'store_address' => $storeAddress,
                        'bank_name' => $bankName,
                        'bank_acct_name' => $bankAcctName,
                        'bank_acct_num' => $bankAcctNum,
                        'vat_status' => $vatStatusVal,
                        'trade_lic' => $tradeLicNum,
                        'lic_auth' => $licAuth,
                        'tax_reg_num' => $taxRegNum,
                        'vat_reg_date' => $vatRegDate,
                        'tax_addr' => $taxAddr,
                        'tax_email' => $taxEmailVal,
                        'is_approved' => $isStoreApproved ? 'true' : 'false',
                        'is_verified' => $isStoreApproved ? 'true' : 'false',
                    ]
                );
            } catch (\Throwable $e) {
                $log->error('vendors', $userId, "UPDATE failed: " . $e->getMessage());
                $errors++;
                continue;
            }

            $log->info('vendors', $userId, "UPDATE (slug stable: '{$slug}')", $changes);
            $migrated++;
        }
    }

    // Reset sequence
    $conn->executeStatement(
        "SELECT setval('vendors_id_seq', COALESCE((SELECT MAX(id) FROM vendors), 0) + 1, false)"
    );

    $conn->commit();

    echo "\n===== vendors migration complete =====\n";
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

function parseLegacyDate(string $value): ?string
{
    $value = trim($value);
    if ($value === '' || $value === '0000-00-00') {
        return null;
    }
    try {
        $dt = new \DateTimeImmutable($value);
        return $dt->format('Y-m-d');
    } catch (\Throwable) {
        return null;
    }
}
