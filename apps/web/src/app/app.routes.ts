import { Routes, TitleStrategy, RouterStateSnapshot } from '@angular/router';
import { Injectable, inject } from '@angular/core';
import { Title } from '@angular/platform-browser';
import { TranslateService } from '@ngx-translate/core';
import { HomeComponent } from './features/home/home';
import { guestActivateGuard, authActivateGuard } from './core/auth/auth.guards';

/**
 * Route titles are stored as i18n KEYS (e.g. `routeTitles.home`) rather than
 * literal English, so the browser <title> is localized. This TitleStrategy
 * resolves the key through TranslateService whenever the active route changes.
 *
 * Register it in the app config:
 *   { provide: TitleStrategy, useClass: I18nTitleStrategy }
 */
@Injectable({ providedIn: 'root' })
export class I18nTitleStrategy extends TitleStrategy {
  private readonly title = inject(Title);
  private readonly translate = inject(TranslateService);

  override updateTitle(snapshot: RouterStateSnapshot): void {
    const key = this.buildTitle(snapshot);
    if (key !== undefined) {
      this.title.setTitle(this.translate.instant(key));
    }
  }
}

/**
 * Top-level route table for the public web app.
 *
 * Phase 1: home + a dev-only component preview + categories index.
 * Phase 2 adds: /category/:slug ✓ + /product/:slug + /designer + /designer/:slug.
 * Y.1 (auth): /login + /register + /verify-phone + /forgot-password +
 *             /reset-password — all gated by guestActivateGuard so
 *             signed-in users get redirected away.
 *
 * This is a client-side-rendered SPA (deployed as static assets on
 * Cloudflare Pages); every route lazy-loads its component chunk and
 * fetches its data client-side.
 */
