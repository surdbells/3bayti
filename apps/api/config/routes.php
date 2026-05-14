<?php

declare(strict_types=1);

use Bayti\Api\Http\Controllers\Auth\ConfirmController;
use Bayti\Api\Http\Controllers\Auth\LoginController;
use Bayti\Api\Http\Controllers\Auth\LogoutAllController;
use Bayti\Api\Http\Controllers\Auth\LogoutController;
use Bayti\Api\Http\Controllers\Auth\MeController;
use Bayti\Api\Http\Controllers\Auth\RefreshController;
use Bayti\Api\Http\Controllers\Auth\RegisterController;
use Bayti\Api\Http\Controllers\Auth\ResetConfirmController;
use Bayti\Api\Http\Controllers\Auth\ResetController;
use Bayti\Api\Http\Controllers\Auth\SendOtpController;
use Bayti\Api\Http\Controllers\Auth\ValidateEmailController;
use Bayti\Api\Http\Controllers\Auth\ValidatePhoneController;
use Bayti\Api\Http\Controllers\HealthController;
use Bayti\Api\Http\Controllers\Address\CreateAddressController;
use Bayti\Api\Http\Controllers\Address\DeleteAddressController;
use Bayti\Api\Http\Controllers\Address\GetAddressController;
use Bayti\Api\Http\Controllers\Address\GetBillingAddressController;
use Bayti\Api\Http\Controllers\Address\ListAddressesController;
use Bayti\Api\Http\Controllers\Address\SetDefaultAddressController;
use Bayti\Api\Http\Controllers\Address\UpdateAddressController;
use Bayti\Api\Http\Controllers\Address\UpsertBillingAddressController;
use Bayti\Api\Http\Controllers\Measurement\DeleteMeasurementsController;
use Bayti\Api\Http\Controllers\Measurement\GetMeasurementsController;
use Bayti\Api\Http\Controllers\Measurement\ListMeasurementsController;
use Bayti\Api\Http\Controllers\Measurement\UpsertMeasurementsController;
use Bayti\Api\Http\Controllers\Profile\ChangePasswordController;
use Bayti\Api\Http\Controllers\Profile\GetProfileController;
use Bayti\Api\Http\Controllers\Profile\UpdateLocationController;
use Bayti\Api\Http\Controllers\Profile\UpdateProfileController;
use Bayti\Api\Http\Middleware\AuthMiddleware;
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
    // Health checks — split deliberately:
    //
    //   GET /v3/health        — liveness (no DB; container orchestration)
    //   GET /v3/health/ready  — readiness (DB ping; deploy gates + monitoring)
    //
    // See HealthController docblock for the rationale.
    $app->get('/v3/health', [HealthController::class, 'liveness']);
    $app->get('/v3/health/ready', [HealthController::class, 'readiness']);

    // -------------------------------------------------------------------
    // /v3/auth/* — registration, login, password reset, session management
    //
    // Wired up incrementally in M1.4.2-M1.4.5. Each sub-phase adds a few
    // routes here; this group makes the structure visible from one place.
    // -------------------------------------------------------------------
    $app->group('/v3/auth', function (RouteCollectorProxy $group): void {
        // M1.4.2 — read-only / no-OTP endpoints (anonymous unless noted)
        $group->post('/validate-email', ValidateEmailController::class);
        $group->post('/validate-phone', ValidatePhoneController::class);
        $group->post('/login', LoginController::class);
        $group->get('/me', MeController::class)
            ->add(AuthMiddleware::class);

        // M1.4.3 — OTP issuance flows (anonymous)
        $group->post('/register', RegisterController::class);
        $group->post('/send-otp', SendOtpController::class);
        $group->post('/confirm', ConfirmController::class);

        // M1.4.4 — password reset flows (anonymous)
        $group->post('/reset', ResetController::class);
        $group->post('/reset/confirm', ResetConfirmController::class);

        // M1.4.5 — token lifecycle
        $group->post('/refresh', RefreshController::class); // anonymous (refresh token in body)
        $group->post('/logout', LogoutController::class)
            ->add(AuthMiddleware::class);
        $group->post('/logout-all', LogoutAllController::class)
            ->add(AuthMiddleware::class);
    });

    // -------------------------------------------------------------------
    // /v3/me/* — current-user-scoped account management
    //
    // Wired up in M1.7. All routes JWT-protected via AuthMiddleware.
    // The path '/me' is read as "the authenticated user" — sibling to
    // /v3/auth/me but covers larger surface (profile, addresses,
    // measurements, etc).
    // -------------------------------------------------------------------
    $app->group('/v3/me', function (RouteCollectorProxy $group): void {
        // M1.7.1 — profile (read + partial update)
        $group->get('/profile', GetProfileController::class);
        // PATCH semantics — JSON Merge Patch (RFC 7396).
        // Empty body is a 200 no-op.
        $group->patch('/profile', UpdateProfileController::class);

        // M1.7.2 phase A — address read + create
        $group->get('/addresses', ListAddressesController::class);
        $group->post('/addresses', CreateAddressController::class);
        $group->get('/addresses/{id}', GetAddressController::class);

        // M1.7.2 phase B — address modify + default-flag management
        $group->put('/addresses/{id}', UpdateAddressController::class);
        $group->delete('/addresses/{id}', DeleteAddressController::class);
        $group->patch('/addresses/{id}/default', SetDefaultAddressController::class);

        // M3.1.1c — billing address (singleton convenience accessor).
        // Distinct from /addresses; backed by the same `addresses` table
        // but exposes only the user's default-billing row. See ADR at
        // docs/runbooks/m3/m3.1.1b-billing-address-decision.md.
        $group->get('/billing-address', GetBillingAddressController::class);
        $group->patch('/billing-address', UpsertBillingAddressController::class);

        // M3.1.1e — current location (upsert).
        // Backed by the user_locations table (Version20260514000001).
        // Single row per user; PATCH creates if missing, updates otherwise.
        $group->patch('/location', UpdateLocationController::class);

        // M3.1.1f — change password (authenticated, re-auth via current_password).
        // Mirrors /v3/auth/reset/confirm's session-handling pattern:
        // revokes all refresh tokens + issues a fresh pair on success.
        // See ChangePasswordController docblock for the full rationale.
        $group->patch('/password', ChangePasswordController::class);

        // M1.7.3 — body measurements (default + per-category sets)
        // Path-segment design: /default for the catch-all, /category/{id}
        // for category-specific. Same controllers handle both — the
        // route definition determines whether {id} is in $args.
        $group->get('/measurements', ListMeasurementsController::class);
        $group->get('/measurements/default', GetMeasurementsController::class);
        $group->get('/measurements/category/{id}', GetMeasurementsController::class);
        $group->put('/measurements/default', UpsertMeasurementsController::class);
        $group->put('/measurements/category/{id}', UpsertMeasurementsController::class);
        $group->delete('/measurements/default', DeleteMeasurementsController::class);
        $group->delete('/measurements/category/{id}', DeleteMeasurementsController::class);
    })->add(AuthMiddleware::class);

    // ===================================================================
    // M2.1 — Catalog: public read endpoints (no auth required)
    // ===================================================================

    // Categories — public tree + per-slug detail
    $app->get('/v3/categories', \Bayti\Api\Http\Controllers\Catalog\ListCategoriesController::class);
    $app->get('/v3/categories/{slug}', \Bayti\Api\Http\Controllers\Catalog\GetCategoryController::class);

    // Brands — public list + per-slug detail
    $app->get('/v3/brands', \Bayti\Api\Http\Controllers\Catalog\ListBrandsController::class);
    $app->get('/v3/brands/{slug}', \Bayti\Api\Http\Controllers\Catalog\GetBrandController::class);

    // Vendors — public list + per-slug detail
    $app->get('/v3/vendors', \Bayti\Api\Http\Controllers\Catalog\ListVendorsController::class);
    $app->get('/v3/vendors/{slug}', \Bayti\Api\Http\Controllers\Catalog\GetVendorController::class);

    // M2.2 — Products (Day 2 of 10-day rollout)
    $app->get('/v3/products', \Bayti\Api\Http\Controllers\Catalog\ListProductsController::class);
    $app->get('/v3/products/{slug}', \Bayti\Api\Http\Controllers\Catalog\GetProductController::class);
    $app->get('/v3/vendors/{slug}/products', \Bayti\Api\Http\Controllers\Catalog\ListVendorProductsController::class);

    // M3.1.5a — by-legacy-id variants for mobile compatibility during
    // the strangler-fig flip. These resolve legacy WordPress/CodeIgniter
    // ids to v3 entities; same response shape as the slug variants.
    // Retired when mobile rebuilds against slug semantics (M3.1.10+).
    //
    // The `by-legacy-id` path segment is distinctive enough not to
    // collide with real slugs, and the segment counts differ from the
    // slug routes (3 vs 2 segments after /v3/) so Slim ordering is moot.
    $app->get('/v3/products/by-legacy-id/{id}', \Bayti\Api\Http\Controllers\Catalog\GetProductByLegacyIdController::class);
    $app->get('/v3/vendors/by-legacy-id/{id}', \Bayti\Api\Http\Controllers\Catalog\GetVendorByLegacyIdController::class);
    $app->get('/v3/vendors/by-legacy-id/{id}/products', \Bayti\Api\Http\Controllers\Catalog\ListVendorProductsByLegacyIdController::class);

    // M3.1.5.5e — Vendor labels (per-vendor merchandising collections).
    // Slug variant for web/canonical use; by-legacy-id variant for
    // mobile compat.
    $app->get('/v3/vendors/{slug}/labels', \Bayti\Api\Http\Controllers\Catalog\ListVendorLabelsController::class);
    $app->get('/v3/vendors/by-legacy-id/{id}/labels', \Bayti\Api\Http\Controllers\Catalog\ListVendorLabelsByLegacyIdController::class);

    // M3.1.5.5f — Styles (curated outfits, community + editorial).
    // Read-only in this phase; admin/future-admin-UI manages writes.
    $app->get('/v3/styles', \Bayti\Api\Http\Controllers\Catalog\ListStylesController::class);

    // M2.2 — Sitemap data for apps/web build-time generator
    $app->get('/v3/sitemap-data', \Bayti\Api\Http\Controllers\Catalog\GetSitemapDataController::class);

    // ===================================================================
    // M2.1 — Catalog: admin endpoints (admin auth required)
    // ===================================================================
    //
    // Middleware order: outermost-first is OPPOSITE of add() order.
    // We want AuthMiddleware to run FIRST (set the user attribute),
    // then AdminAuthMiddleware to run SECOND (check the user is admin).
    // So: add(AdminAuth) THEN add(Auth) — Auth is added last, runs first.

    $app->group('/v3/admin', function (RouteCollectorProxy $group): void {
        // Brand admin
        $group->get('/brands', \Bayti\Api\Http\Controllers\Admin\Brand\ListBrandsAdminController::class);
        $group->post('/brands', \Bayti\Api\Http\Controllers\Admin\Brand\CreateBrandController::class);
        $group->put('/brands/{id}', \Bayti\Api\Http\Controllers\Admin\Brand\UpdateBrandController::class);
        $group->delete('/brands/{id}', \Bayti\Api\Http\Controllers\Admin\Brand\DeleteBrandController::class);

        // Vendor admin
        $group->get('/vendors', \Bayti\Api\Http\Controllers\Admin\Vendor\ListVendorsAdminController::class);
        $group->post('/vendors', \Bayti\Api\Http\Controllers\Admin\Vendor\CreateVendorController::class);
        $group->put('/vendors/{id}', \Bayti\Api\Http\Controllers\Admin\Vendor\UpdateVendorController::class);
        $group->delete('/vendors/{id}', \Bayti\Api\Http\Controllers\Admin\Vendor\DeleteVendorController::class);

        // Category admin
        $group->get('/categories', \Bayti\Api\Http\Controllers\Admin\Category\ListCategoriesAdminController::class);
        $group->post('/categories', \Bayti\Api\Http\Controllers\Admin\Category\CreateCategoryController::class);
        $group->put('/categories/{id}', \Bayti\Api\Http\Controllers\Admin\Category\UpdateCategoryController::class);
        $group->delete('/categories/{id}', \Bayti\Api\Http\Controllers\Admin\Category\DeleteCategoryController::class);
    })
        ->add(\Bayti\Api\Http\Middleware\AdminAuthMiddleware::class)
        ->add(AuthMiddleware::class);

    // Future route groups land below as M2+ phases ship:
    //   /v3/products/*       (M2.2+)
    //   /v3/cart/*           (M3)
    //   /v3/checkout/*       (M3)
    //   /v3/orders/*         (M3)
    //   /v3/vendor/*         (M4)
};
