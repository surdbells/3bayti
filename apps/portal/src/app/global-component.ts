export class GlobalComponent {

  constructor() {}
  public static baseURL = 'https://api.3bayti.ae/' // test

  public static getStoreOrders = GlobalComponent.baseURL + 'admin/common/get-store-orders';
  public static getStoreOrdersByStatus = GlobalComponent.baseURL + 'admin/common/getStoreOrdersByStatus';
  public static getAdminProducts = GlobalComponent.baseURL + 'admin/common/products';
  public static messageVendor = GlobalComponent.baseURL + 'admin/message-vendor';
  public static sales = GlobalComponent.baseURL + 'admin/common/sales';
  public static processing = GlobalComponent.baseURL + 'admin/common/processing';
  public static processingById = GlobalComponent.baseURL + 'admin/common/processingById';
  public static pluralById = GlobalComponent.baseURL + 'admin/common/pluralById';
  public static productsByProcessingId = GlobalComponent.baseURL + 'admin/common/productsByProcessingId';
  public static productsByVendorId = GlobalComponent.baseURL + 'admin/common/productsByVendorId';
  public static logistics = GlobalComponent.baseURL + 'admin/common/logistics';
  public static commissions = GlobalComponent.baseURL + 'admin/common/commissions';
  public static transactions = GlobalComponent.baseURL + 'admin/common/transactions';
  public static tickets = GlobalComponent.baseURL + 'admin/common/tickets';
  public static ticketsMessages = GlobalComponent.baseURL + 'admin/common/ticket-messages';
  public static sendTicketMessage = GlobalComponent.baseURL + 'admin/common/send-ticket-message';
  public static ticketsStatus = GlobalComponent.baseURL + 'admin/common/ticket-status';
  public static ticketsPriority = GlobalComponent.baseURL + 'admin/common/ticket-priority';
  public static AdminUserRegister = GlobalComponent.baseURL + 'admin/common/register';
  public static AdminUserPassword = GlobalComponent.baseURL + 'admin/common/password';


  // POST REQUEST
   public static UserLogin = GlobalComponent.baseURL + 'users/login';
   public static UserRegister = GlobalComponent.baseURL + 'users/register';
   public static UserValidate = GlobalComponent.baseURL + 'users/validate';
   public static EmailValidate = GlobalComponent.baseURL + 'users/validate-email';
   public static UserReset = GlobalComponent.baseURL + 'users/reset';
   public static UserConfirm = GlobalComponent.baseURL + 'users/confirm';
   public static UserResetPassword = GlobalComponent.baseURL + 'users/reset';
   public static UserSettings = GlobalComponent.baseURL + 'users/settings';

   public static createLabel = GlobalComponent.baseURL + 'vendors/labels/create-label';
   public static updateLabel = GlobalComponent.baseURL + 'vendors/labels/update-label';
   public static deleteLabel = GlobalComponent.baseURL + 'vendors/labels/delete-label';
   public static readLabel = GlobalComponent.baseURL + 'vendors/labels/read-label';

   public static getVendorOrders = GlobalComponent.baseURL + 'vendors/orders/get-orders';
   public static getVendorOrdersByStatus = GlobalComponent.baseURL + 'vendors/orders/get-orders-byStatus';
   public static getVendorReturnOrders = GlobalComponent.baseURL + 'vendors/orders/get-return-orders';
   public static getVendorDeliveryOrders = GlobalComponent.baseURL + 'vendors/orders/get-ready-orders';
   public static getOrderItems = GlobalComponent.baseURL + 'vendors/orders/get-order-items';
   public static updateOrderStatus =  GlobalComponent.baseURL + 'vendors/orders/update-order-status';

   public static getProductById = GlobalComponent.baseURL + 'vendors/products/getProductById';
   public static deleteProductById = GlobalComponent.baseURL + 'vendors/products/delete-product';
  public static getProduct = GlobalComponent.baseURL + 'vendors/products/get-products';
  public static createProduct = GlobalComponent.baseURL + 'vendors/products/create-product';
  public static updateProduct = GlobalComponent.baseURL + 'vendors/products/update-product';
  public static getProductReviews = GlobalComponent.baseURL + 'vendors/products/get-products-reviews';

  public static createCollection = GlobalComponent.baseURL + 'admin/collections/create-collection';
  public static updateCollection = GlobalComponent.baseURL + 'admin/collections/update-collection';
  public static getCollection = GlobalComponent.baseURL + 'admin/collections/get-collection';
  public static readCollection = GlobalComponent.baseURL + 'admin/collections/read-collection';

  public static activateCustomer = GlobalComponent.baseURL + 'admin/common/activate-customer';
  public static deactivateCustomer = GlobalComponent.baseURL + 'admin/common/deactivate-customer';

  public static activateStore = GlobalComponent.baseURL + 'admin/common/activate-store';
  public static deactivateStore = GlobalComponent.baseURL + 'admin/common/deactivate-store';
  public static deleteStore = GlobalComponent.baseURL + 'admin/common/delete-store';
  public static getSingleStore = GlobalComponent.baseURL + 'admin/common/getSingleStore';
  public static getProductSales = GlobalComponent.baseURL + 'admin/common/get-sales';


   public static getNotifications = GlobalComponent.baseURL + 'vendors/common/notifications';
   public static markNotifications = GlobalComponent.baseURL + 'vendors/common/mark_notifications';
  public static getVendorStats = GlobalComponent.baseURL + 'vendors/common/dashboard-activity';
  public static getAdminStats = GlobalComponent.baseURL + 'admin/common/dashboard-activity';
  public static getCompliance = GlobalComponent.baseURL + 'vendors/common/compliance';


   public static getUserProfile = GlobalComponent.baseURL + 'utility/shared/user';
   public static updateUserPassword = GlobalComponent.baseURL + 'utility/shared/change-user-password';
   public static getVendorStore = GlobalComponent.baseURL + 'vendors/settings/vendor-store';
   public static getVendorPayment = GlobalComponent.baseURL + 'vendors/settings/vendor-store-payment';

   public static getVendorTax = GlobalComponent.baseURL + 'vendors/settings/vendor-store-tax';
   public static getVendorNotifications = GlobalComponent.baseURL + 'vendors/settings/vendor-store-notifications';
   public static topSelling = GlobalComponent.baseURL + 'vendors/common/top-selling';
   public static topAdminSelling = GlobalComponent.baseURL + 'admin/common/top-selling';
   public static getCustomers = GlobalComponent.baseURL + 'admin/common/get-customers';
   public static getStores = GlobalComponent.baseURL + 'admin/common/get-stores';
   public static updateUserProfile = GlobalComponent.baseURL + 'vendors/settings/update-user-basic';
   public static updateStoreBasic = GlobalComponent.baseURL + 'vendors/settings/update-vendor-store';
   public static updateStorePayment = GlobalComponent.baseURL + 'vendors/settings/update-vendor-payment';
   public static updateStoreTax = GlobalComponent.baseURL + 'vendors/settings/update-vendor-tax';
   public static updateStoreNotifications = GlobalComponent.baseURL + 'vendors/settings/update-vendor-notifications';
   public static updateStoreStatus = GlobalComponent.baseURL + 'vendors/settings/switch-store-status';
   public static updateCompliance = GlobalComponent.baseURL + 'vendors/settings/update-compliance';

   // GET REQUEST
    public static UtilityCategory = GlobalComponent.baseURL + 'utility/category';
    public static UtilityCollections = GlobalComponent.baseURL + 'utility/collections';

  // MEASUREMENTS
  public static createMeasurement = GlobalComponent.baseURL + 'vendors/measurement/create-measurement';
  public static updateMeasurement = GlobalComponent.baseURL + 'vendors/measurement/update-measurement';
  public static readMeasurement = GlobalComponent.baseURL + 'vendors/measurement/get-measurements';
  public static deleteMeasurement = GlobalComponent.baseURL + 'vendors/measurement/delete-measurement';
  public static getMeasurementById = GlobalComponent.baseURL + 'vendors/measurement/getMeasurementById';

  public static getOrderById = GlobalComponent.baseURL + 'vendors/orders/getOrderById';
  public static getUsers = GlobalComponent.baseURL + 'admin/common/get-users';

  public static UtilityStores= GlobalComponent.baseURL + 'utility/stores';
  public static createCoupon= GlobalComponent.baseURL + 'vendors/coupons/create-coupon';
  public static getCouponById= GlobalComponent.baseURL + 'vendors/coupons/get-coupon-by-id';
  public static couponAnalytics= GlobalComponent.baseURL + 'vendors/coupons/coupon-analytics';
  public static getCoupons= GlobalComponent.baseURL + 'vendors/coupons/get-coupons';
  public static toggleCouponStatus= GlobalComponent.baseURL + 'vendors/coupons/toggle-coupon-status';
  public static deleteCoupon= GlobalComponent.baseURL + 'vendors/coupons/delete-coupon';
  public static updateCoupon= GlobalComponent.baseURL + 'vendors/coupons/update-coupon';
  public static validateCoupon= GlobalComponent.baseURL + 'vendors/coupons/validate-coupon';
  public static applyCoupon= GlobalComponent.baseURL + 'vendors/coupons/apply-coupon';




  static validateEmail(email: string) {
    return !!email.match(/(?:[a-z0-9+!#$%&'*\/=?^_`{|}~-]+(?:\.[a-z0-9!#$%&'*+\/=?^_`{|}~-]+)*|"(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21\x23-\x5b\x5d-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])*")@(?:(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z0-9](?:[a-z0-9-]*[a-z0-9])?|\[(?:(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?)\.){3}(?:25[0-5]|2[0-4][0-9]|[01]?[0-9][0-9]?|[a-z0-9-]*[a-z0-9]:(?:[\x01-\x08\x0b\x0c\x0e-\x1f\x21-\x5a\x53-\x7f]|\\[\x01-\x09\x0b\x0c\x0e-\x7f])+)])/gi);
  }
  static validateNumber(number: string){
    let numbers = /^[0-9]+$/;
    return !!number.match(numbers);
  }
  static decodeJWT(token: string): any {
    const base64Url = token.split('.')[1];
    const base64 = base64Url.replace(/-/g, '+').replace(/_/g, '/');
    // Decode base64
    const jsonPayload = decodeURIComponent(
      atob(base64)
        .split('')
        .map(c => '%' + ('00' + c.charCodeAt(0).toString(16)).slice(-2))
        .join('')
    );
    return JSON.parse(jsonPayload);
  }

   static encodeBase64(obj: any): string {
    const json = JSON.stringify(obj);
    const bytes = new TextEncoder().encode(json);
    let binary = '';
    bytes.forEach(b => binary += String.fromCharCode(b));
    return btoa(binary);
  }

  static decodeBase64<T = any>(base64: string): T {
    const binary = atob(base64);
    const bytes = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
      bytes[i] = binary.charCodeAt(i);
    }
    const json = new TextDecoder().decode(bytes);
    return JSON.parse(json) as T;
  }

}
