/**
 * 3bayti API endpoints — auto-generated from the mobile app source.
 *
 * Single source of truth for endpoint paths. Both the mobile app
 * (Capacitor/Ionic) and the web app (Angular Universal SSR) consume
 * from this constant.
 *
 * To regenerate: re-run the API scan against the latest mobile app code.
 * DO NOT hand-edit unless you understand the regen flow.
 */

export const API_BASE_URL = 'https://api.3bayti.ae/';
export const TOPEX_BASE_URL = 'https://shipperapi.topex.ae/api/CommonAPI/';

export const API_ENDPOINTS = {
  // ----- Auth & User -----
  UserConfirm: API_BASE_URL + 'users/confirm',
  UserLogin: API_BASE_URL + 'users/login',
  UserRegister: API_BASE_URL + 'users/register',
  UserReset: API_BASE_URL + 'users/resetMobile',
  sendOOTP: API_BASE_URL + 'users/sendOTP',
  UserValidate: API_BASE_URL + 'users/validate',
  EmailValidate: API_BASE_URL + 'users/validate-email',

  // ----- Cart -----
  IncreaseItem: API_BASE_URL + 'customer/IncreaseItem',
  addToCart: API_BASE_URL + 'customer/addToCart',
  DecreaseItem: API_BASE_URL + 'customer/decreaseItem',
  customerCart: API_BASE_URL + 'customer/read-cart',
  RemoveCartItem: API_BASE_URL + 'customer/removeFromCart',

  // ----- Chat (separate API) -----
  chat_get_conversation: API_BASE_URL + 'chat/get_conversation',
  chat_get_messages: API_BASE_URL + 'chat/get_messages',
  chat_get_prompts: API_BASE_URL + 'chat/get_prompts',
  chat_get_unread_count: API_BASE_URL + 'chat/get_unread_count',
  chat_get_vendor_conversations: API_BASE_URL + 'chat/get_vendor_conversations.php',
  chat_get_vendor_orders: API_BASE_URL + 'chat/get_vendor_orders',
  chat_get_vendors: API_BASE_URL + 'chat/get_vendors',
  chat_mark_read: API_BASE_URL + 'chat/mark_read',
  chat_send_message: API_BASE_URL + 'chat/send_message',
  chat_upload_image: API_BASE_URL + 'chat/upload_image',

  // ----- Customer Browse & Catalog -----
  best_sellers: API_BASE_URL + 'customer/best_sellers',
  best_sellers_listing: API_BASE_URL + 'customer/best_sellers_listing',
  ProductCategory: API_BASE_URL + 'customer/category',
  category_listing: API_BASE_URL + 'customer/category_listing',
  explore: API_BASE_URL + 'customer/explore',
  explore_listing: API_BASE_URL + 'customer/explore_listing',
  featured: API_BASE_URL + 'customer/featured',
  filtered_products: API_BASE_URL + 'customer/filter_product',
  filterexplore: API_BASE_URL + 'customer/filterexplore',
  filterfeatured: API_BASE_URL + 'customer/filterfeatured',
  getToken: API_BASE_URL + 'customer/getToken',
  make_helpful: API_BASE_URL + 'customer/helpful',
  new_arrivals: API_BASE_URL + 'customer/new_arrivals',
  new_arrivals_listing: API_BASE_URL + 'customer/new_arrivals_listing',
  product_by_category: API_BASE_URL + 'customer/product_by_category',
  products_by_labels: API_BASE_URL + 'customer/products_by_labels',
  readConversations: API_BASE_URL + 'customer/read-conversations',
  readMessages: API_BASE_URL + 'customer/read-messages',
  search: API_BASE_URL + 'customer/search',
  sendMessage: API_BASE_URL + 'customer/send-message',
  singleProduct: API_BASE_URL + 'customer/singleProduct',
  single_product: API_BASE_URL + 'customer/single_product',

  // ----- Customer Messaging & Support -----
  createTicket: API_BASE_URL + 'customer/create_ticket',
  readTicketMessages: API_BASE_URL + 'customer/read-ticket-messages',
  readTicket: API_BASE_URL + 'customer/read_ticket',
  sendTicketMessage: API_BASE_URL + 'customer/send-ticket-message',

  // ----- Customer Orders -----
  readCustomerOrders: API_BASE_URL + 'customer/read-customer-orders',
  orderDetails: API_BASE_URL + 'customer/read-order-details',
  customerOrder: API_BASE_URL + 'customer/read-orders',
  read_orders_listing: API_BASE_URL + 'customer/read_orders_listing',

  // ----- Customer Settings -----
  readBilling: API_BASE_URL + 'customer/settings/billing/read-billings',
  updateBilling: API_BASE_URL + 'customer/settings/billing/update-billing',
  deleteReview: API_BASE_URL + 'customer/settings/delete-review',
  readMeasurement: API_BASE_URL + 'customer/settings/measurement/read-measurement',
  updateMeasurement: API_BASE_URL + 'customer/settings/measurement/update-measurement',
  readProfile: API_BASE_URL + 'customer/settings/read-profile',
  readReviews: API_BASE_URL + 'customer/settings/read-reviews',
  storeReviews: API_BASE_URL + 'customer/settings/store-reviews',
  UpdateLocation: API_BASE_URL + 'customer/settings/update-location',
  updateProfile: API_BASE_URL + 'customer/settings/update-profile',

  // ----- Payment & OTP -----
  finalizePayment: API_BASE_URL + 'customer/finalize_payment',
  initiatePayment: API_BASE_URL + 'customer/payment/initiate_payment',
  sendOTP: API_BASE_URL + 'customer/sendOTP',
  validateOTP: API_BASE_URL + 'customer/validateOTP',

  // ----- Reviews -----
  add_review: API_BASE_URL + 'customer/add-review',
  store_reviews: API_BASE_URL + 'customer/store-reviews',

  // ----- Storefront & Vendors -----
  follow_vendor: API_BASE_URL + 'customer/follow',
  read_vendor: API_BASE_URL + 'customer/read-vendor',
  store_labels: API_BASE_URL + 'customer/read_vendor_collection',
  store_latest: API_BASE_URL + 'customer/store_latest',
  unfollow_vendor: API_BASE_URL + 'customer/unfollow',
  vendors_listing: API_BASE_URL + 'customer/vendors_list',
  vendors_products_listing: API_BASE_URL + 'customer/vendors_products',

  // ----- Style Hub -----
  create_style: API_BASE_URL + 'customer/create_style',
  styles_list: API_BASE_URL + 'customer/styles_list',

  // ----- Utility (unauthenticated) -----
  best_sellersUtility: API_BASE_URL + 'utility/best_sellers',
  featuredUtility: API_BASE_URL + 'utility/featured',
  product_by_categoryUtility: API_BASE_URL + 'utility/product_by_category',
  singleProductUtility: API_BASE_URL + 'utility/singleProduct',

  // ----- Vendor Dashboard -----
  vendor_dashboard: API_BASE_URL + 'vendors/dashboard',
  vendor_get_stats: API_BASE_URL + 'vendors/get_stats.php',
  readStoreMeasurement: API_BASE_URL + 'vendors/measurement/get-measurements',
  vendor_toggle_status: API_BASE_URL + 'vendors/toggle_status',
  vendor_add_product: API_BASE_URL + 'vendor/add_product.php',
  vendor_delete_product: API_BASE_URL + 'vendor/delete_product.php',
  vendor_get_earnings: API_BASE_URL + 'vendor/get_earnings.php',
  vendor_get_orders: API_BASE_URL + 'vendor/get_orders.php',
  vendor_get_products: API_BASE_URL + 'vendor/get_products.php',
  vendor_get_reviews: API_BASE_URL + 'vendor/get_reviews.php',
  vendor_request_payout: API_BASE_URL + 'vendor/request_payout.php',
  vendor_respond_review: API_BASE_URL + 'vendor/respond_review.php',
  vendor_update_order_status: API_BASE_URL + 'vendor/update_order_status.php',
  vendor_update_product: API_BASE_URL + 'vendor/update_product.php',
  vendor_update_profile: API_BASE_URL + 'vendor/update_profile.php',
  vendor_update_settings: API_BASE_URL + 'vendor/update_settings.php',

  // ----- Wishlist -----
  addWishlist: API_BASE_URL + 'customer/add_wishlist',
  addWishlistLabel: API_BASE_URL + 'customer/add_wishlist_label',
  readWishlist: API_BASE_URL + 'customer/read_wishlist',
  readWishlistLabel: API_BASE_URL + 'customer/read_wishlist_label',

} as const;

export type ApiEndpointName = keyof typeof API_ENDPOINTS;