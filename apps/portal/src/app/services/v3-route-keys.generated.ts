/**
 * AUTO-GENERATED — do not edit by hand.
 * Source: packages/api-client/src/feature-flags.ts (ENDPOINT_ROUTING).
 * Regenerate: node tools/gen-route-keys.mjs
 *
 * 201 route keys.
 */

/** Every valid v3 route key, as a compile-time-checked union. */
export type V3RouteKey =
  | 'DELETE /admin/brands/:id'
  | 'DELETE /admin/categories/:id'
  | 'DELETE /admin/collections/:id'
  | 'DELETE /admin/products/:id'
  | 'DELETE /admin/vendors/:id'
  | 'DELETE /cart/items/:id'
  | 'DELETE /me/addresses/:id'
  | 'DELETE /me/device-tokens'
  | 'DELETE /me/measurements/:id'
  | 'DELETE /me/wishlist/:productId'
  | 'DELETE /me/wishlist/labels/:id'
  | 'DELETE /vendor/coupons/:id'
  | 'DELETE /vendor/labels/:id'
  | 'DELETE /vendor/measurements/:id'
  | 'DELETE /vendor/products/:id'
  | 'DELETE /wishlist/:productId'
  | 'GET /admin/analytics'
  | 'GET /admin/brands'
  | 'GET /admin/categories'
  | 'GET /admin/collections'
  | 'GET /admin/collections/:id'
  | 'GET /admin/commissions'
  | 'GET /admin/customers'
  | 'GET /admin/notifications'
  | 'GET /admin/orders'
  | 'GET /admin/orders/:id'
  | 'GET /admin/orders/:id/timeline'
  | 'GET /admin/products'
  | 'GET /admin/returns'
  | 'GET /admin/tickets'
  | 'GET /admin/tickets/:id'
  | 'GET /admin/tickets/:id/messages'
  | 'GET /admin/transactions'
  | 'GET /admin/users'
  | 'GET /admin/users/:id'
  | 'GET /admin/vendor-metrics'
  | 'GET /admin/vendors'
  | 'GET /admin/vendors/:id'
  | 'GET /admin/vendors/:id/analytics'
  | 'GET /admin/vendors/:id/metrics'
  | 'GET /auth/me'
  | 'GET /cart'
  | 'GET /categories'
  | 'GET /categories/:slug'
  | 'GET /checkout/status/:order_reference'
  | 'GET /featured-vendors'
  | 'GET /gift-cards/balance'
  | 'GET /gift-cards/mine'
  | 'GET /gift-cards/themes'
  | 'GET /health'
  | 'GET /me/addresses'
  | 'GET /me/addresses/:id'
  | 'GET /me/billing-address'
  | 'GET /me/measurements'
  | 'GET /me/profile'
  | 'GET /me/recommendations'
  | 'GET /me/wishlist'
  | 'GET /me/wishlist/labels'
  | 'GET /mobile/best-sellers'
  | 'GET /mobile/best-sellers-listing'
  | 'GET /mobile/category-listing'
  | 'GET /mobile/explore-listing'
  | 'GET /mobile/featured'
  | 'GET /mobile/new-arrivals'
  | 'GET /mobile/new-arrivals-listing'
  | 'GET /mobile/products-by-labels'
  | 'GET /mobile/read-vendor'
  | 'GET /mobile/search'
  | 'GET /mobile/single-product'
  | 'GET /mobile/single-product-utility'
  | 'GET /mobile/store-labels'
  | 'GET /mobile/store-latest'
  | 'GET /mobile/styles-list'
  | 'GET /mobile/vendors-products'
  | 'GET /orders'
  | 'GET /orders/:id'
  | 'GET /products'
  | 'GET /products/:slug'
  | 'GET /products/:slug/recommendations'
  | 'GET /products/by-legacy-id/:id'
  | 'GET /products/facets'
  | 'GET /sitemap-data'
  | 'GET /utility/categories'
  | 'GET /utility/collections'
  | 'GET /utility/stores'
  | 'GET /vendor/analytics'
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
  | 'GET /vendors/:slug/products'
  | 'GET /vendors/by-legacy-id/:id'
  | 'GET /vendors/by-legacy-id/:id/products'
  | 'GET /wishlist'
  | 'PATCH /admin/orders/:id/status'
  | 'PATCH /admin/orders/:orderId/items/:itemId/status'
  | 'PATCH /admin/tickets/:id/priority'
  | 'PATCH /admin/tickets/:id/status'
  | 'PATCH /admin/users/:id/password'
  | 'PATCH /cart/items/:id'
  | 'PATCH /me/addresses/:id/default'
  | 'PATCH /me/billing-address'
  | 'PATCH /me/location'
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
  | 'POST /admin/brands'
  | 'POST /admin/categories'
  | 'POST /admin/collections'
  | 'POST /admin/notifications'
  | 'POST /admin/notifications/mark-read'
  | 'POST /admin/orders/:id/cancel'
  | 'POST /admin/orders/:id/refund'
  | 'POST /admin/products'
  | 'POST /admin/tickets/:id/messages'
  | 'POST /admin/users'
  | 'POST /admin/users/:id/activate'
  | 'POST /admin/users/:id/deactivate'
  | 'POST /admin/vendors'
  | 'POST /admin/vendors/:id/approve'
  | 'POST /admin/vendors/:id/messages'
  | 'POST /admin/vendors/:id/reactivate'
  | 'POST /admin/vendors/:id/suspend'
  | 'POST /auth/confirm'
  | 'POST /auth/login'
  | 'POST /auth/logout'
  | 'POST /auth/logout-all'
  | 'POST /auth/refresh'
  | 'POST /auth/register'
  | 'POST /auth/reset'
  | 'POST /auth/reset/confirm'
  | 'POST /auth/send-otp'
  | 'POST /auth/validate-email'
  | 'POST /auth/validate-phone'
  | 'POST /cart/items'
  | 'POST /cart/merge'
  | 'POST /checkout/initiate'
  | 'POST /gift-cards/:id/activate'
  | 'POST /gift-cards/purchase'
  | 'POST /gift-cards/redeem'
  | 'POST /me/addresses'
  | 'POST /me/device-tokens'
  | 'POST /me/measurements'
  | 'POST /me/wishlist'
  | 'POST /me/wishlist/labels'
  | 'POST /orders/:id/cancel'
  | 'POST /payment/webhook/noon'
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
  | 'POST /wishlist'
  | 'PUT /admin/brands/:id'
  | 'PUT /admin/categories/:id'
  | 'PUT /admin/collections/:id'
  | 'PUT /admin/products/:id'
  | 'PUT /admin/vendors/:id'
  | 'PUT /me/addresses/:id'
  | 'PUT /me/measurements/:id'
  | 'PUT /vendor/coupons/:id'
  | 'PUT /vendor/labels/:id'
  | 'PUT /vendor/measurements/:id'
  | 'PUT /vendor/products/:id';

