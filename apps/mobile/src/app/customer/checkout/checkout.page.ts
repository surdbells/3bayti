import {Component, OnDestroy, OnInit, ViewChild} from '@angular/core';
import {DecimalPipe} from '@angular/common';
import {Cart} from "../../class/cart";
import {Labels} from "../../class/labels";
import {Subscription} from "rxjs";
import {
  IonAccordion,
  IonAccordionGroup,
  IonButton,
  IonButtons,
  IonCard,
  IonCol,
  IonContent,
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
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import { I18nService } from '../../i18n.service';
import {InAppBrowser} from "@capgo/inappbrowser";
import {TranslatePipe} from "../../translate.pipe";
import {Billing} from "../../class/billing";
import {City} from "../../class/city";
import {Area} from "../../class/area";

import {FormsModule} from "@angular/forms";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { AxPlaceAutocompleteComponent, PlaceDetails } from '../../shared/ax-mobile/place-autocomplete';
import { AddressService, SavedAddress } from '../../core/services/address.service';
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
    IonToolbar,
    IonHeader,
    RouterLink,
    IonRefresher,
    IonRefresherContent,
    IonCol,
    IonRow,
    TranslatePipe,
    IonAccordionGroup,
    IonAccordion,
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
  city: City[] = [];
  area: Area[] = [];
  categories: Labels[] = [];
  isOnline = true;
  isConfirmBilling = false;
  isCityOpen = false;
  isAreaOpen = false;
  /* Z.2 — saved-address book picker. */
  savedAddresses: SavedAddress[] = [];
  isAddressPickerOpen = false;
  selectedAddressId: number | null = null;
  isLoadingAddresses = false;
  private sub: Subscription;
  @ViewChild('accordionGroup', { static: true }) accordionGroup!: IonAccordionGroup;
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
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
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
    area: 'Al Marmoom',
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

  get gcAppliedAmount(): number {
    return this.giftCard.applied ? Number(this.giftCard.preview?.gift_card_amount ?? 0) : 0;
  }

  get gcGatewayAmount(): number {
    return this.giftCard.applied
      ? Number(this.giftCard.preview?.gateway_amount ?? this.bill.total)
      : Number(this.bill.total) || 0;
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
      returnUrl: "https://api.3bayti.ae/customer/complete"
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
      this.getCities();
      this.getArea(2);
      this.loadSavedAddresses();
    }
  }
  load_cart() {
    this.carts = [];
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.request.id = this.single_user.id;
    this.networkAdapter.post_request(this.request, GlobalComponent.customerCart)
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
              // bill — v3 doesn't compute delivery/discount on the
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
          }else{
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = true;
          }
        }
      }))
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
    // Two return-URL prefixes during the M3.1.6 strangler-fig window:
    //   Legacy:  https://api.3bayti.ae/customer/complete?orderId=&...
    //   v3:      https://api.3bayti.ae/v3/checkout/return/{order_reference}
    //
    // We check both. Whichever matches first determines the
    // post-redirect handling: legacy passes query params to /process;
    // v3 passes only order_reference to /process which then polls.
    const legacyReturnPrefix = 'https://api.3bayti.ae/customer/complete';
    const v3ReturnPrefix = 'https://api.3bayti.ae/v3/checkout/return/';
    let listenerHandle: any = null;
    let processed = false; // ensure we only handle the redirect once
    this.networkAdapter.post_request(this.checkout, GlobalComponent.initiatePayment)
      .subscribe({
        next: (response: any) => {
          this.ui_controls.checking_out = false;

          // Detect response shape (dual-shape strangler-fig support):
          //   v3 (post-transform): response.response_code === 200,
          //                        response.data = { url, order_reference, order_id, ... }
          //   Legacy (raw Noon):   response.resultCode === 0,
          //                        response.result.checkoutData.postUrl

          // M3.5 — Gift card full-cover: gateway was skipped server-side.
          // No webview needed — go straight to the success/process page.
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
            title: 'Checkout'
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

              // Match against EITHER prefix; payment provider may redirect
              // to whichever URL was configured server-side (legacy
              // backend → legacy return; v3 backend → v3 return).
              const matchesLegacy = urlStr.startsWith(legacyReturnPrefix);
              const matchesV3 = urlStr.startsWith(v3ReturnPrefix);
              if (!matchesLegacy && !matchesV3) return;

              const deliveryFee = Number(this.bill.delivery) || 0;
              let orderId: string | null = null;
              let merchantReference: string | null = null;
              let paymentType: string | null = null;
              let orderReference: string = v3OrderReference;

              if (matchesLegacy) {
                // Legacy: orderId/merchantReference/paymentType in query
                const params = new URL(urlStr).searchParams;
                orderId = params.get('orderId');
                merchantReference = params.get('merchantReference');
                paymentType = params.get('paymentType');
              } else {
                // v3: order_reference is in the URL path
                // (https://api.3bayti.ae/v3/checkout/return/{ref})
                const refFromUrl = urlStr.substring(v3ReturnPrefix.length).split(/[?#/]/)[0];
                if (refFromUrl) {
                  orderReference = refFromUrl;
                }
              }

              console.log('Captured redirect:', {
                shape: matchesV3 ? 'v3' : 'legacy',
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
    this.router.navigate(['/', 'messages']);
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

  onGiftCodeInput(event: any) {
    const raw = (event.target?.value ?? '').replace(/[^a-zA-Z0-9]/g, '').toUpperCase().slice(0, 16);
    this.giftCard.code = raw.match(/.{1,4}/g)?.join('-') ?? raw;
    this.giftCard.preview = null;
    this.giftCard.applied = false;
  }

  previewGiftCard() {
    const raw = this.giftCard.code.replace(/-/g, '');
    if (raw.length !== 16) { this.error_notification('Enter a full 16-character gift card code.'); return; }
    this.giftCard.checking = true;
    this.networkAdapter.post_v3('/v3/cart/gift-card', { code: this.giftCard.code }).subscribe({
      next: (res: any) => {
        this.giftCard.checking = false;
        if (res?.data?.gift_card_amount) {
          this.giftCard.preview = res.data;
          this.giftCard.applied = true;
        } else {
          this.error_notification(res?.message ?? 'This gift card cannot be applied.');
        }
      },
      error: (err: any) => {
        this.giftCard.checking = false;
        this.error_notification(err?.error?.message ?? 'Gift card not valid.');
      },
    });
  }

  removeGiftCard() {
    this.giftCard.preview = null;
    this.giftCard.applied = false;
    this.giftCard.code    = '';
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
          // v3 only: presence of orderReference triggers polling mode
          // in process.page (else falls back to legacy finalizePayment).
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
    this.networkAdapter.post_request(this.rqst_param, GlobalComponent.readBilling)
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.update = response.data;
            this.ui_controls.is_loading = false;
          }else{
            this.ui_controls.is_empty = true;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  /**
   * Z.2 — Load the user's saved address book (v3). Pre-selects the
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

  getCities() {
    this.ui_controls.is_loading = true;
    this.networkService.get_request(GlobalComponent.topexCities)
      .subscribe(({
        next: (response) => {
          this.city = response.data;
          this.ui_controls.is_loading = false;
        }
      }))
  }
  getArea(city: number) {
    this.ui_controls.is_loading_area = true;
    this.networkService.get_request(GlobalComponent.topexAreaURL+city)
      .subscribe(({
        next: (response) => {
          this.area = response.data;
          this.ui_controls.is_loading_area = false;
        }
      }))
  }

  /**
   * Promise-wrapped version of getArea for use in async flows
   * (specifically onPlaceSelected, which needs to load areas before
   * trying to match the area name from the Google place result).
   * Mirrors getArea's side-effects on this.area and the loading flag.
   */
  private getAreaAsync(cityId: number): Promise<void> {
    return new Promise((resolve) => {
      this.ui_controls.is_loading_area = true;
      this.networkService.get_request(GlobalComponent.topexAreaURL + cityId)
        .subscribe({
          next: (response: any) => {
            this.area = response.data;
            this.ui_controls.is_loading_area = false;
            resolve();
          },
          error: () => {
            /* On error, resolve anyway so the caller doesn't hang.
               this.area stays at whatever it was (possibly empty). */
            this.ui_controls.is_loading_area = false;
            resolve();
          }
        });
    });
  }

  selectCode(d: string, id: number) {
    this.update.city = d;
    this.getArea(id);
  }
  selectArea(d: string) {
    this.update.area = d;
  }

  /**
   * User selected a Google Places suggestion. Try to autofill all
   * three address fields (street, city, area) from the structured
   * place details.
   *
   * Auto-match strategy:
   *   1. Always set update.street to place.street (if Google parsed one)
   *   2. Try to match place.city against this.city[] by case-insensitive
   *      name. If matched, call selectCode() which loads areas.
   *      If unmatched, surface a hint with Google's value so user knows
   *      what to pick manually.
   *   3. After areas load, try to match place.area against this.area[].
   *      Same fallback for unmatched.
   *
   * Anything we can't match is left to the user — the existing
   * dropdowns still work as fallback.
   */
  async onPlaceSelected(place: PlaceDetails): Promise<void> {
    /* Street is the easy case — always assign whatever Google parsed. */
    if (place.street) {
      this.update.street = place.street;
    }

    /* City match: case-insensitive substring match against name field.
       Substring rather than equality because Google may return slightly
       different forms ("Dubai Municipality" vs "Dubai", "Abu Dhabi
       Emirate" vs "Abu Dhabi"). The DB names are short and clean, so
       a substring of the DB name in Google's value is a reliable signal. */
    let cityMatched = false;
    if (place.city && this.city.length > 0) {
      const placeCity = place.city.toLowerCase();
      const matchedCity = this.city.find(c =>
        placeCity.includes(c.name.toLowerCase()) ||
        c.name.toLowerCase().includes(placeCity)
      );
      if (matchedCity) {
        this.update.city = matchedCity.name;
        /* Load areas for the matched city. We await so the area-match
           step below sees fresh data. */
        await this.getAreaAsync(matchedCity.city_ID);
        cityMatched = true;
      } else {
        /* Surface the unmatched city as a hint. The user keeps whatever
           city they previously had selected; we just tell them what
           Google said so they can find the closest match in the list. */
        this.toast.error(
          this.i18n.t('text_city_not_recognized', { city: place.city }),
          { position: 'top-center' }
        );
      }
    }

    /* Area match: only attempt if the city matched (otherwise our
       this.area[] doesn't correspond to the place's city anyway). */
    if (cityMatched && place.area && this.area.length > 0) {
      const placeArea = place.area.toLowerCase();
      const matchedArea = this.area.find(a =>
        placeArea.includes(a.name.toLowerCase()) ||
        a.name.toLowerCase().includes(placeArea)
      );
      if (matchedArea) {
        this.update.area = matchedArea.name;
      } else {
        this.toast.error(
          this.i18n.t('text_area_not_recognized', { area: place.area }),
          { position: 'top-center' }
        );
      }
    }

    /* Confirmation toast — both for sighted users (visual feedback)
       and as an a11y signal that something happened. */
    if (place.street || cityMatched) {
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
      this.networkAdapter.post_request(this.update, GlobalComponent.updateBilling, )
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
          error: () => {
            this.ui_controls.is_updating = false;
            this.error_notification(this.i18n.t('text_unable_to_save_billing_address'));
          }
        }))
    }else {
      this.error_notification(this.i18n.t('text_offline_check_connection'))
    }
  }
}
