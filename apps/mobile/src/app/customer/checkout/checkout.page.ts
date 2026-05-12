import {Component, OnDestroy, OnInit, ViewChild} from '@angular/core';
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
  private sub: Subscription;
  @ViewChild('accordionGroup', { static: true }) accordionGroup!: IonAccordionGroup;
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private toast: AxNotificationService,
    private i18n: I18nService
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
  bill = {
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
  checkout = {
    apiOperation: "INITIATE",
    order: {
      reference: "",
      amount: 0,
      currency: "AED",
      name: "",
      channel: "web",
      category: "pay",
      items: []
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
    }
  }
  load_cart() {
    this.carts = [];
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.request.id = this.single_user.id;
    this.networkService.post_request(this.request, GlobalComponent.customerCart)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200) {
            this.carts = response.data;
            this.bill = response.message;
            this.checkout.order.items = response.status;
            this.ui_controls.is_loading = false;
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
    this.checkout.order.amount = this.bill.total;
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
    const returnUrlPrefix = 'https://api.3bayti.ae/customer/complete';
    let listenerHandle: any = null;
    let processed = false; // ensure we only handle the redirect once
    this.networkService.post_request(this.checkout, GlobalComponent.initiatePayment)
      .subscribe({
        next: (response) => {
          this.ui_controls.checking_out = false;
          if (response.resultCode != 0) {
            this.error_notification(response.message);
            return;
          }
          this.checkout_ready = response;
          console.log('checkout_ready', this.checkout_ready);
          InAppBrowser.openWebView({
            url: this.checkout_ready.result.checkoutData.postUrl,
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
              if (!urlStr.startsWith(returnUrlPrefix)) return;
              const params = new URL(urlStr).searchParams;
              const orderId = params.get('orderId');
              const merchantReference = params.get('merchantReference');
              const paymentType = params.get('paymentType');
              const deliveryFee = this.bill.delivery;
              console.log('Captured redirect:', { orderId, merchantReference, paymentType });
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
                  this.open_processing(orderId, merchantReference, paymentType, deliveryFee);
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
    this.router.navigate(['/', 'orders']);
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
  open_processing(orderId: any, merchantReference: any, paymentType: any, deliveryFee: number) {
    this.router.navigate(
      ['/', 'process'],
      { queryParams: { orderId, merchantReference, paymentType, deliveryFee } }
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
    this.networkService.post_request(this.rqst_param, GlobalComponent.readBilling)
      .subscribe(({
        next: (response) => {
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
      this.networkService.post_request(this.update, GlobalComponent.updateBilling, )
        .subscribe(({
          next: (response) => {
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