export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    component: HomeComponent,
    title: 'routeTitles.home',
  },
  {
    /* Categories index — `/category`. Lazy-loaded; fetches the live
       category list client-side on load. */
    path: 'category',
    loadComponent: () =>
      import('./features/categories/categories').then(m => m.CategoriesComponent),
    title: 'routeTitles.categories',
  },
  {
    /* Category detail — `/category/:slug`. Renders category metadata +
       a filterable product grid with ItemList JSON-LD. */
    path: 'category/:slug',
    loadComponent: () =>
      import('./features/categories/category-detail').then(m => m.CategoryDetailComponent),
    /* Title is set dynamically via SeoService once the data loads;
       the static title here is a fallback for the brief moment before
       hydration and for crawlers that ignore <title> updates. */
    title: 'routeTitles.categories',
  },
  {
    /* Product detail — `/product/:slug`. The PDP. Renders the product
       with Product + Breadcrumb JSON-LD; data is fetched client-side
       for the current slug. */
    path: 'product/:slug',
    loadComponent: () =>
      import('./features/catalog/product-detail').then(m => m.ProductDetailComponent),
    /* Title set dynamically via SeoService. Fallback for crawlers
       that miss the dynamic title update. */
    title: 'routeTitles.product',
  },
  {
    /* Stores directory — `/stores`. Public storefront page:
       featured stores + paginated A-Z grid of all active stores.
       Backed by /v3/vendors + /v3/featured-vendors. (Formerly /designer.) */
    path: 'stores',
    loadComponent: () =>
      import('./features/stores/store-directory-page').then(m => m.StoreDirectoryPageComponent),
    title: 'routeTitles.stores',
  },
  {
    /* Store reviews — `/stores/:slug/reviews`. The store's public
       (approved) reviews list. Target of store-card.ts's vendorReviewsUrl().
       Registered before `stores/:slug` so the more specific path wins. */
    path: 'stores/:slug/reviews',
    loadComponent: () =>
      import('./features/stores/store-reviews-page').then(m => m.StoreReviewsPageComponent),
    title: 'routeTitles.storeReviews',
  },
  {
    /* Store detail — `/stores/:slug`. Store header + their product grid.
       (Formerly /designer/:slug.) */
    path: 'stores/:slug',
    loadComponent: () =>
      import('./features/stores/store-detail-page').then(m => m.StoreDetailPageComponent),
    title: 'routeTitles.storeDetail',
  },
  /* Legacy /designer URLs (pre-rename — indexed + bookmarked) → /stores.
     Param-preserving so deep links to a specific store survive. */
  { path: 'designer', pathMatch: 'full', redirectTo: 'stores' },
  { path: 'designer/:slug', redirectTo: 'stores/:slug' },
  {
    /* Style Hub — `/styles`. Public storefront page: Community,
       3bayti (editorial), and My styles (auth) tabs, each a grid of
       curated-outfit cards. Backed by /v3/styles + /v3/me/styles.
       Registered before `styles/:slug` so the index wins. */
    path: 'styles',
    pathMatch: 'full',
    loadComponent: () =>
      import('./features/styles/styles-hub-page').then(m => m.StylesHubPageComponent),
    title: 'routeTitles.styles',
  },
  {
    /* Style detail — `/styles/:slug`. Cover + the bundled products with
       View product + Add to wishlist, plus a display-only total. */
    path: 'styles/:slug',
    loadComponent: () =>
      import('./features/styles/style-detail-page').then(m => m.StyleDetailPageComponent),
    title: 'routeTitles.styleDetail',
  },
  {
    /* Best Sellers — curated product listing sorted by sales. */
    path: 'best-sellers',
    loadComponent: () =>
      import('./features/listings/product-listing-page').then(m => m.ProductListingPageComponent),
    data: {
      sort: 'best_seller',
      i18nKey: 'bestSellers',
      canonicalPath: '/best-sellers',
      seoTitle: 'Best Sellers · 3bayti',
      seoDescription:
        'Shop the most-loved abayas, kaftans and modest wear on 3bayti — ' +
        'our best-selling pieces from independent UAE designers.',
    },
    title: 'routeTitles.bestSellers',
  },
  {
    /* New Arrivals — curated product listing sorted by recency. */
    path: 'new-arrivals',
    loadComponent: () =>
      import('./features/listings/product-listing-page').then(m => m.ProductListingPageComponent),
    data: {
      sort: 'newest',
      i18nKey: 'newArrivals',
      canonicalPath: '/new-arrivals',
      seoTitle: 'New Arrivals · 3bayti',
      seoDescription:
        'The latest abayas, kaftans and modest wear just added to 3bayti — ' +
        'fresh pieces from independent UAE designers.',
    },
    title: 'routeTitles.newArrivals',
  },
  {
    /* Gift Cards — browse the themed designs + purchase (Phase E2).
       Public page; purchasing requires auth (the API rejects an
       unauthenticated purchase) and sends the buyer to Noon checkout. */
    path: 'gift-cards',
    loadComponent: () =>
      import('./features/gift-cards/gift-card-landing-page').then(m => m.GiftCardLandingPageComponent),
    title: 'routeTitles.giftCards',
  },
  {
    /* Redeem a gift card / check balance (Phase E4). Public; the redeem
       action requires auth and routes a 401 to /login. */
    path: 'gift-cards/redeem',
    loadComponent: () =>
      import('./features/gift-cards/gift-card-redeem-page').then(m => m.GiftCardRedeemPageComponent),
    title: 'routeTitles.giftCardRedeem',
  },
  {
    /* Sell on 3bayti — vendor recruitment pitch (Phase F). Public; seller
       CTAs target the external seller app (VENDOR_APP_URL). */
    path: 'sell',
    loadComponent: () =>
      import('./features/sell/sell-page').then(m => m.SellPageComponent),
    title: 'routeTitles.sell',
  },
  {
    /* Dev-only component preview. noindex'd via SeoService inside the
       component. Lazy-loaded so it doesn't bloat the production bundle
       for normal users. */
    path: '_dev/components',
    loadComponent: () =>
      import('./features/dev-components/dev-components').then(m => m.DevComponentsComponent),
    title: 'routeTitles.devComponents',
  },
  /* --- Auth (M3.2.Y.1) ---------------------------------------------------
     Each auth page is lazy-loaded so the catalog bundle stays clean for
     anonymous browsing. guestActivateGuard redirects authenticated
     visitors away from these pages (no point landing on /login when
     you already have a session). */
  {
    path: 'login',
    canActivate: [guestActivateGuard],
    loadComponent: () =>
      import('./features/auth/login/login').then(m => m.LoginComponent),
    title: 'routeTitles.login',
  },
  {
    path: 'register',
    canActivate: [guestActivateGuard],
    loadComponent: () =>
      import('./features/auth/register/register').then(m => m.RegisterComponent),
    title: 'routeTitles.register',
  },
  {
    /* /verify-phone is intentionally NOT guarded. Two flows land here:
       (a) after /register, where the user is NOT yet authenticated
       (the API issues tokens only on /confirm), and (b) after /login
       with is_phone_verified=false, where the user IS authenticated
       but still needs to complete OTP. A guard would break one of
       the two. */
    path: 'verify-phone',
    loadComponent: () =>
      import('./features/auth/verify-phone/verify-phone').then(m => m.VerifyPhoneComponent),
    title: 'routeTitles.verifyPhone',
  },
  {
    path: 'forgot-password',
    canActivate: [guestActivateGuard],
    loadComponent: () =>
      import('./features/auth/forgot-password/forgot-password').then(m => m.ForgotPasswordComponent),
    title: 'routeTitles.forgotPassword',
  },
  {
    path: 'reset-password',
    canActivate: [guestActivateGuard],
    loadComponent: () =>
      import('./features/auth/reset-password/reset-password').then(m => m.ResetPasswordComponent),
    title: 'routeTitles.resetPassword',
  },
  /* --- Cart + Checkout (M3.2.Y.2) --------------------------------------
     /cart is public — guests and authenticated users both see their
     cart. Checkout routes (Y.2-D+) are auth-gated. */
  {
    path: 'cart',
    loadComponent: () =>
      import('./features/cart/cart-page').then(m => m.CartPageComponent),
    title: 'routeTitles.cart',
  },
  /* --- Account section (M3.2.Y.2 begins; Y.5 expands) -------------------
     All routes under /account require auth. Y.2-C ships /account/addresses;
     Y.2-H + I add /account/orders + /account/orders/:id; Y.5-A adds the
     /account hub; Y.5-B/C/D add profile/password/measurements. */
  {
    path: 'account',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/account/account-hub-page').then(m => m.AccountHubPageComponent),
    title: 'routeTitles.account',
  },
  {
    path: 'account/profile',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/account/account-profile-page').then(m => m.AccountProfilePageComponent),
    title: 'routeTitles.accountProfile',
  },
  {
    path: 'account/password',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/account/account-password-page').then(m => m.AccountPasswordPageComponent),
    title: 'routeTitles.accountPassword',
  },
  {
    path: 'account/measurements',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/account/account-measurements-page').then(m => m.AccountMeasurementsPageComponent),
    title: 'routeTitles.accountMeasurements',
  },
  {
    path: 'account/delete',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/account/account-delete-page').then(m => m.AccountDeletePageComponent),
    title: 'routeTitles.accountDelete',
  },
  {
    /* Order chat inbox — the customer's conversations with vendors. */
    path: 'account/messages',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/messages/messages-inbox-page').then(m => m.MessagesInboxPageComponent),
    title: 'routeTitles.accountMessages',
  },
  {
    /* A single order conversation (uuid-keyed) — thread + composer. */
    path: 'account/messages/:uuid',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/messages/messages-thread-page').then(m => m.MessagesThreadPageComponent),
    title: 'routeTitles.accountMessagesThread',
  },
  {
    path: 'account/wishlist',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/wishlist/account-wishlist-page').then(m => m.AccountWishlistPageComponent),
    title: 'routeTitles.accountWishlist',
  },
  {
    /* Gift cards the buyer owns (purchased + redeemed) — Phase E3. */
    path: 'account/gift-cards',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/gift-cards/my-gift-cards-page').then(m => m.MyGiftCardsPageComponent),
    title: 'routeTitles.accountGiftCards',
  },
  {
    path: 'account/gift-cards/:id',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/gift-cards/gift-card-detail-page').then(m => m.GiftCardDetailPageComponent),
    title: 'routeTitles.accountGiftCardDetail',
  },
  {
    path: 'account/addresses',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/addresses/address-book').then(m => m.AddressBookPageComponent),
    title: 'routeTitles.accountAddresses',
  },
  {
    path: 'account/orders',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/orders/account-orders-page').then(m => m.AccountOrdersPageComponent),
    title: 'routeTitles.accountOrders',
  },
  {
    path: 'account/orders/:id',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/orders/account-order-detail-page').then(m => m.AccountOrderDetailPageComponent),
    title: 'routeTitles.accountOrderDetail',
  },
  {
    path: 'account/orders/:id/return',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/orders/account-order-return-page').then(m => m.AccountOrderReturnPageComponent),
    title: 'routeTitles.accountOrderReturn',
  },
  /* --- Checkout (M3.2.Y.2-D onwards) ----------------------------------
     Three-step flow: address → review → payment handoff.
     All routes auth-gated (Q-CheckoutAuth=A). */
  {
    path: 'checkout/address',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/checkout/checkout-address-page').then(m => m.CheckoutAddressPageComponent),
    title: 'routeTitles.checkoutAddress',
  },
  {
    path: 'checkout/review',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/checkout/checkout-review-page').then(m => m.CheckoutReviewPageComponent),
    title: 'routeTitles.checkoutReview',
  },
  {
    path: 'checkout/payment',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/checkout/checkout-payment-page').then(m => m.CheckoutPaymentPageComponent),
    title: 'routeTitles.checkoutPayment',
  },
  {
    path: 'checkout/return',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/checkout/checkout-return-page').then(m => m.CheckoutReturnPageComponent),
    title: 'routeTitles.checkoutReturn',
  },
  {
    path: 'checkout/success/:id',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/checkout/checkout-success-page').then(m => m.CheckoutSuccessPageComponent),
    title: 'routeTitles.checkoutSuccess',
  },
  {
    /* Catch-all 404 — MUST remain last. This is a client route (not a
       top-level 404.html asset), so Cloudflare Pages' built-in SPA
       fallback still serves index.html for unknown paths and Angular
       renders this page. */
    path: '**',
    loadComponent: () =>
      import('./features/not-found/not-found-page').then(m => m.NotFoundPageComponent),
    title: 'routeTitles.notFound',
  },
];
