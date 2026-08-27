<?php

declare(strict_types=1);

/**
 * Migrate users from legacy MySQL to v3 Postgres.
 *
 * Source: `users` (9,328 rows)
 *
 * Target: `users` (v3 schema, with `legacy_user_id` for idempotency)
 *
 * Decisions
 * =========
 *
 * 1. Vendor detection
 *    -----------------
 *    `is_vendor=1 AND store_name IS NOT NULL AND store_name != ''` -> real vendor.
 *    Otherwise the user is a CUSTOMER even if legacy is_vendor=1.
 *
 * 2. Passwords
 *    ---------
 *    Legacy bcrypt-2y hashes copy verbatim. They work with PHP password_verify()
 *    in v3 unchanged. Migration sets password_changed_at to NULL.
 *
 * 3. Phone
 *    -----
 *    Stored verbatim. May be NULL (4 users) or duplicate (69 users).
 *    UNIQUE constraint relaxed in Version20260512000003.
 *
 * 4. Country code
 *    ------------
 *    Legacy uses '+971' format. Convert to ISO 'AE'. Default to 'AE'
 *    if missing.
 *
 * 5. Email duplicates (71 affected users)
 *    -----------------------------------
 *    Keep oldest user_id with original email. Suffix others with
 *    `+legacy{user_id}@host` to make unique. Log to
 *    `migration_email_conflicts` for manual merge post-demo.
 *
 * 6. Token column
 *    ------------
 *    Dropped. Legacy session value, not a credential.
 *
 * 7. Created column (varchar)
 *    ------------------------
 *    Parse as 'Y-m-d H:i:s'. Fallback to date_trunc('second', NOW()) if unparseable.
 *
 * Idempotency
 * ===========
 * Skip rows whose legacy_user_id is already in v3. Safe to re-run.
 * Email-conflict rows are detected by their existing renamed_email
 * in `migration_email_conflicts`.
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Migration\LegacyDb;
use Bayti\Api\Migration\MigrationLog;
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

echo "===== migrate-users =====\n";
echo "  run_id: " . $log->getRunId() . "\n\n";

// ---- pre-pass: build email collision map ----
// Iterating once over all 9328 users to identify which emails collide
// (case-insensitive). We can't do this during the row-by-row migration
// because we wouldn't know whether the CURRENT row is the "winner" or
// the "loser" without looking ahead at others sharing the email.

echo "Pre-pass: identifying email collisions...\n";

/** @var array<string, int> Map of lower(email) -> oldest user_id */
$emailWinners = [];

/** @var array<int, string> Map of user_id -> normalized email (for losers) */
$userEmails = [];

foreach ($legacy->iterate('SELECT user_id, email FROM users ORDER BY user_id') as $row) {
    $uid = (int) $row['user_id'];
    $email = strtolower(trim((string) $row['email']));
    if ($email === '') {
        continue;
    }
    $userEmails[$uid] = $email;
    if (!isset($emailWinners[$email])) {
        // First time we see this email -> it wins (lowest user_id)
        $emailWinners[$email] = $uid;
    }
}

$collisionCount = 0;
foreach ($userEmails as $uid => $email) {
    if ($emailWinners[$email] !== $uid) {
        $collisionCount++;
    }
}

echo "  Found {$collisionCount} email collisions (will rename losers).\n\n";

// ---- main pass ----

$totalRows = $legacy->count('users');
echo "Migrating {$totalRows} users...\n\n";

$conn->beginTransaction();
$migrated = 0;
$skipped = 0;
$errors = 0;

