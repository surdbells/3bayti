import { Routes } from '@angular/router';
import { authGuard, adminGuard, vendorGuard } from './core/auth/auth.guard';
import { adminDashboardResolver } from './core/resolvers/admin-dashboard.resolver';
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
    loadComponent: () => import('./vendor/vendor-metrics/vendor-metrics.component').then(m => m.VendorMetricsComponent),
    canActivate: [vendorGuard],
    title: 'Dashboard'
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
    canActivate: [adminGuard],
    resolve: { dashboard: adminDashboardResolver },
    title: 'Account'
  },
  {
    path: 'notifications',
    loadComponent: () => import('./vendor/vendor-notifications/vendor-notifications.component').then(m => m.VendorNotificationsComponent),
    canActivate: [authGuard],
    title: 'Notifications'
  },
  {
    path: 'payment_info',
    loadComponent: () => import('./settings/vendor-payment/vendor-payment.component').then(m => m.VendorPaymentComponent),
    canActivate: [vendorGuard],
    title: 'Payment information'
  },
  {
    path: 'tax_information',
    loadComponent: () => import('./settings/vendor-tax/vendor-tax.component').then(m => m.VendorTaxComponent),
    canActivate: [vendorGuard],
    title: 'Tax Information'
  },
  {
    path: 'profile',
    loadComponent: () => import('./shared/user-profile/user-profile.component').then(m => m.UserProfileComponent),
    canActivate: [authGuard],
    title: 'Manage your profile'
  },
  {
    path: 'store',
    loadComponent: () => import('./settings/vendor-store/vendor-store.component').then(m => m.VendorStoreComponent),
    canActivate: [vendorGuard],
    title: 'Manage your store'
  },
  {
    path: 'security',
    loadComponent: () => import('./shared/user-security/user-security.component').then(m => m.UserSecurityComponent),
    canActivate: [authGuard],
    title: 'Security settings'
  },
  {
    path: 'orders',
    loadComponent: () => import('./vendor/vendor-orders/vendor-orders.component').then(m => m.VendorOrdersComponent),
    canActivate: [vendorGuard],
    title: 'Orders'
  },
  {
    path: 'admin_order',
    loadComponent: () => import('./backend/admin-view-order/admin-view-order.component').then(m => m.AdminViewOrderComponent),
    canActivate: [adminGuard],
    title: 'Orders'
  },
  {
    path: 'products',
    loadComponent: () => import('./vendor/vendor-products/vendor-products.component').then(m => m.VendorProductsComponent),
    canActivate: [vendorGuard],
    title: 'Products'
  },
  {
    path: 'analytics',
    loadComponent: () => import('./vendor/vendor-analytics/vendor-analytics.component').then(m => m.VendorAnalyticsComponent),
    canActivate: [vendorGuard],
    title: 'Sales Analytics'
  },
  {
    path: 'metrics',
    loadComponent: () => import('./vendor/vendor-metrics/vendor-metrics.component').then(m => m.VendorMetricsComponent),
    canActivate: [vendorGuard],
    title: 'Store Metrics'
  },
  {
    path: 'create-product',
    loadComponent: () => import('./vendor/create-product/create-product.component').then(m => m.CreateProductComponent),
    canActivate: [vendorGuard],
    title: 'Create product'
  },
  {
    path: 'edit-product',
    loadComponent: () => import('./vendor/edit-product/edit-product.component').then(m => m.EditProductComponent),
    canActivate: [vendorGuard],
    title: 'Edit product'
  },
  {
    path: 'order',
    loadComponent: () => import('./vendor/view-order/view-order.component').then(m => m.ViewOrderComponent),
    canActivate: [vendorGuard],
    title: 'Manage order'
  },{
    path: 'receipt',
    loadComponent: () => import('./vendor/receipt/receipt.component').then(m => m.ReceiptComponent),
    canActivate: [vendorGuard],
    title: 'Receipt'
  },
  {
    path: 'returns',
    loadComponent: () => import('./vendor/vendor-returns/vendor-returns.component').then(m => m.VendorReturnsComponent),
    canActivate: [vendorGuard],
    title: 'Returns'
  },
  {
    path: 'reviews',
    loadComponent: () => import('./vendor/vendor-reviews/vendor-reviews.component').then(m => m.VendorReviewsComponent),
    canActivate: [vendorGuard],
    title: 'Reviews'
  },
  {
    path: 'labels',
    loadComponent: () => import('./settings/labels/label.component').then(m => m.LabelComponent),
    canActivate: [vendorGuard],
    title: 'Store labels'
  },
  {
    path: 'messages',
    loadComponent: () => import('./vendor/vendor-messages/vendor-messages.component').then(m => m.VendorMessagesComponent),
    canActivate: [vendorGuard],
    title: 'Messages'
  },
  {
    path: 'labels',
    loadComponent: () => import('./settings/labels/label.component').then(m => m.LabelComponent),
    canActivate: [vendorGuard],
    title: 'Preference'
  },
  {
    path: 'compliance',
    loadComponent: () => import('./vendor/vendor-compliance/vendor-compliance.component').then(m => m.VendorComplianceComponent),
    canActivate: [vendorGuard],
    title: 'Compliance'
  },
  {
    path: 'collections',
    loadComponent: () => import('./backend/collections/collections.component').then(m => m.CollectionsComponent),
    canActivate: [adminGuard],
    title: 'Collections'
  },
  {
    path: 'create_collections',
    loadComponent: () => import('./backend/collections/create-collection/create-collection.component').then(m => m.CreateCollectionComponent),
    canActivate: [adminGuard],
    title: 'Create collection'
  },
  {
    path: 'edit_collection',
    loadComponent: () => import('./backend/collections/edit-collection/edit-collection.component').then(m => m.EditCollectionComponent),
    canActivate: [adminGuard],
    title: 'Edit collection'
  },
  {
    path: 'campaigns',
    loadComponent: () => import('./backend/campaigns/campaigns.component').then(m => m.CampaignsComponent),
    canActivate: [adminGuard],
    title: 'Campaigns'
  },
  {
    path: 'create_campaign',
    loadComponent: () => import('./backend/campaigns/campaign-form/campaign-form.component').then(m => m.CampaignFormComponent),
    canActivate: [adminGuard],
    title: 'Create campaign'
  },
  {
    path: 'edit_campaign',
    loadComponent: () => import('./backend/campaigns/campaign-form/campaign-form.component').then(m => m.CampaignFormComponent),
    canActivate: [adminGuard],
    title: 'Edit campaign'
  },
  {
    path: 'stores',
    loadComponent: () => import('./backend/stores/stores.component').then(m => m.StoresComponent),
    canActivate: [adminGuard],
    title: 'Stores'
  },
  {
    path: 'customers',
    loadComponent: () => import('./backend/customers/customers.component').then(m => m.CustomersComponent),
    canActivate: [adminGuard],
    title: 'Customers'
  },
  {
    path: 'manage_store',
    loadComponent: () => import('./backend/stores/manage-store/manage-store.component').then(m => m.ManageStoreComponent),
    canActivate: [adminGuard],
    title: 'Manage store'
  },
  {
    path: 'product_sales',
    loadComponent: () => import('./backend/sales/sales.component').then(m => m.SalesComponent),
    canActivate: [adminGuard],
    title: 'Product Sales'
  },
  {
    path: 'vendor_product_sales',
    loadComponent: () => import('./vendor/product-sales/product-sales.component').then(m => m.ProductSalesComponent),
    canActivate: [vendorGuard],
    title: 'Product Sales'
  },
  {
    path: 'measurements',
    loadComponent: () => import('./vendor/measurements/measurements.component').then(m => m.MeasurementsComponent),
    canActivate: [vendorGuard],
    title: 'Measurements'
  },
  {
    path: 'store_orders',
    loadComponent: () => import('./backend/stores/store-orders/store-orders.component').then(m => m.StoreOrdersComponent),
    canActivate: [adminGuard],
    title: 'StoreOrders'
  },
  {
    path: 'delivery',
    loadComponent: () => import('./vendor/vendor-delivery/vendor-delivery.component').then(m => m.VendorDeliveryComponent),
    canActivate: [vendorGuard],
    title: 'Delivery list'
  },
  {
    path: 'store_messages',
    loadComponent: () => import('./backend/stores/store-messages/store-messages.component').then(m => m.StoreMessagesComponent),
    canActivate: [adminGuard],
    title: 'Store messages'
  },
  {
    path: 'store_tickets',
    loadComponent: () => import('./backend/stores/store-tickets/store-tickets.component').then(m => m.StoreTicketsComponent),
    canActivate: [adminGuard],
    title: 'Store tickets'
  },
  {
    path: 'store_products',
    loadComponent: () => import('./backend/stores/store-products/store-products.component').then(m => m.StoreProductsComponent),
    canActivate: [adminGuard],
    title: 'Store products'
  },
  {
    path: 'store_sales',
    loadComponent: () => import('./backend/stores/store-sales/store-sales.component').then(m => m.StoreSalesComponent),
    canActivate: [adminGuard],
    title: 'Store sales'
  },
  {
    path: 'store_reviews',
    loadComponent: () => import('./backend/stores/store-reviews/store-reviews.component').then(m => m.StoreReviewsComponent),
    canActivate: [adminGuard],
    title: 'Store reviews'
  },
  {
    path: 'admin_products',
    loadComponent: () => import('./backend/admin-products/admin-products.component').then(m => m.AdminProductsComponent),
    canActivate: [adminGuard],
    title: 'Products'
  },
  {
    path: 'admin_create_product',
    loadComponent: () => import('./vendor/create-product/create-product.component').then(m => m.CreateProductComponent),
    canActivate: [adminGuard],
    title: 'Create product'
  },
  {
    path: 'admin_edit_product',
    loadComponent: () => import('./vendor/edit-product/edit-product.component').then(m => m.EditProductComponent),
    canActivate: [adminGuard],
    title: 'Edit product'
  },
  {
    path: 'adminviewproduct',
    loadComponent: () => import('./backend/admin-view-product/admin-view-product.component').then(m => m.AdminViewProductComponent),
    canActivate: [adminGuard],
    title: 'View product'
  },
  {
    path: 'adminsales',
    loadComponent: () => import('./backend/sales/sales.component').then(m => m.SalesComponent),
    canActivate: [adminGuard],
    title: 'View sales'
  },
  {
    path: 'admintransactions',
    loadComponent: () => import('./backend/transactions/transactions.component').then(m => m.TransactionsComponent),
    canActivate: [adminGuard],
    title: 'View transactions'
  },
  {
    path: 'admincommissions',
    loadComponent: () => import('./backend/commissions/commissions.component').then(m => m.CommissionsComponent),
    canActivate: [adminGuard],
    title: 'View commission'
  },
  {
    path: 'adminreturns',
    loadComponent: () => import('./backend/returns/returns.component').then(m => m.ReturnsComponent),
    canActivate: [adminGuard],
    title: 'Returns'
  },
  {
    path: 'adminnotifications',
    loadComponent: () => import('./backend/notifications/notifications.component').then(m => m.NotificationsComponent),
    canActivate: [adminGuard],
    title: 'Push notification'
  },
  {
    path: 'adminlogistics',
    loadComponent: () => import('./backend/logistics/logistics.component').then(m => m.LogisticsComponent),
    canActivate: [adminGuard],
    title: 'View delivery'
  },
  {
    path: 'admintickets',
    loadComponent: () => import('./backend/tickets/tickets.component').then(m => m.TicketsComponent),
    canActivate: [adminGuard],
    title: 'View ticket'
  },
  {
    path: 'notification-logs',
    loadComponent: () => import('./backend/notification-logs/notification-logs.component').then(m => m.NotificationLogsComponent),
    canActivate: [adminGuard],
    title: 'Notification Logs'
  },
  {
    path: 'ticket_messages',
    loadComponent: () => import('./backend/tickets/ticket-message/ticket-message.component').then(m => m.TicketMessageComponent),
    canActivate: [adminGuard],
    title: 'Ticket messages'
  },
  {
    path: 'processing',
    loadComponent: () => import('./backend/processing/processing.component').then(m => m.ProcessingComponent),
    canActivate: [adminGuard],
    title: 'ORDER PROCESSING'
  },
  {
    path: 'single',
    loadComponent: () => import('./backend/processing/single/single.component').then(m => m.SingleComponent),
    canActivate: [adminGuard],
    title: 'MANAGE ORDER'
  },
  {
    path: 'plural',
    loadComponent: () => import('./backend/sales/plural/plural.component').then(m => m.PluralComponent),
    canActivate: [adminGuard],
    title: 'VENDOR ORDERS'
  },
  {
    path: 'deliveries',
    loadComponent: () => import('./backend/logistics/deliveries/deliveries.component').then(m => m.DeliveriesComponent),
    canActivate: [adminGuard],
    title: 'VENDOR ORDERS'
  },
  {
    path: 'adminusers',
    loadComponent: () => import('./backend/users/users.component').then(m => m.UsersComponent),
    canActivate: [adminGuard],
    title: 'Platform Users'
  },
  {
    // Deep-linkable role + permission-matrix editor (?id= to edit, omit to create).
    path: 'admin_role',
    loadComponent: () => import('./backend/users/role-editor/role-editor.component').then(m => m.RoleEditorComponent),
    canActivate: [adminGuard],
    title: 'Role editor'
  },
  {
    // Create a staff member (routed; replaces the old in-page "Add staff" drawer).
    path: 'adminusers/new',
    loadComponent: () => import('./backend/users/staff-create/staff-create.component').then(m => m.StaffCreateComponent),
    canActivate: [adminGuard],
    title: 'Add staff'
  },
  {
    // Assign roles to a staff member (replaces the old "Manage roles" drawer).
    path: 'adminusers/:id/roles',
    loadComponent: () => import('./backend/users/staff-roles/staff-roles.component').then(m => m.StaffRolesComponent),
    canActivate: [adminGuard],
    title: 'Manage roles'
  },
  {
    // Reset a staff member's password (replaces the old "Reset password" drawer).
    path: 'adminusers/:id/reset-password',
    loadComponent: () => import('./backend/users/staff-password/staff-password.component').then(m => m.StaffPasswordComponent),
    canActivate: [adminGuard],
    title: 'Reset password'
  },
  {
    path: 'admin-gift-cards',
    loadComponent: () => import('./backend/gift-cards/gift-cards.component').then(m => m.GiftCardsAdminComponent),
    canActivate: [adminGuard],
    title: 'Gift Cards'
  },
  {
    path: 'coupons',
    loadComponent: () => import('./coupon/coupon-list/coupon-list.component').then(m => m.CouponListComponent),
    canActivate: [vendorGuard],
    title: 'Coupons and Discounts'
  },
  {
    path: 'create-coupon',
    loadComponent: () => import('./coupon/create-coupon/create-coupon.component').then(m => m.CreateCouponComponent),
    canActivate: [vendorGuard],
    title: 'Create Coupon'
  },
  {
    path: 'edit-coupon',       // accessed via /edit-coupon?id=123
    loadComponent: () => import('./coupon/edit-coupon/edit-coupon.component').then(m => m.EditCouponComponent),
    canActivate: [vendorGuard],
    title: 'Edit Coupon'
  },
  {
    path: 'coupon-analytics',  // /coupon-analytics for overview, /coupon-analytics?id=123 for single coupon
    loadComponent: () => import('./coupon/coupon-analytics/coupon-analytics.component').then(m => m.CouponAnalyticsComponent),
    canActivate: [vendorGuard],
    title: 'Coupon Analytics'
  }
];
