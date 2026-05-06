<?php

declare(strict_types=1);

use Bayti\Api\Http\Controllers\HealthController;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Middleware\OptionalAuthMiddleware;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Auth\JwtSettings;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;

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
    // PSR-17 ResponseFactory
    // -------------------------------------------------------------------

    /**
     * Middlewares that build their own responses (AuthMiddleware's
     * 401, error handlers) need a ResponseFactory. We bind the
     * Slim PSR-7 factory to the PSR-17 interface here so PHP-DI
     * can inject it by interface anywhere.
     */
    ResponseFactoryInterface::class => static fn (): ResponseFactoryInterface => new ResponseFactory(),

    // -------------------------------------------------------------------
    // Auth — JWT settings + service + middlewares
    // -------------------------------------------------------------------

    /**
     * JwtSettings is built from env vars at container time. The
     * factory throws if JWT_SECRET is missing or too short, which
     * is exactly what we want — the app should refuse to start
     * with a weak signing key.
     */
    JwtSettings::class => static function (): JwtSettings {
        $secret = $_ENV['JWT_SECRET'] ?? '';
        if ($secret === '' || strlen($secret) < 32) {
            // Specific dev guidance — a fresh checkout with no .env
            // would hit this. Helpful error rather than 'invalid
            // argument: shorter than 32'.
            throw new \RuntimeException(
                'JWT_SECRET env var is required and must be at least 32 bytes. ' .
                'Generate one with: php -r "echo bin2hex(random_bytes(64));"'
            );
        }

        return new JwtSettings(
            signingSecret: $secret,
            accessTtlSeconds: (int) ($_ENV['JWT_ACCESS_TOKEN_TTL'] ?? 900),
            refreshTtlSeconds: (int) ($_ENV['JWT_REFRESH_TOKEN_TTL'] ?? 604800),
            issuer: $_ENV['JWT_ISSUER'] ?? '3bayti-api',
        );
    },

    JwtService::class => \DI\autowire(),
    AuthMiddleware::class => \DI\autowire(),
    OptionalAuthMiddleware::class => \DI\autowire(),

    // -------------------------------------------------------------------
    // Controllers — autowired, but listed for discoverability.
    // -------------------------------------------------------------------

    HealthController::class => \DI\autowire(),

    // Doctrine repositories are accessed via EntityManager::getRepository();
    // no DI registrations needed.
];


