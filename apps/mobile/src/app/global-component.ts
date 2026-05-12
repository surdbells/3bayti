import {ReplaySubject} from "rxjs";

export class GlobalComponent {
  constructor() {}
  public static baseURL = 'https://api.3bayti.ae/' // test
  public static topexCitiesURL = 'https://shipperapi.topex.ae/api/CommonAPI/Cities?countryID=1' //
  public static topexAreaURL = 'https://shipperapi.topex.ae/api/CommonAPI/Areas?acityID=' //

  /* POST REQUEST */
  public static UserLogin = GlobalComponent.baseURL + 'users/login';
  public static UserRegister = GlobalComponent.baseURL + 'users/register';
  public static UserReset = GlobalComponent.baseURL + 'users/resetMobile';
  public static UserValidate = GlobalComponent.baseURL + 'users/validate';
  public static EmailValidate = GlobalComponent.baseURL + 'users/validate-email';
  public static ProductCategory = GlobalComponent.baseURL + 'customer/category';
  public static topexCities = GlobalComponent.topexCitiesURL;
  public static UserConfirm = GlobalComponent.baseURL + 'users/confirm';
  public static UpdateLocation = GlobalComponent.baseURL + 'customer/settings/update-location';

  // Measurement
  public static readMeasurement = GlobalComponent.baseURL + 'customer/settings/measurement/read-measurement';
  public static readStoreMeasurement = GlobalComponent.baseURL + 'vendors/measurement/get-measurements';
  public static updateMeasurement = GlobalComponent.baseURL + 'customer/settings/measurement/update-measurement';

  public static readBilling = GlobalComponent.baseURL + 'customer/settings/billing/read-billings';
  public static updateBilling = GlobalComponent.baseURL + 'customer/settings/billing/update-billing';
  public static readReviews = GlobalComponent.baseURL + 'customer/settings/read-reviews';
  public static storeReviews = GlobalComponent.baseURL + 'customer/settings/store-reviews';
  public static deleteReview = GlobalComponent.baseURL + 'customer/settings/delete-review';
  public static readProfile = GlobalComponent.baseURL + 'customer/settings/read-profile';
  public static updateProfile = GlobalComponent.baseURL + 'customer/settings/update-profile';

  public static readWishlist = GlobalComponent.baseURL + 'customer/read_wishlist';
  public static readWishlistLabel = GlobalComponent.baseURL + 'customer/read_wishlist_label';
  public static addWishlistLabel = GlobalComponent.baseURL + 'customer/add_wishlist_label';
  public static addWishlist = GlobalComponent.baseURL + 'customer/add_wishlist';
  public static addToCart = GlobalComponent.baseURL + 'customer/addToCart';

  public static filtered_products = GlobalComponent.baseURL + 'customer/filter_product';
  public static filterexplore = GlobalComponent.baseURL + 'customer/filterexplore';
  public static best_sellers = GlobalComponent.baseURL + 'customer/best_sellers';
  public static best_sellers_listing = GlobalComponent.baseURL + 'customer/best_sellers_listing';
  public static category_listing = GlobalComponent.baseURL + 'customer/category_listing';
  public static new_arrivals = GlobalComponent.baseURL + 'customer/new_arrivals';
  public static new_arrivals_listing = GlobalComponent.baseURL + 'customer/new_arrivals_listing';
  public static customerCart = GlobalComponent.baseURL + 'customer/read-cart';
  public static customerOrder = GlobalComponent.baseURL + 'customer/read-orders';
  public static orderDetails = GlobalComponent.baseURL + 'customer/read-order-details';
  public static RemoveCartItem = GlobalComponent.baseURL + 'customer/removeFromCart';
  public static IncreaseItem = GlobalComponent.baseURL + 'customer/IncreaseItem';
  public static DecreaseItem = GlobalComponent.baseURL + 'customer/decreaseItem';
  public static explore = GlobalComponent.baseURL + 'customer/explore';
  public static explore_listing = GlobalComponent.baseURL + 'customer/explore_listing';
  public static featured = GlobalComponent.baseURL + 'customer/featured';
  public static filterfeatured = GlobalComponent.baseURL + 'customer/filterfeatured';
  public static product_by_category = GlobalComponent.baseURL + 'customer/product_by_category';
  public static products_by_labels = GlobalComponent.baseURL + 'customer/products_by_labels';
  public static search = GlobalComponent.baseURL + 'customer/search';
  public static store_labels = GlobalComponent.baseURL + 'customer/read_vendor_collection';
  public static store_latest = GlobalComponent.baseURL + 'customer/store_latest';
  public static read_vendor = GlobalComponent.baseURL + 'customer/read-vendor';
  public static follow_vendor = GlobalComponent.baseURL + 'customer/follow';
  public static unfollow_vendor = GlobalComponent.baseURL + 'customer/unfollow';
  public static singleProduct = GlobalComponent.baseURL + 'customer/singleProduct';
  public static single_product = GlobalComponent.baseURL + 'customer/single_product';
  public static readConversations = GlobalComponent.baseURL + 'customer/read-conversations';
  public static readMessages = GlobalComponent.baseURL + 'customer/read-messages';
  public static readCustomerOrders = GlobalComponent.baseURL + 'customer/read-customer-orders';
  public static sendMessage = GlobalComponent.baseURL + 'customer/send-message';

