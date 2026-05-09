<?php

declare(strict_types=1);

use Bayti\Api\Domain\User\OtpService;
use Bayti\Api\Http\Controllers\HealthController;
use Bayti\Api\Http\Errors\ApiErrorMiddleware;
use Bayti\Api\Http\Middleware\AuthMiddleware;
use Bayti\Api\Http\Middleware\OptionalAuthMiddleware;
use Bayti\Api\Http\Validator\RequestValidator;
use Bayti\Api\Infrastructure\Auth\JwtService;
use Bayti\Api\Infrastructure\Auth\JwtSettings;
use Bayti\Api\Infrastructure\Otp\InMemoryOtpProvider;
use Bayti\Api\Infrastructure\Otp\MessageCentralOtpProvider;
use Bayti\Api\Infrastructure\Otp\OtpProvider;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\Configuration;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client as GuzzleClient;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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

/**
 * Doctrine config and root path are exposed as DI entries so that
 * factory closures can read them via the container instead of
 * capturing them with `use (...)`.
 *
 * Why this matters
 * ----------------
 * PHP-DI's compiled-container mode (enabled when APP_ENV=prod) generates
 * a static PHP class containing serialised factories. Closures with
 * `use ($foo)` cannot be serialised — they hold runtime variable state
 * that compiler can't represent in source code. Reading from the
 * container instead works identically in dev and prod and works under
 * compilation. See PHP-DI docs:
 * https://php-di.org/doc/php-definitions.html#closures
 */
return [
    'app.rootPath' => static fn(): string => dirname(__DIR__),

    /**
     * Loaded once per container build (PHP-DI caches by entry id).
     * Subsequent get('doctrineConfig') returns the same array.
     */
    'doctrineConfig' => static fn(): array => require dirname(__DIR__) . '/config/doctrine.php',

    // -------------------------------------------------------------------
    // Doctrine ORM
    // -------------------------------------------------------------------

    /**
     * Build the Doctrine ORM Configuration once per container,
     * shared by both the EntityManager and any scripts that need
     * raw config access (CLI tools).
     */
    Configuration::class => static function (ContainerInterface $c): Configuration {
        $doctrineConfig = $c->get('doctrineConfig');
        $env = $_ENV['APP_ENV'] ?? 'dev';
        return ($doctrineConfig['config_factory'])($env, $c->get('app.rootPath'));
    },

    /**
     * Build the DBAL Connection from env-driven params.
     */
    Connection::class => static function (ContainerInterface $c): Connection {
        $doctrineConfig = $c->get('doctrineConfig');
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
    // OTP — provider selection + service
    // -------------------------------------------------------------------

    /**
     * OTP provider selection.
     *
     * APP_ENV=prod  → MessageCentralOtpProvider (real CPaaS)
     * APP_ENV=*     → InMemoryOtpProvider (no network; tests + dev)
     *
     * Override via SMS_PROVIDER=messagecentral if a developer wants
     * to test against the real CPaaS from their machine. NOT a way
     * to use in-memory in prod — production refuses if creds are
     * missing.
     */
    OtpProvider::class => static function (): OtpProvider {
        $env = $_ENV['APP_ENV'] ?? 'dev';
        $override = $_ENV['SMS_PROVIDER'] ?? null;

        $useMessageCentral = $env === 'prod' || $override === 'messagecentral';

        if (!$useMessageCentral) {
            return new InMemoryOtpProvider();
        }

        $customerId = $_ENV['MESSAGECENTRAL_CUSTOMER_ID'] ?? '';
        $apiKey = $_ENV['MESSAGECENTRAL_KEY'] ?? '';
        $email = $_ENV['MESSAGECENTRAL_EMAIL'] ?? '';

        if ($customerId === '' || $apiKey === '' || $email === '') {
            throw new \RuntimeException(
                'MessageCentralOtpProvider requires env vars: MESSAGECENTRAL_CUSTOMER_ID, ' .
                'MESSAGECENTRAL_KEY, MESSAGECENTRAL_EMAIL. ' .
                'Set them, or unset SMS_PROVIDER to use the in-memory adapter for local testing.'
            );
        }

        $http = new GuzzleClient([
            'base_uri' => $_ENV['MESSAGECENTRAL_BASE_URL'] ?? 'https://cpaas.messagecentral.com',
            'timeout' => 10,
            'connect_timeout' => 5,
        ]);

        return new MessageCentralOtpProvider(
            http: $http,
            customerId: $customerId,
            apiKey: $apiKey,
            email: $email,
            country: $_ENV['MESSAGECENTRAL_COUNTRY'] ?? '971',
        );
    },

    OtpService::class => \DI\autowire(),

    // -------------------------------------------------------------------
    // HTTP layer — request validator + error middleware
    // -------------------------------------------------------------------

    /**
     * symfony/validator instance — set up to read constraints from
     * PHP attributes on DTOs. PHP-DI then injects this into
     * RequestValidator wherever needed.
     */
    ValidatorInterface::class => static function (): ValidatorInterface {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    },

    RequestValidator::class => \DI\autowire(),

    /**
     * Outermost JSON error middleware. Catches HttpException + any
     * uncaught Throwable; renders the {error: {...}} envelope.
     * Debug mode is on for non-prod environments — gives developers
     * stack frames in 500 responses without leaking them in prod.
     */
    ApiErrorMiddleware::class => static function (ContainerInterface $c): ApiErrorMiddleware {
        $env = $_ENV['APP_ENV'] ?? 'dev';
        return new ApiErrorMiddleware(
            responseFactory: $c->get(ResponseFactoryInterface::class),
            debugMode: $env !== 'prod',
        );
    },

    // -------------------------------------------------------------------
    // Controllers — autowired, but listed for discoverability.
    // -------------------------------------------------------------------

    HealthController::class => static function (ContainerInterface $c): HealthController {
        // Explicit factory rather than autowire(). Two reasons:
        //
        // 1. PHP-DI's autowiring for ?Type = null parameters is
        //    ambiguous — sometimes it injects the type, sometimes
        //    the default. Explicit construction removes the doubt.
        //
        // 2. Connection construction itself could fail in test or
        //    misconfigured environments (no DB driver available,
        //    DSN parse error, etc.). We catch that and pass null —
        //    liveness endpoint still works without DB; readiness
        //    will report 'no connection injected' degraded state.
        $connection = null;
        try {
            $connection = $c->get(Connection::class);
        } catch (\Throwable) {
            // No DB available — liveness still works; readiness
            // will return degraded with empty checks.
        }

        return new HealthController(
            $c->get(\Psr\Http\Message\ResponseFactoryInterface::class),
            $connection,
        );
    },

    // M1.4.2 — auth controllers (read-only / no-OTP)
    \Bayti\Api\Http\Serializers\UserSerializer::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\ValidateEmailController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\ValidatePhoneController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\LoginController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\MeController::class => \DI\autowire(),

    // M1.4.3 — OTP issuance
    \Bayti\Api\Http\Controllers\Auth\RegisterController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\SendOtpController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\ConfirmController::class => \DI\autowire(),

    // M1.4.4 — password reset
    \Bayti\Api\Http\Controllers\Auth\ResetController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\ResetConfirmController::class => \DI\autowire(),

    // M1.4.5 — token lifecycle
    \Bayti\Api\Http\Controllers\Auth\RefreshController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\LogoutController::class => \DI\autowire(),
    \Bayti\Api\Http\Controllers\Auth\LogoutAllController::class => \DI\autowire(),

    // Doctrine repositories are accessed via EntityManager::getRepository();
    // no DI registrations needed.
];



