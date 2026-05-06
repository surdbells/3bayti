<?php

declare(strict_types=1);

use Bayti\Api\Http\Controllers\HealthController;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;

/**
 * Route registry.
 *
 * Returns a closure that takes the Slim App and registers all routes.
 * As the route count grows, split this into per-resource files (auth.php,
 * catalog.php, orders.php) and require them all from here. For now we
 * keep them inline grouped by `/v3/<domain>/*`.
 *
 * All routes live under /v3 — that's the new platform's namespace per
 * Decision 9 in docs/roadmap.md. The old /v2 catalog endpoints stay
 * on the legacy PHP backend until M2 ships their /v3 replacements.
 */

return function (App $app): void {
    // Health check — outside any auth, no DB dependency.
    $app->get('/v3/health', HealthController::class);

    // -------------------------------------------------------------------
    // /v3/auth/* — registration, login, password reset, session management
    //
    // Wired up incrementally in M1.4.2-M1.4.5. Each sub-phase adds a few
    // routes here; this group makes the structure visible from one place.
    // -------------------------------------------------------------------
    $app->group('/v3/auth', function (RouteCollectorProxy $group): void {
        // M1.4.2 — read-only / no-OTP endpoints land here:
        //   POST /validate-email      (anonymous)
        //   POST /validate-phone      (anonymous)
        //   POST /login               (anonymous)
        //   GET  /me                  (auth required)
        //
        // M1.4.3 — OTP issuance:
        //   POST /register            (anonymous)
        //   POST /send-otp            (anonymous)
        //   POST /confirm             (anonymous)
        //
        // M1.4.4 — Password reset:
        //   POST /reset               (anonymous)
        //   POST /reset/confirm       (anonymous)
        //
        // M1.4.5 — Token lifecycle:
        //   POST /refresh             (anonymous, refresh token in body)
        //   POST /logout              (auth required)
        //   POST /logout-all          (auth required)
    });

    // Future route groups land below as M2+ phases ship:
    //   /v3/account/*    (M1.7 — profile, addresses, measurements)
    //   /v3/products/*   (M2)
    //   /v3/categories/* (M2)
    //   /v3/cart/*       (M3)
    //   /v3/checkout/*   (M3)
    //   /v3/orders/*     (M3)
    //   /v3/vendor/*     (M4)
    //   /v3/admin/*      (M4)
};
