<?php

declare(strict_types=1);

namespace Bayti\Api\Migration;

use Doctrine\DBAL\Connection;

/**
 * Migration steps as composable in-process functions.
 *
 * Originally each migration phase was its own CLI script invoked by an
 * orchestrator via passthru(). aaPanel disables passthru() (along with
 * exec, shell_exec, system, popen) on the production CLI, so the
 * orchestrator died at step 1. Switching to proc_open was an option,
 * but a simpler design is to merge the steps into a single PHP process.
 *
 * This class holds the actual migration logic. Each method:
 *   - takes a Connection + LegacyDb + MigrationLog (shared state)
 *   - reads from legacy, writes to v3 via UPSERT
 *   - returns a simple [migrated, skipped, errors] result tuple
 *
 * The migrate-all.php orchestrator instantiates this once and calls each
 * method in dependency order. Individual migrate-X.php scripts are thin
 * shells that call ONE method (preserved for ad-hoc / debug use).
 *
 * Stable-field semantics
 * ----------------------
 * Per the Day 4 decisions:
 *   - Category slugs:  NEVER change after first sync
 *   - User emails:     NEVER change after first sync
 *   - Vendor slugs:    NEVER change after first sync
 *   - Product slugs:   NEVER change after first sync
 *   - Vendor owner:    NEVER changes after first sync (owner_user_id FK)
 *
 * Everything else is updated on re-sync to reflect legacy edits.
 */
