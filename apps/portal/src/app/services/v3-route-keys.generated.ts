/**
 * AUTO-GENERATED — do not edit by hand.
 * Source: packages/api-client/src/feature-flags.ts (ENDPOINT_ROUTING).
 * Regenerate: node tools/gen-route-keys.mjs
 *
 * 314 route keys.
 */

/** Every valid v3 route key, as a compile-time-checked union. */
export type V3RouteKey =
  | 'DELETE /admin/brands/:id'
  | 'DELETE /admin/campaigns/:id'
  | 'DELETE /admin/categories/:id'
  | 'DELETE /admin/collections/:id'
  | 'DELETE /admin/notification-templates/:id'
  | 'DELETE /admin/products/:id'
  | 'DELETE /admin/promo-codes/:id'
  | 'DELETE /admin/roles/:id'
  | 'DELETE /admin/vendors/:id'
  | 'DELETE /cart/items/:id'
  | 'DELETE /following/:vendorId'
  | 'DELETE /me'
  | 'DELETE /me/addresses/:id'
  | 'DELETE /me/device-tokens'
  | 'DELETE /me/measurements/:id'
  | 'DELETE /me/reviews/:id'
  | 'DELETE /me/social-identities/:provider'
  | 'DELETE /me/wishlist/:productId'
  | 'DELETE /me/wishlist/labels/:id'
  | 'DELETE /vendor/coupons/:id'
  | 'DELETE /vendor/labels/:id'
  | 'DELETE /vendor/measurements/:id'
  | 'DELETE /vendor/products/:id'
  | 'DELETE /wishlist/:productId'
  | 'GET /admin/analytics'
  | 'GET /admin/audit-logs'
  | 'GET /admin/brands'
  | 'GET /admin/campaigns'
  | 'GET /admin/campaigns/:id'
  | 'GET /admin/categories'
  | 'GET /admin/collections'
  | 'GET /admin/collections/:id'
  | 'GET /admin/commissions'
  | 'GET /admin/customers'
  | 'GET /admin/gift-cards'
  | 'GET /admin/gift-cards/:id'
  | 'GET /admin/gift-cards/redemptions'
  | 'GET /admin/insights'
  | 'GET /admin/notification-broadcasts'
  | 'GET /admin/notification-broadcasts/:id'
  | 'GET /admin/notification-broadcasts/:id/recipients'
  | 'GET /admin/notification-logs'
  | 'GET /admin/notification-schedules'
  | 'GET /admin/notification-schedules/:id'
  | 'GET /admin/notification-templates'
  | 'GET /admin/notification-templates/:id'
  | 'GET /admin/notification-templates/variables'
  | 'GET /admin/notifications'
  | 'GET /admin/notifications/audience-preview'
  | 'GET /admin/orders'
  | 'GET /admin/orders/:id'
  | 'GET /admin/orders/:id/timeline'
  | 'GET /admin/permission-catalog'
  | 'GET /admin/products'
  | 'GET /admin/products/:id'
  | 'GET /admin/promo-codes'
  | 'GET /admin/promo-codes/:id'
  | 'GET /admin/promo-codes/:id/analytics'
  | 'GET /admin/returns'
  | 'GET /admin/roles'
  | 'GET /admin/roles/:id'
  | 'GET /admin/top-customers'
  | 'GET /admin/top-stores'
  | 'GET /admin/transactions'
  | 'GET /admin/users'
  | 'GET /admin/users/:id'
  | 'GET /admin/vendor-applications'
  | 'GET /admin/vendor-metrics'
  | 'GET /admin/vendors'
  | 'GET /admin/vendors/:id'
  | 'GET /admin/vendors/:id/analytics'
  | 'GET /admin/vendors/:id/compliance'
  | 'GET /admin/vendors/:id/messages'
  | 'GET /admin/vendors/:id/metrics'
  | 'GET /admin/vendors/:id/products'
  | 'GET /auth/me'
  | 'GET /campaigns/:slug'
  | 'GET /campaigns/active'
  | 'GET /cart'
  | 'GET /cart/gift-wallet'
  | 'GET /categories'
  | 'GET /categories/:slug'
  | 'GET /chat/conversation-stores'
  | 'GET /chat/conversations'
  | 'GET /chat/conversations/:uuid/messages'
  | 'GET /chat/unread-count'
  | 'GET /checkout/status/:order_reference'
  | 'GET /featured-vendors'
  | 'GET /gift-cards/balance'
  | 'GET /gift-cards/mine'
  | 'GET /gift-cards/themes'
  | 'GET /gift-cards/wallet'
  | 'GET /health'
  | 'GET /me/addresses'
  | 'GET /me/addresses/:id'
  | 'GET /me/billing-address'
  | 'GET /me/measurements'
  | 'GET /me/profile'
  | 'GET /me/recommendations'
  | 'GET /me/reviews'
  | 'GET /me/social-identities'
  | 'GET /me/styles'
  | 'GET /me/wishlist'
  | 'GET /me/wishlist/labels'
  | 'GET /mobile/best-sellers'
  | 'GET /mobile/best-sellers-listing'
  | 'GET /mobile/category-listing'
  | 'GET /mobile/explore-listing'
  | 'GET /mobile/featured'
  | 'GET /mobile/my-styles'
  | 'GET /mobile/new-arrivals'
  | 'GET /mobile/new-arrivals-listing'
  | 'GET /mobile/products-by-labels'
  | 'GET /mobile/read-vendor'
  | 'GET /mobile/search'
  | 'GET /mobile/single-product'
  | 'GET /mobile/single-product-utility'
  | 'GET /mobile/store-labels'
  | 'GET /mobile/store-latest'
  | 'GET /mobile/stores'
  | 'GET /mobile/style-detail'
  | 'GET /mobile/styles-list'
  | 'GET /mobile/vendors-products'
  | 'GET /orders'
  | 'GET /orders/:id'
  | 'GET /orders/:id/timeline'
  | 'GET /products'
  | 'GET /products/:productId/reviews'
  | 'GET /products/:slug'
  | 'GET /products/:slug/recommendations'
  | 'GET /products/by-legacy-id/:id'
  | 'GET /products/facets'
  | 'GET /sitemap-data'
  | 'GET /styles'
  | 'GET /styles/:slug'
  | 'GET /utility/categories'
  | 'GET /utility/collections'
  | 'GET /utility/stores'
  | 'GET /vendor/analytics'
  | 'GET /vendor/chat/conversations'
  | 'GET /vendor/chat/conversations/:uuid/messages'
  | 'GET /vendor/chat/unread-count'
  | 'GET /vendor/collections'
  | 'GET /vendor/compliance'
  | 'GET /vendor/coupons'
  | 'GET /vendor/coupons/:id'
  | 'GET /vendor/coupons/:id/analytics'
  | 'GET /vendor/dashboard'
  | 'GET /vendor/labels'
  | 'GET /vendor/measurements'
  | 'GET /vendor/measurements/:id'
  | 'GET /vendor/messages'
  | 'GET /vendor/metrics'
  | 'GET /vendor/notifications'
  | 'GET /vendor/onboarding/status'
  | 'GET /vendor/orders'
  | 'GET /vendor/orders/:id'
  | 'GET /vendor/orders/:id/timeline'
  | 'GET /vendor/products'
  | 'GET /vendor/products/:id'
  | 'GET /vendor/products/:id/sales'
  | 'GET /vendor/returns'
  | 'GET /vendor/returns/:id'
  | 'GET /vendor/reviews'
  | 'GET /vendor/store'
  | 'GET /vendor/store/notifications'
  | 'GET /vendor/store/payment'
  | 'GET /vendor/store/tax'
  | 'GET /vendors'
  | 'GET /vendors/:slug'
  | 'GET /vendors/:slug/labels'
  | 'GET /vendors/:slug/products'
  | 'GET /vendors/:slug/reviews'
  | 'GET /vendors/:slug/size-chart'
  | 'GET /vendors/:vendorId/reviews'
  | 'GET /vendors/:vendorId/size-chart'
  | 'GET /vendors/by-legacy-id/:id'
  | 'GET /vendors/by-legacy-id/:id/products'
  | 'GET /wishlist'
  | 'PATCH /admin/notification-templates/:id/status'
  | 'PATCH /admin/orders/:id/status'
  | 'PATCH /admin/orders/:orderId/items/:itemId/status'
  | 'PATCH /admin/users/:id/password'
  | 'PATCH /cart/items/:id'
  | 'PATCH /me/addresses/:id/default'
  | 'PATCH /me/billing-address'
  | 'PATCH /me/location'
  | 'PATCH /me/notification-preferences'
  | 'PATCH /me/password'
  | 'PATCH /me/profile'
  | 'PATCH /me/wishlist/:productId'
  | 'PATCH /me/wishlist/labels/:id'
  | 'PATCH /vendor/compliance'
  | 'PATCH /vendor/orders/:orderId/items/:itemId/status'
  | 'PATCH /vendor/store'
  | 'PATCH /vendor/store/notifications'
  | 'PATCH /vendor/store/payment'
  | 'PATCH /vendor/store/status'
  | 'PATCH /vendor/store/tax'
  | 'POST /account/deletion-request'
  | 'POST /admin/brands'
  | 'POST /admin/campaigns'
  | 'POST /admin/categories'
  | 'POST /admin/collections'
  | 'POST /admin/gift-cards'
  | 'POST /admin/gift-cards/:id/adjust'
  | 'POST /admin/gift-cards/:id/void'
  | 'POST /admin/notification-broadcasts/:id/resend'
  | 'POST /admin/notification-schedules'
  | 'POST /admin/notification-schedules/:id/cancel'
  | 'POST /admin/notification-schedules/:id/run-now'
  | 'POST /admin/notification-templates'
  | 'POST /admin/notification-templates/:id/duplicate'
  | 'POST /admin/notifications'
  | 'POST /admin/notifications/mark-read'
  | 'POST /admin/orders/:id/cancel'
  | 'POST /admin/orders/:id/refund'
  | 'POST /admin/orders/:id/resend-vendor-notification'
  | 'POST /admin/products'
  | 'POST /admin/promo-codes'
  | 'POST /admin/roles'
  | 'POST /admin/users'
  | 'POST /admin/users/:id/activate'
  | 'POST /admin/users/:id/deactivate'
  | 'POST /admin/users/:id/roles'
  | 'POST /admin/vendor-applications/:id/approve'
  | 'POST /admin/vendor-applications/:id/reject'
  | 'POST /admin/vendor-applications/:id/resend-credentials'
  | 'POST /admin/vendors'
  | 'POST /admin/vendors/:id/approve'
  | 'POST /admin/vendors/:id/compliance/approve'
  | 'POST /admin/vendors/:id/compliance/reject'
  | 'POST /admin/vendors/:id/impersonate'
  | 'POST /admin/vendors/:id/messages'
  | 'POST /admin/vendors/:id/reactivate'
  | 'POST /admin/vendors/:id/suspend'
  | 'POST /auth/confirm'
  | 'POST /auth/login'
  | 'POST /auth/logout'
  | 'POST /auth/logout-all'
  | 'POST /auth/otp-login/send'
  | 'POST /auth/otp-login/verify'
  | 'POST /auth/refresh'
  | 'POST /auth/register'
  | 'POST /auth/register/confirm-email'
  | 'POST /auth/register/initiate'
  | 'POST /auth/register/submit'
  | 'POST /auth/register/verify-phone'
  | 'POST /auth/reset'
  | 'POST /auth/reset/confirm'
  | 'POST /auth/send-otp'
  | 'POST /auth/social'
  | 'POST /auth/validate-email'
  | 'POST /auth/validate-phone'
  | 'POST /cart/gift-card'
  | 'POST /cart/items'
  | 'POST /cart/merge'
  | 'POST /cart/quote'
  | 'POST /chat/conversations/:uuid/messages'
  | 'POST /chat/conversations/:uuid/read'
  | 'POST /checkout/initiate'
  | 'POST /following/:vendorId'
  | 'POST /gift-cards/:id/activate'
  | 'POST /gift-cards/purchase'
  | 'POST /gift-cards/redeem'
  | 'POST /me/addresses'
  | 'POST /me/avatar'
  | 'POST /me/device-tokens'
  | 'POST /me/measurements'
  | 'POST /me/phone'
  | 'POST /me/phone/claim'
  | 'POST /me/phone/claim/verify'
  | 'POST /me/phone/verify'
  | 'POST /me/social-identities'
  | 'POST /me/styles'
  | 'POST /me/wishlist'
  | 'POST /me/wishlist/labels'
  | 'POST /orders/:id/cancel'
  | 'POST /payment/webhook/noon'
  | 'POST /products/:productId/reviews'
  | 'POST /reviews/:id/helpful'
  | 'POST /vendor-applications'
  | 'POST /vendor/chat/conversations/:uuid/messages'
  | 'POST /vendor/chat/conversations/:uuid/read'
  | 'POST /vendor/coupons'
  | 'POST /vendor/coupons/:id/toggle'
  | 'POST /vendor/labels'
  | 'POST /vendor/measurements'
  | 'POST /vendor/messages'
  | 'POST /vendor/messages/:id/read'
  | 'POST /vendor/notifications/mark-read'
  | 'POST /vendor/onboarding/submit'
  | 'POST /vendor/products'
  | 'POST /vendor/returns/:id/confirm-receipt'
  | 'POST /vendors/:vendorId/reviews'
  | 'POST /wishlist'
  | 'PUT /admin/brands/:id'
  | 'PUT /admin/campaigns/:id'
  | 'PUT /admin/categories/:id'
  | 'PUT /admin/collections/:id'
  | 'PUT /admin/notification-schedules/:id'
  | 'PUT /admin/notification-templates/:id'
  | 'PUT /admin/products/:id'
  | 'PUT /admin/promo-codes/:id'
  | 'PUT /admin/roles/:id'
  | 'PUT /admin/users/:id'
  | 'PUT /admin/vendors/:id'
  | 'PUT /me/addresses/:id'
  | 'PUT /me/measurements/:id'
  | 'PUT /me/measurements/default'
  | 'PUT /vendor/coupons/:id'
  | 'PUT /vendor/labels/:id'
  | 'PUT /vendor/measurements/:id'
  | 'PUT /vendor/products/:id';

