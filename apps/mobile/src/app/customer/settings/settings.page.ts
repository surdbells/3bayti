import { Component, OnInit, OnDestroy } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import {
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  NavController,
  Platform,
  ActionSheetController
} from '@ionic/angular/standalone';
import { Preferences } from '@capacitor/preferences';
import { Subscription } from 'rxjs';

import { ConnectionService } from '../../service/connection.service';
import { NetworkService } from '../../service/network.service';
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import { I18nService } from '../../i18n.service';
import { TranslatePipe } from '../../translate.pipe';
import { LanguageSwitcherComponent } from '../../language-switcher.component';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { AxPlaceAutocompleteComponent, PlaceDetails } from '../../shared/ax-mobile/place-autocomplete';
import { PushManager } from '../../core/services/push-manager.service';

@Component({
  selector: 'app-settings',
  templateUrl: './settings.page.html',
  styleUrls: ['./settings.page.scss'],
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    IonContent,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButton,
    IonButtons,
    TranslatePipe,
    LanguageSwitcherComponent,
    AxIconComponent,
    AxBottomSheetComponent,
    AppTabBarComponent,
    AxPlaceAutocompleteComponent
  ]
})
export class SettingsPage implements OnInit, OnDestroy {
  isLocationOpen = false;

  // State
  isOnline = true;
  notificationsEnabled = true;
  private sub: Subscription;
  private backSub?: Subscription;

  ui_controls = {
    is_loading: false,
    updating_location: false
  };

  single_user = {
    id: 0,
    token: '',
    first_name: '',
    last_name: '',
    user_type: '',
    email: '',
    phone: '',
    avatar: '',
    location: '',
    delivery_address: '',
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false
  };

  u_location = {
    id: 0,
    token: '',
    location: ''
  };

  /**
   * Structured place data captured from the last Google Places selection.
   * The legacy endpoint took a single free-form `location` string; the v3
   * endpoint (PATCH /v3/me/location) instead stores structured fields
   * (city + lat/lng + permission flag) and has NO free-form field. We
   * capture these here so update_location() can build the v3 body. Stays
   * null when the user only typed a manual string (no Places selection),
   * in which case we send just the city-less coordinate-less body — see
   * update_location().
   */
  private selectedPlace: { city: string | null; latitude: number; longitude: number } | null = null;

