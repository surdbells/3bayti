import {Component, HostListener, OnDestroy, OnInit} from '@angular/core';

import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCol,
  IonContent,
  IonHeader,
  IonRow,
  IonTitle,
  IonToolbar, NavController, Platform
} from '@ionic/angular/standalone';
import {Router, RouterLink} from "@angular/router";
import {Subscription} from "rxjs";
import {ConnectionService} from "../../service/connection.service";
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {Billing} from "../../class/billing";
import {CartIconComponent} from "../../cart-icon.component";
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
@Component({
  selector: 'app-addresses',
  templateUrl: './addresses.page.html',
  styleUrls: ['./addresses.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    FormsModule,
    IonButtons,
    RouterLink,
    IonCol,
    IonRow,
    IonButton,
    CartIconComponent,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AxTextFieldComponent,
  ]
})
export class AddressesPage implements OnInit, OnDestroy {
  billing: Billing[] = [];
  isOnline = true;
  private sub: Subscription;
  private backSub?: Subscription;
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private i18n: I18nService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ui_controls = {
    is_empty: false,
    is_loading: false,
    is_loading_area: false,
    is_updating: false
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

  ngOnInit() {
    this.getObject();
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }
  ionViewWillLeave() {
    this.backSub?.unsubscribe();
  }
  rqst_param = {
    id: 0,
    token: ""
  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.rqst_param.id = this.single_user.id
      this.rqst_param.token = this.single_user.token
      this.update.name = this.single_user.first_name + " " + this.single_user.last_name;
      this.update.phone = this.single_user.phone;
      this.update.email = this.single_user.email;
      this.get_billing();
    }
  }
  /**
   * Guard against double session-expiry handling.
   *
   * When the access token is dead AND the adapter can't refresh it
   * (refresh_token also expired / revoked), every authed call on the
   * page 401s. Without a guard the page would fire one
   * session-expired toast + /login navigation PER failing call (the
   * "3 toasts" symptom). This latch ensures we surface the friendly
   * message and redirect exactly once.
   */
  private sessionExpiredHandled = false;

  /**
   * True when an adapter response is a 401 the refresh-retry path
   * could not recover (dead access token + un-refreshable session).
   * The adapter already attempted a transparent refresh+retry; a 401
   * reaching the page means refresh was genuinely impossible.
   */
  private isSessionExpired(response: any): boolean {
    return response?.response_code === 401;
  }

  /**
   * Friendly session-expired handling: one toast + redirect to /login.
   * Idempotent via sessionExpiredHandled so concurrent authed calls
   * that all 401 during the dead-session window don't stack toasts.
   */
  private handleSessionExpired() {
    if (this.sessionExpiredHandled) {
      return;
    }
    this.sessionExpiredHandled = true;
    this.error_notification(this.i18n.t('session_expired_sign_in'));
    this.router.navigate(['/', 'login']);
  }

  /**
   * Pull the latest access token from Preferences('user') into the
   * in-memory single_user, then re-fetch billing. The adapter persists
   * rotated tokens to Preferences on a transparent refresh, so reading
   * them back keeps subsequent authed calls on the current token
   * instead of replaying a stale (already-rotated) one.
   */
  private async resyncTokenThenGetBilling() {
    try {
      const ret: any = await Preferences.get({ key: 'user' });
      if (ret.value) {
        this.single_user = JSON.parse(ret.value);
      }
    } catch {
      /* malformed blob — fall through with the existing in-memory token */
    }
    this.get_billing();
  }

  get_billing() {
    this.ui_controls.is_loading = true;
    // Direct v3 (GET /v3/me/billing-address). No response transform is
    // registered for this route-key, so response.data is the raw v3
    // envelope payload: { address: <AddressSerializer.publicShape> | null }.
    // Map the v3 field names onto the page's `update` object (template
    // binds update.name/phone/email/city/area/street/villa_number).
    // Note: the v3 Address has no email field, so we keep the existing
    // update.email (prefilled from the user profile in getObject).
    this.networkAdapter.get_v3('GET /me/billing-address', { authToken: this.single_user.token })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            const address = response.data?.address;
            if (address) {
              this.update.name = address.recipient_name ?? this.update.name;
              this.update.phone = address.recipient_phone ?? this.update.phone;
              this.update.city = address.emirate ?? this.update.city;
              this.update.area = address.area ?? this.update.area;
              this.update.street = address.street_address ?? '';
              this.update.villa_number = address.building_details ?? '';
            } else {
              // No billing address set yet (new user) — keep the
              // prefilled defaults from getObject().
              this.ui_controls.is_empty = true;
            }
            this.ui_controls.is_loading = false;
          } else if (this.isSessionExpired(response)) {
            // Access token expired AND the adapter could not refresh it
            // (refresh_token dead too). Don't surface the raw
            // "Authentication required" — show a friendly message and
            // send the user to sign in. Idempotent across concurrent
            // 401s so we don't stack toasts.
            this.ui_controls.is_loading = false;
            this.handleSessionExpired();
          } else {
            this.ui_controls.is_empty = true;
            this.ui_controls.is_loading = false;
            this.error_notification(response.message);
          }
        }
      }))
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
              // Re-sync the in-memory access token from Preferences
              // before re-fetching. If the PATCH 401'd and the adapter
              // transparently refreshed, it rotated the access +
              // refresh tokens in Preferences('user'); re-using the
              // stale in-memory token here would force ANOTHER refresh
              // with an already-rotated (single-use) refresh token,
              // which the server treats as reuse and revokes the whole
              // session. Reading the fresh token avoids that cascade.
              void this.resyncTokenThenGetBilling();
            } else if (this.isSessionExpired(response)) {
              // Session died mid-edit and refresh couldn't recover it.
              // Friendly message + redirect rather than the raw 401.
              this.ui_controls.is_updating = false;
              this.handleSessionExpired();
            } else {
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
  user_wishlist() {
    this.router.navigate(['/', 'wishlist']);
  }
  user_messages() {
    this.router.navigate(['/', 'chat-vendors']);
  }
  user_cart() {
    this.router.navigate(['/', 'cart']);
  }
  error_notification(message: string) {
    this.toast.error(message, {
      position: "top-center"
    });
  }
  success_notification(message: string) {
    this.toast.success(message, {
      position: "top-center"
    });
  }
}
