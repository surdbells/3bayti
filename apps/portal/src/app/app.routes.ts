import { Routes } from '@angular/router';
export const routes: Routes = [
  {
    path: '',
    loadComponent: () => import('./public/home/home.component').then(m => m.HomeComponent),
  },
  {
    path: 'login',
    loadComponent: () => import('./public/login/login.component').then(m => m.LoginComponent),
    title: 'Login'
  },{
    path: 'register',
    loadComponent: () => import('./public/register/register.component').then(m => m.RegisterComponent),
    title: 'Register'
  },
  {
    path: 'account',
    loadComponent: () => import('./vendor/user/user.component').then(m => m.UserComponent),
    title: 'Account'
  },
  {
    path: 'reset',
    loadComponent: () => import('./public/reset/reset.component').then(m => m.ResetComponent),
    title: 'Reset password'
  },
  {
    path: 'home',
    loadComponent: () => import('./public/home/home.component').then(m => m.HomeComponent),
    title: 'Sell More with Abayti'
  },
  {
    path: 'backend',
    loadComponent: () => import('./backend/admin/admin.component').then(m => m.AdminComponent),
    title: 'Account'
  },
  {
    path: 'notifications',
    loadComponent: () => import('./vendor/vendor-notifications/vendor-notifications.component').then(m => m.VendorNotificationsComponent),
    title: 'Notifications'
  },
  {
    path: 'payment_info',
    loadComponent: () => import('./settings/vendor-payment/vendor-payment.component').then(m => m.VendorPaymentComponent),
    title: 'Payment information'
  },
  {
    path: 'tax_information',
    loadComponent: () => import('./settings/vendor-tax/vendor-tax.component').then(m => m.VendorTaxComponent),
    title: 'Tax Information'
  },
  {
    path: 'profile',
    loadComponent: () => import('./shared/user-profile/user-profile.component').then(m => m.UserProfileComponent),
    title: 'Manage your profile'
  },
  {
    path: 'store',
    loadComponent: () => import('./settings/vendor-store/vendor-store.component').then(m => m.VendorStoreComponent),
    title: 'Manage your store'
  },
  {
    path: 'security',
    loadComponent: () => import('./shared/user-security/user-security.component').then(m => m.UserSecurityComponent),
    title: 'Security settings'
  },
  {
    path: 'orders',
    loadComponent: () => import('./vendor/vendor-orders/vendor-orders.component').then(m => m.VendorOrdersComponent),
    title: 'Orders'
  },
  {
    path: 'admin_order',
    loadComponent: () => import('./backend/admin-view-order/admin-view-order.component').then(m => m.AdminViewOrderComponent),
    title: 'Orders'
  },
  {
    path: 'products',
    loadComponent: () => import('./vendor/vendor-products/vendor-products.component').then(m => m.VendorProductsComponent),
    title: 'Products'
  },
  {
    path: 'create-product',
    loadComponent: () => import('./vendor/create-product/create-product.component').then(m => m.CreateProductComponent),
    title: 'Create product'
  },
  {
    path: 'edit',
    loadComponent: () => import('./vendor/edit-product/edit-product.component').then(m => m.EditProductComponent),
    title: 'Edit product'
  },
  {
    path: 'order',
    loadComponent: () => import('./vendor/view-order/view-order.component').then(m => m.ViewOrderComponent),
    title: 'Manage order'
  },{
    path: 'receipt',
    loadComponent: () => import('./vendor/receipt/receipt.component').then(m => m.ReceiptComponent),
    title: 'Receipt'
  },
  {
    path: 'returns',
    loadComponent: () => import('./vendor/vendor-returns/vendor-returns.component').then(m => m.VendorReturnsComponent),
    title: 'Returns'
  },
  {
    path: 'reviews',
    loadComponent: () => import('./vendor/vendor-reviews/vendor-reviews.component').then(m => m.VendorReviewsComponent),
    title: 'Reviews'
  },
  {
    path: 'labels',
    loadComponent: () => import('./settings/labels/label.component').then(m => m.LabelComponent),
    title: 'Store labels'
  },
  {
    path: 'messages',
    loadComponent: () => import('./vendor/vendor-messages/vendor-messages.component').then(m => m.VendorMessagesComponent),
    title: 'Messages'
  },
  {
    path: 'labels',
    loadComponent: () => import('./settings/labels/label.component').then(m => m.LabelComponent),
    title: 'Preference'
  },
  {
    path: 'compliance',
    loadComponent: () => import('./vendor/vendor-compliance/vendor-compliance.component').then(m => m.VendorComplianceComponent),
    title: 'Compliance'
  },
  {
    path: 'collections',
    loadComponent: () => import('./backend/collections/collections.component').then(m => m.CollectionsComponent),
    title: 'Collections'
  },
  {
    path: 'create_collections',
    loadComponent: () => import('./backend/collections/create-collection/create-collection.component').then(m => m.CreateCollectionComponent),
    title: 'Create collection'
  },
  {
    path: 'edit_collection',
    loadComponent: () => import('./backend/collections/edit-collection/edit-collection.component').then(m => m.EditCollectionComponent),
    title: 'Edit collection'
  },
  {
    path: 'stores',
    loadComponent: () => import('./backend/stores/stores.component').then(m => m.StoresComponent),
    title: 'Stores'
  },
  {
    path: 'customers',
    loadComponent: () => import('./backend/customers/customers.component').then(m => m.CustomersComponent),
    title: 'Customers'
  },
  {
    path: 'manage_store',
    loadComponent: () => import('./backend/stores/manage-store/manage-store.component').then(m => m.ManageStoreComponent),
    title: 'Manage store'
  },
  {
    path: 'product_sales',
    loadComponent: () => import('./backend/sales/sales.component').then(m => m.SalesComponent),
    title: 'Product Sales'
  },
  {
    path: 'measurements',
    loadComponent: () => import('./vendor/measurements/measurements.component').then(m => m.MeasurementsComponent),
    title: 'Measurements'
  },
  {
    path: 'store_orders',
    loadComponent: () => import('./backend/stores/store-orders/store-orders.component').then(m => m.StoreOrdersComponent),
    title: 'StoreOrders'
  },
  {
    path: 'delivery',
    loadComponent: () => import('./vendor/vendor-delivery/vendor-delivery.component').then(m => m.VendorDeliveryComponent),
    title: 'Delivery list'
  },
  {
    path: 'store_messages',
    loadComponent: () => import('./backend/stores/store-messages/store-messages.component').then(m => m.StoreMessagesComponent),
    title: 'Store messages'
  },
  {
    path: 'store_tickets',
    loadComponent: () => import('./backend/stores/store-tickets/store-tickets.component').then(m => m.StoreTicketsComponent),
    title: 'Store tickets'
  },
  {
    path: 'store_products',
    loadComponent: () => import('./backend/stores/store-products/store-products.component').then(m => m.StoreProductsComponent),
    title: 'Store products'
  },
  {
    path: 'store_sales',
    loadComponent: () => import('./backend/stores/store-sales/store-sales.component').then(m => m.StoreSalesComponent),
    title: 'Store sales'
  },
  {
    path: 'store_reviews',
    loadComponent: () => import('./backend/stores/store-reviews/store-reviews.component').then(m => m.StoreReviewsComponent),
    title: 'Store reviews'
  },
  {
    path: 'admin_products',
    loadComponent: () => import('./backend/admin-products/admin-products.component').then(m => m.AdminProductsComponent),
    title: 'Products'
  },
  {
    path: 'adminviewproduct',
    loadComponent: () => import('./backend/admin-view-product/admin-view-product.component').then(m => m.AdminViewProductComponent),
    title: 'View product'
  },
  {
    path: 'adminsales',
    loadComponent: () => import('./backend/sales/sales.component').then(m => m.SalesComponent),
    title: 'View sales'
  },
  {
    path: 'admintransactions',
    loadComponent: () => import('./backend/transactions/transactions.component').then(m => m.TransactionsComponent),
    title: 'View transactions'
  },
  {
    path: 'admincommissions',
    loadComponent: () => import('./backend/commissions/commissions.component').then(m => m.CommissionsComponent),
    title: 'View commission'
  },
  {
    path: 'adminlogistics',
    loadComponent: () => import('./backend/logistics/logistics.component').then(m => m.LogisticsComponent),
    title: 'View delivery'
  },
  {
    path: 'admintickets',
    loadComponent: () => import('./backend/tickets/tickets.component').then(m => m.TicketsComponent),
    title: 'View ticket'
  },
  {
    path: 'ticket_messages',
    loadComponent: () => import('./backend/tickets/ticket-message/ticket-message.component').then(m => m.TicketMessageComponent),
    title: 'Ticket messages'
  },
  {
    path: 'processing',
    loadComponent: () => import('./backend/processing/processing.component').then(m => m.ProcessingComponent),
    title: 'ORDER PROCESSING'
  },
  {
    path: 'single',
    loadComponent: () => import('./backend/processing/single/single.component').then(m => m.SingleComponent),
    title: 'MANAGE ORDER'
  },
  {
    path: 'plural',
    loadComponent: () => import('./backend/sales/plural/plural.component').then(m => m.PluralComponent),
    title: 'VENDOR ORDERS'
  },
  {
    path: 'deliveries',
    loadComponent: () => import('./backend/logistics/deliveries/deliveries.component').then(m => m.DeliveriesComponent),
    title: 'VENDOR ORDERS'
  },
  {
    path: 'adminusers',
    loadComponent: () => import('./backend/users/users.component').then(m => m.UsersComponent),
    title: 'Platform Users'
  },
  {
    path: 'coupons',
    loadComponent: () => import('./coupon/coupon-list/coupon-list.component').then(m => m.CouponListComponent),
    title: 'Coupons and Discounts'
  },
  {
    path: 'create-coupon',
    loadComponent: () => import('./coupon/create-coupon/create-coupon.component').then(m => m.CreateCouponComponent),
    title: 'Create Coupon'
  },
  {
    path: 'edit-coupon',       // accessed via /edit-coupon?id=123
    loadComponent: () => import('./coupon/edit-coupon/edit-coupon.component').then(m => m.EditCouponComponent),
    title: 'Edit Coupon'
  },
  {
    path: 'coupon-analytics',  // /coupon-analytics for overview, /coupon-analytics?id=123 for single coupon
    loadComponent: () => import('./coupon/coupon-analytics/coupon-analytics.component').then(m => m.CouponAnalyticsComponent),
    title: 'Coupon Analytics'
  }
];
