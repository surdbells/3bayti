import { Routes } from '@angular/router';
import { HomeComponent } from './features/home/home';
import { guestActivateGuard, authActivateGuard } from './core/auth/auth.guards';

/**
 * Top-level route table for the public web app.
 *
 * Phase 1: home + a dev-only component preview + categories index.
 * Phase 2 adds: /category/:slug ✓ + /product/:slug + /designer + /designer/:slug.
 * Y.1 (auth): /login + /register + /verify-phone + /forgot-password +
 *             /reset-password — all gated by guestActivateGuard so
 *             signed-in users get redirected away.
 *
 * All routes are SSR'd by default; route-level data is fetched server-
 * side via TransferState (see individual feature components).
 */
export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    component: HomeComponent,
    title: '3bayti — Premium Abayas, Kaftans & Modest Wear',
  },
  {
    /* Categories index — `/category`. Server-rendered with TransferState
       so the prerendered HTML embeds the live category list and the
       browser hydrates without a re-fetch. Lazy-loaded so it's its own
       chunk in the build output. */
    path: 'category',
    loadComponent: () =>
      import('./features/categories/categories').then(m => m.CategoriesComponent),
    title: 'Shop by Category · 3bayti',
  },
  {
    /* Category detail — `/category/:slug`. Each of the 8 categories
       is prerendered at build time (see app.routes.server.ts for the
       slug list provider). Renders category metadata + first 20
       products with full SEO + ItemList JSON-LD. */
    path: 'category/:slug',
    loadComponent: () =>
      import('./features/categories/category-detail').then(m => m.CategoryDetailComponent),
    /* Title is set dynamically via SeoService once the data loads;
       the static title here is a fallback for the brief moment before
       hydration and for crawlers that ignore <title> updates. */
    title: 'Shop by Category · 3bayti',
  },
  {
    /* Product detail — `/product/:slug`. The PDP. The 200 most-recent
       products are prerendered at build time (see app.routes.server.ts
       for the cap and slug fetcher). The remaining ~1,400 products
       fall back to runtime SSR — slower first byte but still fully
       indexable.

       Why the cap: building all 1,657 PDPs would push build time past
       Cloudflare Pages' practical limits. 200 prerendered + runtime
       SSR for the long tail is the chosen balance per W2.2 sprint
       planning. */
    path: 'product/:slug',
    loadComponent: () =>
      import('./features/catalog/product-detail').then(m => m.ProductDetailComponent),
    /* Title set dynamically via SeoService. Fallback for crawlers
       that miss the dynamic title update. */
    title: 'Product · 3bayti',
  },
  {
    /* Dev-only component preview. noindex'd via SeoService inside the
       component. Lazy-loaded so it doesn't bloat the production bundle
       for normal users. */
    path: '_dev/components',
    loadComponent: () =>
      import('./features/dev-components/dev-components').then(m => m.DevComponentsComponent),
    title: 'Component preview · 3bayti',
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
    title: 'Sign in · 3bayti',
  },
  {
    path: 'register',
    canActivate: [guestActivateGuard],
    loadComponent: () =>
      import('./features/auth/register/register').then(m => m.RegisterComponent),
    title: 'Create account · 3bayti',
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
    title: 'Verify your phone · 3bayti',
  },
  {
    path: 'forgot-password',
    canActivate: [guestActivateGuard],
    loadComponent: () =>
      import('./features/auth/forgot-password/forgot-password').then(m => m.ForgotPasswordComponent),
    title: 'Forgot password · 3bayti',
  },
  {
    path: 'reset-password',
    canActivate: [guestActivateGuard],
    loadComponent: () =>
      import('./features/auth/reset-password/reset-password').then(m => m.ResetPasswordComponent),
    title: 'Reset password · 3bayti',
  },
  /* --- Cart + Checkout (M3.2.Y.2) --------------------------------------
     /cart is public — guests and authenticated users both see their
     cart. Checkout routes (Y.2-D+) are auth-gated. */
  {
    path: 'cart',
    loadComponent: () =>
      import('./features/cart/cart-page').then(m => m.CartPageComponent),
    title: 'Your bag · 3bayti',
  },
  /* --- Account section (M3.2.Y.2 begins; Y.5 expands) -------------------
     All routes under /account require auth. Y.2-C ships /account/addresses;
     Y.2-H + I add /account/orders + /account/orders/:id; Y.4-A may add
     a top-level /account dashboard; Y.5 adds profile/wishlist/etc. */
  {
    path: 'account/addresses',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/addresses/address-book').then(m => m.AddressBookPageComponent),
    title: 'Saved addresses · 3bayti',
  },
  {
    path: 'account/orders',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/orders/account-orders-page').then(m => m.AccountOrdersPageComponent),
    title: 'Your orders · 3bayti',
  },
  {
    path: 'account/orders/:id',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/orders/account-order-detail-page').then(m => m.AccountOrderDetailPageComponent),
    title: 'Order details · 3bayti',
  },
  /* --- Checkout (M3.2.Y.2-D onwards) ----------------------------------
     Three-step flow: address → review → payment handoff.
     All routes auth-gated (Q-CheckoutAuth=A). */
  {
    path: 'checkout/address',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/checkout/checkout-address-page').then(m => m.CheckoutAddressPageComponent),
    title: 'Checkout — Shipping · 3bayti',
  },
  {
    path: 'checkout/review',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/checkout/checkout-review-page').then(m => m.CheckoutReviewPageComponent),
    title: 'Checkout — Review · 3bayti',
  },
  {
    path: 'checkout/payment',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/checkout/checkout-payment-page').then(m => m.CheckoutPaymentPageComponent),
    title: 'Checkout — Payment · 3bayti',
  },
  {
    path: 'checkout/success/:id',
    canActivate: [authActivateGuard],
    loadComponent: () =>
      import('./features/checkout/checkout-success-page').then(m => m.CheckoutSuccessPageComponent),
    title: 'Order placed · 3bayti',
  },
];