  constructor(
    private router: Router,
    private platform: Platform,
    private nav: NavController,
    private actionSheetCtrl: ActionSheetController,
    private net: ConnectionService,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private i18n: I18nService,
    private pushManager: PushManager,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  ngOnInit(): void {
    this.loadUser();
    this.loadNotificationPreference();
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
    this.backSub?.unsubscribe();
  }

  ionViewDidEnter(): void {
    this.backSub = this.platform.backButton.subscribeWithPriority(9999, () => {
      this.nav.navigateRoot('/account');
    });
  }

  ionViewWillLeave(): void {
    this.backSub?.unsubscribe();
  }

  async loadUser(): Promise<void> {
    const ret: any = await Preferences.get({ key: 'user' });
    if (!ret.value) {
      this.router.navigate(['/login']);
    } else {
      this.single_user = JSON.parse(ret.value);
      this.u_location.id = this.single_user.id;
      this.u_location.token = this.single_user.token;
      this.u_location.location = this.single_user.location;
    }
  }

  async loadNotificationPreference(): Promise<void> {
    const ret = await Preferences.get({ key: 'notifications_enabled' });
    this.notificationsEnabled = ret.value !== 'false';
  }

  toggleNotifications(): void {
    Preferences.set({ key: 'notifications_enabled', value: String(this.notificationsEnabled) });
    this.showSuccess(this.i18n.t(this.notificationsEnabled ? 'text_notifications_enabled' : 'text_notifications_disabled'));
  }

  onAvatarError(event: Event): void {
    const img = event.target as HTMLImageElement;
    img.src = 'assets/img/avatar-placeholder.jpg';
  }

  // ========================================
  // Navigation methods
  // ========================================

  goBack(): void {
    this.nav.navigateBack('/account');
  }

  openProfile(): void {
    this.router.navigate(['/profile']);
  }

  openAddresses(): void {
    this.router.navigate(['/addresses']);
  }

  openMeasurement(): void {
    this.router.navigate(['/measurements']);
  }

  openReviews(): void {
    this.router.navigate(['/reviews']);
  }

  my_orders(): void {
    this.router.navigate(['/my-orders']);
  }

  user_styles(): void {
    this.router.navigate(['/styles']);
  }

  openTickets(): void {
    this.router.navigate(['/ticketlist']);
  }

  openGiftCards(): void {
    this.router.navigate(['/gift-cards']);
  }

  openHelp(): void {
    const phone = '971504559975';
    const msg = encodeURIComponent('Hello, I need assistance.');
    window.open(`https://wa.me/${phone}?text=${msg}`, '_system');
  }

  OpenEmail(): void {
    const email = 'support@3bayti.com';
    window.open(`mailto:${email}`);
  }

  // Tab bar navigation
  user_home(): void {
    this.nav.navigateRoot('/home');
  }

  user_explore(): void {
    this.nav.navigateRoot('/explore');
  }

  user_cart(): void {
    this.nav.navigateRoot('/cart');
  }

  // ========================================
  // Location update
  // ========================================

  /**
   * User selected a Google Places suggestion in the update-location
   * sheet. Save the formatted address (full readable form, e.g.
   * "Sheikh Zayed Road, Dubai, United Arab Emirates") into the
   * u_location.location field. The user can still edit it manually
   * before tapping Save Changes.
   *
   * If Google didn't return a formatted address (rare), fall back to
   * the parsed street.
   */
  onLocationSelected(place: PlaceDetails): void {
    const display = place.formattedAddress || place.street || '';
    if (display) {
      this.u_location.location = display;
    }
    // Capture the structured fields the v3 endpoint actually stores. We
    // keep city + lat/lng; we deliberately do NOT keep place.country because
    // it's the long-form country NAME ("United Arab Emirates"), whereas the
    // v3 DTO's country_code expects ISO 3166-1 alpha-2 (would 422). lat/lng
    // must travel as a pair per the controller's coordinate-pairing rule.
    if (place.location) {
      this.selectedPlace = {
        city: place.city ?? null,
        latitude: place.location.latitude,
        longitude: place.location.longitude,
      };
    }
  }

  async update_location(): Promise<void> {
    if (!this.isOnline) {
      this.showError(this.i18n.t('text_offline_check_connection'));
      return;
    }

    if (!this.u_location.location.trim()) {
      this.showError(this.i18n.t('text_location_required'));
      return;
    }

    this.ui_controls.updating_location = true;

    // Direct v3 (PATCH /v3/me/location, upsert, RFC 7396 merge-patch). The v3
    // UpdateLocationInput accepts latitude/longitude (must pair)/city/
    // country_code (ISO alpha-2)/permission_granted — all optional — and has
    // NO free-form `location` field. Build the body from the structured place
    // captured in onLocationSelected(); omit fields we don't have so we never
    // send a half coordinate pair or an empty city. permission_granted: true
    // reflects that the user actively selected/confirmed a location.
    // Request transforms don't apply to direct v3 calls, so the body is built
    // explicitly here.
    const body: {
      latitude?: number;
      longitude?: number;
      city?: string;
      permission_granted: boolean;
    } = { permission_granted: true };
    if (this.selectedPlace) {
      body.latitude = this.selectedPlace.latitude;
      body.longitude = this.selectedPlace.longitude;
      if (this.selectedPlace.city) {
        body.city = this.selectedPlace.city;
      }
    }

    this.networkAdapter.patch_v3('PATCH /me/location', body, { authToken: this.u_location.token })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === 'success') {
            // v3 returns { location: <structured> } and does NOT echo a
            // free-form label; keep persisting the display string locally so
            // the account screen still shows the readable address.
            this.single_user.location = this.u_location.location;
            Preferences.set({
              key: 'user',
              value: JSON.stringify(this.single_user)
            });
            this.ui_controls.updating_location = false;
            this.cancel();
            this.showSuccess(response.message);
          } else {
            this.ui_controls.updating_location = false;
            this.showError(response.message || this.i18n.t('text_failed_to_update_location'));
          }
        },
        error: (e) => {
          console.error(e);
          this.ui_controls.updating_location = false;
          this.showError(this.i18n.t('text_failed_to_update_location'));
        }
      });
  }

  cancel(): void {
    this.isLocationOpen = false;
  }

  // ========================================
  // Account actions
  // ========================================

  async signOut(): Promise<void> {
    const actionSheet = await this.actionSheetCtrl.create({
      header: this.i18n.t('confirm_sign_out'),
      buttons: [
        {
          text: this.i18n.t('button_sign_out'),
          role: 'destructive',
          handler: async () => {
            // M3.2.Z.5-B — deactivate this device's push token BEFORE
            // clearing the session (Q-Z5.2). onSignedOutReadingToken
            // reads the still-valid auth token from Preferences('user'),
            // so it must run before that key is removed. Fire-safe:
            // PushManager swallows all errors and is a no-op on web.
            await this.pushManager.onSignedOutReadingToken();
            await Preferences.remove({ key: 'keep_session' });
            await Preferences.remove({ key: 'user' });
            this.router.navigate(['/home']);
          }
        },
        {
          text: this.i18n.t('cancel'),
          role: 'cancel'
        }
      ]
    });
    await actionSheet.present();
  }

  confirmDelete(): void {
    // Open the in-app deletion flow (password re-auth -> DELETE /v3/me).
    // Replaces the prior mailto-to-support stopgap now that the self-serve
    // endpoint is wired (Apple 5.1.1(v) requires in-app deletion).
    this.router.navigate(['/delete-account']);
  }

  // ========================================
  // Toast helpers
  // ========================================

  showError(message: string): void {
    this.toast.error(message, { position: 'top-center' });
  }

  showSuccess(message: string): void {
    this.toast.success(message, { position: 'top-center' });
  }
}
