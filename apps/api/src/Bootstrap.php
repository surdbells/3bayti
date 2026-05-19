<?php

declare(strict_types=1);

namespace Bayti\Api;

use DI\ContainerBuilder;
use Dotenv\Dotenv;
use Slim\App;
use Slim\Factory\AppFactory;

/**
 * Slim app factory.
 *
 * Builds the dependency-injection container, creates the Slim app,
 * registers routes + middleware, and returns the configured App.
 *
 * Calling Bootstrap::createApp() is the single place where the entire
 * app comes together. Tests use this same factory; that's the contract.
 */
final class Bootstrap
{
    /**
     * Build a fully-wired Slim App.
     *
     * @param string|null $envPath Override .env file path (used by tests).
     */
    public static function createApp(?string $envPath = null): App
    {
        // 1. Load environment variables. .env at apps/api/.env in dev;
        //    in production these are provided by the hosting platform
        //    (DigitalOcean App Platform secret store).
        $rootPath = $envPath ?? dirname(__DIR__);
        if (file_exists($rootPath . '/.env')) {
            Dotenv::createImmutable($rootPath)->load();
        }

        // 2. Initialize Sentry as early as possible (right after .env so
        //    we have SENTRY_DSN). Per Sentry docs, this should happen
        //    before any code that might throw — DI container build,
        //    routes, etc. — so even bootstrap-time crashes are reported.
        //
        //    If SENTRY_DSN is unset, init() is skipped — the SDK silently
        //    becomes a no-op, captureException() does nothing. That's
        //    deliberate for tests + local dev where we don't want to
        //    pollute Sentry with noise.
        //
        //    We use the BARE \Sentry\init() global, not a wrapper class,
        //    because Sentry's SDK was designed around global state. The
        //    HubInterface that captureException reaches is a singleton.
        //    Wrapping it would just add indirection without isolation.
        self::initSentry();

        // 3. Build the DI container.
        $containerBuilder = new ContainerBuilder();
        $containerBuilder->useAutowiring(true);
        $containerBuilder->useAttributes(false);
        $containerBuilder->addDefinitions(require $rootPath . '/config/di.php');

        // Production optimisations: compile container, disable
        // useless reflection. Toggled by APP_ENV.
        if (($_ENV['APP_ENV'] ?? 'dev') === 'prod') {
            $containerBuilder->enableCompilation($rootPath . '/var/cache/di');
        }

        $container = $containerBuilder->build();

        // 4. Tell Slim's AppFactory which container to use, then create app.
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // 5. Wire middleware, in execution order:
        //    - Body-parsing first (so handlers see decoded JSON)
        //    - Routing next (resolves which handler will run)
        //    - RequestId middleware (ensures every request has a
        //      correlation id; M1.6.2.B)
        //    - Our JSON error middleware OUTERMOST — catches everything
        //      including routing 404s and middleware errors, renders
        //      the standard {error:{code,message,details}} envelope.
        //
        // Slim's add() is LIFO at execution time, so the last add()
        // is the OUTERMOST middleware. Thus order of add() is:
        //   add(BodyParsing) — INNERMOST
        //   add(Routing)
        //   add(RequestId)   — runs BEFORE error middleware so 5xx
        //                       responses still carry X-Request-Id
        //   add(ApiError)    — OUTERMOST
        //
        // We don't use Slim's built-in ErrorMiddleware because it
        // renders HTML by default and doesn't speak our error envelope.
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        // M3.2.X.15-D — Display currency context. Runs on every
        // request; pure (just adds an attribute). Catalog
        // controllers read the attribute; non-catalog routes
        // ignore it. Placed inside the request-id frame so the
        // attribute is set before any catalog handler runs.
        $app->add($container->get(\Bayti\Api\Http\Middleware\CurrencyContextMiddleware::class));
        $app->add($container->get(\Bayti\Api\Http\Middleware\RequestIdMiddleware::class));
        $app->add($container->get(\Bayti\Api\Http\Errors\ApiErrorMiddleware::class));

        // 6. Register routes. Kept in a dedicated file so this factory
        //    stays readable as the route count grows.
        (require $rootPath . '/config/routes.php')($app);

        return $app;
    }

    /**
     * Initialise Sentry SDK if SENTRY_DSN is set.
     *
     * Called BEFORE the DI container is built so that container-build
     * exceptions (e.g., bad DI config) get reported.
     *
     * Configuration choices
     * ---------------------
     *   - release: APP_VERSION (already set by deploy script). Sentry
     *     uses this to associate errors with deploys, calculate
     *     "first seen in release X" metrics, etc.
     *   - environment: APP_ENV ('prod' / 'dev' / 'test'). Lets us
     *     filter dashboard noise by environment.
     *   - send_default_pii: false. We explicitly DON'T want Sentry's
     *     default behaviour of capturing IP/user from request — we
     *     manage that explicitly via setUser() in AuthMiddleware
     *     with our own privacy policy (id + role flags only, no PII).
     *   - traces_sample_rate: 0. Performance monitoring (APM) off
     *     for now — extra cost, not needed at our scale.
     *
     * Tests & local dev: leave SENTRY_DSN unset → init() is skipped,
     * captureException() becomes no-op.
     */
    private static function initSentry(): void
    {
        $dsn = $_ENV['SENTRY_DSN'] ?? '';
        if ($dsn === '') {
            // No DSN — Sentry stays a no-op. Common for tests / local dev.
            return;
        }

        \Sentry\init([
            'dsn' => $dsn,
            'release' => $_ENV['APP_VERSION'] ?? 'unknown',
            'environment' => $_ENV['APP_ENV'] ?? 'dev',
            // Privacy: no auto-PII capture. We attach user context
            // explicitly via AuthMiddleware (id + role flags only).
            'send_default_pii' => false,
            // No APM — defer to M5+ if/when we need traces.
            'traces_sample_rate' => 0.0,
            // Server name useful in self-hosted infra — matches
            // hostname to event for "which box" forensics.
            'server_name' => gethostname() ?: 'unknown',
        ]);
    }
}