final class MigrationSteps
{
    public function __construct(
        private readonly Connection $conn,
        private readonly LegacyDb $legacy,
        private readonly MigrationLog $log,
        private readonly Slugger $slugger,
    ) {
    }

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    public function migrateCategories(): array
    {
        echo "===== migrate-categories =====\n";

        // Reserve already-present slugs so we don't collide
        foreach ($this->conn->fetchAllAssociative('SELECT slug FROM categories') as $row) {
            $this->slugger->reserve($row['slug']);
        }

        $rows = $this->legacy->fetchAll(
            'SELECT category_id, icon, category_name, is_active FROM category ORDER BY category_id'
        );
        echo "  Found " . count($rows) . " legacy categories.\n\n";

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $this->conn->beginTransaction();
        try {
            foreach ($rows as $row) {
                $legacyId = (int) $row['category_id'];

                $name = trim((string) $row['category_name']);
                if ($name === '') {
                    $this->log->error('categories', $legacyId, 'Empty name — skipping');
                    $skipped++;
                    continue;
                }

                $icon = trim((string) ($row['icon'] ?? ''));
                $isActive = (bool) ((int) $row['is_active']);

                $existing = $this->conn->fetchAssociative(
                    'SELECT id, slug, name, icon, is_active FROM categories WHERE legacy_category_id = ?',
                    [$legacyId]
                );

                if ($existing === false) {
                    $slug = $this->slugger->make($name, fallback: 'cat-' . $legacyId);
                    if ($slug === null) {
                        $this->log->error('categories', $legacyId, "Slug generation failed for '{$name}'");
                        $errors++;
                        continue;
                    }

                    $this->conn->executeStatement(
                        "INSERT INTO categories
                            (legacy_category_id, slug, name, parent_id, path, display_order,
                             is_active, icon, created_at, updated_at)
                         VALUES
                            (:legacy_id, :slug, :name, NULL, :path, 0, :is_active, :icon, NOW(), NOW())",
                        [
                            'legacy_id' => $legacyId,
                            'slug' => $slug,
                            'name' => $name,
                            'path' => $slug,
                            'is_active' => $isActive ? 'true' : 'false',
                            'icon' => $icon !== '' ? $icon : null,
                        ]
                    );

                    $this->log->info('categories', $legacyId, "INSERT as '{$slug}' ({$name})");
                    $migrated++;
                } else {
                    $changes = [];
                    if ($existing['name'] !== $name) {
                        $changes['name'] = ['from' => $existing['name'], 'to' => $name];
                    }
                    if (((string) ($existing['icon'] ?? '')) !== $icon) {
                        $changes['icon'] = ['from' => $existing['icon'], 'to' => $icon];
                    }
                    $existingActive = $this->cmpBool($existing['is_active']);
                    if ($existingActive !== $isActive) {
                        $changes['is_active'] = ['from' => $existingActive, 'to' => $isActive];
                    }

                    if (count($changes) === 0) {
                        continue; // No drift
                    }

                    $this->conn->executeStatement(
                        "UPDATE categories
                         SET name = :name, icon = :icon, is_active = :is_active, updated_at = NOW()
                         WHERE legacy_category_id = :legacy_id",
                        [
                            'legacy_id' => $legacyId,
                            'name' => $name,
                            'icon' => $icon !== '' ? $icon : null,
                            'is_active' => $isActive ? 'true' : 'false',
                        ]
                    );

                    $this->log->info('categories', $legacyId, "UPDATE (slug stable: '{$existing['slug']}')", $changes);
                    $migrated++;
                }
            }

            $this->conn->executeStatement(
                "SELECT setval('categories_id_seq', COALESCE((SELECT MAX(id) FROM categories), 0) + 1, false)"
            );

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        echo "  migrated={$migrated} skipped={$skipped} errors={$errors}\n\n";
        return ['migrated' => $migrated, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @return array{migrated: int, skipped: int, errors: int, conflicts: int}
     */
    public function migrateUsers(): array
    {
        echo "===== migrate-users =====\n";

        // Pre-pass: build email collision map (winners = lowest user_id)
        echo "  Pre-pass: identifying email collisions...\n";
        $emailWinners = [];
        $userEmails = [];
        foreach ($this->legacy->iterate('SELECT user_id, email FROM users ORDER BY user_id') as $r) {
            $uid = (int) $r['user_id'];
            $email = strtolower(trim((string) $r['email']));
            if ($email === '') {
                continue;
            }
            $userEmails[$uid] = $email;
            if (!isset($emailWinners[$email])) {
                $emailWinners[$email] = $uid;
            }
        }
        $collisionCount = 0;
        foreach ($userEmails as $uid => $email) {
            if ($emailWinners[$email] !== $uid) {
                $collisionCount++;
            }
        }
        echo "  Found {$collisionCount} email collisions.\n\n";

        $totalRows = $this->legacy->count('users');
        echo "  Migrating {$totalRows} users...\n\n";

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $this->conn->beginTransaction();
        try {
            foreach ($this->legacy->iterate(
                "SELECT user_id, first_name, last_name, email, phone, countryCode,
                        password, is_customer, is_vendor, is_admin, is_active,
                        is_finance, is_support, _sub_admin, is_2fa, store_legal_name,
                        trade_license_number, created, last_login, store_name
                 FROM users
                 ORDER BY user_id"
            ) as $row) {
                $legacyId = (int) $row['user_id'];
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                if ($email === '') {
                    $this->log->skip('users', $legacyId, 'Empty email');
                    $skipped++;
                    continue;
                }

                $passwordHash = (string) ($row['password'] ?? '');
                if (strlen($passwordHash) !== 60 || !str_starts_with($passwordHash, '$2y$')) {
                    $this->log->warn('users', $legacyId, "Suspicious password hash (len=" . strlen($passwordHash) . ")");
                }

                $existingUser = $this->conn->fetchAssociative(
                    'SELECT id, email, phone, country_code, first_name, last_name,
                            password_hash, is_customer, is_vendor, is_admin, is_active,
                            is_finance, is_support, is_sub_admin, store_legal_name,
                            trade_license_number, is_2fa_enabled
                     FROM users WHERE legacy_user_id = ?',
                    [$legacyId]
                );

                $isCollisionLoser = ($emailWinners[$email] ?? null) !== $legacyId;
                $renamedEmail = null;
                if ($isCollisionLoser && $existingUser === false) {
                    $atPos = strrpos($email, '@');
                    if ($atPos === false) {
                        $this->log->skip('users', $legacyId, "Malformed email '{$email}'");
                        $skipped++;
                        continue;
                    }
                    $local = substr($email, 0, $atPos);
                    $domain = substr($email, $atPos);
                    $renamedEmail = $local . '+legacy' . $legacyId . $domain;
                }

                $countryCode = trim((string) ($row['countryCode'] ?? ''));
                $cc = match (true) {
                    $countryCode === '' => 'AE',
                    $countryCode === '+971' => 'AE',
                    $countryCode === '+966' => 'SA',
                    $countryCode === '+965' => 'KW',
                    $countryCode === '+974' => 'QA',
                    $countryCode === '+973' => 'BH',
                    $countryCode === '+968' => 'OM',
                    preg_match('/^[A-Z]{2}$/', $countryCode) === 1 => $countryCode,
                    default => 'AE',
                };

                $phone = trim((string) ($row['phone'] ?? ''));
                $phone = $phone === '' ? null : $phone;

                $created = $this->parseLegacyTimestamp((string) ($row['created'] ?? ''));
                $lastLogin = $this->parseLegacyTimestamp((string) ($row['last_login'] ?? ''));

                $storeName = trim((string) ($row['store_name'] ?? ''));
                $isReallyVendor = ((int) ($row['is_vendor'] ?? 0) === 1) && $storeName !== '';

                $isCustomer = (int) ($row['is_customer'] ?? 1) === 1;
                $isAdmin = (int) ($row['is_admin'] ?? 0) === 1;
                $isFinance = (int) ($row['is_finance'] ?? 0) === 1;
                $isSupport = (int) ($row['is_support'] ?? 0) === 1;
                $isSubAdmin = (int) ($row['_sub_admin'] ?? 0) === 1;
                $isActive = (int) ($row['is_active'] ?? 1) === 1;
                $is2fa = (int) ($row['is_2fa'] ?? 0) === 1;

                $firstName = trim((string) ($row['first_name'] ?? '')) ?: null;
                $lastName = trim((string) ($row['last_name'] ?? '')) ?: null;
                $storeLegalName = trim((string) ($row['store_legal_name'] ?? '')) ?: null;
                $tradeLicense = trim((string) ($row['trade_license_number'] ?? '')) ?: null;

                if ($existingUser === false) {
                    $insertEmail = $renamedEmail ?? $email;
                    try {
                        $this->conn->executeStatement(
                            "INSERT INTO users
                                (legacy_user_id, first_name, last_name, email, phone, country_code,
                                 password_hash, password_changed_at,
                                 is_customer, is_vendor, is_admin, is_finance, is_support, is_sub_admin,
                                 is_active, is_2fa_enabled, is_phone_verified, is_email_verified,
                                 locale, timezone,
                                 store_legal_name, trade_license_number,
                                 last_login_at, created_at, updated_at)
                             VALUES
                                (:legacy_id, :first_name, :last_name, :email, :phone, :cc,
                                 :pw_hash, NULL,
                                 :is_customer, :is_vendor, :is_admin, :is_finance, :is_support, :is_subadmin,
                                 :is_active, :is_2fa, FALSE, FALSE,
                                 'en', 'Asia/Dubai',
                                 :store_legal_name, :trade_license,
                                 :last_login, :created, NOW())",
                            [
                                'legacy_id' => $legacyId,
                                'first_name' => $firstName,
                                'last_name' => $lastName,
                                'email' => $insertEmail,
                                'phone' => $phone,
                                'cc' => $cc,
                                'pw_hash' => $passwordHash,
                                'is_customer' => $isCustomer ? 'true' : 'false',
                                'is_vendor' => $isReallyVendor ? 'true' : 'false',
                                'is_admin' => $isAdmin ? 'true' : 'false',
                                'is_finance' => $isFinance ? 'true' : 'false',
                                'is_support' => $isSupport ? 'true' : 'false',
                                'is_subadmin' => $isSubAdmin ? 'true' : 'false',
                                'is_active' => $isActive ? 'true' : 'false',
                                'is_2fa' => $is2fa ? 'true' : 'false',
                                'store_legal_name' => $storeLegalName,
                                'trade_license' => $tradeLicense,
                                'last_login' => $lastLogin,
                                'created' => $created ?? date('Y-m-d H:i:sP'),
                            ]
                        );
                    } catch (\Throwable $e) {
                        $this->log->error('users', $legacyId, "INSERT failed: " . $e->getMessage(), [
                            'email' => $insertEmail,
                        ]);
                        $errors++;
                        continue;
                    }

                    if ($renamedEmail !== null) {
                        $winnerLegacyId = $emailWinners[$email];
                        $winnerV3Id = $this->conn->fetchOne(
                            'SELECT id FROM users WHERE legacy_user_id = ?',
                            [$winnerLegacyId]
                        );
                        $newUserId = $this->conn->fetchOne(
                            'SELECT id FROM users WHERE legacy_user_id = ?',
                            [$legacyId]
                        );
                        $this->conn->executeStatement(
                            "INSERT INTO migration_email_conflicts
                                (legacy_user_id, v3_user_id, original_email, renamed_email,
                                 conflict_with_user_id, resolution_status, created_at)
                             VALUES
                                (:lid, :v3id, :orig, :renamed, :conflict_id, 'pending', NOW())
                             ON CONFLICT DO NOTHING",
                            [
                                'lid' => $legacyId,
                                'v3id' => $newUserId,
                                'orig' => $email,
                                'renamed' => $renamedEmail,
                                'conflict_id' => $winnerV3Id !== false ? $winnerV3Id : null,
                            ]
                        );
                        $this->log->warn('users', $legacyId, "Email collision -> renamed to {$renamedEmail}");
                    }
                    $migrated++;
                } else {
                    // UPDATE — email + password_changed_at stable
                    $changes = [];
                    $set = [];
                    $params = ['legacy_id' => $legacyId];

                    if ($existingUser['first_name'] !== $firstName) {
                        $changes['first_name'] = ['from' => $existingUser['first_name'], 'to' => $firstName];
                        $set[] = 'first_name = :first_name'; $params['first_name'] = $firstName;
                    }
                    if ($existingUser['last_name'] !== $lastName) {
                        $changes['last_name'] = ['from' => $existingUser['last_name'], 'to' => $lastName];
                        $set[] = 'last_name = :last_name'; $params['last_name'] = $lastName;
                    }
                    if ($existingUser['phone'] !== $phone) {
                        $changes['phone'] = ['from' => $existingUser['phone'], 'to' => $phone];
                        $set[] = 'phone = :phone'; $params['phone'] = $phone;
                    }
                    if ($existingUser['country_code'] !== $cc) {
                        $changes['country_code'] = ['from' => $existingUser['country_code'], 'to' => $cc];
                        $set[] = 'country_code = :cc'; $params['cc'] = $cc;
                    }
                    if ($this->cmpBool($existingUser['is_customer']) !== $isCustomer) {
                        $changes['is_customer'] = ['from' => $existingUser['is_customer'], 'to' => $isCustomer];
                        $set[] = 'is_customer = :is_customer'; $params['is_customer'] = $isCustomer ? 'true' : 'false';
                    }
                    if ($this->cmpBool($existingUser['is_vendor']) !== $isReallyVendor) {
                        $changes['is_vendor'] = ['from' => $existingUser['is_vendor'], 'to' => $isReallyVendor];
                        $set[] = 'is_vendor = :is_vendor'; $params['is_vendor'] = $isReallyVendor ? 'true' : 'false';
                    }
                    if ($this->cmpBool($existingUser['is_active']) !== $isActive) {
                        $changes['is_active'] = ['from' => $existingUser['is_active'], 'to' => $isActive];
                        $set[] = 'is_active = :is_active'; $params['is_active'] = $isActive ? 'true' : 'false';
                    }
                    if (($existingUser['store_legal_name'] ?? null) !== $storeLegalName) {
                        $changes['store_legal_name'] = ['from' => $existingUser['store_legal_name'], 'to' => $storeLegalName];
                        $set[] = 'store_legal_name = :sln'; $params['sln'] = $storeLegalName;
                    }
                    if (($existingUser['trade_license_number'] ?? null) !== $tradeLicense) {
                        $changes['trade_license_number'] = ['from' => $existingUser['trade_license_number'], 'to' => $tradeLicense];
                        $set[] = 'trade_license_number = :tln'; $params['tln'] = $tradeLicense;
                    }
                    if ($existingUser['password_hash'] !== $passwordHash && $passwordHash !== '') {
                        $changes['password_hash'] = ['from' => '***', 'to' => '*** (rotated)'];
                        $set[] = 'password_hash = :pw_hash'; $params['pw_hash'] = $passwordHash;
                    }

                    if (count($changes) === 0) {
                        continue;
                    }

                    $set[] = 'updated_at = NOW()';
                    $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE legacy_user_id = :legacy_id';
                    try {
                        $this->conn->executeStatement($sql, $params);
                    } catch (\Throwable $e) {
                        $this->log->error('users', $legacyId, "UPDATE failed: " . $e->getMessage());
                        $errors++;
                        continue;
                    }

                    $this->log->info('users', $legacyId, "UPDATE (email stable: '{$existingUser['email']}')", $changes);
                    $migrated++;
                }

                if (($migrated % 500) === 0 && $migrated > 0) {
                    echo "  ... {$migrated} processed\n";
                }
            }

            $this->conn->executeStatement(
                "SELECT setval('users_id_seq', COALESCE((SELECT MAX(id) FROM users), 0) + 1, false)"
            );

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        echo "  migrated={$migrated} skipped={$skipped} errors={$errors} conflicts={$collisionCount}\n\n";
        return ['migrated' => $migrated, 'skipped' => $skipped, 'errors' => $errors, 'conflicts' => $collisionCount];
    }

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    public function migrateVendors(): array
    {
        echo "===== migrate-vendors =====\n";

        foreach ($this->conn->fetchAllAssociative('SELECT slug FROM vendors') as $row) {
            $this->slugger->reserve($row['slug']);
        }

        $candidateUserIds = $this->legacy->fetchAll("
            SELECT DISTINCT user_id FROM (
                SELECT user_id FROM users
                WHERE is_vendor = 1 AND store_name IS NOT NULL AND store_name != ''
                UNION
                SELECT u.user_id FROM users u
                INNER JOIN products p ON p.store_id = u.user_id
                WHERE p.status IN ('published', 'draft')
            ) AS combined
        ");

        echo "  Found " . count($candidateUserIds) . " candidate vendors.\n\n";

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $this->conn->beginTransaction();
        try {
            foreach ($candidateUserIds as $candidate) {
                $userId = (int) $candidate['user_id'];

                $existingVendor = $this->conn->fetchAssociative(
                    'SELECT id, slug, name, description, contact_email, contact_phone,
                            is_active, is_verified, is_store_approved,
                            legal_name, store_email, store_phone_raw, store_address,
                            vat_status, trade_license_number, tax_registration_number,
                            registered_tax_address, tax_contact_email
                     FROM vendors WHERE legacy_vendor_id = ?',
                    [$userId]
                );

                $ownerV3Id = $this->conn->fetchOne(
                    'SELECT id FROM users WHERE legacy_user_id = ?',
                    [$userId]
                );
                if ($ownerV3Id === false) {
                    $this->log->skip('vendors', $userId, 'Owner user not migrated');
                    $skipped++;
                    continue;
                }

                $u = $this->legacy->fetchOne("
                    SELECT user_id, email, phone, countryCode, is_vendor,
                           store_name, store_legal_name, store_email, store_phone,
                           store_description, store_logo, store_cover, store_address,
                           store_bank_name, store_account_name, store_account_number,
                           store_status, store_approved,
                           vat_status, trade_license_number, licensing_authority,
                           tax_registration_number, vat_registration_effective_date,
                           registered_tax_address, tax_contact_email,
                           created
                    FROM users WHERE user_id = {$userId}
                ");

                if ($u === null) {
                    $errors++;
                    continue;
                }

                $storeName = trim((string) ($u['store_name'] ?? ''));
                $isSynthetic = false;
                if ($storeName === '') {
                    $emailLocal = explode('@', (string) $u['email'])[0] ?? 'store';
                    $clean = ucwords(str_replace(['.', '_', '-'], ' ', $emailLocal));
                    $storeName = 'Store - ' . $clean;
                    if (strlen($storeName) > 180) {
                        $storeName = substr($storeName, 0, 180);
                    }
                    $isSynthetic = true;
                }

                if ($existingVendor !== false) {
                    $slug = $existingVendor['slug'];
                } else {
                    $slug = $this->slugger->make($storeName, fallback: 'vendor-' . $userId);
                    if ($slug === null) {
                        $this->log->error('vendors', $userId, "Slug generation failed");
                        $errors++;
                        continue;
                    }
                }

                $contactEmail = trim((string) ($u['store_email'] ?? ''));
                if ($contactEmail === '') {
                    $contactEmail = trim((string) ($u['email'] ?? ''));
                }
                if ($contactEmail === '') {
                    $this->log->error('vendors', $userId, 'No email available');
                    $errors++;
                    continue;
                }

                $isStoreApproved = (int) ($u['store_approved'] ?? 0) === 1;
                $isActive = true;
                $vatRegDate = $this->parseLegacyDate((string) ($u['vat_registration_effective_date'] ?? ''));

                $logoBlob = $u['store_logo'] ?? null;
                $coverBlob = $u['store_cover'] ?? null;
                $logoDataUrl = is_string($logoBlob) && $logoBlob !== '' ? $logoBlob : null;
                $coverDataUrl = is_string($coverBlob) && $coverBlob !== '' ? $coverBlob : null;

                $created = $this->parseLegacyTimestamp((string) ($u['created'] ?? '')) ?? date('Y-m-d H:i:sP');

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
                    try {
                        $this->conn->executeStatement(
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
                                 :created, NOW())",
                            [
                                'legacy_id' => $userId, 'slug' => $slug, 'name' => $storeName,
                                'description' => $description, 'contact_email' => $contactEmail,
                                'contact_phone' => $contactPhone,
                                'is_active' => $isActive ? 'true' : 'false',
                                'is_verified' => $isStoreApproved ? 'true' : 'false',
                                'is_approved' => $isStoreApproved ? 'true' : 'false',
                                'owner_user_id' => $ownerV3Id,
                                'legal_name' => $legalName, 'store_email' => $storeEmailVal,
                                'store_phone_raw' => $storePhoneRaw, 'store_address' => $storeAddress,
                                'bank_name' => $bankName, 'bank_acct_name' => $bankAcctName,
                                'bank_acct_num' => $bankAcctNum,
                                'vat_status' => $vatStatusVal, 'trade_lic' => $tradeLicNum,
                                'lic_auth' => $licAuth, 'tax_reg_num' => $taxRegNum,
                                'vat_reg_date' => $vatRegDate,
                                'tax_addr' => $taxAddr, 'tax_email' => $taxEmailVal,
                                'legacy_logo' => $logoDataUrl, 'legacy_cover' => $coverDataUrl,
                                'created' => $created,
                            ]
                        );
                    } catch (\Throwable $e) {
                        $this->log->error('vendors', $userId, "INSERT failed: " . $e->getMessage());
                        $errors++;
                        continue;
                    }

                    $this->log->info('vendors', $userId, "INSERT as '{$slug}' ({$storeName})" . ($isSynthetic ? ' [SYNTHETIC]' : ''));
                    $migrated++;
                } else {
                    // UPDATE — slug + owner stable
                    $changes = [];
                    if ($existingVendor['name'] !== $storeName) {
                        $changes['name'] = ['from' => $existingVendor['name'], 'to' => $storeName];
                    }
                    if (($existingVendor['description'] ?? null) !== $description) {
                        $changes['description'] = ['from' => $existingVendor['description'], 'to' => $description];
                    }
                    if ($existingVendor['contact_email'] !== $contactEmail) {
                        $changes['contact_email'] = ['from' => $existingVendor['contact_email'], 'to' => $contactEmail];
                    }
                    if ($this->cmpBool($existingVendor['is_store_approved']) !== $isStoreApproved) {
                        $changes['is_store_approved'] = ['from' => $existingVendor['is_store_approved'], 'to' => $isStoreApproved];
                    }

                    if (count($changes) === 0) {
                        continue;
                    }

                    try {
                        $this->conn->executeStatement(
                            "UPDATE vendors SET
                                name = :name, description = :description,
                                contact_email = :contact_email, contact_phone = :contact_phone,
                                legal_name = :legal_name, store_email = :store_email,
                                store_phone_raw = :store_phone_raw, store_address = :store_address,
                                store_bank_name = :bank_name,
                                store_bank_account_name = :bank_acct_name,
                                store_bank_account_number = :bank_acct_num,
                                vat_status = :vat_status, trade_license_number = :trade_lic,
                                licensing_authority = :lic_auth,
                                tax_registration_number = :tax_reg_num,
                                vat_registration_effective_date = :vat_reg_date,
                                registered_tax_address = :tax_addr, tax_contact_email = :tax_email,
                                is_store_approved = :is_approved, is_verified = :is_verified,
                                updated_at = NOW()
                             WHERE legacy_vendor_id = :legacy_id",
                            [
                                'legacy_id' => $userId, 'name' => $storeName,
                                'description' => $description, 'contact_email' => $contactEmail,
                                'contact_phone' => $contactPhone,
                                'legal_name' => $legalName, 'store_email' => $storeEmailVal,
                                'store_phone_raw' => $storePhoneRaw, 'store_address' => $storeAddress,
                                'bank_name' => $bankName, 'bank_acct_name' => $bankAcctName,
                                'bank_acct_num' => $bankAcctNum,
                                'vat_status' => $vatStatusVal, 'trade_lic' => $tradeLicNum,
                                'lic_auth' => $licAuth, 'tax_reg_num' => $taxRegNum,
                                'vat_reg_date' => $vatRegDate,
                                'tax_addr' => $taxAddr, 'tax_email' => $taxEmailVal,
                                'is_approved' => $isStoreApproved ? 'true' : 'false',
                                'is_verified' => $isStoreApproved ? 'true' : 'false',
                            ]
                        );
                    } catch (\Throwable $e) {
                        $this->log->error('vendors', $userId, "UPDATE failed: " . $e->getMessage());
                        $errors++;
                        continue;
                    }

                    $this->log->info('vendors', $userId, "UPDATE (slug stable: '{$slug}')", $changes);
                    $migrated++;
                }
            }

            $this->conn->executeStatement(
                "SELECT setval('vendors_id_seq', COALESCE((SELECT MAX(id) FROM vendors), 0) + 1, false)"
            );

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        echo "  migrated={$migrated} skipped={$skipped} errors={$errors}\n\n";
        return ['migrated' => $migrated, 'skipped' => $skipped, 'errors' => $errors];
    }

    private const LEGACY_PRODUCT_IMAGE_HOST = 'https://api.3bayti.ae/vendors/products/';

    private const SIZE_COLUMN_MAP = [
        'size_xs' => 'XS', 'size_s' => 'S', 'size_m' => 'M', 'size_l' => 'L',
        'size_xl' => 'XL', 'size_xxl' => 'XXL',
        'size_50' => '50', 'size_51' => '51', 'size_52' => '52', 'size_53' => '53',
        'size_54' => '54', 'size_55' => '55', 'size_56' => '56', 'size_57' => '57',
        'size_58' => '58', 'size_59' => '59', 'size_60' => '60', 'size_61' => '61',
        'size_62' => '62', 'size_63' => '63', 'size_64' => '64',
        'size_custom' => 'CUSTOM',
    ];

    private const STATUS_MAP = [
        'published' => 'active',
        'deleted' => 'soft_deleted',
        'draft' => 'draft',
    ];

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    public function migrateProducts(): array
    {
        echo "===== migrate-products =====\n";

        foreach ($this->conn->fetchAllAssociative('SELECT slug FROM products') as $row) {
            $this->slugger->reserve($row['slug']);
        }

        echo "  Building lookup maps...\n";
        $vendorMap = [];
        foreach ($this->conn->fetchAllAssociative('SELECT id, legacy_vendor_id FROM vendors WHERE legacy_vendor_id IS NOT NULL') as $row) {
            $vendorMap[(int) $row['legacy_vendor_id']] = (int) $row['id'];
        }
        $categoryMap = [];
        foreach ($this->conn->fetchAllAssociative('SELECT id, legacy_category_id FROM categories WHERE legacy_category_id IS NOT NULL') as $row) {
            $categoryMap[(int) $row['legacy_category_id']] = (int) $row['id'];
        }
        echo "  Vendors: " . count($vendorMap) . " mapped, Categories: " . count($categoryMap) . " mapped\n";

        $totalRows = $this->legacy->count('products');
        echo "  Migrating {$totalRows} products...\n\n";

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $sizeCols = implode(', ', array_keys(self::SIZE_COLUMN_MAP));
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
                label, collection, colors,
                {$sizeCols}
            FROM products
            ORDER BY product_id
        ";

        $this->conn->beginTransaction();
        try {
            foreach ($this->legacy->iterate($sql) as $row) {
                $legacyId = (int) $row['product_id'];

                $existingProduct = $this->conn->fetchAssociative(
                    'SELECT id, slug FROM products WHERE legacy_product_id = ?',
                    [$legacyId]
                );

                $legacyStoreId = (int) ($row['store_id'] ?? 0);
                $vendorId = $vendorMap[$legacyStoreId] ?? null;
                if ($vendorId === null) {
                    $this->log->skip('products', $legacyId, "Vendor not found for store_id={$legacyStoreId}");
                    $skipped++;
                    continue;
                }

                $legacyCategoryId = (int) ($row['category_id'] ?? 0);
                $categoryId = $categoryMap[$legacyCategoryId] ?? null;

                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    $this->log->skip('products', $legacyId, 'Empty name');
                    $skipped++;
                    continue;
                }

                if ($existingProduct !== false) {
                    $slug = $existingProduct['slug'];
                } else {
                    $slug = $this->slugger->make($name, fallback: 'product-' . $legacyId);
                    if ($slug === null) {
                        $this->log->error('products', $legacyId, "Slug generation failed");
                        $errors++;
                        continue;
                    }
                }

                $legacyStatus = (string) ($row['status'] ?? 'draft');
                $v3Status = self::STATUS_MAP[$legacyStatus] ?? 'draft';
                $isActive = $v3Status === 'active';

                $availableSizes = [];
                foreach (self::SIZE_COLUMN_MAP as $col => $label) {
                    if ((int) ($row[$col] ?? 0) === 1) {
                        $availableSizes[] = $label;
                    }
                }

                $colorsRaw = (string) ($row['colors'] ?? '');
                $availableColors = array_values(array_filter(
                    array_map('trim', explode(',', $colorsRaw)),
                    static fn (string $c) => $c !== ''
                ));

                $primaryImage = trim((string) ($row['image_1'] ?? ''));
                $primaryImageUrl = null;
                if ($primaryImage !== '' && !str_starts_with($primaryImage, 'assets/img/placeholder')) {
                    if (str_starts_with($primaryImage, 'http://') || str_starts_with($primaryImage, 'https://')) {
                        $primaryImageUrl = $primaryImage;
                    } elseif (str_starts_with($primaryImage, 'products_images/')) {
                        $primaryImageUrl = self::LEGACY_PRODUCT_IMAGE_HOST . substr($primaryImage, strlen('products_images/'));
                    } else {
                        $primaryImageUrl = self::LEGACY_PRODUCT_IMAGE_HOST . ltrim($primaryImage, '/');
                    }
                }

                $imagesRaw = $row['images'] ?? null;
                $imageUrls = [];
                if (is_string($imagesRaw) && $imagesRaw !== '') {
                    $parsed = json_decode($imagesRaw, true);
                    if (is_array($parsed)) {
                        foreach ($parsed as $path) {
                            if (!is_string($path) || $path === '') continue;
                            if (str_starts_with($path, 'assets/img/placeholder')) continue;
                            if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
                                $imageUrls[] = $path;
                            } elseif (str_starts_with($path, 'products_images/')) {
                                $imageUrls[] = self::LEGACY_PRODUCT_IMAGE_HOST . substr($path, strlen('products_images/'));
                            } else {
                                $imageUrls[] = self::LEGACY_PRODUCT_IMAGE_HOST . ltrim($path, '/');
                            }
                        }
                    }
                }

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

                $price = $row['price'] !== null ? number_format((float) $row['price'], 2, '.', '') : '0.00';
                $salePrice = $row['sale_price'] !== null
                    ? number_format((float) $row['sale_price'], 2, '.', '')
                    : null;
                $cost = $row['cost_per_item'] !== null
                    ? number_format((float) $row['cost_per_item'], 2, '.', '')
                    : null;

                $stockQuantity = max(0, (int) ($row['quantity'] ?? 0));
                $allowOversell = (int) ($row['allow_checkout_when_out_of_stock'] ?? 0) === 1;
                $stockStatus = (string) ($row['stock_status'] ?? 'in_stock');
                if (!in_array($stockStatus, ['in_stock', 'out_of_stock', 'limited', 'on_backorder'], true)) {
                    $stockStatus = 'in_stock';
                }

                $createdAt = $this->parseLegacyTimestamp((string) ($row['created_at'] ?? '')) ?? date('Y-m-d H:i:sP');
                $updatedAt = $this->parseLegacyTimestamp((string) ($row['updated_at'] ?? '')) ?? $createdAt;

                try {
                    $this->conn->executeStatement(
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
                             delivery_info, label_id,
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
                             :delivery::jsonb, :label_id,
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
                             updated_at = NOW()",
                        [
                            'legacy_id' => $legacyId, 'vendor_id' => $vendorId,
                            'category_id' => $categoryId, 'slug' => $slug,
                            'name' => $name,
                            'description' => trim((string) ($row['description'] ?? '')) ?: null,
                            'status' => $v3Status,
                            'is_active' => $isActive ? 'true' : 'false',
                            'price' => $price, 'sale_price' => $salePrice, 'cost' => $cost,
                            'stock_qty' => $stockQuantity,
                            'allow_oversell' => $allowOversell ? 'true' : 'false',
                            'stock_status' => $stockStatus,
                            'min_qty' => $row['minimum_order_quantity'] !== null
                                ? (int) $row['minimum_order_quantity'] : null,
                            'max_qty' => $row['maximum_order_quantity'] !== null
                                && (int) $row['maximum_order_quantity'] > 0
                                ? (int) $row['maximum_order_quantity'] : null,
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
                            'created_at' => $createdAt, 'updated_at' => $updatedAt,
                        ]
                    );
                } catch (\Throwable $e) {
                    $this->log->error('products', $legacyId, "UPSERT failed: " . $e->getMessage(), [
                        'slug' => $slug, 'name' => substr($name, 0, 60),
                    ]);
                    $errors++;
                    continue;
                }

                $migrated++;
                if ($migrated % 200 === 0) {
                    echo "  ... {$migrated} processed\n";
                }
            }

            $this->conn->executeStatement(
                "SELECT setval('products_id_seq', COALESCE((SELECT MAX(id) FROM products), 0) + 1, false)"
            );

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        echo "  migrated={$migrated} skipped={$skipped} errors={$errors}\n\n";
        return ['migrated' => $migrated, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * @return array{migrated: int, skipped: int, errors: int}
     */
    public function migrateReviews(): array
    {
        echo "===== migrate-reviews =====\n";

        $vendorMap = [];
        foreach ($this->conn->fetchAllAssociative('SELECT id, legacy_vendor_id FROM vendors WHERE legacy_vendor_id IS NOT NULL') as $r) {
            $vendorMap[(int) $r['legacy_vendor_id']] = (int) $r['id'];
        }
        $userMap = [];
        foreach ($this->conn->fetchAllAssociative('SELECT id, legacy_user_id FROM users WHERE legacy_user_id IS NOT NULL') as $r) {
            $userMap[(int) $r['legacy_user_id']] = (int) $r['id'];
        }

        $rows = $this->legacy->fetchAll(
            'SELECT id, customer_id, store_id, customer_name, customer_email,
                    product_name, star, title, comment, vendor_reply, status, created_at
             FROM ec_reviews ORDER BY id'
        );
        echo "  Found " . count($rows) . " legacy reviews.\n\n";

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $this->conn->beginTransaction();
        try {
            foreach ($rows as $row) {
                $legacyId = (int) $row['id'];

                $vendorLegacyId = (int) ($row['store_id'] ?? 0);
                $vendorId = $vendorMap[$vendorLegacyId] ?? null;
                if ($vendorId === null) {
                    $this->log->skip('reviews', $legacyId, "Vendor not migrated for store_id={$vendorLegacyId}");
                    $skipped++;
                    continue;
                }

                $customerLegacyId = (int) ($row['customer_id'] ?? 0);
                $userId = $userMap[$customerLegacyId] ?? null;

                $star = (float) ($row['star'] ?? 0);
                if ($star < 0 || $star > 5) {
                    $star = max(0.0, min(5.0, $star));
                }
                $starFormatted = number_format($star, 1, '.', '');

                $createdAt = $this->parseLegacyTimestamp((string) ($row['created_at'] ?? '')) ?? date('Y-m-d H:i:sP');

                try {
                    $this->conn->executeStatement(
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
                             :created, NOW())
                         ON CONFLICT (legacy_review_id) DO UPDATE SET
                             vendor_id = EXCLUDED.vendor_id,
                             user_id = EXCLUDED.user_id,
                             reviewer_name = EXCLUDED.reviewer_name,
                             reviewer_email = EXCLUDED.reviewer_email,
                             product_name_snapshot = EXCLUDED.product_name_snapshot,
                             star = EXCLUDED.star,
                             title = EXCLUDED.title,
                             comment = EXCLUDED.comment,
                             vendor_reply = EXCLUDED.vendor_reply,
                             updated_at = NOW()",
                        [
                            'legacy_id' => $legacyId,
                            'vendor_id' => $vendorId, 'user_id' => $userId,
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
                    $this->log->error('reviews', $legacyId, "UPSERT failed: " . $e->getMessage());
                    $errors++;
                    continue;
                }
                $migrated++;
            }

            $this->conn->executeStatement(
                "SELECT setval('product_reviews_id_seq', COALESCE((SELECT MAX(id) FROM product_reviews), 0) + 1, false)"
            );

            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        echo "  migrated={$migrated} skipped={$skipped} errors={$errors}\n\n";
        return ['migrated' => $migrated, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Wipe fictional M2.1.B seed catalog rows. Use on INITIAL migration only.
     *
     * @return array{brands: int, categories: int, vendors: int}
     */
    public function wipeFictionalSeed(): array
    {
        echo "===== wipe-fictional-seed =====\n";

        $brandCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM brands');
        $categoryCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM categories');
        $vendorCount = (int) $this->conn->fetchOne('SELECT COUNT(*) FROM vendors');

        echo "  Before: brands={$brandCount}, categories={$categoryCount}, vendors={$vendorCount}\n";

        $this->conn->beginTransaction();
        try {
            $this->conn->executeStatement(
                "DELETE FROM audit_log WHERE subject_type IN ('Brand', 'Vendor', 'Category')"
            );
            $this->conn->executeStatement('DELETE FROM brands');
            $this->conn->executeStatement('DELETE FROM categories');
            $this->conn->executeStatement('DELETE FROM vendors');
            $this->conn->executeStatement('ALTER SEQUENCE brands_id_seq RESTART WITH 1');
            $this->conn->executeStatement('ALTER SEQUENCE categories_id_seq RESTART WITH 1');
            $this->conn->executeStatement('ALTER SEQUENCE vendors_id_seq RESTART WITH 1');
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            throw $e;
        }

        echo "  After: brands=0, categories=0, vendors=0 ✓\n\n";
        return ['brands' => $brandCount, 'categories' => $categoryCount, 'vendors' => $vendorCount];
    }

    // --- helpers ---

    private function cmpBool(mixed $v): bool
    {
        return $v === true || $v === 't' || $v === '1' || $v === 1;
    }

    private function parseLegacyTimestamp(string $value): ?string
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

    private function parseLegacyDate(string $value): ?string
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
}
