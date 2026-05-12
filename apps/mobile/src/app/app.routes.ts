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
    path: 'wishlist',
    loadComponent: () => import('./customer/wishlist/wishlist.page').then( m => m.WishlistPage)
  },
  {
    path: 'messages',
    loadComponent: () => import('./customer/messages/messages.page').then( m => m.MessagesPage)
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
    path: 'orders',
    loadComponent: () => import('./customer/orders/orders.page').then(m => m.OrdersPage)
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
    path: 'ticketlist',
    loadComponent: () => import('./customer/ticket-list/ticket-list.page').then( m => m.TicketListPage)
  },
  {
    path: 'ticketmessages',
    loadComponent: () => import('./customer/ticket-messages/ticket-messages.page').then( m => m.TicketMessagesPage)
  },
  {
    path: 'createticket',
    loadComponent: () => import('./customer/create-ticket/create-ticket.page').then( m => m.CreateTicketPage)
  },
  {
    path: 'home',
    loadComponent: () => import('./public/home/home.page').then( m => m.HomePage)
  },
  {
    path: 'single',
    loadComponent: () => import('./public/single/single.page').then( m => m.SinglePage)
  },
  {
    path: 'styles',
    loadComponent: () => import('./customer/styles/styles.page').then( m => m.StylesPage)
  },
  {
    path: 'style-view',
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
  },...CHAT_ROUTES
];
