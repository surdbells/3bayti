<?php

declare(strict_types=1);

/**
 * Doctrine Migrations configuration.
 *
 * Loaded by bin/console (via PhpFile config loader). Tells migrations
 * where to find migration classes and how to track which have been
 * applied.
 */

return [
    'table_storage' => [
        // Stores applied migration versions. PostgreSQL puts this
        // in the 'public' schema by default.
        'table_name' => 'doctrine_migration_versions',
        'version_column_name' => 'version',
        'version_column_length' => 191,
        'executed_at_column_name' => 'executed_at',
        'execution_time_column_name' => 'execution_time',
    ],

    'migrations_paths' => [
        // PSR-4 namespace → directory mapping. Migrations live in
        // apps/api/migrations/, namespaced under Bayti\Api\Migrations.
        'Bayti\\Api\\Migrations' => __DIR__ . '/migrations',
    ],

    'all_or_nothing' => true,
    'transactional' => true,
    'check_database_platform' => false,
    'organize_migrations' => 'none',
    'connection' => null,
    'em' => null,
];
