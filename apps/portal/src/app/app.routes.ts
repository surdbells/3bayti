import { Routes } from '@angular/router';
import { authGuard, adminGuard, vendorGuard, requirePermission } from './core/auth/auth.guard';
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
    path: 'orders/:id',
    loadComponent: () => import('./vendor/vendor-order-detail/vendor-order-detail.component').then(m => m.VendorOrderDetailComponent),
    canActivate: [vendorGuard],
    title: 'Order detail'
  },
  {
    path: 'admin_order',
    loadComponent: () => import('./backend/admin-view-order/admin-view-order.component').then(m => m.AdminViewOrderComponent),
    canActivate: [adminGuard, requirePermission('orders.view_detail')],
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
    path: 'admin/collections',
    loadComponent: () => import('./backend/collections/collections.component').then(m => m.CollectionsComponent),
    canActivate: [adminGuard, requirePermission('catalog.collections_view')],
    title: 'Collections'
  },
  {
    path: 'admin/collections/new',
    loadComponent: () => import('./backend/collections/create-collection/create-collection.component').then(m => m.CreateCollectionComponent),
    canActivate: [adminGuard, requirePermission('catalog.collections_manage')],
    title: 'Create collection'
  },
  {
    path: 'admin/ota',
    loadComponent: () => import('./backend/ota/ota.component').then(m => m.OtaComponent),
    canActivate: [adminGuard, requirePermission('settings.edit')],
    title: 'OTA updates'
  },
  {
    path: 'admin/collections/edit',
    loadComponent: () => import('./backend/collections/edit-collection/edit-collection.component').then(m => m.EditCollectionComponent),
    canActivate: [adminGuard, requirePermission('catalog.collections_manage')],
    title: 'Edit collection'
  },
  {
    path: 'admin/campaigns',
    loadComponent: () => import('./backend/campaigns/campaigns.component').then(m => m.CampaignsComponent),
    canActivate: [adminGuard, requirePermission('catalog.campaigns_view')],
    title: 'Campaigns'
  },
  {
    path: 'admin/campaigns/new',
    loadComponent: () => import('./backend/campaigns/campaign-form/campaign-form.component').then(m => m.CampaignFormComponent),
    canActivate: [adminGuard, requirePermission('catalog.campaigns_manage')],
    title: 'Create campaign'
  },
  {
    path: 'admin/campaigns/edit',
    loadComponent: () => import('./backend/campaigns/campaign-form/campaign-form.component').then(m => m.CampaignFormComponent),
    canActivate: [adminGuard, requirePermission('catalog.campaigns_manage')],
    title: 'Edit campaign'
  },
  {
    path: 'admin/stores',
    loadComponent: () => import('./backend/stores/stores.component').then(m => m.StoresComponent),
    canActivate: [adminGuard, requirePermission('vendors.view')],
    title: 'Stores'
  },
  {
    path: 'admin/stores/:id',
    loadComponent: () => import('./backend/stores/manage-store/manage-store.component').then(m => m.ManageStoreComponent),
    canActivate: [adminGuard, requirePermission('vendors.view_detail')],
    title: 'Manage store'
  },
  {
    path: 'admin/vendor-applications',
    loadComponent: () => import('./backend/vendor-applications/vendor-applications.component').then(m => m.VendorApplicationsComponent),
    canActivate: [adminGuard, requirePermission('vendors.view')],
    title: 'Vendor Applications'
  },
  {
    path: 'admin/customers',
    loadComponent: () => import('./backend/customers/customers.component').then(m => m.CustomersComponent),
    // No dedicated customers.* catalog key exists; customer records are
    // order/buyer data, so we gate on the closest key the API enforces.
    canActivate: [adminGuard, requirePermission('orders.view')],
    title: 'Customers'
  },
  {
    path: 'admin/customers/:id',
    loadComponent: () => import('./backend/customers/customer-detail/customer-detail.component').then(m => m.CustomerDetailComponent),
    canActivate: [adminGuard, requirePermission('orders.view')],
    title: 'Customer'
  },
  {
    path: 'manage_store',
    loadComponent: () => import('./backend/stores/manage-store/manage-store.component').then(m => m.ManageStoreComponent),
    canActivate: [adminGuard, requirePermission('vendors.view_detail')],
    title: 'Manage store'
  },
  {
    path: 'admin/sales',
    loadComponent: () => import('./backend/sales/sales.component').then(m => m.SalesComponent),
    canActivate: [adminGuard, requirePermission('sales.view')],
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
    path: 'admin/store-orders',
    loadComponent: () => import('./backend/stores/store-orders/store-orders.component').then(m => m.StoreOrdersComponent),
    canActivate: [adminGuard, requirePermission('vendors.view_detail')],
    title: 'StoreOrders'
  },
  {
    path: 'delivery',
    loadComponent: () => import('./vendor/vendor-delivery/vendor-delivery.component').then(m => m.VendorDeliveryComponent),
    canActivate: [vendorGuard],
    title: 'Delivery list'
  },
  {
    path: 'admin/store-messages',
    loadComponent: () => import('./backend/stores/store-messages/store-messages.component').then(m => m.StoreMessagesComponent),
    canActivate: [adminGuard, requirePermission('vendors.view_detail')],
    title: 'Store messages'
  },
  {
    path: 'admin/store-products',
    loadComponent: () => import('./backend/stores/store-products/store-products.component').then(m => m.StoreProductsComponent),
    canActivate: [adminGuard, requirePermission('products.view')],
    title: 'Store products'
  },
  {
    path: 'admin/store-sales',
    loadComponent: () => import('./backend/stores/store-sales/store-sales.component').then(m => m.StoreSalesComponent),
    canActivate: [adminGuard, requirePermission('sales.view')],
    title: 'Store sales'
  },
  {
    path: 'admin/store-reviews',
    loadComponent: () => import('./backend/stores/store-reviews/store-reviews.component').then(m => m.StoreReviewsComponent),
    canActivate: [adminGuard, requirePermission('vendors.view_detail')],
    title: 'Store reviews'
  },
  {
    path: 'admin/products',
    loadComponent: () => import('./backend/admin-products/admin-products.component').then(m => m.AdminProductsComponent),
    canActivate: [adminGuard, requirePermission('products.view')],
    title: 'Products'
  },
  {
    path: 'admin/products/new',
    loadComponent: () => import('./vendor/create-product/create-product.component').then(m => m.CreateProductComponent),
    canActivate: [adminGuard, requirePermission('products.create')],
    title: 'Create product'
  },
  {
    path: 'admin/products/edit',
    loadComponent: () => import('./vendor/edit-product/edit-product.component').then(m => m.EditProductComponent),
    canActivate: [adminGuard, requirePermission('products.edit')],
    title: 'Edit product'
  },
  {
    // Numeric-looking detail view — MUST stay after the literal new/edit
    // routes above so /admin/products/new isn't captured by :id.
    path: 'admin/products/:id',
    loadComponent: () => import('./backend/admin-view-product/admin-view-product.component').then(m => m.AdminViewProductComponent),
    canActivate: [adminGuard, requirePermission('products.view')],
    title: 'View product'
  },
  {
    path: 'adminviewproduct',
    loadComponent: () => import('./backend/admin-view-product/admin-view-product.component').then(m => m.AdminViewProductComponent),
    canActivate: [adminGuard, requirePermission('products.view')],
    title: 'View product'
  },
  {
    path: 'adminsales',
    loadComponent: () => import('./backend/sales/sales.component').then(m => m.SalesComponent),
    canActivate: [adminGuard, requirePermission('sales.view')],
    title: 'View sales'
  },
  {
    path: 'admin/transactions',
    loadComponent: () => import('./backend/transactions/transactions.component').then(m => m.TransactionsComponent),
    canActivate: [adminGuard, requirePermission('payouts.view_transactions')],
    title: 'View transactions'
  },
  {
    path: 'admin/commissions',
    loadComponent: () => import('./backend/commissions/commissions.component').then(m => m.CommissionsComponent),
    canActivate: [adminGuard, requirePermission('payouts.view_commissions')],
    title: 'View commission'
  },
  {
    path: 'admin/returns',
    loadComponent: () => import('./backend/returns/returns.component').then(m => m.ReturnsComponent),
    canActivate: [adminGuard, requirePermission('returns.view')],
    title: 'Returns'
  },
  {
    path: 'admin/notifications',
    loadComponent: () => import('./backend/notifications/notifications.component').then(m => m.NotificationsComponent),
    canActivate: [adminGuard, requirePermission('notifications.send')],
    title: 'Push notification'
  },
  {
    // Detail before the list so :id doesn't shadow a literal segment.
    path: 'admin/notification-broadcasts/:id',
    loadComponent: () => import('./backend/notification-broadcasts/notification-broadcast-detail.component').then(m => m.NotificationBroadcastDetailComponent),
    canActivate: [adminGuard, requirePermission('notifications.view')],
    title: 'Broadcast details'
  },
  {
    path: 'admin/notification-broadcasts',
    loadComponent: () => import('./backend/notification-broadcasts/notification-broadcasts.component').then(m => m.NotificationBroadcastsComponent),
    canActivate: [adminGuard, requirePermission('notifications.view')],
    title: 'Notification history'
  },
  {
    path: 'admin/logistics',
    loadComponent: () => import('./backend/logistics/logistics.component').then(m => m.LogisticsComponent),
    canActivate: [adminGuard, requirePermission('logistics.view')],
    title: 'View delivery'
  },
  {
    path: 'admin/notification-logs',
    loadComponent: () => import('./backend/notification-logs/notification-logs.component').then(m => m.NotificationLogsComponent),
    canActivate: [adminGuard, requirePermission('notifications.view')],
    title: 'Notification Logs'
  },
  {
    path: 'admin/orders',
    loadComponent: () => import('./backend/processing/processing.component').then(m => m.ProcessingComponent),
    canActivate: [adminGuard, requirePermission('orders.view')],
    title: 'Order processing'
  },
  {
    path: 'admin/orders/:id',
    loadComponent: () => import('./backend/admin-view-order/admin-view-order.component').then(m => m.AdminViewOrderComponent),
    canActivate: [adminGuard, requirePermission('orders.view_detail')],
    title: 'Order'
  },
  {
    path: 'admin/order-manage',
    loadComponent: () => import('./backend/processing/single/single.component').then(m => m.SingleComponent),
    canActivate: [adminGuard, requirePermission('orders.view_detail')],
    title: 'MANAGE ORDER'
  },
  {
    path: 'admin/vendor-orders',
    loadComponent: () => import('./backend/sales/plural/plural.component').then(m => m.PluralComponent),
    canActivate: [adminGuard, requirePermission('sales.view')],
    title: 'VENDOR ORDERS'
  },
  {
    path: 'admin/deliveries',
    loadComponent: () => import('./backend/logistics/deliveries/deliveries.component').then(m => m.DeliveriesComponent),
    canActivate: [adminGuard, requirePermission('logistics.view')],
    title: 'VENDOR ORDERS'
  },
  {
    path: 'admin/users',
    loadComponent: () => import('./backend/users/users.component').then(m => m.UsersComponent),
    canActivate: [adminGuard, requirePermission('users.view')],
    title: 'Platform Users'
  },
  {
    // Deep-linkable role + permission-matrix editor (?id= to edit, omit to create).
    path: 'admin/roles',
    loadComponent: () => import('./backend/users/role-editor/role-editor.component').then(m => m.RoleEditorComponent),
    canActivate: [adminGuard, requirePermission('roles.view')],
    title: 'Role editor'
  },
  {
    // Create a staff member (routed; replaces the old in-page "Add staff" drawer).
    path: 'admin/users/new',
    loadComponent: () => import('./backend/users/staff-create/staff-create.component').then(m => m.StaffCreateComponent),
    canActivate: [adminGuard, requirePermission('users.create')],
    title: 'Add staff'
  },
  {
    // Assign roles to a staff member (replaces the old "Manage roles" drawer).
    path: 'admin/users/:id/roles',
    loadComponent: () => import('./backend/users/staff-roles/staff-roles.component').then(m => m.StaffRolesComponent),
    canActivate: [adminGuard, requirePermission('users.manage_roles')],
    title: 'Manage roles'
  },
  {
    // Reset a staff member's password (replaces the old "Reset password" drawer).
    path: 'admin/users/:id/reset-password',
    loadComponent: () => import('./backend/users/staff-password/staff-password.component').then(m => m.StaffPasswordComponent),
    canActivate: [adminGuard, requirePermission('users.edit')],
    title: 'Reset password'
  },
  {
    path: 'admin/gift-cards',
    loadComponent: () => import('./backend/gift-cards/gift-cards.component').then(m => m.GiftCardsAdminComponent),
    canActivate: [adminGuard, requirePermission('gift_cards.view')],
    title: 'Gift Cards'
  },
  {
    path: 'admin/gift-cards/:id',
    loadComponent: () => import('./backend/gift-cards/gift-card-detail.component').then(m => m.GiftCardDetailComponent),
    canActivate: [adminGuard, requirePermission('gift_cards.view')],
    title: 'Gift Card'
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
  },
  // ── Pretty-URL migration: legacy admin paths redirect to /admin/* ──
  { path: 'stores', redirectTo: 'admin/stores', pathMatch: 'full' },
  { path: 'customers', redirectTo: 'admin/customers', pathMatch: 'full' },
  { path: 'processing', redirectTo: 'admin/orders', pathMatch: 'full' },
  { path: 'product_sales', redirectTo: 'admin/sales', pathMatch: 'full' },
  { path: 'admin_products', redirectTo: 'admin/products', pathMatch: 'full' },
  { path: 'collections', redirectTo: 'admin/collections', pathMatch: 'full' },
  { path: 'create_collections', redirectTo: 'admin/collections/new', pathMatch: 'full' },
  { path: 'edit_collection', redirectTo: 'admin/collections/edit', pathMatch: 'full' },
  { path: 'ota', redirectTo: 'admin/ota', pathMatch: 'full' },
  { path: 'campaigns', redirectTo: 'admin/campaigns', pathMatch: 'full' },
  { path: 'create_campaign', redirectTo: 'admin/campaigns/new', pathMatch: 'full' },
  { path: 'edit_campaign', redirectTo: 'admin/campaigns/edit', pathMatch: 'full' },
  { path: 'vendor-applications', redirectTo: 'admin/vendor-applications', pathMatch: 'full' },
  { path: 'store_orders', redirectTo: 'admin/store-orders', pathMatch: 'full' },
  { path: 'store_products', redirectTo: 'admin/store-products', pathMatch: 'full' },
  { path: 'store_sales', redirectTo: 'admin/store-sales', pathMatch: 'full' },
  { path: 'store_reviews', redirectTo: 'admin/store-reviews', pathMatch: 'full' },
  { path: 'store_messages', redirectTo: 'admin/store-messages', pathMatch: 'full' },
  { path: 'admin_create_product', redirectTo: 'admin/products/new', pathMatch: 'full' },
  { path: 'admin_edit_product', redirectTo: 'admin/products/edit', pathMatch: 'full' },
  { path: 'admintransactions', redirectTo: 'admin/transactions', pathMatch: 'full' },
  { path: 'admincommissions', redirectTo: 'admin/commissions', pathMatch: 'full' },
  { path: 'adminreturns', redirectTo: 'admin/returns', pathMatch: 'full' },
  { path: 'adminnotifications', redirectTo: 'admin/notifications', pathMatch: 'full' },
  { path: 'adminlogistics', redirectTo: 'admin/logistics', pathMatch: 'full' },
  { path: 'notification-logs', redirectTo: 'admin/notification-logs', pathMatch: 'full' },
  { path: 'single', redirectTo: 'admin/order-manage', pathMatch: 'full' },
  { path: 'plural', redirectTo: 'admin/vendor-orders', pathMatch: 'full' },
  { path: 'deliveries', redirectTo: 'admin/deliveries', pathMatch: 'full' },
  { path: 'adminusers/new', redirectTo: 'admin/users/new', pathMatch: 'full' },
  { path: 'adminusers/:id/roles', redirectTo: 'admin/users/:id/roles', pathMatch: 'full' },
  { path: 'adminusers/:id/reset-password', redirectTo: 'admin/users/:id/reset-password', pathMatch: 'full' },
  { path: 'adminusers', redirectTo: 'admin/users', pathMatch: 'full' },
  { path: 'admin_role', redirectTo: 'admin/roles', pathMatch: 'full' },
  { path: 'admin-gift-cards/:id', redirectTo: 'admin/gift-cards/:id', pathMatch: 'full' },
  { path: 'admin-gift-cards', redirectTo: 'admin/gift-cards', pathMatch: 'full' },
  { path: 'adminsales', redirectTo: 'admin/sales', pathMatch: 'full' },
];
