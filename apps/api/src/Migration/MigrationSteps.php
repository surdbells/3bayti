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
                         SET name = :name, icon = :icon, is_active = :is_active, updated_at = date_trunc('second', NOW())
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
            try {
                if ($this->conn->isTransactionActive()) {
                    $this->conn->rollBack();
                }
            } catch (\Throwable) {}
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
                            trade_license_number, is_2fa
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
                                 is_active, is_2fa, is_phone_verified, is_email_verified,
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
                                 :last_login, :created, date_trunc('second', NOW()))",
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
                                (:lid, :v3id, :orig, :renamed, :conflict_id, 'pending', date_trunc('second', NOW()))
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

                    $set[] = "updated_at = date_trunc('second', NOW())";
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
            try {
                if ($this->conn->isTransactionActive()) {
                    $this->conn->rollBack();
                }
            } catch (\Throwable) {}
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
                                 :created, date_trunc('second', NOW()))",
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
                                updated_at = date_trunc('second', NOW())
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
            try {
                if ($this->conn->isTransactionActive()) {
                    $this->conn->rollBack();
                }
            } catch (\Throwable) {}
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
                             label_id = COALESCE(products.label_id, EXCLUDED.label_id),
                             updated_at = date_trunc('second', NOW())",
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
                            'label_id' => null, // populated by migrateVendorLabels() remap
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
            try {
                if ($this->conn->isTransactionActive()) {
                    $this->conn->rollBack();
                }
            } catch (\Throwable) {}
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
                             :created, date_trunc('second', NOW()))
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
                             updated_at = date_trunc('second', NOW())",
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
            try {
                if ($this->conn->isTransactionActive()) {
                    $this->conn->rollBack();
                }
            } catch (\Throwable) {}
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
            try {
                if ($this->conn->isTransactionActive()) {
                    $this->conn->rollBack();
                }
            } catch (\Throwable) {}
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

    /**
     * @return array{migrated: int, skipped: int, errors: int, status: string}
     */
    public function migrateVendorLabels(): array
    {
        echo "===== migrate-vendor-labels =====\n";

        // The legacy schema for labels is not fully understood from
        // the v3 codebase alone — Phase 0 reconnaissance in M3.1.5.5
        // couldn't identify the exact legacy table name. Probe two
        // candidates that match the legacy naming convention; skip
        // gracefully if neither exists.
        //
        // CANDIDATE 1: `labels` — singular-table convention used by
        //   the legacy `category` table (already migrated). Most
        //   likely match.
        // CANDIDATE 2: `vendor_collections` — alternative based on
        //   the legacy endpoint name (read_vendor_collection).
        //
        // Operator can extend this list by editing the array if
        // their legacy schema uses a different name. Once production
        // run identifies the actual table, this can be simplified
        // to a single hard-coded name.
        $candidates = ['vendor_custom_labels', 'labels', 'vendor_collections'];
        $sourceTable = null;
        foreach ($candidates as $name) {
            if ($this->legacy->tableExists($name)) {
                $sourceTable = $name;
                break;
            }
        }

        if ($sourceTable === null) {
            echo "  Legacy labels table not found (probed: " . implode(', ', $candidates) . ").\n";
            echo "  Skipping vendor_labels migration. Edit migrateVendorLabels()\n";
            echo "  candidates array if the legacy table has a different name.\n\n";
            return [
                'migrated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'status' => 'skipped_no_legacy_table',
            ];
        }

        // Column probe — different legacy schemas may have used
        // `id` vs `label_id` for the PK, `vendor_id` vs `store_id`
        // for the vendor FK, etc. Use the columns we find.
        $idCol = $this->legacy->columnExists($sourceTable, 'label_id') ? 'label_id'
            : ($this->legacy->columnExists($sourceTable, 'vcl_id') ? 'vcl_id'
            : ($this->legacy->columnExists($sourceTable, 'id') ? 'id' : null));
        $nameCol = $this->legacy->columnExists($sourceTable, 'label_name') ? 'label_name'
            : ($this->legacy->columnExists($sourceTable, 'label') ? 'label'
            : ($this->legacy->columnExists($sourceTable, 'name') ? 'name' : null));
        $vendorCol = $this->legacy->columnExists($sourceTable, 'store_id') ? 'store_id'
            : ($this->legacy->columnExists($sourceTable, 'user_id') ? 'user_id'
            : ($this->legacy->columnExists($sourceTable, 'vendor_id') ? 'vendor_id' : null));

        if ($idCol === null || $nameCol === null || $vendorCol === null) {
            echo "  Legacy table '{$sourceTable}' has unexpected column shape.\n";
            echo "  Expected id-like, name-like, vendor-like columns; got:\n";
            echo "    id=" . ($idCol ?? '(missing)') . " name=" . ($nameCol ?? '(missing)')
                . " vendor=" . ($vendorCol ?? '(missing)') . "\n\n";
            return [
                'migrated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'status' => 'skipped_unexpected_columns',
            ];
        }

        $rows = $this->legacy->fetchAll(
            "SELECT {$idCol} AS lid, {$nameCol} AS lname, {$vendorCol} AS lvendor "
            . "FROM `{$sourceTable}` ORDER BY {$idCol}"
        );
        echo "  Found " . count($rows) . " legacy labels in '{$sourceTable}'.\n\n";

        // Reserve already-present slugs so we don't collide. Note:
        // slugs are per-vendor-unique, not globally, so the slugger's
        // global reservation is over-strict here. For M3.1.5.5
        // pragmatism, accept the over-strict behaviour — slug
        // collisions across vendors are rare and the slugger appends
        // a numeric suffix when needed.
        foreach ($this->conn->fetchAllAssociative('SELECT slug FROM vendor_labels') as $row) {
            $this->slugger->reserve($row['slug']);
        }

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $this->conn->beginTransaction();
        try {
            foreach ($rows as $row) {
                $legacyId = (int) $row['lid'];
                $name = trim((string) $row['lname']);
                $legacyVendorId = (int) $row['lvendor'];

                if ($name === '') {
                    $this->log->error('vendor_labels', $legacyId, 'Empty name — skipping');
                    $skipped++;
                    continue;
                }

                // Resolve the legacy vendor id to the v3 vendor.
                $v3VendorId = $this->conn->fetchOne(
                    'SELECT id FROM vendors WHERE legacy_vendor_id = ?',
                    [$legacyVendorId]
                );

                if ($v3VendorId === false || $v3VendorId === null) {
                    $this->log->error(
                        'vendor_labels',
                        $legacyId,
                        "Orphan: legacy vendor {$legacyVendorId} not found in v3"
                    );
                    $skipped++;
                    continue;
                }
                $v3VendorId = (int) $v3VendorId;

                $existing = $this->conn->fetchAssociative(
                    'SELECT id, slug, name FROM vendor_labels WHERE legacy_label_id = ?',
                    [$legacyId]
                );

                if ($existing === false) {
                    $slug = $this->slugger->make($name, fallback: 'label-' . $legacyId);
                    if ($slug === null) {
                        $this->log->error('vendor_labels', $legacyId, "Slug generation failed for '{$name}'");
                        $errors++;
                        continue;
                    }

                    $this->conn->executeStatement(
                        "INSERT INTO vendor_labels
                            (legacy_label_id, vendor_id, slug, name, display_order,
                             is_active, created_at, updated_at)
                         VALUES
                            (:legacy_id, :vendor_id, :slug, :name, NULL, TRUE,
                             date_trunc('second', NOW()), date_trunc('second', NOW()))",
                        [
                            'legacy_id' => $legacyId,
                            'vendor_id' => $v3VendorId,
                            'slug' => $slug,
                            'name' => $name,
                        ]
                    );

                    $this->log->info('vendor_labels', $legacyId, "INSERT as '{$slug}' ({$name})");
                    $migrated++;
                } else {
                    if ($existing['name'] !== $name) {
                        $this->conn->executeStatement(
                            "UPDATE vendor_labels
                             SET name = :name, updated_at = date_trunc('second', NOW())
                             WHERE legacy_label_id = :legacy_id",
                            ['legacy_id' => $legacyId, 'name' => $name]
                        );
                        $this->log->info(
                            'vendor_labels',
                            $legacyId,
                            "UPDATE name (slug stable: '{$existing['slug']}')",
                            ['name' => ['from' => $existing['name'], 'to' => $name]]
                        );
                        $migrated++;
                    }
                }
            }

            // Remap: re-read legacy products.label column and match to
            // vendor_labels.legacy_label_id now that labels are inserted.
            // label_id was stored as NULL during migrateProducts() to avoid
            // the FK violation (vendor_labels was empty at that point).
            $legacyLabelled = $this->legacy->fetchAll(
                "SELECT product_id, label FROM products WHERE label IS NOT NULL AND label != 0"
            );
            $remapped = 0;
            foreach ($legacyLabelled as $lp) {
                $v3LabelId = $this->conn->fetchOne(
                    'SELECT id FROM vendor_labels WHERE legacy_label_id = ?',
                    [(int) $lp['label']]
                );
                if ($v3LabelId === false || $v3LabelId === null) {
                    continue;
                }
                $remapped += (int) $this->conn->executeStatement(
                    'UPDATE products SET label_id = ? WHERE legacy_product_id = ?',
                    [(int) $v3LabelId, (int) $lp['product_id']]
                );
            }
            echo "  Remapped {$remapped} products.label_id from legacy → v3 ids.\n";

            // Validate the FK now that data is consistent.
            $this->conn->executeStatement('ALTER TABLE products VALIDATE CONSTRAINT fk_products_label');
            echo "  Validated fk_products_label.\n";

            $this->conn->executeStatement(
                "SELECT setval('vendor_labels_id_seq', COALESCE((SELECT MAX(id) FROM vendor_labels), 0) + 1, false)"
            );

            $this->conn->commit();
        } catch (\Throwable $e) {
            try {
                if ($this->conn->isTransactionActive()) {
                    $this->conn->rollBack();
                }
            } catch (\Throwable) {}
            throw $e;
        }

        echo "  migrated={$migrated} skipped={$skipped} errors={$errors}\n\n";
        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'errors' => $errors,
            'status' => 'completed',
        ];
    }

    /**
     * @return array{migrated: int, skipped: int, errors: int, status: string}
     */
    public function migrateStyles(): array
    {
        echo "===== migrate-styles =====\n";

        // Same defensive probe pattern as migrateVendorLabels: the
        // exact legacy schema for styles isn't fully understood.
        // Candidates based on naming conventions visible in the
        // legacy codebase:
        //   CANDIDATE 1: `styles` — most likely.
        //   CANDIDATE 2: `stylist` — alternative based on mobile
        //                folder name (customer/styles/).
        $candidates = ['styles', 'collections', 'stylist'];
        $sourceTable = null;
        foreach ($candidates as $name) {
            if ($this->legacy->tableExists($name)) {
                $sourceTable = $name;
                break;
            }
        }

        if ($sourceTable === null) {
            echo "  Legacy styles table not found (probed: " . implode(', ', $candidates) . ").\n";
            echo "  Skipping styles migration. Edit migrateStyles() candidates\n";
            echo "  array if the legacy table has a different name.\n\n";
            return [
                'migrated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'status' => 'skipped_no_legacy_table',
            ];
        }

        // Column probe.
        $idCol = $this->legacy->columnExists($sourceTable, 'id') ? 'id'
            : ($this->legacy->columnExists($sourceTable, 'collections_id') ? 'collections_id'
            : ($this->legacy->columnExists($sourceTable, 'style_id') ? 'style_id' : null));
        $nameCol = $this->legacy->columnExists($sourceTable, 'style_name') ? 'style_name'
            : ($this->legacy->columnExists($sourceTable, 'collection_name') ? 'collection_name'
            : ($this->legacy->columnExists($sourceTable, 'name') ? 'name' : null));
        $typeCol = $this->legacy->columnExists($sourceTable, 'type') ? 'type' : null;
        // total_price is optional; if absent, default to 0.
        $totalPriceCol = $this->legacy->columnExists($sourceTable, 'total_price') ? 'total_price' : null;
        // cover_image_url field name varies legacy/v3.
        $coverCol = $this->legacy->columnExists($sourceTable, 'cover_image') ? 'cover_image'
            : ($this->legacy->columnExists($sourceTable, 'image') ? 'image' : null);

        if ($idCol === null || $nameCol === null) {
            echo "  Legacy table '{$sourceTable}' missing required id/name columns.\n";
            echo "    id={$idCol} name={$nameCol}\n\n";
            return [
                'migrated' => 0,
                'skipped' => 0,
                'errors' => 0,
                'status' => 'skipped_unexpected_columns',
            ];
        }

        $selectCols = "{$idCol} AS lid, {$nameCol} AS lname";
        if ($typeCol !== null) {
            $selectCols .= ", {$typeCol} AS ltype";
        }
        if ($totalPriceCol !== null) {
            $selectCols .= ", {$totalPriceCol} AS ltotal";
        }
        if ($coverCol !== null) {
            $selectCols .= ", {$coverCol} AS lcover";
        }

        $rows = $this->legacy->fetchAll(
            "SELECT {$selectCols} FROM `{$sourceTable}` ORDER BY {$idCol}"
        );
        echo "  Found " . count($rows) . " legacy styles in '{$sourceTable}'.\n\n";

        foreach ($this->conn->fetchAllAssociative('SELECT slug FROM styles') as $row) {
            $this->slugger->reserve($row['slug']);
        }

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $this->conn->beginTransaction();
        try {
            foreach ($rows as $row) {
                $legacyId = (int) $row['lid'];
                $name = trim((string) $row['lname']);
                $legacyType = isset($row['ltype']) ? (string) $row['ltype'] : 'community';
                $totalPrice = isset($row['ltotal']) ? (string) $row['ltotal'] : '0.00';
                $cover = isset($row['lcover']) ? trim((string) $row['lcover']) : '';

                if ($name === '') {
                    $this->log->error('styles', $legacyId, 'Empty name — skipping');
                    $skipped++;
                    continue;
                }

                // Map legacy string type to v3 smallint enum.
                // Default to community for unknown values; log as info.
                $styleType = strtolower(trim($legacyType)) === 'editorial' ? 2 : 1;

                $existing = $this->conn->fetchAssociative(
                    'SELECT id, slug, name FROM styles WHERE legacy_style_id = ?',
                    [$legacyId]
                );

                if ($existing === false) {
                    $slug = $this->slugger->make($name, fallback: 'style-' . $legacyId);
                    if ($slug === null) {
                        $this->log->error('styles', $legacyId, "Slug generation failed for '{$name}'");
                        $errors++;
                        continue;
                    }

                    $this->conn->executeStatement(
                        "INSERT INTO styles
                            (legacy_style_id, slug, name, description, cover_image_url,
                             style_type, total_price, is_active, display_order,
                             created_at, updated_at)
                         VALUES
                            (:legacy_id, :slug, :name, NULL, :cover, :type, :price,
                             TRUE, NULL,
                             date_trunc('second', NOW()), date_trunc('second', NOW()))",
                        [
                            'legacy_id' => $legacyId,
                            'slug' => $slug,
                            'name' => $name,
                            'cover' => $cover !== '' ? $cover : null,
                            'type' => $styleType,
                            'price' => $totalPrice,
                        ]
                    );

                    $this->log->info('styles', $legacyId, "INSERT as '{$slug}' ({$name}, type={$styleType})");
                    $migrated++;
                } else {
                    if ($existing['name'] !== $name) {
                        $this->conn->executeStatement(
                            "UPDATE styles
                             SET name = :name, updated_at = date_trunc('second', NOW())
                             WHERE legacy_style_id = :legacy_id",
                            ['legacy_id' => $legacyId, 'name' => $name]
                        );
                        $this->log->info(
                            'styles',
                            $legacyId,
                            "UPDATE name (slug stable: '{$existing['slug']}')",
                            ['name' => ['from' => $existing['name'], 'to' => $name]]
                        );
                        $migrated++;
                    }
                }
            }

            $this->conn->executeStatement(
                "SELECT setval('styles_id_seq', COALESCE((SELECT MAX(id) FROM styles), 0) + 1, false)"
            );

            $this->conn->commit();
        } catch (\Throwable $e) {
            try {
                if ($this->conn->isTransactionActive()) {
                    $this->conn->rollBack();
                }
            } catch (\Throwable) {}
            throw $e;
        }

        echo "  migrated={$migrated} skipped={$skipped} errors={$errors}\n";
        echo "  Note: style_products join rows NOT migrated by this method —\n";
        echo "  the legacy schema for product membership is not yet known.\n";
        echo "  Run a separate migrate-style-products step once the join\n";
        echo "  table shape is identified (legacy candidate: 'stylist_products').\n\n";

        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'errors' => $errors,
            'status' => 'completed',
        ];
    }

    // ===================================================================
    // M3.1.6h — Order migration (orders, order_items, order_addresses)
    // ===================================================================
    //
    // The legacy schema for these tables isn't fully documented in this
    // sandbox. The methods below use the same defensive probing pattern
    // as migrateStyles/migrateVendorLabels: probe table names, probe
    // columns, gracefully skip with explanatory output if the schema
    // doesn't match expectations.
    //
    // DEPLOY OPERATOR ACTION REQUIRED before running --include-orders:
    //   1. Verify the legacy table names match one of the probed candidates
    //      (see candidate arrays in each method below). If your legacy
    //      schema uses different names, edit the candidates lists.
    //   2. Verify column names match expectations. If they don't, the
    //      column probe will skip the migration with output describing
    //      what was missing.
    //   3. Test with --dry-run first on a staging copy.
    //   4. Carts are NOT migrated: they're transient state. Active legacy
    //      carts will simply be lost on cutover; users see an empty v3
    //      cart on first login and re-add items. This is documented in
    //      the M3.1.6 plan as an explicit decision.

    /**
     * @return array{migrated: int, skipped: int, errors: int, status: string}
     */
    public function migrateOrders(): array
    {
        echo "===== migrate-orders =====\n";

        // Probe table name. Legacy candidates ordered by likelihood.
        $candidates = ['orders', 'order', 'order_master', 'customer_orders'];
        $sourceTable = $this->probeTable($candidates);
        if ($sourceTable === null) {
            return $this->skipMissingTable('orders', $candidates);
        }
        echo "  Source table: {$sourceTable}\n";

        // Probe required columns.
        $idCol = $this->firstPresentColumn($sourceTable, ['order_id', 'id']);
        $userCol = $this->firstPresentColumn($sourceTable, ['user_id', 'customer_id']);
        $totalCol = $this->firstPresentColumn($sourceTable, ['total', 'total_amount', 'grand_total']);
        $statusCol = $this->firstPresentColumn($sourceTable, ['status', 'order_status', 'payment_status']);
        $createdCol = $this->firstPresentColumn($sourceTable, ['created', 'created_at', 'date_created', 'order_date']);

        if ($idCol === null || $userCol === null || $totalCol === null) {
            echo "  Legacy table '{$sourceTable}' missing required columns.\n";
            echo "    id={$idCol} user={$userCol} total={$totalCol}\n";
            echo "  Skipping. Edit migrateOrders() column probes if names differ.\n\n";
            return ['migrated' => 0, 'skipped' => 0, 'errors' => 0, 'status' => 'skipped_unexpected_columns'];
        }

        // Optional columns — passed through if present.
        $subtotalCol = $this->firstPresentColumn($sourceTable, ['subtotal', 'sub_total', 'items_total']);
        $deliveryCol = $this->firstPresentColumn($sourceTable, ['delivery_fee', 'shipping_fee', 'delivery_cost']);
        $discountCol = $this->firstPresentColumn($sourceTable, ['discount', 'discount_amount']);
        $currencyCol = $this->firstPresentColumn($sourceTable, ['currency', 'currency_code']);
        $orderRefCol = $this->firstPresentColumn($sourceTable, ['order_reference', 'order_code', 'reference']);
        $paidAtCol = $this->firstPresentColumn($sourceTable, ['paid_at', 'payment_date']);

        $selectCols = "{$idCol} AS lid, {$userCol} AS luser, {$totalCol} AS ltotal";
        if ($statusCol !== null) {
            $selectCols .= ", {$statusCol} AS lstatus";
        }
        if ($createdCol !== null) {
            $selectCols .= ", {$createdCol} AS lcreated";
        }
        if ($subtotalCol !== null) {
            $selectCols .= ", {$subtotalCol} AS lsubtotal";
        }
        if ($deliveryCol !== null) {
            $selectCols .= ", {$deliveryCol} AS ldelivery";
        }
        if ($discountCol !== null) {
            $selectCols .= ", {$discountCol} AS ldiscount";
        }
        if ($currencyCol !== null) {
            $selectCols .= ", {$currencyCol} AS lcurrency";
        }
        if ($orderRefCol !== null) {
            $selectCols .= ", {$orderRefCol} AS lref";
        }
        if ($paidAtCol !== null) {
            $selectCols .= ", {$paidAtCol} AS lpaid_at";
        }

        $totalRows = $this->legacy->count($sourceTable);
        echo "  Migrating {$totalRows} orders...\n\n";

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $this->conn->beginTransaction();
        try {
            foreach ($this->legacy->iterate("SELECT {$selectCols} FROM {$sourceTable} ORDER BY {$idCol}") as $row) {
                $legacyId = (int) $row['lid'];
                $legacyUserId = (int) $row['luser'];

                if ($legacyId === 0 || $legacyUserId === 0) {
                    $skipped++;
                    continue;
                }

                // Resolve v3 user_id via users.legacy_user_id mapping
                // (created by migrateUsers).
                $userRow = $this->conn->fetchAssociative(
                    'SELECT id FROM users WHERE legacy_user_id = ?',
                    [$legacyUserId],
                );
                if ($userRow === false) {
                    $this->log->error('orders', $legacyId, "User legacy_user_id={$legacyUserId} not found");
                    $skipped++;
                    continue;
                }
                $v3UserId = (int) $userRow['id'];

                // Existence check.
                $existing = $this->conn->fetchAssociative(
                    'SELECT id FROM orders WHERE legacy_order_id = ?',
                    [$legacyId],
                );
                if ($existing !== false) {
                    $skipped++;
                    continue;
                }

                $total = $this->normaliseMoneyString($row['ltotal'] ?? '0');
                $subtotal = isset($row['lsubtotal']) ? $this->normaliseMoneyString($row['lsubtotal']) : $total;
                $delivery = isset($row['ldelivery']) ? $this->normaliseMoneyString($row['ldelivery']) : '0.00';
                $discount = isset($row['ldiscount']) ? $this->normaliseMoneyString($row['ldiscount']) : '0.00';
                $currency = isset($row['lcurrency']) ? (string) $row['lcurrency'] : 'AED';
                $createdAt = isset($row['lcreated']) ? (string) $row['lcreated'] : date('Y-m-d H:i:s');

                // Order reference: legacy may not have one; synthesise
                // from legacy_id with V3M (M = migrated) prefix.
                $ref = isset($row['lref']) && (string) $row['lref'] !== ''
                    ? substr((string) $row['lref'], 0, 32)
                    : 'V3M-' . str_pad((string) $legacyId, 10, '0', STR_PAD_LEFT);

                $v3Status = $this->mapLegacyOrderStatus(isset($row['lstatus']) ? (string) $row['lstatus'] : 'paid');
                $paidAt = isset($row['lpaid_at']) && (string) $row['lpaid_at'] !== ''
                    ? (string) $row['lpaid_at']
                    : null;

                try {
                    $this->conn->insert('orders', [
                        'legacy_order_id' => $legacyId,
                        'user_id' => $v3UserId,
                        'order_reference' => $ref,
                        'status' => $v3Status,
                        'subtotal' => $subtotal,
                        'delivery_fee' => $delivery,
                        'discount' => $discount,
                        'total' => $total,
                        'currency' => $currency,
                        'paid_at' => $paidAt,
                        'created_at' => $createdAt,
                        'updated_at' => $createdAt,
                    ]);
                    $migrated++;
                } catch (\Throwable $e) {
                    $this->log->error('orders', $legacyId, $e->getMessage());
                    $errors++;
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            echo "  ABORTED: " . $e->getMessage() . "\n\n";
            throw $e;
        }

        echo "  Done: {$migrated} migrated, {$skipped} skipped, {$errors} errors.\n\n";
        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'errors' => $errors,
            'status' => 'completed',
        ];
    }

    /**
     * @return array{migrated: int, skipped: int, errors: int, status: string}
     */
    public function migrateOrderItems(): array
    {
        echo "===== migrate-order-items =====\n";

        $candidates = ['order_items', 'orderitems', 'order_item', 'order_details', 'order_lines'];
        $sourceTable = $this->probeTable($candidates);
        if ($sourceTable === null) {
            return $this->skipMissingTable('order_items', $candidates);
        }
        echo "  Source table: {$sourceTable}\n";

        $idCol = $this->firstPresentColumn($sourceTable, ['order_item_id', 'id']);
        $orderCol = $this->firstPresentColumn($sourceTable, ['order_id', 'parent_order_id']);
        $productCol = $this->firstPresentColumn($sourceTable, ['product_id']);
        $qtyCol = $this->firstPresentColumn($sourceTable, ['quantity', 'qty']);
        $priceCol = $this->firstPresentColumn($sourceTable, ['price', 'unit_price']);

        if ($idCol === null || $orderCol === null || $productCol === null || $qtyCol === null || $priceCol === null) {
            echo "  Legacy table '{$sourceTable}' missing required columns.\n";
            echo "    id={$idCol} order={$orderCol} product={$productCol} qty={$qtyCol} price={$priceCol}\n";
            echo "  Skipping. Edit migrateOrderItems() column probes if names differ.\n\n";
            return ['migrated' => 0, 'skipped' => 0, 'errors' => 0, 'status' => 'skipped_unexpected_columns'];
        }

        $sizeCol = $this->firstPresentColumn($sourceTable, ['size']);
        $colorCol = $this->firstPresentColumn($sourceTable, ['color', 'colour']);
        $nameCol = $this->firstPresentColumn($sourceTable, ['product_name', 'name']);
        $imageCol = $this->firstPresentColumn($sourceTable, ['product_image', 'image']);
        $customCol = $this->firstPresentColumn($sourceTable, ['is_custom', 'custom']);
        $measureCol = $this->firstPresentColumn($sourceTable, ['measurement']);
        $extraMeasureCol = $this->firstPresentColumn($sourceTable, ['extra_measurement']);
        $noteCol = $this->firstPresentColumn($sourceTable, ['note', 'notes']);

        $selectCols = "{$idCol} AS lid, {$orderCol} AS lorder, {$productCol} AS lproduct, {$qtyCol} AS lqty, {$priceCol} AS lprice";
        if ($sizeCol !== null) {
            $selectCols .= ", {$sizeCol} AS lsize";
        }
        if ($colorCol !== null) {
            $selectCols .= ", {$colorCol} AS lcolor";
        }
        if ($nameCol !== null) {
            $selectCols .= ", {$nameCol} AS lname";
        }
        if ($imageCol !== null) {
            $selectCols .= ", {$imageCol} AS limage";
        }
        if ($customCol !== null) {
            $selectCols .= ", {$customCol} AS lcustom";
        }
        if ($measureCol !== null) {
            $selectCols .= ", {$measureCol} AS lmeasure";
        }
        if ($extraMeasureCol !== null) {
            $selectCols .= ", {$extraMeasureCol} AS lextra";
        }
        if ($noteCol !== null) {
            $selectCols .= ", {$noteCol} AS lnote";
        }

        $totalRows = $this->legacy->count($sourceTable);
        echo "  Migrating {$totalRows} order items...\n\n";

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $this->conn->beginTransaction();
        try {
            foreach ($this->legacy->iterate("SELECT {$selectCols} FROM {$sourceTable} ORDER BY {$idCol}") as $row) {
                $legacyId = (int) $row['lid'];
                $legacyOrderId = (int) $row['lorder'];
                $legacyProductId = (int) $row['lproduct'];

                // Resolve v3 order_id + product_id + vendor_id.
                $orderRow = $this->conn->fetchAssociative(
                    'SELECT id FROM orders WHERE legacy_order_id = ?',
                    [$legacyOrderId],
                );
                if ($orderRow === false) {
                    $skipped++;
                    continue;
                }
                $productRow = $this->conn->fetchAssociative(
                    'SELECT id, vendor_id, name FROM products WHERE legacy_product_id = ?',
                    [$legacyProductId],
                );
                if ($productRow === false) {
                    $this->log->error('order_items', $legacyId, "Product legacy_product_id={$legacyProductId} not found");
                    $skipped++;
                    continue;
                }

                try {
                    $this->conn->insert('order_items', [
                        'order_id' => $orderRow['id'],
                        'product_id' => $productRow['id'],
                        'vendor_id' => $productRow['vendor_id'],
                        'quantity' => (int) $row['lqty'],
                        'unit_price' => $this->normaliseMoneyString($row['lprice']),
                        'subtotal' => $this->normaliseMoneyString(
                            bcmul((string) $row['lprice'], (string) (int) $row['lqty'], 2)
                        ),
                        'product_name_snapshot' => isset($row['lname'])
                            ? substr((string) $row['lname'], 0, 255)
                            : substr((string) $productRow['name'], 0, 255),
                        'product_image_snapshot' => isset($row['limage']) ? (string) $row['limage'] : null,
                        'size' => isset($row['lsize']) ? (string) $row['lsize'] : null,
                        'color' => isset($row['lcolor']) ? (string) $row['lcolor'] : null,
                        'is_custom' => isset($row['lcustom']) && (bool) $row['lcustom'] ? 1 : 0,
                        'measurement' => isset($row['lmeasure']) ? (string) $row['lmeasure'] : null,
                        'extra_measurement' => isset($row['lextra']) ? (string) $row['lextra'] : null,
                        'note' => isset($row['lnote']) ? (string) $row['lnote'] : null,
                        'item_status' => 'pending',
                    ]);
                    $migrated++;
                } catch (\Throwable $e) {
                    $this->log->error('order_items', $legacyId, $e->getMessage());
                    $errors++;
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            echo "  ABORTED: " . $e->getMessage() . "\n\n";
            throw $e;
        }

        echo "  Done: {$migrated} migrated, {$skipped} skipped, {$errors} errors.\n\n";
        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'errors' => $errors,
            'status' => 'completed',
        ];
    }

    /**
     * @return array{migrated: int, skipped: int, errors: int, status: string}
     */
    public function migrateOrderAddresses(): array
    {
        echo "===== migrate-order-addresses =====\n";

        // Legacy schema: orders typically denormalise the address INTO
        // the orders row itself rather than a separate table. Try both.
        $candidates = ['order_addresses', 'order_address', 'order_shipping_address'];
        $sourceTable = $this->probeTable($candidates);

        if ($sourceTable !== null) {
            // Separate address-table path: the legacy database has a
            // dedicated address table whose column names can't be known
            // in advance without inspecting that specific schema.
            // This branch deliberately falls through to the
            // inline-on-orders path below, which works for the known
            // 3bayti legacy schema and handles the vast majority of
            // real deployments. If a future legacy database uses a
            // separate table, a schema probe + column mapping would be
            // needed here — add it then; for now log a clear operator
            // notice and continue with the safe fallback path.
            echo "  Found separate address table '{$sourceTable}', but column\n";
            echo "  mapping for an unknown schema is out of scope for this\n";
            echo "  automated migrator. Falling back to the inline-on-orders\n";
            echo "  address path (the standard 3bayti legacy layout).\n";
            echo "  If your addresses are in '{$sourceTable}', inspect its\n";
            echo "  columns and add a dedicated migration step.\n";
        }

        // Inline-on-orders path: extract address fields from the orders
        // table itself. Probe the orders table for address columns.
        $ordersTable = $this->probeTable(['orders', 'order', 'order_master']);
        if ($ordersTable === null) {
            return $this->skipMissingTable('orders (for inline addresses)', ['orders']);
        }

        $idCol = $this->firstPresentColumn($ordersTable, ['order_id', 'id']);
        $streetCol = $this->firstPresentColumn($ordersTable, ['shipping_street', 'shipping_address', 'address']);
        $cityCol = $this->firstPresentColumn($ordersTable, ['shipping_city', 'city', 'emirate']);
        $phoneCol = $this->firstPresentColumn($ordersTable, ['shipping_phone', 'customer_phone', 'phone']);
        $nameCol = $this->firstPresentColumn($ordersTable, ['shipping_name', 'customer_name']);
        $emailCol = $this->firstPresentColumn($ordersTable, ['customer_email', 'email']);

        if ($idCol === null || $streetCol === null || $cityCol === null) {
            echo "  Orders table missing address columns (street, city).\n";
            echo "  Skipping. Address data will need to be backfilled from user profiles\n";
            echo "  or marked as 'address unavailable' on migrated orders.\n\n";
            return ['migrated' => 0, 'skipped' => 0, 'errors' => 0, 'status' => 'skipped_no_inline_address'];
        }

        $selectCols = "{$idCol} AS lid, {$streetCol} AS lstreet, {$cityCol} AS lcity";
        if ($phoneCol !== null) {
            $selectCols .= ", {$phoneCol} AS lphone";
        }
        if ($nameCol !== null) {
            $selectCols .= ", {$nameCol} AS lname";
        }
        if ($emailCol !== null) {
            $selectCols .= ", {$emailCol} AS lemail";
        }

        $totalRows = $this->legacy->count($ordersTable);
        echo "  Synthesising shipping addresses from {$totalRows} legacy orders...\n\n";

        $migrated = 0;
        $skipped = 0;
        $errors = 0;

        $this->conn->beginTransaction();
        try {
            foreach ($this->legacy->iterate("SELECT {$selectCols} FROM {$ordersTable} ORDER BY {$idCol}") as $row) {
                $legacyOrderId = (int) $row['lid'];

                $orderRow = $this->conn->fetchAssociative(
                    'SELECT id FROM orders WHERE legacy_order_id = ?',
                    [$legacyOrderId],
                );
                if ($orderRow === false) {
                    $skipped++;
                    continue;
                }

                // Skip if shipping address already exists for this order.
                $existing = $this->conn->fetchAssociative(
                    "SELECT id FROM order_addresses WHERE order_id = ? AND type = 'shipping'",
                    [$orderRow['id']],
                );
                if ($existing !== false) {
                    $skipped++;
                    continue;
                }

                $street = trim((string) ($row['lstreet'] ?? ''));
                $city = trim((string) ($row['lcity'] ?? ''));
                $phone = trim((string) ($row['lphone'] ?? '0000000000'));
                $name = trim((string) ($row['lname'] ?? 'Migrated Customer'));
                $email = trim((string) ($row['lemail'] ?? 'unknown@migrated.local'));

                if ($street === '' || $city === '' || $phone === '' || $name === '' || $email === '') {
                    $skipped++;
                    continue;
                }

                try {
                    // Insert shipping. Some legacy orders may not have
                    // a separate billing address — duplicate shipping
                    // as billing when needed (rather than leaving
                    // billing null, which would break checkout-related
                    // queries downstream).
                    $now = date('Y-m-d H:i:s');
                    $this->conn->insert('order_addresses', [
                        'order_id' => $orderRow['id'],
                        'type' => 'shipping',
                        'first_name' => substr($name, 0, 100),
                        'phone' => substr($phone, 0, 32),
                        'email' => substr($email, 0, 255),
                        'street' => substr($street, 0, 255),
                        'city' => substr($city, 0, 100),
                        'country_code' => 'AE',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $this->conn->insert('order_addresses', [
                        'order_id' => $orderRow['id'],
                        'type' => 'billing',
                        'first_name' => substr($name, 0, 100),
                        'phone' => substr($phone, 0, 32),
                        'email' => substr($email, 0, 255),
                        'street' => substr($street, 0, 255),
                        'city' => substr($city, 0, 100),
                        'country_code' => 'AE',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $migrated++;
                } catch (\Throwable $e) {
                    $this->log->error('order_addresses', $legacyOrderId, $e->getMessage());
                    $errors++;
                }
            }
            $this->conn->commit();
        } catch (\Throwable $e) {
            $this->conn->rollBack();
            echo "  ABORTED: " . $e->getMessage() . "\n\n";
            throw $e;
        }

        echo "  Done: {$migrated} migrated, {$skipped} skipped, {$errors} errors.\n\n";
        return [
            'migrated' => $migrated,
            'skipped' => $skipped,
            'errors' => $errors,
            'status' => 'completed',
        ];
    }

    // ===================================================================
    // M3.1.6h — Helpers
    // ===================================================================

    /** @param list<string> $candidates */
    private function probeTable(array $candidates): ?string
    {
        foreach ($candidates as $name) {
            if ($this->legacy->tableExists($name)) {
                return $name;
            }
        }
        return null;
    }

    /** @param list<string> $candidates */
    private function firstPresentColumn(string $table, array $candidates): ?string
    {
        foreach ($candidates as $col) {
            if ($this->legacy->columnExists($table, $col)) {
                return $col;
            }
        }
        return null;
    }

    /**
     * @param list<string> $candidates
     * @return array{migrated: int, skipped: int, errors: int, status: string}
     */
    private function skipMissingTable(string $what, array $candidates): array
    {
        echo "  Legacy table for '{$what}' not found (probed: " . implode(', ', $candidates) . ").\n";
        echo "  Skipping. Edit candidates list if your legacy schema differs.\n\n";
        return ['migrated' => 0, 'skipped' => 0, 'errors' => 0, 'status' => 'skipped_no_legacy_table'];
    }

    private function normaliseMoneyString(mixed $raw): string
    {
        if ($raw === null) {
            return '0.00';
        }
        $s = (string) $raw;
        $s = trim($s);
        if ($s === '') {
            return '0.00';
        }
        // bcmath handles arbitrary-precision; round to 2dp.
        return bcadd($s, '0', 2);
    }

    private function mapLegacyOrderStatus(string $legacy): string
    {
        $key = strtolower(trim($legacy));
        return match ($key) {
            'pending', 'pending_payment', '0' => 'pending_payment',
            'paid', 'completed', '1' => 'paid',
            'fulfilling', 'processing', '2' => 'fulfilling',
            'shipped', 'in_transit', '3' => 'shipped',
            'delivered', '4' => 'delivered',
            'cancelled', 'canceled', '5' => 'cancelled',
            'refunded', '6' => 'refunded',
            'failed', '7' => 'failed',
            default => 'paid', // Migrated rows assumed paid unless told otherwise
        };
    }
}