/** Runtime set of valid route keys (frozen). */
export const V3_ROUTE_KEYS: ReadonlySet<V3RouteKey> = new Set([
  'DELETE /admin/brands/:id',
  'DELETE /admin/campaigns/:id',
  'DELETE /admin/categories/:id',
  'DELETE /admin/collections/:id',
  'DELETE /admin/notification-templates/:id',
  'DELETE /admin/products/:id',
  'DELETE /admin/promo-codes/:id',
  'DELETE /admin/roles/:id',
  'DELETE /admin/vendors/:id',
  'DELETE /cart/items/:id',
  'DELETE /following/:vendorId',
  'DELETE /me',
  'DELETE /me/addresses/:id',
  'DELETE /me/device-tokens',
  'DELETE /me/measurements/:id',
  'DELETE /me/reviews/:id',
  'DELETE /me/social-identities/:provider',
  'DELETE /me/wishlist/:productId',
  'DELETE /me/wishlist/labels/:id',
  'DELETE /vendor/coupons/:id',
  'DELETE /vendor/labels/:id',
  'DELETE /vendor/measurements/:id',
  'DELETE /vendor/products/:id',
  'DELETE /wishlist/:productId',
  'GET /admin/analytics',
  'GET /admin/audit-logs',
  'GET /admin/brands',
  'GET /admin/campaigns',
  'GET /admin/campaigns/:id',
  'GET /admin/categories',
  'GET /admin/collections',
  'GET /admin/collections/:id',
  'GET /admin/commissions',
  'GET /admin/customers',
  'GET /admin/gift-cards',
  'GET /admin/gift-cards/:id',
  'GET /admin/gift-cards/redemptions',
  'GET /admin/insights',
  'GET /admin/notification-broadcasts',
  'GET /admin/notification-broadcasts/:id',
  'GET /admin/notification-broadcasts/:id/recipients',
  'GET /admin/notification-logs',
  'GET /admin/notification-schedules',
  'GET /admin/notification-schedules/:id',
  'GET /admin/notification-templates',
  'GET /admin/notification-templates/:id',
  'GET /admin/notification-templates/variables',
  'GET /admin/notifications',
  'GET /admin/notifications/audience-preview',
  'GET /admin/orders',
  'GET /admin/orders/:id',
  'GET /admin/orders/:id/timeline',
  'GET /admin/permission-catalog',
  'GET /admin/products',
  'GET /admin/products/:id',
  'GET /admin/promo-codes',
  'GET /admin/promo-codes/:id',
  'GET /admin/promo-codes/:id/analytics',
  'GET /admin/returns',
  'GET /admin/roles',
  'GET /admin/roles/:id',
  'GET /admin/top-customers',
  'GET /admin/top-stores',
  'GET /admin/transactions',
  'GET /admin/users',
  'GET /admin/users/:id',
  'GET /admin/vendor-applications',
  'GET /admin/vendor-metrics',
  'GET /admin/vendors',
  'GET /admin/vendors/:id',
  'GET /admin/vendors/:id/analytics',
  'GET /admin/vendors/:id/compliance',
  'GET /admin/vendors/:id/messages',
  'GET /admin/vendors/:id/metrics',
  'GET /admin/vendors/:id/products',
  'GET /auth/me',
  'GET /campaigns/:slug',
  'GET /campaigns/active',
  'GET /cart',
  'GET /cart/gift-wallet',
  'GET /categories',
  'GET /categories/:slug',
  'GET /chat/conversation-stores',
  'GET /chat/conversations',
  'GET /chat/conversations/:uuid/messages',
  'GET /chat/unread-count',
  'GET /checkout/status/:order_reference',
  'GET /featured-vendors',
  'GET /gift-cards/balance',
  'GET /gift-cards/mine',
  'GET /gift-cards/themes',
  'GET /gift-cards/wallet',
  'GET /health',
  'GET /me/addresses',
  'GET /me/addresses/:id',
  'GET /me/billing-address',
  'GET /me/measurements',
  'GET /me/profile',
  'GET /me/recommendations',
  'GET /me/reviews',
  'GET /me/social-identities',
  'GET /me/styles',
  'GET /me/wishlist',
  'GET /me/wishlist/labels',
  'GET /mobile/best-sellers',
  'GET /mobile/best-sellers-listing',
  'GET /mobile/category-listing',
  'GET /mobile/explore-listing',
  'GET /mobile/featured',
  'GET /mobile/my-styles',
  'GET /mobile/new-arrivals',
  'GET /mobile/new-arrivals-listing',
  'GET /mobile/products-by-labels',
  'GET /mobile/read-vendor',
  'GET /mobile/search',
  'GET /mobile/single-product',
  'GET /mobile/single-product-utility',
  'GET /mobile/store-labels',
  'GET /mobile/store-latest',
  'GET /mobile/stores',
  'GET /mobile/style-detail',
  'GET /mobile/styles-list',
  'GET /mobile/vendors-products',
  'GET /orders',
  'GET /orders/:id',
  'GET /orders/:id/timeline',
  'GET /products',
  'GET /products/:productId/reviews',
  'GET /products/:slug',
  'GET /products/:slug/recommendations',
  'GET /products/by-legacy-id/:id',
  'GET /products/facets',
  'GET /sitemap-data',
  'GET /styles',
  'GET /styles/:slug',
  'GET /utility/categories',
  'GET /utility/collections',
  'GET /utility/stores',
  'GET /vendor/analytics',
  'GET /vendor/chat/conversations',
  'GET /vendor/chat/conversations/:uuid/messages',
  'GET /vendor/chat/unread-count',
  'GET /vendor/collections',
  'GET /vendor/compliance',
  'GET /vendor/coupons',
  'GET /vendor/coupons/:id',
  'GET /vendor/coupons/:id/analytics',
  'GET /vendor/dashboard',
  'GET /vendor/labels',
  'GET /vendor/measurements',
  'GET /vendor/measurements/:id',
  'GET /vendor/messages',
  'GET /vendor/metrics',
  'GET /vendor/notifications',
  'GET /vendor/onboarding/status',
  'GET /vendor/orders',
  'GET /vendor/orders/:id',
  'GET /vendor/orders/:id/timeline',
  'GET /vendor/products',
  'GET /vendor/products/:id',
  'GET /vendor/products/:id/sales',
  'GET /vendor/returns',
  'GET /vendor/returns/:id',
  'GET /vendor/reviews',
  'GET /vendor/store',
  'GET /vendor/store/notifications',
  'GET /vendor/store/payment',
  'GET /vendor/store/tax',
  'GET /vendors',
  'GET /vendors/:slug',
  'GET /vendors/:slug/labels',
  'GET /vendors/:slug/products',
  'GET /vendors/:slug/reviews',
  'GET /vendors/:slug/size-chart',
  'GET /vendors/:vendorId/reviews',
  'GET /vendors/:vendorId/size-chart',
  'GET /vendors/by-legacy-id/:id',
  'GET /vendors/by-legacy-id/:id/products',
  'GET /wishlist',
  'PATCH /admin/notification-templates/:id/status',
  'PATCH /admin/orders/:id/status',
  'PATCH /admin/orders/:orderId/items/:itemId/status',
  'PATCH /admin/users/:id/password',
  'PATCH /cart/items/:id',
  'PATCH /me/addresses/:id/default',
  'PATCH /me/billing-address',
  'PATCH /me/location',
  'PATCH /me/notification-preferences',
  'PATCH /me/password',
  'PATCH /me/profile',
  'PATCH /me/wishlist/:productId',
  'PATCH /me/wishlist/labels/:id',
  'PATCH /vendor/compliance',
  'PATCH /vendor/orders/:orderId/items/:itemId/status',
  'PATCH /vendor/store',
  'PATCH /vendor/store/notifications',
  'PATCH /vendor/store/payment',
  'PATCH /vendor/store/status',
  'PATCH /vendor/store/tax',
  'POST /account/deletion-request',
  'POST /admin/brands',
  'POST /admin/campaigns',
  'POST /admin/categories',
  'POST /admin/collections',
  'POST /admin/gift-cards',
  'POST /admin/gift-cards/:id/adjust',
  'POST /admin/gift-cards/:id/void',
  'POST /admin/notification-broadcasts/:id/resend',
  'POST /admin/notification-schedules',
  'POST /admin/notification-schedules/:id/cancel',
  'POST /admin/notification-schedules/:id/run-now',
  'POST /admin/notification-templates',
  'POST /admin/notification-templates/:id/duplicate',
  'POST /admin/notifications',
  'POST /admin/notifications/mark-read',
  'POST /admin/orders/:id/cancel',
  'POST /admin/orders/:id/refund',
  'POST /admin/orders/:id/resend-vendor-notification',
  'POST /admin/products',
  'POST /admin/promo-codes',
  'POST /admin/roles',
  'POST /admin/users',
  'POST /admin/users/:id/activate',
  'POST /admin/users/:id/deactivate',
  'POST /admin/users/:id/roles',
  'POST /admin/vendor-applications/:id/approve',
  'POST /admin/vendor-applications/:id/reject',
  'POST /admin/vendor-applications/:id/resend-credentials',
  'POST /admin/vendors',
  'POST /admin/vendors/:id/approve',
  'POST /admin/vendors/:id/compliance/approve',
  'POST /admin/vendors/:id/compliance/reject',
  'POST /admin/vendors/:id/impersonate',
  'POST /admin/vendors/:id/messages',
  'POST /admin/vendors/:id/reactivate',
  'POST /admin/vendors/:id/suspend',
  'POST /auth/confirm',
  'POST /auth/login',
  'POST /auth/logout',
  'POST /auth/logout-all',
  'POST /auth/otp-login/send',
  'POST /auth/otp-login/verify',
  'POST /auth/refresh',
  'POST /auth/register',
  'POST /auth/register/confirm-email',
  'POST /auth/register/initiate',
  'POST /auth/register/submit',
  'POST /auth/register/verify-phone',
  'POST /auth/reset',
  'POST /auth/reset/confirm',
  'POST /auth/send-otp',
  'POST /auth/social',
  'POST /auth/validate-email',
  'POST /auth/validate-phone',
  'POST /cart/gift-card',
  'POST /cart/items',
  'POST /cart/merge',
  'POST /cart/quote',
  'POST /chat/conversations/:uuid/messages',
  'POST /chat/conversations/:uuid/read',
  'POST /checkout/initiate',
  'POST /following/:vendorId',
  'POST /gift-cards/:id/activate',
  'POST /gift-cards/purchase',
  'POST /gift-cards/redeem',
  'POST /me/addresses',
  'POST /me/avatar',
  'POST /me/device-tokens',
  'POST /me/measurements',
  'POST /me/phone',
  'POST /me/phone/claim',
  'POST /me/phone/claim/verify',
  'POST /me/phone/verify',
  'POST /me/social-identities',
  'POST /me/styles',
  'POST /me/wishlist',
  'POST /me/wishlist/labels',
  'POST /orders/:id/cancel',
  'POST /payment/webhook/noon',
  'POST /products/:productId/reviews',
  'POST /reviews/:id/helpful',
  'POST /vendor-applications',
  'POST /vendor/chat/conversations/:uuid/messages',
  'POST /vendor/chat/conversations/:uuid/read',
  'POST /vendor/coupons',
  'POST /vendor/coupons/:id/toggle',
  'POST /vendor/labels',
  'POST /vendor/measurements',
  'POST /vendor/messages',
  'POST /vendor/messages/:id/read',
  'POST /vendor/notifications/mark-read',
  'POST /vendor/onboarding/submit',
  'POST /vendor/products',
  'POST /vendor/returns/:id/confirm-receipt',
  'POST /vendors/:vendorId/reviews',
  'POST /wishlist',
  'PUT /admin/brands/:id',
  'PUT /admin/campaigns/:id',
  'PUT /admin/categories/:id',
  'PUT /admin/collections/:id',
  'PUT /admin/notification-schedules/:id',
  'PUT /admin/notification-templates/:id',
  'PUT /admin/products/:id',
  'PUT /admin/promo-codes/:id',
  'PUT /admin/roles/:id',
  'PUT /admin/users/:id',
  'PUT /admin/vendors/:id',
  'PUT /me/addresses/:id',
  'PUT /me/measurements/:id',
  'PUT /me/measurements/default',
  'PUT /vendor/coupons/:id',
  'PUT /vendor/labels/:id',
  'PUT /vendor/measurements/:id',
  'PUT /vendor/products/:id',
] as const);

/** Type guard: is an arbitrary string a known route key? */
export function isV3RouteKey(key: string): key is V3RouteKey {
  return (V3_ROUTE_KEYS as ReadonlySet<string>).has(key);
}
