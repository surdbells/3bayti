<?php

declare(strict_types=1);

use Bayti\Api\Http\Controllers\HealthController;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;

/**
 * PHP-DI container definitions.
 *
 * Most controllers/services are autowired (PHP-DI inspects constructors
 * and resolves type-hinted dependencies automatically). We only add
 * explicit factories here when a class needs special construction —
 * e.g. database connections, third-party SDK clients.
 *
 * Keep this file as the single source of truth for "how does X get
 * built?" so we don't end up with hidden new statements scattered
 * around the codebase.
 */

$rootPath = dirname(__DIR__);
$doctrineConfig = require $rootPath . '/config/doctrine.php';

return [
    // -------------------------------------------------------------------
    // Doctrine ORM
    // -------------------------------------------------------------------

    /**
     * Build the Doctrine ORM Configuration once per container,
     * shared by both the EntityManager and any scripts that need
     * raw config access (CLI tools).
     */
    Configuration::class => static function () use ($doctrineConfig, $rootPath): Configuration {
        $env = $_ENV['APP_ENV'] ?? 'dev';
        return ($doctrineConfig['config_factory'])($env, $rootPath);
    },

    /**
     * Build the DBAL Connection from env-driven params.
     */
    Connection::class => static function () use ($doctrineConfig): Connection {
        return ($doctrineConfig['connection_factory'])($doctrineConfig['connection']);
    },

    /**
     * The Doctrine EntityManager — main entry point for DB work.
     * Slim controllers and services type-hint EntityManagerInterface
     * and PHP-DI provides this concrete instance.
     */
    EntityManagerInterface::class => static function (ContainerInterface $c): EntityManagerInterface {
        return new EntityManager(
            $c->get(Connection::class),
            $c->get(Configuration::class),
        );
    },

    // -------------------------------------------------------------------
    // Controllers — autowired, but listed for discoverability.
    // -------------------------------------------------------------------

    HealthController::class => \DI\autowire(),

    // Doctrine repositories (Auth-related entities) are accessed via
    // EntityManager::getRepository(); no DI registrations needed.

    // Redis connection, mailer, payment SDK, etc. wire up here as we
    // add them. M1.5 adds Redis; M3 adds the payment SDK.
];

