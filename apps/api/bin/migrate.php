#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * 3bayti API — production migration runner.
 *
 * Runs as a "predeploy job" in DigitalOcean App Platform: fires once
 * per release, BEFORE the new container takes traffic. If migrations
 * fail (non-zero exit), App Platform aborts the deploy and keeps the
 * old version serving — no downtime, no half-applied schema.
 *
 * What this DOES
 * --------------
 * Wraps the Doctrine migrations CLI with three guarantees:
 *   1. Non-interactive (--no-interaction). Predeploy jobs don't have
 *      a TTY; any prompt would hang forever.
 *   2. Allow no-prefixed migrations OK (--allow-no-migration). Fresh
 *      DB with no migration history table is a normal first-deploy
 *      state, not an error.
 *   3. Hard exit on failure. The PHP process exits with code 1 if
 *      anything goes wrong; App Platform reads the exit code.
 *
 * What this does NOT do
 * ---------------------
 *   - No backups. Production DB backups are App Platform's
 *     responsibility (Managed Postgres has automated daily snapshots
 *     with point-in-time recovery for 7 days). If a migration goes
 *     wrong, restore from there. We do NOT try to manage backups
 *     ourselves.
 *
 *   - No down-migration on failure. Doctrine migrations support
 *     up/down but production runs only up. If a deploy fails halfway,
 *     restoring from PITR is safer than running unverified down
 *     migrations.
 *
 *   - No schema validation. We rely on Doctrine's migrations:diff
 *     during development to catch entity/schema drift; production
 *     just applies what's already been generated.
 *
 * Manual usage (rare, but documented for emergencies):
 *
 *   docker exec -it <container> php bin/migrate.php
 *   docker exec -it <container> php bin/migrate.php --dry-run
 *
 * The --dry-run flag uses Doctrine's own dry-run mode: prints what
 * SQL would run without applying it. Useful for verifying a planned
 * migration matches what's expected.
 */

require __DIR__ . '/../vendor/autoload.php';

use Bayti\Api\Bootstrap;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\PhpFile;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

// Stamp every line with a marker so it's easy to grep migrate.php
// output from the rest of the predeploy log.
$out = static function (string $msg): void {
    fwrite(STDERR, "[migrate] {$msg}\n");
};

$out('starting migration runner');
$out('APP_ENV=' . ($_ENV['APP_ENV'] ?? 'unset'));

// Build the same DI container the HTTP app uses, then pull out the
// EntityManager. This guarantees we're using IDENTICAL config to
// the running service — no chance of "tests pass with config A but
// migrations run against config B".
try {
    $app = Bootstrap::createApp();
    $container = $app->getContainer();
    if ($container === null) {
        $out('ERROR: DI container missing from Bootstrap');
        exit(1);
    }

    /** @var EntityManagerInterface $em */
    $em = $container->get(EntityManagerInterface::class);
} catch (\Throwable $e) {
    $out('ERROR: failed to bootstrap app: ' . $e->getMessage());
    $out('  ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
}

// Smoke-test the connection BEFORE attempting migrations. If the
// DB is unreachable, we'd rather fail with a clear message than
// have Doctrine throw something cryptic mid-migration.
try {
    $em->getConnection()->executeQuery('SELECT 1');
    $out('database reachable');
} catch (\Throwable $e) {
    $out('ERROR: database unreachable: ' . $e->getMessage());
    exit(1);
}

// Load the migrations config (same file used by bin/console).
$migrationsConfigFile = __DIR__ . '/../migrations.php';
if (!file_exists($migrationsConfigFile)) {
    $out('ERROR: migrations config file missing: ' . $migrationsConfigFile);
    exit(1);
}

$migrationsFactory = DependencyFactory::fromConnection(
    new PhpFile($migrationsConfigFile),
    new ExistingConnection($em->getConnection()),
);

// Wrap in a Symfony Console Application so MigrateCommand has the
// runtime it expects (it reads --no-interaction and other flags via
// the framework).
$cli = new Application('3bayti API migrate', '1.0.0');
$cli->setAutoExit(false); // we handle the exit code ourselves

$migrateCommand = new MigrateCommand($migrationsFactory);
$cli->add($migrateCommand);

// Build the input — equivalent to:
//   bin/console migrations:migrate --no-interaction --allow-no-migration
//
// --dry-run support: pass through if the user invoked us with it.
$args = $argv;
array_shift($args); // drop the script name
$dryRun = in_array('--dry-run', $args, true);

$input = new ArrayInput([
    'command' => 'migrations:migrate',
    '--no-interaction' => true,
    '--allow-no-migration' => true,
] + ($dryRun ? ['--dry-run' => true] : []));

$output = new ConsoleOutput();

try {
    $exitCode = $cli->run($input, $output);
} catch (\Throwable $e) {
    $out('ERROR: migration crashed: ' . $e->getMessage());
    $out('  ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
}

if ($exitCode !== 0) {
    $out("ERROR: migrations:migrate returned exit code {$exitCode}");
    exit($exitCode);
}

$out('migrations applied successfully');
exit(0);
