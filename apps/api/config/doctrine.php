<?php

declare(strict_types=1);

/**
 * Doctrine ORM configuration.
 *
 * Returns an array of bound values consumed by config/di.php to build
 * the Doctrine EntityManager. Two pieces of config:
 *
 *   1. Connection params — built from env vars (.env in dev, DO App
 *      Platform secret store in prod). PostgreSQL 16 driver.
 *
 *   2. ORM setup — mapping driver (attributes, not XML/YAML), entity
 *      directories (`src/Domain/`), proxies + cache toggles based on
 *      APP_ENV.
 *
 * Doctrine 3 differs from 2.x in a few important places:
 *   - Uses Setup factory directly (no ORMSetup helper required)
 *   - Connection driver names are simplified ('pdo_pgsql' not 'pgsql')
 *   - getConfiguration().setMetadataCache() is mandatory (no defaults)
 */

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\PhpFilesAdapter;

return [
    'connection' => [
        'driver'   => $_ENV['DB_DRIVER']   ?? 'pdo_pgsql',
        'host'     => $_ENV['DB_HOST']     ?? 'localhost',
        'port'     => (int)($_ENV['DB_PORT'] ?? 5432),
        'dbname'   => $_ENV['DB_DATABASE'] ?? '3bayti',
        'user'     => $_ENV['DB_USERNAME'] ?? '3bayti',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
        'charset'  => $_ENV['DB_CHARSET']  ?? 'utf8',

        // SSL configuration — DO Managed Postgres requires SSL by default.
        // Local Docker Postgres doesn't. Toggle via env.
        'sslmode'  => $_ENV['DB_SSLMODE']  ?? 'prefer',
    ],

    /**
     * Build a Doctrine Configuration object based on the current environment.
     *
     * Returned by config/di.php as a service factory.
     */
    'config_factory' => static function (string $env, string $rootPath): Configuration {
        $isDev = $env !== 'prod';

        $config = new Configuration();

        // Mapping: read attributes from PHP entity classes under src/Domain.
        // We do NOT use XML/YAML mappings — attributes keep schema and
        // entity definitions co-located, which matches how the rest of
        // the codebase uses PHP attributes (Slim routing, swagger-php, etc.).
        $config->setMetadataDriverImpl(new AttributeDriver([
            $rootPath . '/src/Domain',
        ]));

        // Proxy directory — Doctrine generates dynamic proxy classes
        // for entities. In prod, proxies are pre-generated at build
        // time and loaded; in dev, they're regenerated on-demand which
        // is slower but safer.
        $config->setProxyDir($rootPath . '/var/cache/doctrine/proxies');
        $config->setProxyNamespace('Bayti\\Api\\DoctrineProxies');
        $config->setAutoGenerateProxyClasses($isDev);

        // Caching — array adapter in dev (no persistence between requests
        // but no setup either), file-based in prod (faster than no cache,
        // doesn't need Redis). We can swap to RedisAdapter in M5 hardening.
        $cache = $isDev
            ? new ArrayAdapter()
            : new PhpFilesAdapter('doctrine.metadata', 0, $rootPath . '/var/cache/doctrine');

        $config->setMetadataCache($cache);
        $config->setQueryCache($cache);

        // Naming strategy: convert PHP camelCase property names to
        // snake_case column names automatically. Matches PostgreSQL
        // convention and avoids forcing every property to carry a
        // `#[Column(name: '...')]` attribute.
        $config->setNamingStrategy(new \Doctrine\ORM\Mapping\UnderscoreNamingStrategy(CASE_LOWER));

        return $config;
    },

    /**
     * Build the DBAL Connection from the connection params.
     */
    'connection_factory' => static function (array $params): \Doctrine\DBAL\Connection {
        return DriverManager::getConnection($params);
    },
];
