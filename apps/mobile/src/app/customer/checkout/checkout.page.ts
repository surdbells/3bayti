import {ChangeDetectorRef, Component, OnDestroy, OnInit, ViewChild} from '@angular/core';
import {DecimalPipe} from '@angular/common';
import {Cart} from "../../class/cart";
import {Labels} from "../../class/labels";
import {Subscription} from "rxjs";
import {
  IonAccordionGroup,
  IonButton,
  IonButtons,
  IonCard,
  IonCheckbox,
  IonCol,
  IonContent,
  IonFooter,
  IonHeader,
  IonItem,
  IonLabel,
  IonList,
  IonRefresher,
  IonRefresherContent,
  IonRow,
  IonText,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from "@ionic/angular/standalone";
import {ConnectionService} from "../../service/connection.service";
import {Router, RouterLink} from "@angular/router";
import {ActionSheetController} from "@ionic/angular";
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import { apiErrorMessage } from '../../core/http/api-error';
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import { I18nService } from '../../i18n.service';
import {InAppBrowser} from "@capgo/inappbrowser";
import {TranslatePipe} from "../../translate.pipe";
import {Billing} from "../../class/billing";

import {FormsModule} from "@angular/forms";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { AxPlaceAutocompleteComponent, PlaceDetails } from '../../shared/ax-mobile/place-autocomplete';
import { AddressService, SavedAddress, NewAddress } from '../../core/services/address.service';
import { ClipboardService } from '../../core/services/clipboard.service';
import { CheckoutActivityService } from '../../core/services/checkout-activity.service';
@Component({
  selector: 'app-checkout',
  templateUrl: './checkout.page.html',
  styleUrls: ['./checkout.page.scss'],
  imports: [
    IonItem,
    IonText,
    IonLabel,
    IonList,
    IonTitle,
    IonCard,
    IonContent,
    IonButton,
    IonButtons,
    IonCheckbox,
    IonToolbar,
    IonHeader,
    IonFooter,
    RouterLink,
    IonRefresher,
    IonRefresherContent,
    IonCol,
    IonRow,
    TranslatePipe,
    IonAccordionGroup,
    FormsModule,
    DecimalPipe,
    AxIconComponent,
    AxLoaderComponent,
    AxTextFieldComponent,
    AxBottomSheetComponent,
    AxPlaceAutocompleteComponent,
  ],
  standalone: true
})
export class CheckoutPage implements OnInit, OnDestroy {
  carts: Cart[] = [];
  billing: Billing[] = [];
  categories: Labels[] = [];
  isOnline = true;
  isConfirmBilling = false;
  /* Multistep flow (web parity): 1 = Address, 2 = Review. Payment is
     implicit, the Noon webview opens from step 2 on Place Order, so
     there is no explicit step-3 state. */
  step: 1 | 2 = 1;
  /* Z.2, saved-address book picker. */
  savedAddresses: SavedAddress[] = [];
  isAddressPickerOpen = false;
  selectedAddressId: number | null = null;
  isLoadingAddresses = false;

  /* Add-new-address bottom sheet (web parity, mirrors address-form.ts). */
  isAddAddressOpen = false;
  isSavingAddress = false;
  /* The 7 UAE emirates, same option set the inline form used. */
  readonly emirates: string[] = [
    'Abu Dhabi', 'Dubai', 'Sharjah', 'Ajman',
    'Umm Al-Quwain', 'Ras Al Khaimah', 'Fujairah',
  ];
  /* Field set matches web address-form.ts exactly. */
  newAddress = {
    label: '',
    recipient_name: '',
    recipient_phone: '',
    emirate: '',
    area: '',
    street_address: '',
    building_details: '',
    postal_code: '',
    is_default: false,
  };
  private sub: Subscription;
  @ViewChild('accordionGroup', { static: true }) accordionGroup!: IonAccordionGroup;
  @ViewChild(IonContent) content?: IonContent;
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private i18n: I18nService,
    private addressService: AddressService,
    private cdr: ChangeDetectorRef,
    private clipboard: ClipboardService,
    private checkoutActivity: CheckoutActivityService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  /** True once payment has been initiated, so we hold the OTA guard exactly
   *  once and release it when leaving checkout. */
  private otaGuardEngaged = false;
  ui_controls = {
    is_loading: false,
    is_creating: false,
    checking_out: false,
    is_loading_area: false,
    is_updating: false,
    is_loading_category: false,
    is_empty: false
  }
  rqst_param = {
    id: 0,
    token: ""
  }
  request = {
    id: 0,
    token: ""
  }
  remove = {
    id: 0,
    token: "",
    item: 0,
  }
  checkout_ready = {
    resultCode: 0,
    message: "",
    resultClass: 0,
    classDescription: "",
    actionHint: "",
    requestReference: "",
    result: {
      nextActions: "",
      order: {
        status: "",
        creationTime: "",
        errorCode: 0,
        id: 0,
        amount: 0,
        currency: "",
        name: "",
        reference: "",
        category: "",
        channel: ""
      },
      configuration: {
        tokenizeCc: false,
        locale: "",
        paymentAction: ""
      },
      business: {
        id: "",
        name: ""
      },
      checkoutData: {
        postUrl: "",
        jsUrl: ""
      },
      deviceFingerPrint: {
        sessionId: ""
      }
    }
  }
  single_user = {
    id: 0,
    token: "",
    first_name: "",
    last_name: "",
    user_type: "",
    email: "",
    phone: "",
    avatar: "",
    location: "",
    delivery_address: "",
    billing_name: "",
    billing_phone: "",
    billing_email: "",
    billing_country: "",
    billing_city: "",
    billing_area: "",
    billing_street: "",
    villa_number: "",
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false
  }
  update = {
    id: 0,
    token: '',
    name: '',
    phone: '',
    email: '',
    city: 'Dubai',
    area: '',
    street: '',
    villa_number: ''
  };
  addCloset = {
    id: 0,
    token: "",
    label_id: 0,
    product_id: 0,
    product_name: "",
    product_image: ""
  }
  bill: {
    count: number;
    discount: number | string;
    delivery: number | string;
    subtotal: number | string;
    total: number | string;
    f_discount: string;
    f_delivery: string;
    f_subtotal: string;
    f_total: string;
  } = {
    count: 0,
    discount: 0,
    delivery: 0,
    subtotal: 0,
    total: 0,
    f_discount: "",
    f_delivery: "",
    f_subtotal: "",
    f_total: ""
  };

  // ── Gift card ──────────────────────────────────────────────────────
  giftCard = {
    code:              '',        // user-entered code (formatted XXXX-XXXX-XXXX-XXXX)
    preview:           null as any, // result of POST /v3/cart/gift-card
    applied:           false,
    checking:          false,
    is_open:           false,     // accordion open state
  };

  // ── Gift wallet (aggregate balance across the customer's cards) ────
  //
  // One-tap alternative to typing a single code: the server sums every
  // spendable card the customer owns/redeemed and draws across them
  // (soonest-expiry first). Full cover skips Noon entirely; partial cover
  // charges Noon the remainder. Mutually exclusive with the code path, an
  // explicit code wins server-side, so the UI only offers one at a time.
  wallet = {
    balance:   0,        // total spendable wallet balance (AED)
    applied:   false,    // user tapped "apply"
    loading:   false,    // preview request in flight
    preview:   null as any, // GET /cart/gift-wallet response.data
  };

  /** Wallet has spendable balance and no single code is applied. */
  get walletAvailable(): boolean {
    return this.wallet.balance > 0 && !this.giftCard.applied;
  }

  /**
   * The order total the gift balance has to cover, the server-authoritative
   * quote total (subtotal + delivery − promo), i.e. exactly the "Amount due"
   * the summary and floating bar show before any gift credit.
   */
  get payableBeforeGift(): number {
    return Number(this.promo.quote?.total ?? this.bill.total) || 0;
  }

  /**
   * Amount the wallet draws for this cart (0 when not applied).
   *
   * Computed against payableBeforeGift rather than echoing the preview's
   * `applied`, so the figure always matches the total on screen, including
   * when a promo is applied after the preview was fetched. The server caps
   * the real draw the same way at initiate.
   */
  get walletAppliedAmount(): number {
    if (!this.wallet.applied) { return 0; }
    return Math.min(this.wallet.balance, this.payableBeforeGift);
  }

  /** True when the wallet covers the whole payable total. */
  get walletCoversOrder(): boolean {
    return this.wallet.applied
      && this.payableBeforeGift > 0
      && this.wallet.balance >= this.payableBeforeGift;
  }

  /**
   * Fetch the wallet preview for the current cart. Safe to call repeatedly;
   * failures degrade silently (the wallet section just stays hidden, the
   * customer can still pay normally or use a code).
   */
  loadGiftWallet(): void {
    if (!this.single_user.token) { return; }
    this.wallet.loading = true;
    this.networkAdapter
      .get_v3('GET /cart/gift-wallet', { authToken: this.single_user.token })
      .subscribe({
        next: (res: any) => {
          const d = res?.data;
          if (res?.response_code === 200 && d) {
            this.wallet.preview = d;
            this.wallet.balance = Number(d.wallet_balance ?? 0);
          } else {
            this.wallet.preview = null;
            this.wallet.balance = 0;
          }
          this.wallet.loading = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.wallet.preview = null;
          this.wallet.balance = 0;
          this.wallet.loading = false;
          this.cdr.markForCheck();
        },
      });
  }

  /** One-tap apply of the wallet. Clears any typed code (server: code wins). */
  applyGiftWallet(): void {
    if (!this.wallet.preview) { return; }
    this.removeGiftCard();
    this.wallet.applied = true;
    this.cdr.markForCheck();
  }

  removeGiftWallet(): void {
    this.wallet.applied = false;
    this.cdr.markForCheck();
  }

  get gcAppliedAmount(): number {
    if (this.wallet.applied) { return this.walletAppliedAmount; }
    return this.giftCard.applied ? Number(this.giftCard.preview?.gift_card_amount ?? 0) : 0;
  }

  /**
   * What the payment gateway will actually be charged. Derived from the
   * authoritative quote total minus the gift credit (never the preview's own
   * remainder, which is computed before any promo and can drift).
   */
  get gcGatewayAmount(): number {
    if (this.wallet.applied) {
      return Math.max(0, this.payableBeforeGift - this.walletAppliedAmount);
    }
    if (this.giftCard.applied) {
      const applied = Number(this.giftCard.preview?.gift_card_amount ?? 0);
      return Math.max(0, this.payableBeforeGift - applied);
    }
    return this.payableBeforeGift;
  }

  /**
   * Amount due now. The quote (POST /v3/cart/quote) is the source of truth
   * for the order total (subtotal + delivery − promo discount); a gift card
   * reduces that by its applied credit. Using quote.total (not the gift-card
   * endpoint's own gateway_amount) keeps the figure consistent with the rest
   * of the breakdown when a promo and a gift card are both applied. The server
   * reconciles the exact charge (incl. full-cover gateway-skip) at initiate.
   */
  get amountDue(): number {
    const total = Number(this.promo.quote?.total ?? this.bill.total) || 0;
    return (this.giftCard.applied || this.wallet.applied)
      ? Math.max(0, total - this.gcAppliedAmount)
      : total;
  }

  // ── Promo code ─────────────────────────────────────────────────────
  // Additive (M-promo). The server is authoritative for all totals via
  // POST /v3/cart/quote, `promo.quote` holds the last response.data so
  // the breakdown card renders server-computed money (DECIMAL STRINGS).
  promo = {
    code:     '',          // user-entered promo code
    applied:  false,       // a promo is currently applied
    checking: false,       // an applyPromo() round-trip is in flight
    error:    '',          // localized error message (shown inline)
    is_open:  false,       // collapsible-card open state (mirrors giftCard.is_open)
    quote:    null as any,  // last POST /cart/quote response.data
  };

  /**
   * Map a v3 PROMO_* / cart error code to a localized message. Returns a
   * generic fallback for unknown codes so we never surface a raw code.
   */
  private promoErrorMessage(code: string | null | undefined, details?: any): string {
    switch (code) {
      case 'PROMO_NOT_FOUND':            return this.i18n.t('text_promo_not_found');
      case 'PROMO_INACTIVE':             return this.i18n.t('text_promo_inactive');
      case 'PROMO_NOT_YET_VALID':        return this.i18n.t('text_promo_not_yet_valid');
      case 'PROMO_EXPIRED':              return this.i18n.t('text_promo_expired');
      case 'PROMO_CURRENCY_MISMATCH':    return this.i18n.t('text_promo_currency_mismatch');
      case 'PROMO_MIN_SUBTOTAL_NOT_MET':
        return this.i18n.t('text_promo_min_subtotal_not_met', {
          min_subtotal: details?.min_subtotal ?? '',
          currency: details?.currency ?? '',
        });
      case 'PROMO_GLOBAL_LIMIT_REACHED': return this.i18n.t('text_promo_global_limit_reached');
      case 'PROMO_USER_LIMIT_REACHED':   return this.i18n.t('text_promo_user_limit_reached');
      case 'PROMO_NOT_APPLICABLE_TO_CART': return this.i18n.t('text_promo_not_applicable_to_cart');
      case 'BUSINESS_RULE_VIOLATION':    return this.i18n.t('text_promo_cart_empty');
      default:                           return this.i18n.t('text_promo_invalid');
    }
  }
  checkout = {
    apiOperation: "INITIATE",
    order: {
      reference: "",
      amount: 0,
      currency: "AED",
      name: "",
      channel: "web",
      category: "pay",
      items: [] as any[]
    },
    shipping: {
      address: {
        street: "",
        city: "",
        stateProvince: "",
        country: "AE",
        postalCode: null
      },
      contact: {
        firstName: "",
        lastName: "",
        phone: "",
        mobilePhone: "",
        email: ""
      }
    },
    configuration: {
      paymentAction: "SALE",
      tokenizeCc: "true",
      locale: "en",
      returnUrl: "https://api-v3.3bayti.ae/v3/checkout/return/"
    },
    deliveryConfiguration: {
      link: {
        isAutomatedInvoiceDeliveryRequired: true,
        method: "email",
        toRecipients: [] as string[]
      },
      receipt: {
        receiptNumber: "",
        isAutomatedReceiptDeliveryRequired: true,
        method: "email",
        toRecipients: [] as string[]
      }
    }
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
    // Release the OTA guard when leaving checkout (payment sheet closed or
    // navigated to the confirmation screen, which holds its own guard).
    if (this.otaGuardEngaged) {
      this.checkoutActivity.end();
      this.otaGuardEngaged = false;
    }
  }
  user_profile() {
    this.router.navigate(['/', 'settings']);
  }
  ngOnInit() {
    this.getObject();
  }

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.request.id = this.single_user.id
      this.request.token = this.single_user.token

      this.rqst_param.id = this.single_user.id
      this.rqst_param.token = this.single_user.token
      this.update.name = this.single_user.first_name + " " + this.single_user.last_name;
      this.update.phone = this.single_user.phone;
      this.update.email = this.single_user.email;

      this.load_cart();
      this.get_billing();
      this.loadSavedAddresses();
    }
  }
  load_cart() {
    this.carts = [];
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.request.id = this.single_user.id;
    // Direct v3 (GET /v3/cart). The transformCartListResponse response
    // transform still applies via get_v3, so response.data keeps the
    // {items, bill, ...} shape, the dual-shape handling below is left
    // intact (the v3 branch is the one that runs).
    this.networkAdapter.get_v3('GET /cart', { authToken: this.single_user.token })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200) {
            // Dual-shape support during M3.1.6 strangler-fig migration.
            // See cart.page.ts load_cart for the detailed explanation.
            const data = response.data;
            if (Array.isArray(data)) {
              // Legacy shape
              this.carts = data;
              this.bill = response.message;
              this.checkout.order.items = response.status;
            } else if (data && typeof data === 'object' && Array.isArray(data.items)) {
              // v3 shape (post-transform)
              this.carts = data.items;
              // Merge transform's {count, subtotal} over the default
              // bill, v3 doesn't compute delivery/discount on the
              // cart endpoint; those come from the checkout flow.
              this.bill = {
                ...this.bill,
                count: typeof data.bill?.count === 'number' ? data.bill.count : 0,
                subtotal: typeof data.bill?.subtotal === 'string'
                  ? data.bill.subtotal
                  : (data.bill?.subtotal ?? 0),
              };
              this.checkout.order.items = this.bill.count as any;
            } else {
              this.carts = [];
              this.bill = { ...this.bill, count: 0, subtotal: 0 };
            }
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = this.carts.length === 0;
            this.cdr.markForCheck();
            // Fetch server-authoritative base totals for the breakdown
            // card once the cart is loaded. Guard on token (set in
            // getObject before load_cart runs). Promo additive (M-promo).
            if (this.single_user.token) {
              this.quoteCart(null);
            }
          }else{
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = true;
            this.cdr.markForCheck();
          }
        }
      }))
  }

  /**
   * Step 1 -> Step 2. Validate that a delivery address is ready: either a
   * saved address is selected, OR the inline form carries the same required
   * fields the existing confirm/checkout logic relies on (isConfirmBilling).
   * On success, mark billing confirmed and advance to Review (scroll top).
   * On failure, surface the existing delivery-info prompt and stay on step 1.
   */
  goToReview() {
    // A saved address must be selected before advancing to review/payment.
    // applySavedAddress() (picking from the list or adding a new one) sets
    // selectedAddressId + isConfirmBilling. The address now comes ONLY from a
    // saved address, which is already persisted server-side, and its id is
    // forwarded to checkout_initiate() as shipping/billing_address_id.
    if (this.selectedAddressId === null || !this.isConfirmBilling) {
      this.error_notification(this.i18n.t('text_select_delivery_address'));
      return;
    }

    this.step = 2;
    this.scrollContentToTop();
    // Safety net: the base quote is fetched on cart load, but if that raced
    // or failed (leaving the order-summary totals at 0), re-fetch it now that
    // the user is on the review step so delivery/total populate.
    if (!this.promo.quote) {
      this.quoteCart(this.promo.applied ? this.promo.code : null);
    }
    // Gift wallet: fetch here (not on cart load) so the preview is sized
    // against the final cart the customer is about to pay for.
    this.loadGiftWallet();
  }

  /** Step 2 -> Step 1 (back to the address step). */
  backToAddress() {
    this.step = 1;
    this.scrollContentToTop();
  }

  /** Convenience alias for back-to-review semantics from step 2. */
  backToReview() {
    this.step = 1;
    this.scrollContentToTop();
  }

  private scrollContentToTop() {
    try {
      this.content?.scrollToTop(200);
    } catch {
      /* Non-fatal: scrolling is a nicety, not required. */
    }
  }

  checkout_initiate() {
    if (!this.isConfirmBilling){
      this.toggleAccordion();
      this.error_notification(this.i18n.t('text_confirm_delivery_info'))
      return;
    }

    // Bill total may be string (v3) or number (legacy). Coerce defensively.
    this.checkout.order.amount = this.gcGatewayAmount;
    (this.checkout.order as any).gift_card_code = this.giftCard.applied ? this.giftCard.code : undefined;
    this.checkout.order.reference = GlobalComponent.generateTransactionReference();
    this.checkout.order.name = this.single_user.first_name + " " + this.single_user.last_name;
    this.checkout.shipping.address.street = this.single_user.billing_street;
    this.checkout.shipping.address.city = this.single_user.billing_city;
    this.checkout.shipping.address.country = "AE";
    this.checkout.shipping.address.stateProvince = this.single_user.billing_area;

    this.checkout.shipping.contact.email = this.single_user.email;
    this.checkout.shipping.contact.phone = this.single_user.billing_phone;
    this.checkout.shipping.contact.firstName = this.single_user.first_name;
    this.checkout.shipping.contact.lastName = this.single_user.last_name;

    this.checkout.deliveryConfiguration.link.toRecipients.push(this.single_user.email);
    this.checkout.deliveryConfiguration.receipt.receiptNumber = GlobalComponent.generateTransactionReceipt();
    this.checkout.deliveryConfiguration.receipt.toRecipients.push(this.single_user.email);

    this.ui_controls.checking_out = true;
    // Hold the OTA guard for the payment window so an update can't reload the
    // web view mid-payment. Engaged once; released in ngOnDestroy.
    if (!this.otaGuardEngaged) {
      this.otaGuardEngaged = true;
      this.checkoutActivity.begin();
    }
    // Return-URL detection. Three shapes the webview may surface after
    // Noon finishes hosted checkout:
    //   Legacy:  https://api-v3.3bayti.ae/customer/complete?orderId=&...
    //   v3 API:  https://api-v3.3bayti.ae/v3/checkout/return/{order_reference}
    //   Web:     {WEB_APP_URL}/checkout/return?ref={order_reference}
    //
    // The v3 API return endpoint 302-redirects to the web return page. Some
    // webviews only surface the FINAL committed URL (the web page), not the
    // intermediate API URL, so we must also match the web return page or the
    // browser hangs on a blank screen after a successful payment. Whichever
    // matches first closes the webview and hands off to /process, which polls
    // GET /v3/checkout/status to the authoritative outcome.
    const legacyReturnPrefix = 'https://api-v3.3bayti.ae/customer/complete';
    const v3ReturnPrefix = 'https://api-v3.3bayti.ae/v3/checkout/return/';
    let listenerHandle: any = null;
    let processed = false; // ensure we only handle the redirect once
    // Direct v3 (POST /v3/checkout/initiate). The server derives the cart,
    // the customer's default address, and all totals from the authenticated
    // session, delivery_fee/discount are ignored server-side and promo is a
    // later stage. So we send only the channel + optional gift card code,
    // NOT the big Noon `checkout` object. The registered
    // transformInitiatePaymentResponse maps v3 'checkout_url' -> 'url', so the
    // response handling below (response.data.url) is unchanged.
    const initiateBody = {
      channel: 'MOBILE',
      gift_card_code: this.giftCard.applied ? this.giftCard.code : undefined,
      // One-tap wallet: the server draws across every spendable card the
      // customer owns. Ignored server-side when gift_card_code is also sent
      // (an explicit code wins), and the two are mutually exclusive in the UI.
      use_gift_wallet: this.wallet.applied ? true : undefined,
      promo_code: this.promo.applied ? this.promo.code : undefined,
      // Ship + bill to the SELECTED saved address (web parity). Without these
      // the server falls back to the customer's default address, ignoring the
      // user's pick. v3 InitiateCheckoutInput accepts both as optional.
      shipping_address_id: this.selectedAddressId ?? undefined,
      billing_address_id: this.selectedAddressId ?? undefined,
    };
    this.networkAdapter.post_v3('POST /checkout/initiate', initiateBody, { authToken: this.single_user.token })
      .subscribe({
        next: (response: any) => {
          this.ui_controls.checking_out = false;

          // Detect response shape (dual-shape strangler-fig support):
          //   v3 (post-transform): response.response_code === 200,
          //                        response.data = { url, order_reference, order_id, ... }
          //   Legacy (raw Noon):   response.resultCode === 0,
          //                        response.result.checkoutData.postUrl

          // M3.5, Gift card full-cover: gateway was skipped server-side.
          // No webview needed, go straight to the success/process page.
          const isGatewaySkipped =
            response.response_code === 200 &&
            response.data?.gateway_skipped === true;

          if (isGatewaySkipped) {
            this.ui_controls.checking_out = false;
            const orderRef = response.data?.order_reference ?? '';
            this.router.navigate(['/success'], {
              queryParams: { orderReference: orderRef, gift_card_paid: '1' }
            });
            return;
          }

          const isV3Shape =
            response.response_code === 200 &&
            response.data &&
            typeof response.data === 'object' &&
            typeof response.data.url === 'string' &&
            response.data.url.length > 0;

          const isLegacyShape =
            response.resultCode === 0 &&
            response.result &&
            response.result.checkoutData &&
            typeof response.result.checkoutData.postUrl === 'string';

          if (!isV3Shape && !isLegacyShape) {
            // Either an error (resultCode != 0) or an unexpected shape.
            const errMsg =
              typeof response.message === 'string' && response.message.length > 0
                ? response.message
                : this.i18n.t('text_something_went_wrong');
            this.error_notification(errMsg);
            return;
          }

          // Extract the webview URL + v3-specific order_reference.
          const webviewUrl = isV3Shape
            ? response.data.url
            : response.result.checkoutData.postUrl;
          const v3OrderReference = isV3Shape
            ? (typeof response.data.order_reference === 'string'
                ? response.data.order_reference
                : '')
            : '';

          this.checkout_ready = response;
          console.log('checkout_ready', this.checkout_ready, { isV3Shape });

          InAppBrowser.openWebView({
            url: webviewUrl,
            title: this.i18n.t('button_checkout')
          })
            .then(openRes => {
              console.log('webview opened', openRes);
            })
            .catch(err => {
              console.error('openWebView failed', err);
            });
          InAppBrowser.addListener('urlChangeEvent', (info: any) => {
            try {
              if (processed) return;              // already handled once
              if (!info || !info.url) return;    // defensive
              const urlStr: string = info.url;

              // Parse once (host-tolerant). Match against any of the three
              // return shapes; the provider/redirect chain determines which
              // one the webview surfaces first.
              let parsed: URL | null = null;
              try { parsed = new URL(urlStr); } catch { parsed = null; }

              const matchesLegacy = urlStr.startsWith(legacyReturnPrefix);
              const matchesV3 = urlStr.startsWith(v3ReturnPrefix);
              // Web return page after the API 302. Host-agnostic (match on the
              // path + presence of ?ref=) so it fires whether WEB_APP_URL is
              // 3bayti.ae, www.3bayti.ae, etc. This is the branch that fixes
              // the post-payment white screen when the webview only reports
              // the final committed URL, not the intermediate API return URL.
              const matchesWebReturn =
                !!parsed &&
                parsed.pathname.replace(/\/+$/, '') === '/checkout/return' &&
                parsed.searchParams.has('ref');
              if (!matchesLegacy && !matchesV3 && !matchesWebReturn) return;

              const deliveryFee = Number(this.bill.delivery) || 0;
              let orderId: string | null = null;
              let merchantReference: string | null = null;
              let paymentType: string | null = null;
              let orderReference: string = v3OrderReference;

              if (matchesLegacy) {
                // Legacy: orderId/merchantReference/paymentType in query
                const params = (parsed ?? new URL(urlStr)).searchParams;
                orderId = params.get('orderId');
                merchantReference = params.get('merchantReference');
                paymentType = params.get('paymentType');
              } else if (matchesV3) {
                // v3 API return: order_reference is in the URL path
                // (https://api-v3.3bayti.ae/v3/checkout/return/{ref})
                const refFromUrl = urlStr.substring(v3ReturnPrefix.length).split(/[?#/]/)[0];
                if (refFromUrl) {
                  orderReference = refFromUrl;
                }
              } else {
                // Web return page: order_reference is in ?ref=
                const refFromQuery = parsed?.searchParams.get('ref') ?? '';
                if (refFromQuery) {
                  orderReference = refFromQuery;
                }
              }

              console.log('Captured redirect:', {
                shape: matchesV3 ? 'v3' : (matchesLegacy ? 'legacy' : 'web'),
                orderId, merchantReference, paymentType, orderReference,
              });
              processed = true; // prevent re-entry
              try {
                if (listenerHandle) {
                  if (typeof listenerHandle.remove === 'function') {
                    listenerHandle.remove();
                  } else if (typeof listenerHandle === 'function') {
                    listenerHandle(); // some libs return an unsubscribe function
                  }
                }
              } catch (e) {
                console.warn('Listener cleanup failed', e);
              }
              const closeWebview = async () => {
                try {
                  if (InAppBrowser.close) {
                    await InAppBrowser.close();
                  } else {
                    console.warn('No close method found on InAppBrowser plugin');
                  }
                } catch (e) {
                  console.warn('Error closing webview', e);
                } finally {
                  this.open_processing(
                    orderId, merchantReference, paymentType, deliveryFee,
                    orderReference,
                  );
                }
              };
              closeWebview();
            } catch (err) {
              console.error('Error handling urlChangeEvent', err);
            }
          })
            .then((handle: any) => {
              listenerHandle = handle;
              console.log('urlChangeEvent listener attached', listenerHandle);
            })
            .catch((err: any) => {
              console.error('addListener failed', err);
            });

        },
        error: (e) => {
          this.ui_controls.checking_out = false;
          console.error('initiatePayment error', e.toString());
          return;
        },
        complete: () => {
          this.ui_controls.checking_out = false;
          console.info('complete');
        }
      });
  }
  user_home() {
    this.router.navigate(['/', 'account']);
  }
  user_wishlist() {
    this.router.navigate(['/', 'wishlist']);
  }
  user_explore() {
    this.router.navigate(['/', 'explore']);
  }
  user_orders() {
    this.router.navigate(['/', 'my-orders']);
  }
  user_cart() {
    this.router.navigate(['/', 'cart']);
  }
  user_messages() {
    this.router.navigate(['/', 'chat-vendors']);
  }
  handleRefresh(event: any) {
    setTimeout(() => {
      this.load_cart();
      event.target.complete();
    }, 200);
  }
  // ── Gift card methods ─────────────────────────────────────────────

  toggleGiftCardSection() {
    this.giftCard.is_open = !this.giftCard.is_open;
  }

  togglePromoSection() {
    this.promo.is_open = !this.promo.is_open;
  }

  onGiftCodeInput(event: any) {
    this.setGiftCode((event.target?.value ?? ''));
  }

  /**
   * Paste a gift-card code from the clipboard. Reuses the same normalisation
   * as typing (strip non-alphanumerics, uppercase, 16 chars, 4×4 grouping) so
   * a code copied from an email/SMS/wallet drops straight in.
   */
  async pasteGiftCode(): Promise<void> {
    const text = await this.clipboard.read();
    if (text === null) {
      // Clipboard couldn't be read (blocked / old shell), tell the user to
      // paste manually rather than failing silently.
      this.error_notification(this.i18n.t('gc_paste_failed'));
      return;
    }
    if (text === '') { return; }
    this.setGiftCode(text);
    this.cdr.markForCheck();
  }

  /** Normalise raw input into the hyphenated code + reset any applied state. */
  private setGiftCode(raw: string): void {
    const cleaned = raw.replace(/[^a-zA-Z0-9]/g, '').toUpperCase().slice(0, 16);
    this.giftCard.code = cleaned.match(/.{1,4}/g)?.join('-') ?? cleaned;
    this.giftCard.preview = null;
    this.giftCard.applied = false;
  }

  previewGiftCard() {
    const raw = this.giftCard.code.replace(/-/g, '');
    if (raw.length !== 16) { this.error_notification(this.i18n.t('text_gift_card_enter_full')); return; }
    this.giftCard.checking = true;
    // Direct v3 (POST /v3/cart/gift-card). Must pass the route-key form, not
    // a raw path, resolveConfig throws on a non-route-key, so the previous
    // '/v3/cart/gift-card' literal broke the call. 'POST /cart/gift-card' is
    // registered in feature-flags. Response.data shape:
    // { code, theme, gift_card_amount, gateway_amount, balance_remaining,
    //   cart_total, currency }, the page reads gift_card_amount/gateway_amount.
    this.networkAdapter.post_v3('POST /cart/gift-card', { code: this.giftCard.code }, { authToken: this.single_user.token }).subscribe({
      next: (res: any) => {
        this.giftCard.checking = false;
        if (res?.data?.gift_card_amount) {
          this.giftCard.preview = res.data;
          this.giftCard.applied = true;
          // A specific code wins server-side, so drop the wallet selection to
          // keep the displayed totals honest.
          this.wallet.applied = false;
        } else {
          // Prefer the server's specific message (e.g. "Gift card not found.",
          // "This gift card is not spendable") over a generic one.
          this.error_notification(res?.message || res?.error?.message || this.i18n.t('text_gift_card_cannot_apply'));
        }
        this.cdr.markForCheck();
      },
      error: (err: any) => {
        this.giftCard.checking = false;
        console.warn('[checkout] /cart/gift-card error', err?.status, err?.error);
        const msg = err?.error?.error?.message || err?.error?.message;
        this.error_notification(msg || this.i18n.t('text_gift_card_invalid'));
        this.cdr.markForCheck();
      },
    });
  }

  removeGiftCard() {
    this.giftCard.preview = null;
    this.giftCard.applied = false;
    this.giftCard.code    = '';
    this.cdr.markForCheck();
  }

  // ── Promo code methods ────────────────────────────────────────────

  /**
   * Re-quote the cart against the server (POST /v3/cart/quote).
   *
   * `promoCode` of null/'' clears any applied promo and fetches base
   * totals. A non-empty code asks the server to evaluate the promo.
   *
   * The MobileNetworkAdapter routes HTTP 4xx (e.g. the 422 promo
   * errors) through the SUCCESS channel as an error envelope
   * ({ status:'error', error_code, message }), while network-level
   * failures go through the error callback. We handle the promo error
   * in BOTH places so the code is read from whichever path fires:
   *   - envelope (next):  response.error_code
   *   - raw HttpError (error cb): err?.error?.error?.code (v3 envelope)
   * On any promo error we clear `applied` and re-quote with null so the
   * breakdown still shows server-authoritative base totals.
   */
  private quoteCart(promoCode: string | null) {
    this.networkAdapter
      .post_v3('POST /cart/quote', { promo_code: promoCode }, { authToken: this.single_user.token })
      .subscribe({
        next: (response: any) => {
          // Error envelope surfaced via the success channel (4xx).
          if (response?.status === 'error' || (response?.response_code && response.response_code !== 200)) {
            const code = response?.error_code ?? response?.error?.code;
            // translateError surfaces v3 structured details as `error_details`
            // on the success-channel envelope (422s never reach the error cb).
            const details = response?.error_details ?? response?.error?.details;
            console.warn('[checkout] /cart/quote error envelope', { code, message: response?.message, response });
            this.handlePromoError(promoCode, code, details);
            return;
          }

          const data = response?.data;
          console.log('[checkout] /cart/quote ok', { promoCode, subtotal: data?.subtotal, delivery_fee: data?.delivery_fee, total: data?.total });
          this.promo.quote = data;

          if (promoCode && data?.applied_promo) {
            this.promo.applied = true;
            this.promo.error = '';
          } else if (promoCode) {
            // Server accepted the request but didn't apply a promo.
            this.promo.applied = false;
          } else {
            // Base re-quote (clearing): nothing applied.
            this.promo.applied = false;
          }
          this.promo.checking = false;
          this.cdr.markForCheck();
        },
        error: (err: any) => {
          // Raw HttpErrorResponse (network error or, defensively, a 4xx
          // that reached this channel). Read the v3 envelope's code.
          console.warn('[checkout] /cart/quote http error', err?.status, err?.error);
          const code = err?.error?.error?.code ?? err?.error?.code;
          const details = err?.error?.error?.details ?? err?.error?.details;
          this.handlePromoError(promoCode, code, details);
        },
      });
  }

  /**
   * Shared promo-failure handler: localize the error, mark not-applied,
   * and re-quote with null so base totals remain visible. Guards against
   * recursion (only the original promo attempt re-quotes the base).
   */
  private handlePromoError(attemptedCode: string | null, code: string | null | undefined, details?: any) {
    this.promo.applied = false;
    this.promo.checking = false;
    if (attemptedCode) {
      this.promo.error = this.promoErrorMessage(code, details);
      // Re-fetch base totals so the breakdown isn't left empty.
      this.quoteCart(null);
    }
    this.cdr.markForCheck();
  }

  applyPromo() {
    const code = this.promo.code.trim();
    if (!code) { return; }
    this.promo.code = code;
    this.promo.checking = true;
    this.quoteCart(code);
  }

  removePromo() {
    this.promo.code = '';
    this.promo.applied = false;
    this.promo.error = '';
    this.quoteCart(null);
  }

  error_notification(message: string) {
    this.toast.error(message, {
      position: "top-center"
    });
  }
  success_notification(message: string) {
    this.toast.success(message, {
      position: 'top-center'
    });
  }
  open_processing(
    orderId: any,
    merchantReference: any,
    paymentType: any,
    deliveryFee: number,
    orderReference: string = '',
  ) {
    this.router.navigate(
      ['/', 'process'],
      {
        queryParams: {
          orderId, merchantReference, paymentType, deliveryFee,
          // process.page polls GET /v3/checkout/status to confirm, keyed
          // by orderReference (or the merchant reference / order id for
          // older-format returns).
          orderReference,
        }
      }
    );
  }
  toggleAccordion = () => {
    const nativeEl = this.accordionGroup;
    if (nativeEl.value === 'first') {
      nativeEl.value = undefined;
    } else {
      nativeEl.value = 'first';
    }
  };


  get_billing() {
    this.ui_controls.is_loading = true;
    // Direct v3 (GET /v3/me/billing-address). No response transform is
    // registered for this route-key, so response.data is the raw v3
    // envelope payload: { address: <AddressSerializer.publicShape> | null }.
    // Map the v3 field names onto the page's `update` object (template
    // binds update.name/phone/email/city/area/street/villa_number) AND
    // onto single_user.billing_* (which checkout_initiate() reads), so
    // downstream code is unchanged. Mirrors addresses.page.ts get_billing.
    this.networkAdapter.get_v3('GET /me/billing-address', { authToken: this.single_user.token })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            const address = response.data?.address;
            if (address) {
              this.update.name = address.recipient_name ?? this.update.name;
              this.update.phone = address.recipient_phone ?? this.update.phone;
              this.update.email = address.email ?? this.update.email;
              this.update.city = address.emirate ?? this.update.city;
              this.update.area = address.area ?? this.update.area;
              this.update.street = address.street_address ?? '';
              this.update.villa_number = address.building_details ?? '';

              // Mirror onto the billing_* fields checkout_initiate() reads
              // so the downstream payment flow sees the saved address.
              this.single_user.billing_name = this.update.name;
              this.single_user.billing_phone = this.update.phone;
              this.single_user.billing_email = this.update.email;
              this.single_user.billing_city = this.update.city;
              this.single_user.billing_area = this.update.area;
              this.single_user.billing_street = this.update.street;
              this.single_user.villa_number = this.update.villa_number;
            } else {
              // No billing address set yet (new user), keep the
              // prefilled defaults from getObject().
              this.ui_controls.is_empty = true;
            }
            this.ui_controls.is_loading = false;
            this.cdr.markForCheck();
          }else{
            this.ui_controls.is_empty = true;
            this.ui_controls.is_loading = false;
            this.cdr.markForCheck();
          }
        }
      }))
  }
  /**
   * Z.2, Load the user's saved address book (v3). Pre-selects the
   * default address (if any) and applies it to the checkout so a
   * returning customer can confirm in one tap.
   */
  async loadSavedAddresses() {
    this.isLoadingAddresses = true;
    try {
      this.savedAddresses = await this.addressService.list(this.single_user.token);
      const def = this.savedAddresses.find(a => a.is_default || a.is_default_shipping)
        ?? this.savedAddresses[0];
      if (def) {
        this.applySavedAddress(def);
      }
    } catch {
      /* Non-fatal: the manual delivery-info form remains usable. */
    } finally {
      this.isLoadingAddresses = false;
    }
  }

  openAddressPicker() {
    if (this.savedAddresses.length > 0) {
      this.isAddressPickerOpen = true;
    }
  }

  /**
   * Open the add-new-address bottom sheet (web parity). Resets the form to
   * a clean slate so a previous (cancelled) entry doesn't leak in, then
   * pre-fills the recipient Name and Phone from the logged-in user as a
   * convenience default, both stay fully editable (most people ship to
   * themselves, but they can type a different recipient). Closes the
   * saved-address picker if it happened to be open.
   */
  openAddAddress() {
    const defaultName = [this.single_user?.first_name, this.single_user?.last_name]
      .filter((p) => !!p && `${p}`.trim().length > 0)
      .join(' ')
      .trim();
    const defaultPhone = (this.single_user?.phone || this.single_user?.billing_phone || '').trim();
    this.newAddress = {
      label: '',
      recipient_name: defaultName,
      recipient_phone: defaultPhone,
      emirate: '',
      area: '',
      street_address: '',
      building_details: '',
      postal_code: '',
      is_default: false,
    };
    this.isAddressPickerOpen = false;
    this.isAddAddressOpen = true;
  }

  /**
   * User selected a Google Places suggestion inside the add-new sheet.
   * Autofill the street field from the structured place details. Emirate is a
   * fixed dropdown and area is free text (web parity), so we don't auto-match.
   */
  async onNewAddressPlaceSelected(place: PlaceDetails): Promise<void> {
    if (place.street) {
      this.newAddress.street_address = place.street;
      this.toast.success(
        this.i18n.t('text_address_autofilled'),
        { position: 'top-center' }
      );
    }
  }

  /**
   * Validate + create a new saved address (web parity with address-form.ts).
   * Required: recipient_name, recipient_phone, emirate, area, street_address -
   * each surfaces a SPECIFIC error, never a generic message. On success the
   * new address is added to the list, selected (applySavedAddress), and the
   * sheet closes. On failure the SERVER message is surfaced.
   */
  async saveNewAddress(): Promise<void> {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }

    const name = this.newAddress.recipient_name.trim();
    const phoneRaw = this.newAddress.recipient_phone.trim();
    const emirate = this.newAddress.emirate.trim();
    const area = this.newAddress.area.trim();
    const street = this.newAddress.street_address.trim();

    // Specific per-field validation (no generic "please fill all fields").
    if (name.length === 0) {
      this.error_notification(this.i18n.t('text_name_required'));
      return;
    }
    if (phoneRaw.length === 0) {
      this.error_notification(this.i18n.t('text_phone_required'));
      return;
    }
    if (emirate.length === 0) {
      this.error_notification(this.i18n.t('text_emirate_required'));
      return;
    }
    if (area.length === 0) {
      this.error_notification(this.i18n.t('text_area_required'));
      return;
    }
    if (street.length === 0) {
      this.error_notification(this.i18n.t('text_street_required'));
      return;
    }

    // E.164 normalization, identical to update_billing(): prefix +971 and
    // strip leading zeros when there's no leading "+".
    const recipient_phone = phoneRaw.startsWith('+')
      ? phoneRaw
      : '+971' + phoneRaw.replace(/^0+/, '');

    const optionalOrNull = (s: string): string | null => {
      const trimmed = s.trim();
      return trimmed === '' ? null : trimmed;
    };

    const payload: NewAddress = {
      recipient_name: name,
      recipient_phone,
      emirate,
      area,
      street_address: street,
      label: optionalOrNull(this.newAddress.label),
      building_details: optionalOrNull(this.newAddress.building_details),
      postal_code: optionalOrNull(this.newAddress.postal_code),
      // v3 CreateAddressInput accepts a single `is_default` flag; the split
      // is_default_shipping/is_default_billing keys were silently dropped by
      // RequestValidator, so a "default"-checked new address was never
      // promoted (left checkout reading a stale/incomplete default).
      is_default: this.newAddress.is_default,
    };

    this.isSavingAddress = true;
    try {
      const created = await this.addressService.create(this.single_user.token, payload);
      if (!created) {
        this.error_notification(this.i18n.t('text_unable_to_save_billing_address'));
        return;
      }
      // Refresh the book, then select the freshly-created address so it's the
      // delivery target. Reloading keeps default flags server-authoritative;
      // we re-select by id so the right row stays highlighted.
      await this.loadSavedAddresses();
      const fresh = this.savedAddresses.find(a => a.id === created.id) ?? created;
      this.applySavedAddress(fresh);
      this.isAddAddressOpen = false;
      this.success_notification(this.i18n.t('text_address_saved'));
    } catch (err: any) {
      const msg = err?.error?.error?.message || err?.error?.message;
      this.error_notification(msg || this.i18n.t('text_unable_to_save_billing_address'));
    } finally {
      this.isSavingAddress = false;
      this.cdr.markForCheck();
    }
  }

  /**
   * Apply a saved address to the checkout: fills both the editable
   * delivery-info form (this.update) and the single_user.billing_*
   * fields that checkout_initiate() reads, and marks delivery info
   * confirmed so the user can proceed straight to payment.
   */
  applySavedAddress(addr: SavedAddress) {
    this.selectedAddressId = addr.id;

    const name = addr.recipient_name ?? `${this.single_user.first_name} ${this.single_user.last_name}`.trim();
    const phone = addr.recipient_phone ?? this.single_user.phone;

    this.update.name = name;
    this.update.phone = phone;
    this.update.email = this.single_user.email;
    this.update.city = addr.emirate ?? this.update.city;
    this.update.area = addr.area ?? this.update.area;
    this.update.street = addr.street_address ?? '';
    this.update.villa_number = addr.building_details ?? '';

    this.single_user.billing_name = name;
    this.single_user.billing_phone = phone;
    this.single_user.billing_email = this.single_user.email;
    this.single_user.billing_city = addr.emirate ?? '';
    this.single_user.billing_area = addr.area ?? '';
    this.single_user.billing_street = addr.street_address ?? '';
    this.single_user.billing_country = addr.country ?? 'AE';

    this.isConfirmBilling = true;
    this.isAddressPickerOpen = false;
  }

  /** Human-readable one-line summary for an address row. */
  addressSummary(addr: SavedAddress): string {
    return [addr.street_address, addr.area, addr.emirate]
      .filter(Boolean)
      .join(', ');
  }

  /**
   * User selected a Google Places suggestion. Autofill the street from
   * the structured place details. Emirate is a fixed dropdown and area
   * is free text (web parity), so we no longer auto-match them against a
   * city/area API, the user picks the emirate and types the area.
   */
  async onPlaceSelected(place: PlaceDetails): Promise<void> {
    /* Street is the easy case, always assign whatever Google parsed. */
    if (place.street) {
      this.update.street = place.street;
      this.toast.success(
        this.i18n.t('text_address_autofilled'),
        { position: 'top-center' }
      );
    }
  }

  update_billing() {
    if(this.isOnline){
      this.update.id = this.single_user.id;
      this.update.token = this.single_user.token;
      if (this.update.city.length == 0) {
        this.error_notification(this.i18n.t('text_city_required'));
        return;
      }
      if (this.update.area.length == 0) {
        this.error_notification(this.i18n.t('text_area_required'));
        return;
      }
      if (this.update.street.length == 0) {
        this.error_notification(this.i18n.t('text_street_required'));
        return;
      }
      if (this.update.villa_number.length == 0) {
        this.error_notification(this.i18n.t('text_villa_number_required'));
        return;
      }
      this.ui_controls.is_updating = true;
      // Direct v3 (PATCH /v3/me/billing-address, upsert). Request transforms
      // do NOT apply to direct calls, so build the v3 body explicitly from
      // UpsertBillingAddressInput's field names/types. recipient_phone must
      // be E.164 ("+" + country code); the user's stored phone is already
      // E.164 (login transform), but normalize defensively for the
      // tel-national input by prefixing the UAE code when "+" is missing.
      // Mirrors addresses.page.ts update_billing.
      const phone = this.update.phone?.trim() ?? '';
      const recipient_phone = phone.startsWith('+')
        ? phone
        : '+971' + phone.replace(/^0+/, '');
      const v3Body = {
        recipient_name: this.update.name,
        recipient_phone,
        emirate: this.update.city,
        area: this.update.area,
        street_address: this.update.street,
        building_details: this.update.villa_number,
      };
      this.networkAdapter.patch_v3('PATCH /me/billing-address', v3Body, { authToken: this.single_user.token })
        .subscribe(({
          next: (response: any) => {
            if (response.response_code === 200 && response.status === "success") {
              this.ui_controls.is_updating = false;
              this.isConfirmBilling = true;
              this.success_notification(response.message);
              this.single_user.delivery_address = this.update.city + ", " + this.update.area + ", " + this.update.street;
              this.single_user.billing_name = this.update.name;
              this.single_user.billing_phone = this.update.phone;
              this.single_user.billing_email = this.update.email;
              this.single_user.billing_city = this.update.city;
              this.single_user.billing_area = this.update.area;
              this.single_user.billing_street = this.update.street;
              this.single_user.villa_number = this.update.villa_number;
              Preferences.set({
                key: 'user',
                value: JSON.stringify(this.single_user)
              });
              this.toggleAccordion();
            }else{
              this.ui_controls.is_updating = false
              this.error_notification(response.message);
            }
          },
          error: (err: any) => {
            this.ui_controls.is_updating = false;
            this.error_notification(apiErrorMessage(err, this.i18n.t('text_unable_to_save_billing_address')));
          }
        }))
    }else {
      this.error_notification(this.i18n.t('text_offline_check_connection'))
    }
  }
}
