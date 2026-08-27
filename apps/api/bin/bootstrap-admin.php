#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Bootstrap admin, promote a user to admin via CLI.
 *
 * Usage:
 *   php bin/bootstrap-admin.php <email>
 *   php bin/bootstrap-admin.php <email> --yes     (skip confirmation)
 *
 * Designed as a one-time bootstrap tool. Once you have an admin user,
 * future admin promotions should happen via /v3/admin/users (M2.x or
 * M4 work) so they're audit-logged with an actor.
 *
 * Safety
 * ------
 *   - Validates user exists (exits 1 if not)
 *   - Idempotent (already-admin user is a no-op)
 *   - Prompts for confirmation by default; --yes skips
 *   - Emits an audit log entry with userId=null (system actor)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Bayti\Api\Domain\Audit\AuditEmitter;
use Bayti\Api\Domain\User\User;
use Bayti\Api\Domain\User\UserRepository;
use Doctrine\ORM\EntityManagerInterface;

// ----- arg parsing ---------------------------------------------------

if ($argc < 2) {
    fwrite(STDERR, "Usage: php bin/bootstrap-admin.php <email> [--yes]\n");
    exit(2);
}

$email = $argv[1];
$skipPrompt = in_array('--yes', $argv, true);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Invalid email: $email\n");
    exit(2);
}

// ----- setup ---------------------------------------------------------

$app = Bootstrap::createApp();
$container = $app->getContainer();
if ($container === null) {
    fwrite(STDERR, "DI container not available\n");
    exit(1);
}

/** @var EntityManagerInterface $em */
$em = $container->get(EntityManagerInterface::class);
/** @var UserRepository $repo */
$repo = $em->getRepository(User::class);

// ----- lookup --------------------------------------------------------

$user = $repo->findOneBy(['email' => $email]);
if ($user === null) {
    fwrite(STDERR, "No user with email: $email\n");
    fwrite(STDERR, "Hint: register the user via the API first, then promote.\n");
    exit(1);
}

if ($user->isAdmin()) {
    echo "User {$email} (id={$user->getId()}) is already an admin.\n";
    echo "No changes made.\n";
    exit(0);
}

// ----- confirm -------------------------------------------------------

echo "About to grant admin privileges to:\n";
echo "  ID:    " . $user->getId() . "\n";
echo "  Email: " . $user->getEmail() . "\n";
echo "  Name:  " . trim($user->getFirstName() . ' ' . $user->getLastName()) . "\n";

if (!$skipPrompt) {
    echo "\nProceed? [y/N]: ";
    $answer = trim(fgets(STDIN) ?: '');
    if (strtolower($answer) !== 'y') {
        echo "Cancelled.\n";
        exit(0);
    }
}

// ----- promote -------------------------------------------------------

// User entity has setRoles($customer, $vendor, $admin, $subAdmin).
// Pass null for unchanged.
$user->setRoles(admin: true);
$em->flush();

// ----- audit ---------------------------------------------------------

try {
    /** @var AuditEmitter $audit */
    $audit = $container->get(AuditEmitter::class);
    $audit->recordUpdate(
        request: null,        // no HTTP request, CLI invocation
        actor: null,          // no actor user, system event
        subject: $user,
        beforeSnapshot: ['is_admin' => false],
        afterSnapshot: ['is_admin' => true],
    );
} catch (Throwable $e) {
    // Audit is best-effort; the promotion already happened.
    fwrite(STDERR, "WARN: failed to write audit log: " . $e->getMessage() . "\n");
}

echo "\n";
echo "Admin granted.\n";
echo "User can now access /v3/admin/* endpoints.\n";

exit(0);
