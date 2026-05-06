<?php

declare(strict_types=1);

use Bayti\Api\Http\Controllers\HealthController;
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

return [
    // Controllers — autowired, but listed for discoverability.
    HealthController::class => \DI\autowire(),

    // Doctrine EntityManager, Redis connection, mailer, payment SDK,
    // etc. wire up here as we add them. M1 adds the database; M3
    // adds Redis + queue + payment SDK.
];