/** Runtime set of valid route keys (frozen). */
export const V3_ROUTE_KEYS: ReadonlySet<V3RouteKey> = new Set([
  'DELETE /admin/brands/:id',
  'DELETE /admin/categories/:id',
  'DELETE /admin/collections/:id',
  'DELETE /admin/products/:id',
  'DELETE /admin/vendors/:id',
  'DELETE /cart/items/:id',
  'DELETE /me/addresses/:id',
  'DELETE /me/device-tokens',
  'DELETE /me/measurements/:id',
  'DELETE /me/wishlist/:productId',
  'DELETE /me/wishlist/labels/:id',
  'DELETE /vendor/coupons/:id',
  'DELETE /vendor/labels/:id',
  'DELETE /vendor/measurements/:id',
  'DELETE /vendor/products/:id',
  'DELETE /wishlist/:productId',
  'GET /admin/analytics',
  'GET /admin/brands',
  'GET /admin/categories',
  'GET /admin/collections',
  'GET /admin/collections/:id',
  'GET /admin/commissions',
  'GET /admin/customers',
  'GET /admin/notifications',
  'GET /admin/orders',
  'GET /admin/orders/:id',
  'GET /admin/orders/:id/timeline',
  'GET /admin/products',
  'GET /admin/returns',
  'GET /admin/tickets',
  'GET /admin/tickets/:id',
  'GET /admin/tickets/:id/messages',
  'GET /admin/transactions',
  'GET /admin/users',
  'GET /admin/users/:id',
  'GET /admin/vendor-metrics',
  'GET /admin/vendors',
  'GET /admin/vendors/:id',
  'GET /admin/vendors/:id/analytics',
  'GET /admin/vendors/:id/metrics',
  'GET /auth/me',
  'GET /cart',
  'GET /categories',
  'GET /categories/:slug',
  'GET /checkout/status/:order_reference',
  'GET /featured-vendors',
  'GET /gift-cards/balance',
  'GET /gift-cards/mine',
  'GET /gift-cards/themes',
  'GET /health',
  'GET /me/addresses',
  'GET /me/addresses/:id',
  'GET /me/billing-address',
  'GET /me/measurements',
  'GET /me/profile',
  'GET /me/recommendations',
  'GET /me/wishlist',
  'GET /me/wishlist/labels',
  'GET /mobile/best-sellers',
  'GET /mobile/best-sellers-listing',
  'GET /mobile/category-listing',
  'GET /mobile/explore-listing',
  'GET /mobile/featured',
  'GET /mobile/new-arrivals',
  'GET /mobile/new-arrivals-listing',
  'GET /mobile/products-by-labels',
  'GET /mobile/read-vendor',
  'GET /mobile/search',
  'GET /mobile/single-product',
  'GET /mobile/single-product-utility',
  'GET /mobile/store-labels',
  'GET /mobile/store-latest',
  'GET /mobile/styles-list',
  'GET /mobile/vendors-products',
  'GET /orders',
  'GET /orders/:id',
  'GET /products',
  'GET /products/:slug',
  'GET /products/:slug/recommendations',
  'GET /products/by-legacy-id/:id',
  'GET /products/facets',
  'GET /sitemap-data',
  'GET /utility/categories',
  'GET /utility/collections',
  'GET /utility/stores',
  'GET /vendor/analytics',
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
  'GET /vendors/:slug/products',
  'GET /vendors/by-legacy-id/:id',
  'GET /vendors/by-legacy-id/:id/products',
  'GET /wishlist',
  'PATCH /admin/orders/:id/status',
  'PATCH /admin/orders/:orderId/items/:itemId/status',
  'PATCH /admin/tickets/:id/priority',
  'PATCH /admin/tickets/:id/status',
  'PATCH /admin/users/:id/password',
  'PATCH /cart/items/:id',
  'PATCH /me/addresses/:id/default',
  'PATCH /me/billing-address',
  'PATCH /me/location',
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
  'POST /admin/brands',
  'POST /admin/categories',
  'POST /admin/collections',
  'POST /admin/notifications',
  'POST /admin/notifications/mark-read',
  'POST /admin/orders/:id/cancel',
  'POST /admin/orders/:id/refund',
  'POST /admin/products',
  'POST /admin/tickets/:id/messages',
  'POST /admin/users',
  'POST /admin/users/:id/activate',
  'POST /admin/users/:id/deactivate',
  'POST /admin/vendors',
  'POST /admin/vendors/:id/approve',
  'POST /admin/vendors/:id/messages',
  'POST /admin/vendors/:id/reactivate',
  'POST /admin/vendors/:id/suspend',
  'POST /auth/confirm',
  'POST /auth/login',
  'POST /auth/logout',
  'POST /auth/logout-all',
  'POST /auth/refresh',
  'POST /auth/register',
  'POST /auth/reset',
  'POST /auth/reset/confirm',
  'POST /auth/send-otp',
  'POST /auth/validate-email',
  'POST /auth/validate-phone',
  'POST /cart/items',
  'POST /cart/merge',
  'POST /checkout/initiate',
  'POST /gift-cards/:id/activate',
  'POST /gift-cards/purchase',
  'POST /gift-cards/redeem',
  'POST /me/addresses',
  'POST /me/device-tokens',
  'POST /me/measurements',
  'POST /me/wishlist',
  'POST /me/wishlist/labels',
  'POST /orders/:id/cancel',
  'POST /payment/webhook/noon',
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
  'POST /wishlist',
  'PUT /admin/brands/:id',
  'PUT /admin/categories/:id',
  'PUT /admin/collections/:id',
  'PUT /admin/products/:id',
  'PUT /admin/vendors/:id',
  'PUT /me/addresses/:id',
  'PUT /me/measurements/:id',
  'PUT /vendor/coupons/:id',
  'PUT /vendor/labels/:id',
  'PUT /vendor/measurements/:id',
  'PUT /vendor/products/:id',
] as const);

/** Type guard: is an arbitrary string a known route key? */
export function isV3RouteKey(key: string): key is V3RouteKey {
  return (V3_ROUTE_KEYS as ReadonlySet<string>).has(key);
}
