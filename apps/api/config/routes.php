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
use Bayti\Api\Http\Controllers\Profile\DeleteAccountController;
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

    // Signed, short-lived serving of private KYC documents (authorised by the
    // HMAC signature in the query, minted inside the authenticated compliance
    // GET responses — see ComplianceDocumentSigner). No auth middleware: the
    // signature is the credential.
    $app->get(
        '/v3/compliance-documents/{vendorId:[0-9]+}/{field}',
        \Bayti\Api\Http\Controllers\Compliance\ServeComplianceDocumentController::class,
    );

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
    // /v3/upload — authenticated image upload (product images, vendor logo/cover)
    // One endpoint; context query param selects the storage path:
    //   ?context=product       → products/{vendor-slug}/{ulid}.{ext}
    //   ?context=vendor_logo   → vendors/{vendor-slug}/logo.{ext}
    //   ?context=vendor_cover  → vendors/{vendor-slug}/cover.{ext}
    // Returns {storage_path, url, mime_type, size_bytes}.
    // Apache serves var/uploads/ under /uploads/ — the URL in the
    // response is the canonical origin URL; CF image transforms are
    // applied by the front-end wrapper around that URL.
    $app->post('/v3/upload',
        \Bayti\Api\Http\Controllers\Media\UploadImageController::class,
    )->add(\Bayti\Api\Http\Middleware\AuthMiddleware::class);

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

        // Workstream C — profile picture upload (mobile + portal).
        $group->post('/avatar', \Bayti\Api\Http\Controllers\Me\UpdateMeAvatarController::class);

        // M3.2.X.12-G — Personalized "for-you" recommendations
        $group->get(
            '/recommendations',
            \Bayti\Api\Http\Controllers\Me\GetMeRecommendationsController::class,
        );

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

        // M3.2.Y.6-A — account deletion (authenticated, re-auth via
        // current_password). Deactivates + soft-deletes the user and
        // revokes all refresh tokens. Order history is retained.
        // See DeleteAccountController docblock for the full rationale.
        $group->delete('', DeleteAccountController::class);

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

        // M3.2.Y.6-C — Wishlist (products-only). GET lists saved
        // products (paginated, ProductSerializer list shape); POST is
        // idempotent (already-saved → no-op success, not 409); DELETE
        // by productId is idempotent (always 204).
        $group->get('/wishlist', \Bayti\Api\Http\Controllers\Wishlist\ListWishlistController::class);
        $group->post('/wishlist', \Bayti\Api\Http\Controllers\Wishlist\AddWishlistItemController::class);

        // M3.2.Z.3-API — wishlist labels (Q-Z3=B). Registered BEFORE the
        // /wishlist/{productId} routes so 'labels' isn't captured as a
        // product id.
        $group->get('/wishlist/labels', \Bayti\Api\Http\Controllers\Wishlist\ListWishlistLabelsController::class);
        $group->post('/wishlist/labels', \Bayti\Api\Http\Controllers\Wishlist\CreateWishlistLabelController::class);
        $group->patch('/wishlist/labels/{id:[0-9]+}', \Bayti\Api\Http\Controllers\Wishlist\RenameWishlistLabelController::class);
        $group->delete('/wishlist/labels/{id:[0-9]+}', \Bayti\Api\Http\Controllers\Wishlist\DeleteWishlistLabelController::class);

        // Move a saved product between labels (or to uncategorized).
        $group->patch('/wishlist/{productId:[0-9]+}', \Bayti\Api\Http\Controllers\Wishlist\MoveWishlistItemController::class);
        $group->delete('/wishlist/{productId:[0-9]+}', \Bayti\Api\Http\Controllers\Wishlist\RemoveWishlistItemController::class);

        // M3.2.Z.4-D — Push notification device tokens. Register
        // (upsert) on app launch / token refresh; deactivate on
        // logout / opt-out. Both are idempotent and owner-scoped.
        $group->post('/device-tokens', \Bayti\Api\Http\Controllers\Notification\RegisterDeviceTokenController::class);
        $group->delete('/device-tokens', \Bayti\Api\Http\Controllers\Notification\DeleteDeviceTokenController::class);

        // v3 customer Follow (replaces legacy /customer/follow + /unfollow).
        // Idempotent + owner-scoped; the vendor id is in the path.
        $group->post('/following/{vendorId:[0-9]+}', \Bayti\Api\Http\Controllers\Following\FollowVendorController::class);
        $group->delete('/following/{vendorId:[0-9]+}', \Bayti\Api\Http\Controllers\Following\UnfollowVendorController::class);

        // v3 customer support tickets (owner-scoped). Replaces legacy
        // /customer/create_ticket, read_ticket, read-ticket-messages,
        // send-ticket-message. /tickets is registered before the
        // /tickets/{id} variants by virtue of exact-vs-pattern matching.
        $group->get('/tickets', \Bayti\Api\Http\Controllers\Ticket\ListMyTicketsController::class);
        $group->post('/tickets', \Bayti\Api\Http\Controllers\Ticket\CreateMyTicketController::class);
        $group->get('/tickets/{id:[0-9]+}', \Bayti\Api\Http\Controllers\Ticket\GetMyTicketController::class);
        $group->get('/tickets/{id:[0-9]+}/messages', \Bayti\Api\Http\Controllers\Ticket\ListMyTicketMessagesController::class);
        $group->post('/tickets/{id:[0-9]+}/messages', \Bayti\Api\Http\Controllers\Ticket\CreateMyTicketMessageController::class);

        // v3 customer reviews — own reviews (replaces legacy
        // /customer/settings/read-reviews + /settings/delete-review).
        $group->get('/reviews', \Bayti\Api\Http\Controllers\Review\ListMyReviewsController::class);
        $group->delete('/reviews/{id:[0-9]+}', \Bayti\Api\Http\Controllers\Review\DeleteMyReviewController::class);

        // v3 customer style submission (replaces legacy /customer/create_style).
        $group->post('/styles', \Bayti\Api\Http\Controllers\Style\CreateStyleController::class);
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
        // M3.5 — Gift card preview: how much of this card applies to the cart.
        // Pure read — no balance deducted (debit happens at /checkout/initiate).
        $group->post('/gift-card', \Bayti\Api\Http\Controllers\GiftCard\ApplyGiftCardToCartController::class);
    })->add(AuthMiddleware::class);

    // ===================================================================
    // M3.5 — Gift Cards
    // ===================================================================
    // Public (no auth):
    $app->get('/v3/gift-cards/themes',
        \Bayti\Api\Http\Controllers\GiftCard\ListGiftCardThemesController::class);
    $app->get('/v3/gift-cards/balance',
        \Bayti\Api\Http\Controllers\GiftCard\CheckGiftCardBalanceController::class);

    // Authenticated customer routes:
    $app->post('/v3/gift-cards/purchase',
        \Bayti\Api\Http\Controllers\GiftCard\PurchaseGiftCardController::class
    )->add(\Bayti\Api\Http\Middleware\AuthMiddleware::class);
    $app->get('/v3/gift-cards/mine',
        \Bayti\Api\Http\Controllers\GiftCard\ListMyGiftCardsController::class
    )->add(\Bayti\Api\Http\Middleware\AuthMiddleware::class);
    $app->post('/v3/gift-cards/redeem',
        \Bayti\Api\Http\Controllers\GiftCard\RedeemGiftCardController::class
    )->add(\Bayti\Api\Http\Middleware\AuthMiddleware::class);

    // Admin/internal — activate after payment confirmation:
    $app->post('/v3/gift-cards/{id:[0-9]+}/activate',
        \Bayti\Api\Http\Controllers\GiftCard\ActivateGiftCardController::class
    )
        ->add(\Bayti\Api\Http\Middleware\AdminAuthMiddleware::class)
        ->add(\Bayti\Api\Http\Middleware\AuthMiddleware::class);

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

    // Order-scoped customer↔vendor chat (customer side). One thread per
    // order item; messages endpoint doubles as the polling source and
    // carries the conversation meta so the screen opens in one call.
    $app->group('/v3/chat', function (RouteCollectorProxy $group): void {
        $group->get('/conversations', \Bayti\Api\Http\Controllers\Chat\Customer\ListConversationsController::class);
        // Store-grouped inbox (replaces legacy /customer/read-messages).
        $group->get('/conversation-stores', \Bayti\Api\Http\Controllers\Chat\Customer\ListConversationStoresController::class);
        $group->get('/unread-count', \Bayti\Api\Http\Controllers\Chat\Customer\GetUnreadCountController::class);
        $group->get(
            '/conversations/{uuid}/messages',
            \Bayti\Api\Http\Controllers\Chat\Customer\GetMessagesController::class,
        );
        $group->post(
            '/conversations/{uuid}/messages',
            \Bayti\Api\Http\Controllers\Chat\Customer\SendMessageController::class,
        );
        $group->post(
            '/conversations/{uuid}/read',
            \Bayti\Api\Http\Controllers\Chat\Customer\MarkReadController::class,
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

    // M3.2.Y.3-A — Noon payment-return browser redirect.
    //
    // INTENTIONALLY UNAUTHENTICATED — Noon redirects the raw browser
    // here after hosted checkout; we cannot rely on a valid auth token
    // being present on this request. The endpoint leaks nothing: it
    // only reflects the supplied reference into a 302 to the web app's
    // polling page. The authoritative, ownership-enforcing check
    // happens when the web app polls GET /v3/checkout/status/{ref}
    // (which IS authenticated). See CheckoutReturnRedirectController.
    //
    // The mobile webview intercepts this API path before the redirect
    // resolves, so it is unaffected by the 302 target.
    //
    // NEVER add AuthMiddleware to this route.
    $app->get(
        '/v3/checkout/return/{order_reference}',
        \Bayti\Api\Http\Controllers\Checkout\CheckoutReturnRedirectController::class,
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
    // HP-BE2 — Storefront campaigns (homepage Anniversary Deals + Flash Sale).
    // /active MUST be registered before /{slug} so the literal path is not
    // captured by the slug placeholder.
    $app->get('/v3/campaigns/active', \Bayti\Api\Http\Controllers\Catalog\GetActiveCampaignsController::class);
    $app->get('/v3/campaigns/{slug}', \Bayti\Api\Http\Controllers\Catalog\GetCampaignController::class);
    $app->get('/v3/vendors/{slug}', \Bayti\Api\Http\Controllers\Catalog\GetVendorController::class);

    // Guest cart price resolution (public — no auth). Resolves a device-
    // local cart payload into a server-priced display cart so the
    // storefront drawer / cart page show authoritative, current prices.
    // The /v3/cart group is auth-only (no anonymous session per Q7=B);
    // this single read-only endpoint is the guest-facing exception.
    $app->post('/v3/cart/resolve', \Bayti\Api\Http\Controllers\Cart\ResolveCartController::class);

    // M2.2 — Products (Day 2 of 10-day rollout)
    $app->get('/v3/products', \Bayti\Api\Http\Controllers\Catalog\ListProductsController::class);
    // M3.2.X.10 — Faceted search. Registered BEFORE /v3/products/{slug}
    // so the literal 'facets' segment doesn't get eaten by the slug
    // matcher.
    $app->get('/v3/products/facets', \Bayti\Api\Http\Controllers\Catalog\ListFacetsController::class);
    $app->get('/v3/products/{slug}', \Bayti\Api\Http\Controllers\Catalog\GetProductController::class);
    // M3.2.X.12-G — Per-product recommendations (public)
    $app->get('/v3/products/{slug}/recommendations',
        \Bayti\Api\Http\Controllers\Catalog\GetProductRecommendationsController::class);
    $app->get('/v3/vendors/{slug}/products', \Bayti\Api\Http\Controllers\Catalog\ListVendorProductsController::class);

    // Product/vendor reviews (Group B / B1). Numeric id in the path
    // (mobile passes ids); the distinct /reviews suffix + 3-segment
    // shape means no collision with the {slug} catalog routes above.
    // Public reads expose APPROVED reviews only.
    $app->get('/v3/products/{productId:[0-9]+}/reviews', \Bayti\Api\Http\Controllers\Review\ListProductReviewsController::class);
    $app->get('/v3/vendors/{vendorId:[0-9]+}/reviews', \Bayti\Api\Http\Controllers\Review\ListVendorPublicReviewsController::class);
    // Authed customer review actions.
    $app->post('/v3/products/{productId:[0-9]+}/reviews', \Bayti\Api\Http\Controllers\Review\CreateReviewController::class)->add(AuthMiddleware::class);
    $app->post('/v3/vendors/{vendorId:[0-9]+}/reviews', \Bayti\Api\Http\Controllers\Review\CreateVendorReviewController::class)->add(AuthMiddleware::class);
    $app->post('/v3/reviews/{id:[0-9]+}/helpful', \Bayti\Api\Http\Controllers\Review\MarkReviewHelpfulController::class)->add(AuthMiddleware::class);

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

    $perm = $app->getContainer()->get(\Bayti\Api\Http\Middleware\PermissionGuard::class);

    $app->group('/v3/admin', function (RouteCollectorProxy $group) use ($perm): void {
        // Admin in-app notification feed (admin top-bar bell) — replaces the
        // legacy /vendors/common/notifications call for admins.
        $group->get(
            '/notifications',
            \Bayti\Api\Http\Controllers\Admin\Notification\ListAdminNotificationsController::class,
        )->add($perm->for('notifications.view'));
        $group->post(
            '/notifications/mark-read',
            \Bayti\Api\Http\Controllers\Admin\Notification\MarkAdminNotificationsReadController::class,
        )->add($perm->for('notifications.view'));

        // Chat moderation — feed of PII-flagged message attempts.
        $group->get(
            '/chat/flagged',
            \Bayti\Api\Http\Controllers\Admin\Chat\ListFlaggedMessagesController::class,
        )->add($perm->for('tickets.view'));
        $group->get(
            '/chat/conversations/{uuid}',
            \Bayti\Api\Http\Controllers\Admin\Chat\GetConversationController::class,
        )->add($perm->for('tickets.view'));

        // Brand admin
        $group->get('/brands', \Bayti\Api\Http\Controllers\Admin\Brand\ListBrandsAdminController::class)->add($perm->for('catalog.brands_view'));
        $group->post('/brands', \Bayti\Api\Http\Controllers\Admin\Brand\CreateBrandController::class)->add($perm->for('catalog.brands_manage'));
        $group->put('/brands/{id}', \Bayti\Api\Http\Controllers\Admin\Brand\UpdateBrandController::class)->add($perm->for('catalog.brands_manage'));
        $group->delete('/brands/{id}', \Bayti\Api\Http\Controllers\Admin\Brand\DeleteBrandController::class)->add($perm->for('catalog.brands_manage'));

        // Vendor admin
        $group->get('/vendors', \Bayti\Api\Http\Controllers\Admin\Vendor\ListVendorsAdminController::class)->add($perm->for('vendors.view'));
        $group->post('/vendors', \Bayti\Api\Http\Controllers\Admin\Vendor\CreateVendorController::class)->add($perm->for('vendors.create'));
        $group->put('/vendors/{id}', \Bayti\Api\Http\Controllers\Admin\Vendor\UpdateVendorController::class)->add($perm->for('vendors.edit'));
        $group->delete('/vendors/{id}', \Bayti\Api\Http\Controllers\Admin\Vendor\DeleteVendorController::class)->add($perm->for('vendors.suspend'));

        // M3.2.X.6-C — Vendor lifecycle state transitions
        $group->post('/vendors/{id:[0-9]+}/approve',
            \Bayti\Api\Http\Controllers\Admin\Vendor\ApproveVendorController::class)->add($perm->for('vendors.approve'));
        // Admin KYC compliance review.
        $group->get('/vendors/{id:[0-9]+}/compliance',
            \Bayti\Api\Http\Controllers\Admin\Vendor\Compliance\GetAdminVendorComplianceController::class)->add($perm->for('vendors.view_compliance'));
        $group->post('/vendors/{id:[0-9]+}/compliance/approve',
            \Bayti\Api\Http\Controllers\Admin\Vendor\Compliance\ApproveVendorComplianceController::class)->add($perm->for('vendors.review_compliance'));
        $group->post('/vendors/{id:[0-9]+}/compliance/reject',
            \Bayti\Api\Http\Controllers\Admin\Vendor\Compliance\RejectVendorComplianceController::class)->add($perm->for('vendors.review_compliance'));
        $group->post('/vendors/{id:[0-9]+}/suspend',
            \Bayti\Api\Http\Controllers\Admin\Vendor\SuspendVendorController::class)->add($perm->for('vendors.suspend'));
        $group->post('/vendors/{id:[0-9]+}/reactivate',
            \Bayti\Api\Http\Controllers\Admin\Vendor\ReactivateVendorController::class)->add($perm->for('vendors.approve'));

        // M3.2.X.14-D — Cross-vendor metrics list (admin dashboard).
        // Registered BEFORE /vendors/{id:[0-9]+}/metrics so the
        // literal 'vendor-metrics' path doesn't get parsed as a
        // vendor id.
        $group->get('/vendor-metrics',
            \Bayti\Api\Http\Controllers\Admin\Vendor\ListAdminVendorMetricsController::class)->add($perm->for('reports.view'));

        // Platform-wide analytics dashboard (admin home screen KPIs)
        $group->get('/analytics',
            \Bayti\Api\Http\Controllers\Admin\GetAdminPlatformAnalyticsController::class)->add($perm->for('reports.view'));

        // M3.2.X.14-B — Vendor performance metrics (admin single-vendor view)
        $group->get('/vendors/{id:[0-9]+}/metrics',
            \Bayti\Api\Http\Controllers\Admin\Vendor\GetAdminVendorMetricsController::class)->add($perm->for('reports.view'));

        // M3.2.X.13-E — Vendor analytics dashboard (admin single-vendor view)
        $group->get('/vendors/{id:[0-9]+}/analytics',
            \Bayti\Api\Http\Controllers\Admin\Vendor\GetAdminVendorAnalyticsController::class)->add($perm->for('reports.view'));

        // M3.2.X.12-G — Admin recommendations debug
        $group->get('/recommendations/{product_id:[0-9]+}/explain',
            \Bayti\Api\Http\Controllers\Admin\Catalog\GetAdminRecommendationsExplainController::class)->add($perm->for('reports.view'));

        // Category admin
        $group->get('/categories', \Bayti\Api\Http\Controllers\Admin\Category\ListCategoriesAdminController::class)->add($perm->for('catalog.categories_view'));
        $group->post('/categories', \Bayti\Api\Http\Controllers\Admin\Category\CreateCategoryController::class)->add($perm->for('catalog.categories_manage'));
        $group->put('/categories/{id}', \Bayti\Api\Http\Controllers\Admin\Category\UpdateCategoryController::class)->add($perm->for('catalog.categories_manage'));
        $group->delete('/categories/{id}', \Bayti\Api\Http\Controllers\Admin\Category\DeleteCategoryController::class)->add($perm->for('catalog.categories_manage'));

        // Admin orders surface (M3.1.7-D)
        $group->get('/orders', \Bayti\Api\Http\Controllers\Admin\Order\ListAdminOrdersController::class)->add($perm->for('orders.view'));
        $group->get('/orders/{id:[0-9]+}', \Bayti\Api\Http\Controllers\Admin\Order\GetAdminOrderController::class)->add($perm->for('orders.view_detail'));
        $group->patch('/orders/{id:[0-9]+}/status',
            \Bayti\Api\Http\Controllers\Admin\Order\OverrideOrderStatusController::class)->add($perm->for('orders.override_status'));
        $group->patch('/orders/{orderId:[0-9]+}/items/{itemId:[0-9]+}/status',
            \Bayti\Api\Http\Controllers\Admin\Order\OverrideOrderItemStatusController::class)->add($perm->for('orders.update_item_status'));

        // M3.2.X.17-C — Order timeline (admin chronological event feed)
        $group->get('/orders/{id:[0-9]+}/timeline',
            \Bayti\Api\Http\Controllers\Admin\Order\GetAdminOrderTimelineController::class)->add($perm->for('orders.view_detail'));

        // Refund (M3.1.7-E)
        $group->post('/orders/{id:[0-9]+}/refund',
            \Bayti\Api\Http\Controllers\Admin\Order\RefundOrderController::class)->add($perm->for('orders.refund'));

        // Cancel (M3.1.7-F)
        $group->post('/orders/{id:[0-9]+}/cancel',
            \Bayti\Api\Http\Controllers\Admin\Order\CancelOrderController::class)->add($perm->for('orders.cancel'));

        // Disputes (M3.1.7-G)
        $group->get('/disputes', \Bayti\Api\Http\Controllers\Admin\Dispute\ListDisputesController::class)->add($perm->for('disputes.view'));
        $group->get('/disputes/{id:[0-9]+}', \Bayti\Api\Http\Controllers\Admin\Dispute\GetDisputeController::class)->add($perm->for('disputes.view'));
        $group->patch('/disputes/{id:[0-9]+}', \Bayti\Api\Http\Controllers\Admin\Dispute\ResolveDisputeController::class)->add($perm->for('disputes.resolve'));

        // Notification logs (M3.2.X.4-C) — admin observability surface
        // for the notification_logs table. Filters: order_id, template,
        // status, recipient, error_kind, since, until, limit, offset.
        $group->get('/notification-logs',
            \Bayti\Api\Http\Controllers\Admin\NotificationLog\ListNotificationLogsController::class)->add($perm->for('notifications.view'));

        // M3.3.2-C — Admin user list, detail, activate, deactivate.
        $group->get('/users',
            \Bayti\Api\Http\Controllers\Admin\User\ListUsersController::class)->add($perm->for('users.view'));
        $group->get('/users/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\User\GetUserController::class)->add($perm->for('users.view'));
        $group->post('/users/{id:[0-9]+}/activate',
            \Bayti\Api\Http\Controllers\Admin\User\ActivateUserController::class)->add($perm->for('users.deactivate'));
        $group->post('/users/{id:[0-9]+}/deactivate',
            \Bayti\Api\Http\Controllers\Admin\User\DeactivateUserController::class)->add($perm->for('users.deactivate'));
        // M5.1 — Admin-initiated staff creation + password reset.
        $group->post('/users',
            \Bayti\Api\Http\Controllers\Admin\User\CreateUserController::class)->add($perm->for('users.create'));
        $group->patch('/users/{id:[0-9]+}/password',
            \Bayti\Api\Http\Controllers\Admin\User\AdminResetPasswordController::class)->add($perm->for('users.edit'));

        // M3.4-H — Product collection CRUD (admin).
        $group->get('/collections',
            [\Bayti\Api\Http\Controllers\Admin\Collection\CollectionCrudController::class, 'list'])->add($perm->for('catalog.collections_view'));
        $group->post('/collections',
            [\Bayti\Api\Http\Controllers\Admin\Collection\CollectionCrudController::class, 'create'])->add($perm->for('catalog.collections_manage'));
        $group->get('/collections/{id:[0-9]+}',
            [\Bayti\Api\Http\Controllers\Admin\Collection\CollectionCrudController::class, 'get'])->add($perm->for('catalog.collections_view'));
        $group->put('/collections/{id:[0-9]+}',
            [\Bayti\Api\Http\Controllers\Admin\Collection\CollectionCrudController::class, 'update'])->add($perm->for('catalog.collections_manage'));
        $group->delete('/collections/{id:[0-9]+}',
            [\Bayti\Api\Http\Controllers\Admin\Collection\CollectionCrudController::class, 'delete'])->add($perm->for('catalog.collections_manage'));

        // HP-BE3 — Campaigns CRUD (homepage Anniversary Deals + Flash Sale).
        $group->get('/campaigns',
            [\Bayti\Api\Http\Controllers\Admin\Catalog\CampaignCrudController::class, 'list'])->add($perm->for('catalog.campaigns_view'));
        $group->post('/campaigns',
            [\Bayti\Api\Http\Controllers\Admin\Catalog\CampaignCrudController::class, 'create'])->add($perm->for('catalog.campaigns_manage'));
        $group->get('/campaigns/{id:[0-9]+}',
            [\Bayti\Api\Http\Controllers\Admin\Catalog\CampaignCrudController::class, 'get'])->add($perm->for('catalog.campaigns_view'));
        $group->put('/campaigns/{id:[0-9]+}',
            [\Bayti\Api\Http\Controllers\Admin\Catalog\CampaignCrudController::class, 'update'])->add($perm->for('catalog.campaigns_manage'));
        $group->delete('/campaigns/{id:[0-9]+}',
            [\Bayti\Api\Http\Controllers\Admin\Catalog\CampaignCrudController::class, 'delete'])->add($perm->for('catalog.campaigns_manage'));

        // M3.4-G — Support ticket CRUD + messaging.
        $group->get('/tickets',
            \Bayti\Api\Http\Controllers\Admin\Ticket\ListTicketsController::class)->add($perm->for('tickets.view'));
        $group->get('/tickets/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\Ticket\GetTicketController::class)->add($perm->for('tickets.view'));
        $group->patch('/tickets/{id:[0-9]+}/status',
            \Bayti\Api\Http\Controllers\Admin\Ticket\UpdateTicketStatusController::class)->add($perm->for('tickets.update_status'));
        $group->patch('/tickets/{id:[0-9]+}/priority',
            \Bayti\Api\Http\Controllers\Admin\Ticket\UpdateTicketPriorityController::class)->add($perm->for('tickets.update_status'));
        $group->get('/tickets/{id:[0-9]+}/messages',
            \Bayti\Api\Http\Controllers\Admin\Ticket\ListTicketMessagesController::class)->add($perm->for('tickets.view'));
        $group->post('/tickets/{id:[0-9]+}/messages',
            \Bayti\Api\Http\Controllers\Admin\Ticket\CreateTicketMessageController::class)->add($perm->for('tickets.reply'));

        // M3.3.2-D — Admin finance (transactions + commissions read).
        $group->get('/transactions',
            \Bayti\Api\Http\Controllers\Admin\Finance\ListTransactionsController::class)->add($perm->for('payouts.view_transactions'));
        $group->get('/commissions',
            \Bayti\Api\Http\Controllers\Admin\Finance\ListCommissionsController::class)->add($perm->for('payouts.view_commissions'));

        // M3.4-C — Admin message a specific vendor (portal → vendor comms).
        $group->post('/vendors/{id:[0-9]+}/messages',
            \Bayti\Api\Http\Controllers\Admin\Vendor\SendVendorMessageController::class)->add($perm->for('vendors.edit'));

        // Admin push broadcast (real implementation of legacy send_notifications).
        $group->post('/notifications',
            \Bayti\Api\Http\Controllers\Admin\Notification\SendBroadcastNotificationController::class)->add($perm->for('notifications.send'));

        // M3.3.2-F — Admin product write (admin can create/update/delete any product).
        $group->post('/products',
            \Bayti\Api\Http\Controllers\Admin\Product\CreateAdminProductController::class)->add($perm->for('products.create'));
        $group->put('/products/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\Product\UpdateAdminProductController::class)->add($perm->for('products.edit'));
        $group->delete('/products/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\Product\DeleteAdminProductController::class)->add($perm->for('products.delete'));

        // M3.2.X.8-E — Promo code CRUD. Soft-delete preserves
        // promo_redemptions FK; hard-delete only when zero redemptions.
        $group->get('/promo-codes',
            \Bayti\Api\Http\Controllers\Admin\PromoCode\ListPromoCodesController::class)->add($perm->for('coupons.view'));
        $group->get('/promo-codes/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\PromoCode\GetPromoCodeController::class)->add($perm->for('coupons.view'));
        $group->post('/promo-codes',
            \Bayti\Api\Http\Controllers\Admin\PromoCode\CreatePromoCodeController::class)->add($perm->for('coupons.create'));
        $group->put('/promo-codes/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\PromoCode\UpdatePromoCodeController::class)->add($perm->for('coupons.edit'));
        $group->delete('/promo-codes/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\PromoCode\DeletePromoCodeController::class)->add($perm->for('coupons.delete'));

        // M3.2.X.18-F — Returns admin surface
        $group->get('/returns',
            \Bayti\Api\Http\Controllers\Admin\Order\ListAdminReturnsController::class)->add($perm->for('returns.view'));
        $group->get('/returns/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\Order\GetAdminReturnController::class)->add($perm->for('returns.view'));
        $group->post('/returns/{id:[0-9]+}/approve',
            \Bayti\Api\Http\Controllers\Admin\Order\ApproveReturnController::class)->add($perm->for('returns.approve'));
        $group->post('/returns/{id:[0-9]+}/deny',
            \Bayti\Api\Http\Controllers\Admin\Order\DenyReturnController::class)->add($perm->for('returns.deny'));
        $group->post('/returns/{id:[0-9]+}/mark-picked-up',
            \Bayti\Api\Http\Controllers\Admin\Order\MarkPickedUpController::class)->add($perm->for('returns.approve'));
        $group->post('/returns/{id:[0-9]+}/record-refund',
            \Bayti\Api\Http\Controllers\Admin\Order\RecordReturnRefundController::class)->add($perm->for('returns.refund'));

        // M3.2.X.15-F — FX rate management (display-only multi-currency).
        // GET lists all rates with staleness flags; PUT upserts the rate
        // for a single target currency. Audited via AuditEmitter with
        // subject_type='FxRate'; visible via the X.4-C audit-log surface.
        $group->get('/fx-rates',
            \Bayti\Api\Http\Controllers\Admin\Currency\ListFxRatesController::class)->add($perm->for('settings.view'));
        $group->put('/fx-rates/{target:[A-Za-z]{3}}',
            \Bayti\Api\Http\Controllers\Admin\Currency\UpsertFxRateController::class)->add($perm->for('settings.edit'));

        // ── RBAC: roles & staff permission management (Phase C4) ──────────
        $group->get('/permission-catalog',
            \Bayti\Api\Http\Controllers\Admin\Role\ListPermissionCatalogController::class)->add($perm->for('roles.view'));
        $group->get('/roles',
            \Bayti\Api\Http\Controllers\Admin\Role\ListRolesController::class)->add($perm->for('roles.view'));
        $group->post('/roles',
            \Bayti\Api\Http\Controllers\Admin\Role\CreateRoleController::class)->add($perm->for('roles.create'));
        $group->get('/roles/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\Role\GetRoleController::class)->add($perm->for('roles.view'));
        $group->put('/roles/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\Role\UpdateRoleController::class)->add($perm->for('roles.edit'));
        $group->delete('/roles/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Admin\Role\DeleteRoleController::class)->add($perm->for('roles.delete'));
        $group->post('/users/{id:[0-9]+}/roles',
            \Bayti\Api\Http\Controllers\Admin\Role\AssignUserRolesController::class)->add($perm->for('users.manage_roles'));
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
        // M3.2.X.13-E — vendor self-serve analytics dashboard
        $group->get(
            '/analytics',
            \Bayti\Api\Http\Controllers\Vendor\GetVendorSelfAnalyticsController::class,
        );
        // F7a — insightful vendor dashboard (catalog + sales + operations).
        $group->get(
            '/dashboard',
            \Bayti\Api\Http\Controllers\Vendor\GetVendorDashboardController::class,
        );
        // Vendor in-app notification feed (top-bar bell) — replaces the
        // legacy /vendors/common/notifications.
        $group->get(
            '/notifications',
            \Bayti\Api\Http\Controllers\Vendor\Notification\ListVendorNotificationsController::class,
        );
        $group->post(
            '/notifications/mark-read',
            \Bayti\Api\Http\Controllers\Vendor\Notification\MarkVendorNotificationsReadController::class,
        );
        // Vendor KYC compliance documents — replaces the legacy
        // /vendors/settings/update-compliance + the misused onboarding submit.
        $group->get(
            '/compliance',
            \Bayti\Api\Http\Controllers\Vendor\Compliance\GetVendorComplianceController::class,
        );
        $group->patch(
            '/compliance',
            \Bayti\Api\Http\Controllers\Vendor\Compliance\UpdateVendorComplianceController::class,
        );

        // M3.4-C — Vendor product reviews (read-only).
        $group->get('/reviews',
            \Bayti\Api\Http\Controllers\Vendor\Review\ListVendorReviewsController::class,
        );

        // M3.4-D — Vendor label CRUD.
        $group->get('/labels',
            [\Bayti\Api\Http\Controllers\Vendor\Label\VendorLabelCrudController::class, 'list'],
        );
        $group->post('/labels',
            [\Bayti\Api\Http\Controllers\Vendor\Label\VendorLabelCrudController::class, 'create'],
        );
        $group->put('/labels/{id:[0-9]+}',
            [\Bayti\Api\Http\Controllers\Vendor\Label\VendorLabelCrudController::class, 'update'],
        );
        $group->delete('/labels/{id:[0-9]+}',
            [\Bayti\Api\Http\Controllers\Vendor\Label\VendorLabelCrudController::class, 'delete'],
        );

        // M3.3.1-F — Vendor coupon CRUD (vendor-scoped promo codes)
        $group->get('/coupons',
            \Bayti\Api\Http\Controllers\Vendor\Coupon\ListVendorCouponsController::class,
        );
        $group->post('/coupons',
            \Bayti\Api\Http\Controllers\Vendor\Coupon\CreateVendorCouponController::class,
        );
        $group->put('/coupons/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Vendor\Coupon\UpdateVendorCouponController::class,
        );
        $group->delete('/coupons/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Vendor\Coupon\DeleteVendorCouponController::class,
        );
        // F3 — vendor coupon detail + usage analytics.
        $group->get('/coupons/{id:[0-9]+}',
            [\Bayti\Api\Http\Controllers\Vendor\Coupon\VendorCouponAnalyticsController::class, 'detail']);
        $group->get('/coupons/{id:[0-9]+}/analytics',
            [\Bayti\Api\Http\Controllers\Vendor\Coupon\VendorCouponAnalyticsController::class, 'analytics']);

        // Vendor read-only collections list (for the product form picker).
        // Reuses the admin controller's list method — read-only, no writes
        // exposed under the vendor group.
        $group->get('/collections',
            [\Bayti\Api\Http\Controllers\Admin\Collection\CollectionCrudController::class, 'list']);

        // F2 — Vendor size chart (the store's published size guide).
        // Named "measurements" for portal/legacy continuity; this is the
        // vendor's size chart, NOT a customer's body measurements
        // (/v3/me/measurements).
        $group->get('/measurements',
            [\Bayti\Api\Http\Controllers\Vendor\VendorSizeChartController::class, 'list']);
        $group->post('/measurements',
            [\Bayti\Api\Http\Controllers\Vendor\VendorSizeChartController::class, 'create']);
        $group->put('/measurements/{id:[0-9]+}',
            [\Bayti\Api\Http\Controllers\Vendor\VendorSizeChartController::class, 'update']);
        $group->delete('/measurements/{id:[0-9]+}',
            [\Bayti\Api\Http\Controllers\Vendor\VendorSizeChartController::class, 'delete']);

        // F3 — Vendor message inbox (read-side of admin→vendor messages).
        $group->get('/messages',
            [\Bayti\Api\Http\Controllers\Vendor\VendorMessagesController::class, 'list']);
        $group->post('/messages/{id:[0-9]+}/read',
            [\Bayti\Api\Http\Controllers\Vendor\VendorMessagesController::class, 'markRead']);

        // M3.3.1-D — Vendor store settings
        $group->get('/store',
            \Bayti\Api\Http\Controllers\Vendor\Settings\GetVendorStoreController::class,
        );
        $group->patch('/store',
            \Bayti\Api\Http\Controllers\Vendor\Settings\UpdateVendorStoreController::class,
        );

        // M3.4-A — Vendor payment (bank/payout) + tax (UAE VAT compliance).
        // All fields already on the Vendor entity; no schema migration needed.
        $group->get('/store/payment',
            \Bayti\Api\Http\Controllers\Vendor\Settings\GetVendorPaymentController::class,
        );
        $group->patch('/store/payment',
            \Bayti\Api\Http\Controllers\Vendor\Settings\UpdateVendorPaymentController::class,
        );
        $group->get('/store/tax',
            \Bayti\Api\Http\Controllers\Vendor\Settings\GetVendorTaxController::class,
        );
        $group->patch('/store/tax',
            \Bayti\Api\Http\Controllers\Vendor\Settings\UpdateVendorTaxController::class,
        );

        // M3.4-B — Vendor notification preferences + store status toggle.
        $group->get('/store/notifications',
            \Bayti\Api\Http\Controllers\Vendor\Settings\GetVendorNotificationsController::class,
        );
        $group->patch('/store/notifications',
            \Bayti\Api\Http\Controllers\Vendor\Settings\UpdateVendorNotificationsController::class,
        );
        $group->patch('/store/status',
            \Bayti\Api\Http\Controllers\Vendor\Settings\ToggleVendorStoreStatusController::class,
        );

        // M3.3.1-C — Vendor product write (create / update / soft-delete).
        // Read is handled by the public GET /v3/vendors/{slug}/products +
        // GET /v3/vendors/by-legacy-id/{id}/products catalog endpoints.
        // Self-scoped list (the vendor's OWN catalog, all states) for the
        // portal — resolves the vendor from the authenticated user.
        $group->get('/products',
            \Bayti\Api\Http\Controllers\Vendor\Product\ListVendorOwnProductsController::class,
        );
        // Single product (any status) for the vendor's preview/detail.
        $group->get('/products/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Vendor\Product\GetVendorOwnProductController::class,
        );
        // Per-product sales (summary + daily series + recent orders).
        $group->get('/products/{id:[0-9]+}/sales',
            \Bayti\Api\Http\Controllers\Vendor\Product\GetVendorProductSalesController::class,
        );
        $group->post('/products',
            \Bayti\Api\Http\Controllers\Vendor\Product\CreateVendorProductController::class,
        );
        $group->put('/products/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Vendor\Product\UpdateVendorProductController::class,
        );
        $group->delete('/products/{id:[0-9]+}',
            \Bayti\Api\Http\Controllers\Vendor\Product\DeleteVendorProductController::class,
        );
    })
        ->add(\Bayti\Api\Http\Middleware\VendorAuthMiddleware::class)
        ->add(AuthMiddleware::class);

    // Order-scoped customer↔vendor chat (vendor side). Same guard as the
    // rest of the vendor surface; conversations are scoped to the stores
    // the authenticated vendor owns.
    $app->group('/v3/vendor/chat', function (RouteCollectorProxy $group): void {
        $group->get('/conversations', \Bayti\Api\Http\Controllers\Chat\Vendor\ListConversationsController::class);
        $group->get('/unread-count', \Bayti\Api\Http\Controllers\Chat\Vendor\GetUnreadCountController::class);
        $group->get(
            '/conversations/{uuid}/messages',
            \Bayti\Api\Http\Controllers\Chat\Vendor\GetMessagesController::class,
        );
        $group->post(
            '/conversations/{uuid}/messages',
            \Bayti\Api\Http\Controllers\Chat\Vendor\SendMessageController::class,
        );
        $group->post(
            '/conversations/{uuid}/read',
            \Bayti\Api\Http\Controllers\Chat\Vendor\MarkReadController::class,
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