try {
    foreach ($legacy->iterate(
        "SELECT user_id, first_name, last_name, email, phone, countryCode,
                password, is_customer, is_vendor, is_admin, is_active,
                is_finance, is_support, _sub_admin, is_2fa, store_legal_name,
                trade_license_number, created, last_login, store_name,
                arm, bust, hip, length, armhole, shoulder
         FROM users
         ORDER BY user_id"
    ) as $row) {
        $legacyId = (int) $row['user_id'];

        $email = strtolower(trim((string) ($row['email'] ?? '')));
        if ($email === '') {
            $log->skip('users', $legacyId, 'Empty email');
            $skipped++;
            continue;
        }

        $passwordHash = (string) ($row['password'] ?? '');
        if (strlen($passwordHash) !== 60 || !str_starts_with($passwordHash, '$2y$')) {
            $log->warn('users', $legacyId, "Suspicious password hash (len=" . strlen($passwordHash) . ")");
        }

        // ---- Email collision handling, only matters on first INSERT ----
        // Per Sodiq's decision, email is STABLE across re-syncs. So if a v3 row
        // already exists for this legacy_user_id, we keep its current email
        // regardless of what's in legacy now. Collision logic only applies
        // when this row is brand new.
        $existingUser = $conn->fetchAssociative(
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
            // First-time insert AND we're a collision loser, generate suffixed email
            $atPos = strrpos($email, '@');
            if ($atPos === false) {
                $log->skip('users', $legacyId, "Malformed email '{$email}'");
                $skipped++;
                continue;
            }
            $local = substr($email, 0, $atPos);
            $domain = substr($email, $atPos);
            $renamedEmail = $local . '+legacy' . $legacyId . $domain;
        }

        // Country code transform
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

        // Phone, verbatim, may be NULL
        $phone = trim((string) ($row['phone'] ?? ''));
        $phone = $phone === '' ? null : $phone;

        $created = parseLegacyTimestamp((string) ($row['created'] ?? ''));
        $lastLogin = parseLegacyTimestamp((string) ($row['last_login'] ?? ''));

        // is_vendor: real vendor only if store_name populated
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
            // -------- INSERT --------
            $insertEmail = $renamedEmail ?? $email;
            try {
                $conn->executeStatement(
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
                $log->error('users', $legacyId, "INSERT failed: " . $e->getMessage(), [
                    'email' => $insertEmail,
                ]);
                $errors++;
                continue;
            }

            // If we renamed the email, log conflict
            if ($renamedEmail !== null) {
                $winnerLegacyId = $emailWinners[$email];
                $winnerV3Id = $conn->fetchOne(
                    'SELECT id FROM users WHERE legacy_user_id = ?',
                    [$winnerLegacyId]
                );
                $newUserId = $conn->fetchOne(
                    'SELECT id FROM users WHERE legacy_user_id = ?',
                    [$legacyId]
                );
                $conn->executeStatement(
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
                $log->warn('users', $legacyId, "Email collision -> renamed to {$renamedEmail}");
            }

            $migrated++;
        } else {
            // -------- UPDATE, email stable, password stable, all else updated --------
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
            // Boolean comparison, normalize before compare
            $cmpBool = static fn ($v): bool => $v === true || $v === 't' || $v === '1' || $v === 1;

            if ($cmpBool($existingUser['is_customer']) !== $isCustomer) {
                $changes['is_customer'] = ['from' => $existingUser['is_customer'], 'to' => $isCustomer];
                $set[] = 'is_customer = :is_customer'; $params['is_customer'] = $isCustomer ? 'true' : 'false';
            }
            if ($cmpBool($existingUser['is_vendor']) !== $isReallyVendor) {
                $changes['is_vendor'] = ['from' => $existingUser['is_vendor'], 'to' => $isReallyVendor];
                $set[] = 'is_vendor = :is_vendor'; $params['is_vendor'] = $isReallyVendor ? 'true' : 'false';
            }
            if ($cmpBool($existingUser['is_active']) !== $isActive) {
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

            // Password hash UPDATE, legacy may rotate, we want re-sync to pick that up.
            // Email and renamed_email are NOT updated (stable per decision).
            if ($existingUser['password_hash'] !== $passwordHash && $passwordHash !== '') {
                $changes['password_hash'] = ['from' => '***', 'to' => '*** (rotated)'];
                $set[] = 'password_hash = :pw_hash'; $params['pw_hash'] = $passwordHash;
            }

            if (count($changes) === 0) {
                continue; // No drift, silent
            }

            $set[] = 'updated_at = date_trunc('second', NOW())';
            $sql = 'UPDATE users SET ' . implode(', ', $set) . ' WHERE legacy_user_id = :legacy_id';
            try {
                $conn->executeStatement($sql, $params);
            } catch (\Throwable $e) {
                $log->error('users', $legacyId, "UPDATE failed: " . $e->getMessage());
                $errors++;
                continue;
            }

            $log->info('users', $legacyId, "UPDATE (email stable: '{$existingUser['email']}')", $changes);
            $migrated++;
        }

        if (($migrated % 500) === 0) {
            echo "  ... {$migrated} processed\n";
        }
    }

    // Reset sequence so new signups don't collide
    $conn->executeStatement(
        "SELECT setval('users_id_seq', COALESCE((SELECT MAX(id) FROM users), 0) + 1, false)"
    );

    $conn->commit();

    echo "\n";
    echo "===== users migration complete =====\n";
    echo "  Migrated:  {$migrated}\n";
    echo "  Skipped:   {$skipped}\n";
    echo "  Errors:    {$errors}\n";
    echo "  Email conflicts: {$collisionCount}\n";
    echo "\nRun this to see conflicts:\n";
    echo "  psql -d bayti_v3 -c 'SELECT * FROM migration_email_conflicts WHERE resolution_status = \\'pending\\''\n";
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
        // 'Y-m-d H:i:s' (legacy MySQL DATETIME) -> 'Y-m-d H:i:sP' (Postgres TIMESTAMPTZ)
        $dt = new \DateTimeImmutable($value, new \DateTimeZone('Asia/Dubai'));
        return $dt->format('Y-m-d H:i:sP');
    } catch (\Throwable) {
        return null;
    }
}