  public static createTicket = GlobalComponent.baseURL + 'customer/create_ticket';
  public static readTicket = GlobalComponent.baseURL + 'customer/read_ticket';
  public static readTicketMessages = GlobalComponent.baseURL + 'customer/read-ticket-messages';
  public static sendTicketMessage = GlobalComponent.baseURL + 'customer/send-ticket-message';

  public static store_reviews = GlobalComponent.baseURL + 'customer/store-reviews';
  public static add_review = GlobalComponent.baseURL + 'customer/add-review';
  public static make_helpful = GlobalComponent.baseURL + 'customer/helpful';
  public static vendors_listing = GlobalComponent.baseURL + 'customer/vendors_list';
  public static styles_list = GlobalComponent.baseURL + 'customer/styles_list';
  public static create_style = GlobalComponent.baseURL + 'customer/create_style';
  public static vendors_products_listing = GlobalComponent.baseURL + 'customer/vendors_products';
  public static read_orders_listing = GlobalComponent.baseURL + 'customer/read_orders_listing';


  // ordering process

  public static initiatePayment = GlobalComponent.baseURL + 'customer/payment/initiate_payment';
  public static finalizePayment = GlobalComponent.baseURL + 'customer/finalize_payment';
  public static getToken = GlobalComponent.baseURL + 'customer/getToken';
  public static sendOTP = GlobalComponent.baseURL + 'customer/sendOTP';
  public static sendOOTP = GlobalComponent.baseURL + 'users/sendOTP';
  public static validateOTP = GlobalComponent.baseURL + 'customer/validateOTP';


  public static featuredUtility = GlobalComponent.baseURL + 'utility/featured';
  public static best_sellersUtility = GlobalComponent.baseURL + 'utility/best_sellers';
  public static singleProductUtility = GlobalComponent.baseURL + 'utility/singleProduct';
  public static product_by_categoryUtility = GlobalComponent.baseURL + 'utility/product_by_category';


  public static chat_get_vendors = GlobalComponent.baseURL + 'chat/get_vendors';
  public static chat_get_vendor_orders = GlobalComponent.baseURL + 'chat/get_vendor_orders';
  public static chat_get_conversation = GlobalComponent.baseURL + 'chat/get_conversation';
  public static chat_get_messages = GlobalComponent.baseURL + 'chat/get_messages';
  public static chat_send_message = GlobalComponent.baseURL + 'chat/send_message';
  public static chat_upload_image = GlobalComponent.baseURL + 'chat/upload_image';
  public static chat_get_prompts = GlobalComponent.baseURL + 'chat/get_prompts';
  public static chat_mark_read = GlobalComponent.baseURL + 'chat/mark_read';
  public static chat_get_unread_count = GlobalComponent.baseURL + 'chat/get_unread_count';
  public static chat_get_vendor_conversations = GlobalComponent.baseURL + 'chat/get_vendor_conversations.php';

  // VENDOR
  // ========================================
  // VENDOR DASHBOARD ENDPOINTS
  // ========================================

  // Get vendor dashboard data (store info + stats)
  public static vendor_dashboard =  GlobalComponent.baseURL + 'vendors/dashboard';
  // Toggle store active/inactive status
  public static vendor_toggle_status = GlobalComponent.baseURL + 'vendors/toggle_status';
  // Get detailed vendor statistics
  public static vendor_get_stats = GlobalComponent.baseURL + 'vendors/get_stats.php';
  // Get vendor orders list
  public static vendor_get_orders = 'vendor/get_orders.php';
  // Update order status
  public static vendor_update_order_status = 'vendor/update_order_status.php';
  // Get vendor products list
  public static vendor_get_products = 'vendor/get_products.php';
  // Add new product
  public static vendor_add_product = 'vendor/add_product.php';
  // Update existing product
  public static vendor_update_product = 'vendor/update_product.php';
  // Delete product
  public static vendor_delete_product = 'vendor/delete_product.php';
  // Get earnings and payout history
  public static vendor_get_earnings = 'vendor/get_earnings.php';
  // Request payout
  public static vendor_request_payout = 'vendor/request_payout.php';
  // Get customer reviews
  public static vendor_get_reviews = 'vendor/get_reviews.php';
  // Respond to a review
  public static vendor_respond_review = 'vendor/respond_review.php';
  // Update store profile
  public static vendor_update_profile = 'vendor/update_profile.php';
  // Update store settings
  public static vendor_update_settings = 'vendor/update_settings.php';





  static validateEmail(email: string) {
    return !!email.match(/(?:[a-z0-9+!#$%&'*/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+/=?^_`{|}~-]+)*|"(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])*")@(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?|[a-z0-9-]*[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)])/gi);
  }
  static validateNumber(number: string){
    let numbers = /^[0-9]+$/;
    return !!number.match(numbers);
  }

  static convertFile(file : File) {
    const result = new ReplaySubject<string>(1);
    const reader = new FileReader();
    reader.readAsDataURL(file);
    reader.onload = (event: any) => result.next(btoa(event.target.result.toString()));
    return result;
  }
  public static generateTransactionReference(prefix: string = 'TRN'): string {
    const timestamp = Date.now();
    const randomString = Math.random().toString(36).substring(2, 8); // 6 characters for randomness
    return `${prefix}-${timestamp}-${randomString}`.toUpperCase();
  }
  public static generateTransactionReceipt(prefix: string = 'RCPT'): string {
    const timestamp = Date.now();
    const randomString = Math.random().toString(36).substring(2, 8); // 6 characters for randomness
    return `${prefix}-${timestamp}-${randomString}`.toUpperCase();
  }
}
