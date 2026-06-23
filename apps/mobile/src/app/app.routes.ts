import { Routes } from '@angular/router';
import {CHAT_ROUTES} from "./chat.routes";
import { introGuard } from './intro.guard';

export const routes: Routes = [
  { path: '', redirectTo: 'intro', pathMatch: 'full' },
  {
    path: 'intro',
    loadComponent: () => import('./public/intro/intro.page').then(m => m.IntroPage),
    canActivate: [introGuard],
    title: 'Welcome'
  },
  {
    path: 'login',
    loadComponent: () => import('./public/login/login.page').then( m => m.LoginPage)
  },
  {
    path: 'register',
    loadComponent: () => import('./public/register/register.page').then( m => m.RegisterPage)
  },
  {
    path: 'reset',
    loadComponent: () => import('./public/reset/reset.page').then( m => m.ResetPage)
  },
  {
    path: 'account',
    loadComponent: () => import('./customer/account/account.page').then( m => m.AccountPage)
  },
  {
    path: 'settings',
    loadComponent: () => import('./customer/settings/settings.page').then(m => m.SettingsPage)
  },
  {
    // In-app account deletion (Apple 5.1.1(v)) — DELETE /v3/me with re-auth
    path: 'delete-account',
    loadComponent: () => import('./customer/delete-account/delete-account.page').then(m => m.DeleteAccountPage),
    title: 'Delete Account'
  },
  {
    path: 'wishlist',
    loadComponent: () => import('./customer/wishlist/wishlist.page').then( m => m.WishlistPage)
  },
  {
    path: 'cart',
    loadComponent: () => import('./customer/cart/cart.page').then( m => m.CartPage)
  },
  {
    path: 'measurements',
    loadComponent: () => import('./customer/measurements/measurements.page').then( m => m.MeasurementsPage)
  },
  {
    path: 'orders/:id',
    loadComponent: () => import('./customer/order-detail/order-detail.page').then(m => m.OrderDetailPage)
  },
  {
    path: 'reviews',
    loadComponent: () => import('./customer/reviews/reviews.page').then( m => m.ReviewsPage)
  },
  {
    path: 'addresses',
    loadComponent: () => import('./customer/addresses/addresses.page').then( m => m.AddressesPage)
  },
  {
    path: 'start',
    loadComponent: () => import('./start/start.page').then( m => m.StartPage)
  },
  {
    path: 'welcome',
    loadComponent: () => import('./welcome/welcome.page').then( m => m.WelcomePage)
  },
  {
    path: 'profile',
    loadComponent: () => import('./customer/profile/profile.page').then( m => m.ProfilePage)
  },
  {
    path: 'product',
    loadComponent: () => import('./customer/product/product.page').then( m => m.ProductPage)
  },
  {
    path: 'search',
    loadComponent: () => import('./customer/search/search.page').then( m => m.SearchPage)
  },
  {
    path: 'checkout',
    loadComponent: () => import('./customer/checkout/checkout.page').then(m => m.CheckoutPage)
  },
  {
    path: 'vendors',
    loadComponent: () => import('./customer/vendors/vendors.page').then( m => m.VendorsPage)
  },
  {
    path: 'explore',
    loadComponent: () => import('./customer/vertican/vertican.page').then( m => m.VerticanPage)
  },
  {
    path: 'store_reviews',
    loadComponent: () => import('./customer/store-reviews/store-reviews.page').then( m => m.StoreReviewsPage)
  },
  {
    path: 'success',
    loadComponent: () => import('./customer/success/success.page').then( m => m.SuccessPage)
  },
  {
    path: 'failed',
    loadComponent: () => import('./customer/failed/failed.page').then( m => m.FailedPage)
  },
  {
    path: 'process',
    loadComponent: () => import('./customer/process/process.page').then( m => m.ProcessPage)
  },
  {
    path: 'home',
    loadComponent: () => import('./public/home/home.page').then( m => m.HomePage)
  },
  {
    path: 'styles',
    loadComponent: () => import('./customer/styles/styles.page').then( m => m.StylesPage)
  },
  {
    // :slug makes the page deep-linkable + hard-reload-safe. When navigated
    // from the list, the style still comes via router state (fast path); on
    // a hard reload / shared link, the page re-fetches by this slug.
    path: 'style-view/:slug',
    loadComponent: () => import('./customer/styles/style-view/style-view.page').then( m => m.StyleViewPage)
  },
  {
    path: 'create',
    loadComponent: () => import('./customer/styles/create/create.page').then( m => m.CreatePage)
  },
  {
    path: 'best-sellers',
    loadComponent: () => import('./customer/best-sellers/best-sellers.page').then( m => m.BestSellersPage)
  },
  {
    path: 'new-arrivals',
    loadComponent: () => import('./customer/new-arrivals/new-arrivals.page').then( m => m.NewArrivalsPage)
  },
  {
    path: 'category',
    loadComponent: () => import('./customer/category/category.page').then( m => m.CategoryPage)
  },
  {
    path: 'vendor-reviews',
    loadComponent: () => import('./customer/vendor-reviews/vendor-reviews.page').then( m => m.VendorReviewsPage)
  },
  {
    path: 'my-orders',
    loadComponent: () => import('./customer/my-orders/my-orders.page').then( m => m.MyOrdersPage)
  }, {
    path: 'store-dashboard',
    loadComponent: () => import('./vendor/store-dashboard/store-dashboard.page').then(m => m.StoreDashboardPage),
    title: 'Store Dashboard'
  }, {
    // M3.1.7-I — vendor orders list
    path: 'vendor-orders',
    loadComponent: () => import('./vendor/vendor-orders/vendor-orders.page').then(m => m.VendorOrdersPage),
    title: 'Vendor Orders'
  }, {
    // M3.1.7-I — vendor order detail (item-level transitions)
    path: 'vendor-orders/:id',
    loadComponent: () => import('./vendor/vendor-order-detail/vendor-order-detail.page').then(m => m.VendorOrderDetailPage),
    title: 'Order Detail'
  }, {
    // Vendor catalog — own products list + quick edits
    path: 'vendor-products',
    loadComponent: () => import('./vendor/vendor-products/vendor-products.page').then(m => m.VendorProductsPage),
    title: 'My Products'
  }, {
    // Vendor self-serve earnings (GET /vendor/analytics)
    path: 'vendor-earnings',
    loadComponent: () => import('./vendor/vendor-earnings/vendor-earnings.page').then(m => m.VendorEarningsPage),
    title: 'Earnings'
  }, {
    // Vendor payout / bank details (GET|PATCH /vendor/store/payment)
    path: 'vendor-payouts',
    loadComponent: () => import('./vendor/vendor-payouts/vendor-payouts.page').then(m => m.VendorPayoutsPage),
    title: 'Payouts'
  },
  {
    path: 'gift-cards',
    loadComponent: () => import('./customer/gift-cards/gift-cards.page').then(m => m.GiftCardsPage)
  },
  {
    path: 'my-gift-cards',
    loadComponent: () => import('./customer/my-gift-cards/my-gift-cards.page').then(m => m.MyGiftCardsPage)
  },
  {
    path: 'gift-card-detail',
    loadComponent: () => import('./customer/gift-card-detail/gift-card-detail.page').then(m => m.GiftCardDetailPage)
  },
  {
    path: 'gift-card-redeem',
    loadComponent: () => import('./customer/gift-card-redeem/gift-card-redeem.page').then(m => m.GiftCardRedeemPage)
  },
  ...CHAT_ROUTES
];
