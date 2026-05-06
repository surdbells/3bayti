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

        // 2. Build the DI container.
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

        // 3. Tell Slim's AppFactory which container to use, then create app.
        AppFactory::setContainer($container);
        $app = AppFactory::create();

        // 4. Wire middleware, in execution order:
        //    - Body-parsing first (so handlers see decoded JSON)
        //    - Routing next (resolves which handler will run)
        //    - Error-handling last (outermost — catches everything)
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(
            displayErrorDetails: ($_ENV['APP_ENV'] ?? 'dev') !== 'prod',
            logErrors: true,
            logErrorDetails: true
        );

        // 5. Register routes. Kept in a dedicated file so this factory
        //    stays readable as the route count grows.
        (require $rootPath . '/config/routes.php')($app);

        return $app;
    }
}
