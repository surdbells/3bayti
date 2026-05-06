<?php

declare(strict_types=1);

use Bayti\Api\Http\Controllers\HealthController;
use Slim\App;

/**
 * Route registry.
 *
 * Returns a closure that takes the Slim App and registers all routes.
 * As the route count grows, split this into per-resource files (auth.php,
 * catalog.php, orders.php) and require them all from here. For now a
 * single file is fine.
 *
 * All routes live under /v3 — that's the new platform's namespace per
 * Decision 9 in docs/roadmap.md. The old /v2 catalog endpoints stay
 * on the legacy PHP backend until M2 ships their /v3 replacements.
 */

return function (App $app): void {
    // Health check — outside any auth, no DB dependency.
    $app->get('/v3/health', HealthController::class);

    // Future routes register here as M1+ phases land:
    //   /v3/auth/*       (M1)
    //   /v3/account/*    (M1)
    //   /v3/products/*   (M2)
    //   /v3/categories/* (M2)
    //   /v3/cart/*       (M3)
    //   /v3/checkout/*   (M3)
    //   /v3/orders/*     (M3)
    //   /v3/vendor/*     (M4)
    //   /v3/admin/*      (M4)
};
