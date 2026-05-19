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
    // M3.2.X.11-G — Marketing-email unsubscribe (public, no auth)
    //
    // GET /v3/notifications/unsubscribe?token=...
    //
    // Hit from email clients. Signed JWT in the query string is the
    // only authentication. Returns HTML (not JSON) — the response
    // renders in the email client's preview pane / browser.
    // -------------------------------------------------------------------
    $app->get(
        '/v3/notifications/unsubscribe',
        \Bayti\Api\Http\Controllers\Notification\UnsubscribeController::class,
    );

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
    // M3.1.6d — Cart (authenticated; locked Q7=B server-side per user)
    // ===================================================================
    //
    // Guests use device-local storage and call POST /v3/cart/merge
    // after sign-in to migrate the device cart into the server. The
    // /merge endpoint is in this group because it requires
    // authentication — the guest cart payload is in the body, not
    // identified by any guest token (no anonymous-session model
    // per Q7=B).
    $app->group('/v3/cart', function (RouteCollectorProxy $group): void {
        $group->get('', \Bayti\Api\Http\Controllers\Cart\GetCartController::class);
        $group->post('/items', \Bayti\Api\Http\Controllers\Cart\AddCartItemController::class);
        $group->patch('/items/{id}', \Bayti\Api\Http\Controllers\Cart\UpdateCartItemController::class);
        $group->delete('/items/{id}', \Bayti\Api\Http\Controllers\Cart\RemoveCartItemController::class);
        $group->post('/merge', \Bayti\Api\Http\Controllers\Cart\MergeAnonCartController::class);
        // M3.2.X.8-C — Server-authoritative price quote with optional
        // promo code resolution. Idempotent read; no DB writes.
        $group->post('/quote', \Bayti\Api\Http\Controllers\Cart\QuoteCartController::class);
    })->add(AuthMiddleware::class);

    // ===================================================================
    // M3.1.6e — Orders (authenticated read-only customer view)
    // ===================================================================
    //
    // Replaces legacy /customer/read-orders, /customer/read_orders_listing,
    // /customer/read-order-details. Vendor-side + admin endpoints come
    // in M3.1.7.
    $app->group('/v3/orders', function (RouteCollectorProxy $group): void {
        $group->get('', \Bayti\Api\Http\Controllers\Order\ListOrdersController::class);
        $group->get('/{id}', \Bayti\Api\Http\Controllers\Order\GetOrderController::class);
        // M3.1.7-F — customer self-serve cancel (pending_payment only)
        $group->post('/{id:[0-9]+}/cancel', \Bayti\Api\Http\Controllers\Order\CancelOrderController::class);
        // M3.2.X.18-D — customer return submission + list per order
        $group->post(
            '/{id:[0-9]+}/returns',
            \Bayti\Api\Http\Controllers\Order\SubmitReturnController::class,
        );
        $group->get(
            '/{id:[0-9]+}/returns',
            \Bayti\Api\Http\Controllers\Order\ListCustomerReturnsController::class,
        );
    })->add(AuthMiddleware::class);

    // M3.2.X.18-D — Customer return detail/cancel + photo serve.
    // Separate /v3/returns group so customers can address returns
    // directly by id (matches mobile UX of "my returns" tab).
    $app->group('/v3/returns', function (RouteCollectorProxy $group): void {
        $group->get('/{id:[0-9]+}', \Bayti\Api\Http\Controllers\Order\GetReturnController::class);
        $group->post(
            '/{id:[0-9]+}/cancel',
            \Bayti\Api\Http\Controllers\Order\CancelReturnController::class,
        );
        // Photo serve has 3-branch auth (customer/vendor/admin) inside
        // the controller. AuthMiddleware just enforces some token.
        $group->get(
            '/{id:[0-9]+}/photos/{photoId:[0-9]+}',
            \Bayti\Api\Http\Controllers\Order\ServeReturnPhotoController::class,
        );
    })->add(AuthMiddleware::class);

    // ===================================================================
    // M3.1.6f1+f2 — Checkout (initiate against Noon + status polling)
    // ===================================================================
    //
    // POST /v3/checkout/initiate       — auth required (M3.1.6f1)
    // GET  /v3/checkout/status/{ref}   — auth required (M3.1.6f2)
    //
    // The webhook receiver below is OUTSIDE this group — Noon doesn't
    // have one of our user tokens.
    $app->group('/v3/checkout', function (RouteCollectorProxy $group): void {
        $group->post(
            '/initiate',
            \Bayti\Api\Http\Controllers\Checkout\InitiateCheckoutController::class,
        );
        $group->get(
            '/status/{order_reference}',
            \Bayti\Api\Http\Controllers\Checkout\GetCheckoutStatusController::class,
        );
    })->add(AuthMiddleware::class);

    // M3.1.6f2 — Noon webhook receiver.
    //
    // INTENTIONALLY UNAUTHENTICATED — Noon does NOT have one of our
    // JWTs. Authentication is via:
    //   1. Signature header (M3.1.6: logged + accepted;
    //      M3.1.7: strict HMAC verification)
    //   2. RETRIEVE-ORDER-BEFORE-ACTING — the load-bearing safety
    //      mechanism. Even with no signature verification, a spoofed
    //      webhook cannot make us mark an order paid because the
    //      controller calls gateway.retrieveOrder server-to-server
    //      (authenticated with OUR merchant credentials) to confirm
    //      the true state with Noon directly.
    //
    // NEVER add AuthMiddleware to this route.
    $app->post(
        '/v3/payment/webhook/noon',
        \Bayti\Api\Http\Controllers\Checkout\NoonWebhookController::class,
    );

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
    $app->get('/v3/featured-vendors', \Bayti\Api\Http\Controllers\Catalog\ListFeaturedVendorsController::class);
    $app->get('/v3/vendors/{slug}', \Bayti\Api\Http\Controllers\Catalog\GetVendorController::class);

    // M2.2 — Products (Day 2 of 10-day rollout)
    $app->get('/v3/products', \Bayti\Api\Http\Controllers\Catalog\ListProductsController::class);
    // M3.2.X.10 — Faceted search. Registered BEFORE /v3/products/{slug}
    // so the literal 'facets' segment doesn't get eaten by the slug
    // matcher.
    $app->get('/v3/products/facets', \Bayti\Api\Http\Controllers\Catalog\ListFacetsController::class);
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

        // M3.2.X.6-C — Vendor lifecycle state transitions
        $group->post('/vendors/{id:[0-9]+}/approve',
            \Bayti\Api\Http\Controllers\Admin\Vendor\ApproveVendorController::class);
        $group->post('/vendors/{id:[0-9]+}/suspend',
            \Bayti\Api\Http\Controllers\Admin\Vendor\SuspendVendorController::class);
        $group->post('/vendors/{id:[0-9]+}/reactivate',
            \Bayti\Api\Http\Controllers\Admin\Vendor\ReactivateVendorController::class);

        // M3.2.X.14-D — Cross-vendor metrics list (admin dashboard).
        // Registered BEFORE /vendors/{id:[0-9]+}/metrics so the
        // literal 'vendor-metrics' path doesn't get parsed as a
        // vendor id.
        $group->get('/vendor-metrics',
            \Bayti\Api\Http\Controllers\Admin\Vendor\ListAdminVendorMetricsController::class);

        // M3.2.X.14-B — Vendor performance metrics (admin single-vendor view)
        $group->get('/vendors/{id:[0-9]+}/metrics',
            \Bayti\Api\Http\Controllers\Admin\Vendor\GetAdminVendorMetricsController::class);

        // Category admin
        $group->get('/categories', \Bayti\Api\Http\Controllers\Admin\Category\ListCategoriesAdminController::class);
        $group->post('/categories', \Bayti\Api\Http\Controllers\Admin\Category\CreateCategoryController::class);
        $group->put('/categories/{id}', \Bayti\Api\Http\Controllers\Admin\Category\UpdateCategoryController::class);
        $group->delete('/categories/{id}', \Bayti\Api\Http\Controllers\Admin\Category\DeleteCategoryController::class);

        // Admin orders surface (M3.1.7-D)
        $group->get('/orders', \Bayti\Api\Http\Controllers\Admin\Order\ListAdminOrdersController::class);
        $group->get('/orders/{id:[0-9]+}', \Bayti\Api\Http\Controllers\Admin\Order\GetAdminOrderController::class);
        $group->patch('/orders/{id:[0-9]+}/status',
            \Bayti\Api\Http\Controllers\Admin\Order\OverrideOrderStatusController::class);
        $group->patch('/orders/{orderId:[0-9]+}/items/{itemId:[0-9]+}/status',
            \Bayti\Api\Http\Controllers\Admin\Order\OverrideOrderItemStatusController::class);

        // M3.2.X.17-C — Order timeline (admin chronological event feed)
        $group->get('/orders/{id:[0-9]+}/timeline',
            \Bayti\Api\Http\Controllers\Admin\Order\GetAdminOrderTimelineController::class);

        // Refund (M3.1.7-E)
        $group->post('/orders/{id:[0-9]+}/refund',
            \Bayti\Api\Http\Controllers\Admin\Order\RefundOrderController::class);

        // Cancel (M3.1.7-F)
        $group->post('/orders/{id:[0-9]+}/cancel',
            \Bayti\Api\Http\Controllers\Admin\Order\CancelOrderController::class);

        // Disputes (M3.1.7-G)
        $group->get('/disputes', \Bayti\Api\Http\Controllers\Admin\Dispute\ListDisputesController::class);
        $group->get('/disputes/{id:[0-9]+}', \Bayti\Api\Http\Controllers\Admin\Dispute\GetDisputeController::class);
        $group->patch('/disputes/{id:[0-9]+}', \Bayti\Api\Http\Controllers\Admin\Dispute\ResolveDisputeController::class);

        // Notification logs (M3.2.X.4-C) — admin observability surface
        // for the notification_logs table. Filters: order_id, template,
        // status, recipient, error_kind, since, until, limit, offset.
        $group->get('/notification-logs',
            \Bayti\Api\Http\Controllers\Admin\NotificationLog\ListNotificationLogsController::class);

        // M3.2.X.8-E — Promo code CRUD. Soft-delete preserves
        // promo_redemptions FK; hard-delete only when zero redemptions.
        $group->get('/promo-codes',
            \Bayti\Api\Http\Controllers\Admin\PromoCode\ListPromoCodesController::class);
        $group->get('/promo-codes/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\PromoCode\GetPromoCodeController::class);
        $group->post('/promo-codes',
            \Bayti\Api\Http\Controllers\Admin\PromoCode\CreatePromoCodeController::class);
        $group->put('/promo-codes/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\PromoCode\UpdatePromoCodeController::class);
        $group->delete('/promo-codes/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\PromoCode\DeletePromoCodeController::class);

        // M3.2.X.18-F — Returns admin surface
        $group->get('/returns',
            \Bayti\Api\Http\Controllers\Admin\Order\ListAdminReturnsController::class);
        $group->get('/returns/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\Order\GetAdminReturnController::class);
        $group->post('/returns/{id:[0-9]+}/approve',
            \Bayti\Api\Http\Controllers\Admin\Order\ApproveReturnController::class);
        $group->post('/returns/{id:[0-9]+}/deny',
            \Bayti\Api\Http\Controllers\Admin\Order\DenyReturnController::class);
        $group->post('/returns/{id:[0-9]+}/mark-picked-up',
            \Bayti\Api\Http\Controllers\Admin\Order\MarkPickedUpController::class);
        $group->post('/returns/{id:[0-9]+}/record-refund',
            \Bayti\Api\Http\Controllers\Admin\Order\RecordReturnRefundController::class);

        // M3.2.X.15-F — FX rate management (display-only multi-currency).
        // GET lists all rates with staleness flags; PUT upserts the rate
        // for a single target currency. Audited via AuditEmitter with
        // subject_type='FxRate'; visible via the X.4-C audit-log surface.
        $group->get('/fx-rates',
            \Bayti\Api\Http\Controllers\Admin\Currency\ListFxRatesController::class);
        $group->put('/fx-rates/{target:[A-Za-z]{3}}',
            \Bayti\Api\Http\Controllers\Admin\Currency\UpsertFxRateController::class);
    })
        ->add(\Bayti\Api\Http\Middleware\AdminAuthMiddleware::class)
        ->add(AuthMiddleware::class);

    // -----------------------------------------------------------------
    // Vendor surface (M3.1.7-C)
    // -----------------------------------------------------------------
    // Vendors see their own orders + advance line items through the
    // fulfilment lifecycle (accepted → preparing → shipped → delivered).
    // Cross-vendor isolation enforced at the repository layer.
    $app->group('/v3/vendor', function (RouteCollectorProxy $group): void {
        $group->get('/orders', \Bayti\Api\Http\Controllers\Vendor\Order\ListVendorOrdersController::class);
        $group->get('/orders/{id:[0-9]+}', \Bayti\Api\Http\Controllers\Vendor\Order\GetVendorOrderController::class);
        $group->patch('/orders/{orderId:[0-9]+}/items/{itemId:[0-9]+}/status',
            \Bayti\Api\Http\Controllers\Vendor\Order\TransitionVendorOrderItemController::class);
        // M3.2.X.17-D — vendor order timeline
        $group->get('/orders/{id:[0-9]+}/timeline',
            \Bayti\Api\Http\Controllers\Vendor\Order\GetVendorOrderTimelineController::class);
        // M3.2.X.18-E — vendor return endpoints
        $group->get(
            '/returns',
            \Bayti\Api\Http\Controllers\Vendor\Order\ListVendorReturnsController::class,
        );
        $group->get(
            '/returns/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Vendor\Order\GetVendorReturnController::class,
        );
        $group->post(
            '/returns/{id:[0-9]+}/confirm-receipt',
            \Bayti\Api\Http\Controllers\Vendor\Order\ConfirmReceiptController::class,
        );
        // M3.2.X.14-C — vendor self-serve performance metrics
        $group->get(
            '/metrics',
            \Bayti\Api\Http\Controllers\Vendor\GetVendorSelfMetricsController::class,
        );
    })
        ->add(\Bayti\Api\Http\Middleware\VendorAuthMiddleware::class)
        ->add(AuthMiddleware::class);

    // M3.2.X.6-D — Vendor self-serve onboarding endpoints.
    //
    // SEPARATE route group with AuthMiddleware ONLY (no
    // VendorAuthMiddleware). Per Option I locked in the M3.2.X.6
    // plan: these endpoints intentionally allow pending/suspended
    // vendors through:
    //
    //   - POST /v3/vendor/onboarding/submit creates the vendor for
    //     a user who is NOT yet a vendor (any authenticated user
    //     can submit).
    //   - GET /v3/vendor/onboarding/status lets a vendor user check
    //     their pending status — the lifecycle gate would block
    //     them from this if it ran here.
    //
    // The submit controller flips is_vendor=true; the status
    // controller has its own inline is_vendor check to filter
    // non-vendor users while leaving pending+suspended users
    // through.
    $app->group('/v3/vendor/onboarding', function (RouteCollectorProxy $group): void {
        $group->post('/submit',
            \Bayti\Api\Http\Controllers\Vendor\Onboarding\SubmitOnboardingController::class);
        $group->get('/status',
            \Bayti\Api\Http\Controllers\Vendor\Onboarding\GetOnboardingStatusController::class);
    })
        ->add(AuthMiddleware::class);

    // Future route groups land below as M2+ phases ship:
    //   /v3/products/*       (M2.2+)
    //   /v3/cart/*           (M3)
    //   /v3/checkout/*       (M3)
    //   /v3/orders/*         (M3)
    //   /v3/vendor/*         (M4)
};
