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
import { App as CapacitorApp } from '@capacitor/app';
import { FirebaseAuthentication } from '@capacitor-firebase/authentication';
import { Subscription } from 'rxjs';

import { environment } from '../../../environments/environment';

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
import { AuthSessionService } from '../../core/services/auth-session.service';
import { supportWhatsappLink } from '../../core/constants/support.constants';

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

  /**
   * Marketing push opt-out toggle ("Promotions & reminders").
   * The toggle is shown ON when the user is OPTED IN (i.e. NOT opted out),
   * so marketingEnabled === !marketing_opt_out. Read from GET /me/profile
   * (field marketing_opt_out) and written via
   * PATCH /v3/me/notification-preferences { marketing_opt_out }.
   */
  marketingEnabled = true;
  marketingSaving = false;
  /** Hide the marketing row until we know the real value from the profile. */
  marketingLoaded = false;

  /**
   * Installed app version string shown in the Settings footer, e.g.
   * "v0.0.1 (3)". Sourced at runtime from the native Capacitor App plugin
   * (App.getInfo() -> version/build, i.e. CFBundleShortVersionString /
   * versionName + build number). On web/dev getInfo() is unimplemented and
   * throws, so we fall back to environment.appVersion. Seeded with the
   * env fallback so the footer never flashes a wrong/placeholder value.
   */
  appVersion = `v${environment.appVersion}`;

  ui_controls = {
    is_loading: false,
    updating_location: false
  };

  /**
   * Connected accounts (linked social providers) state.
   *
   * `socialProviders` is the set of provider keys currently linked to the
   * account (from GET /me/social-identities), e.g. ['google']. The UI
   * renders one row per supported provider, showing Connect or Disconnect.
   * `socialBusy` holds the provider whose Connect/Disconnect call is in
   * flight (per-row spinner); `socialLoaded` hides the section until the
   * first fetch resolves so it never flashes a wrong state.
   */
  readonly supportedProviders: Array<{ key: string; labelKey: string; glyph: string }> = [
    { key: 'google', labelKey: 'social_provider_google', glyph: 'G' },
    { key: 'apple', labelKey: 'social_provider_apple', glyph: '' },
  ];
  socialProviders: string[] = [];
  socialBusy: string | null = null;
  socialLoaded = false;

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
    private authSession: AuthSessionService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  ngOnInit(): void {
    this.loadUser();
    this.loadNotificationPreference();
    this.loadAppVersion();
  }

  // ========================================
  // Connected accounts (social identities)
  // ========================================

  /** Is the given provider currently linked to this account? */
  isProviderLinked(key: string): boolean {
    return this.socialProviders.includes(key);
  }

  /**
   * Fetch the linked social providers. GET /me/social-identities returns
   * data either as an array of identity objects ({ provider, ... }) or an
   * object with an `identities` array; we read the `provider` field from
   * each. Silent on failure (the section just shows everything unlinked).
   */
  loadSocialIdentities(): void {
    const token = this.single_user.token;
    if (!token) { this.socialLoaded = true; return; }
    this.networkAdapter.get_v3('GET /me/social-identities', { authToken: token })
      .subscribe({
        next: (response: any) => {
          this.socialLoaded = true;
          if (response?.response_code !== 200) return;
          this.socialProviders = this.extractProviders(response.data);
        },
        error: () => { this.socialLoaded = true; },
      });
  }

  private extractProviders(data: any): string[] {
    const list = Array.isArray(data)
      ? data
      : Array.isArray(data?.identities)
        ? data.identities
        : [];
    return list
      .map((x: any) => (typeof x === 'string' ? x : x?.provider))
      .filter((p: any): p is string => typeof p === 'string' && p.length > 0);
  }

  /**
   * Connect (link) a social provider to the current account: run the native
   * Firebase sign-in for that provider, read the Firebase ID token, then
   * POST /me/social-identities { id_token }. A 409 means the identity is
   * already linked (to this or another account) — surfaced as a clear msg.
   * Cancelled sign-in is swallowed quietly.
   */
  async connectProvider(key: string): Promise<void> {
    if (this.socialBusy) return;
    if (!this.isOnline) {
      this.showError(this.i18n.t('text_offline_check_connection'));
      return;
    }
    this.socialBusy = key;
    try {
      if (key === 'google') {
        await FirebaseAuthentication.signInWithGoogle();
      } else {
        await FirebaseAuthentication.signInWithApple();
      }
      const { token } = await FirebaseAuthentication.getIdToken();
      if (!token) {
        this.socialBusy = null;
        this.showError(this.i18n.t('text_social_signin_failed'));
        return;
      }
      this.networkAdapter.post_v3(
        'POST /me/social-identities',
        { id_token: token },
        { authToken: this.single_user.token },
      ).subscribe({
        next: (response: any) => {
          this.socialBusy = null;
          if (response?.response_code === 200 && response?.status === 'success') {
            this.showSuccess(this.i18n.t('social_connected'));
            this.loadSocialIdentities();
          } else if (response?.response_code === 409) {
            this.showError(this.i18n.t('social_connect_conflict'));
          } else {
            this.showError(response?.message || this.i18n.t('social_connect_failed'));
          }
        },
        error: () => {
          this.socialBusy = null;
          this.showError(this.i18n.t('social_connect_failed'));
        },
      });
    } catch (err) {
      this.socialBusy = null;
      if (this.isCancelledSignIn(err)) return;
      console.error('[Settings] connect provider failed', err);
      this.showError(this.i18n.t('text_social_signin_failed'));
    }
  }

  /**
   * Disconnect (unlink) a social provider. DELETE /me/social-identities/:provider.
   * A 422 is the server's last-method guard (you can't remove your only
   * remaining sign-in method) — surfaced with a clear explanation.
   */
  disconnectProvider(key: string): void {
    if (this.socialBusy) return;
    if (!this.isOnline) {
      this.showError(this.i18n.t('text_offline_check_connection'));
      return;
    }
    this.socialBusy = key;
    this.networkAdapter.delete_v3('DELETE /me/social-identities/:provider', {
      authToken: this.single_user.token,
      pathParams: { provider: key },
    }).subscribe({
      next: (response: any) => {
        this.socialBusy = null;
        if (response?.response_code === 200 && response?.status === 'success') {
          this.showSuccess(this.i18n.t('social_disconnected'));
          this.socialProviders = this.socialProviders.filter((p) => p !== key);
        } else if (response?.response_code === 422) {
          this.showError(this.i18n.t('social_disconnect_last_method'));
        } else {
          this.showError(response?.message || this.i18n.t('social_disconnect_failed'));
        }
      },
      error: () => {
        this.socialBusy = null;
        this.showError(this.i18n.t('social_disconnect_failed'));
      },
    });
  }

  private isCancelledSignIn(err: unknown): boolean {
    const msg = (err && typeof err === 'object' && 'message' in err
      ? String((err as any).message)
      : String(err)).toLowerCase();
    return (
      msg.includes('cancel') ||
      msg.includes('canceled') ||
      msg.includes('1001') ||
      msg.includes('12501')
    );
  }

  /**
   * Resolve the real installed app version for the Settings footer. Prefers
   * the native Capacitor App plugin (App.getInfo() returns the user-facing
   * semver in `version` and the build code in `build`). On the web/dev
   * platform getInfo() is unimplemented and throws — we swallow that and keep
   * the environment.appVersion fallback already seeded into appVersion.
   */
  private async loadAppVersion(): Promise<void> {
    try {
      const info = await CapacitorApp.getInfo();
      this.appVersion = info.build
        ? `v${info.version} (${info.build})`
        : `v${info.version}`;
    } catch {
      /* web/dev: getInfo() unimplemented — keep env fallback */
    }
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }

  // Hardware back is left to Ionic's default IonRouterOutlet handling so it
  // pops to the previous screen natively (and closes any open overlay first)
  // instead of the old priority-9999 override that force-reset the stack to
  // /account — which broke native back and caused the inconsistent loops.

  async loadUser(): Promise<void> {
    const ret: any = await Preferences.get({ key: 'user' });
    if (!ret.value) {
      this.router.navigate(['/login']);
    } else {
      this.single_user = JSON.parse(ret.value);
      this.u_location.id = this.single_user.id;
      this.u_location.token = this.single_user.token;
      this.u_location.location = this.single_user.location;
      // Sync the avatar from the server for sessions whose cached 'user'
      // blob predates login-time avatar persistence.
      this.refreshProfileAvatar();
      // Now that we have the auth token, fetch linked social providers.
      this.loadSocialIdentities();
    }
  }

  /**
   * Pull the freshest profile from GET /v3/me/profile and sync the avatar
   * into single_user + the cached 'user' Preferences blob. The v3 profile
   * emits the picture under `avatar_url` (UserSerializer::publicProfile);
   * the template binds `single_user.avatar`. Silent on failure (the
   * avatar.png fallback in the template applies).
   */
  refreshProfileAvatar(): void {
    if (!this.single_user.token) return;
    this.networkAdapter.get_v3('GET /me/profile', { authToken: this.single_user.token })
      .subscribe({
        next: (response: any) => {
          if (response.response_code !== 200) return;
          const user = response?.data?.user;
          if (!user) return;
          // Hydrate the marketing opt-out toggle from the same profile fetch
          // (no extra request). The toggle is ON when the user is opted IN,
          // i.e. marketingEnabled === !marketing_opt_out. Defaults to opted-in
          // (true) when the field is absent.
          this.marketingEnabled = user.marketing_opt_out !== true;
          this.marketingLoaded = true;
          if (typeof user.avatar_url !== 'string') return;
          if (user.avatar_url === this.single_user.avatar) return;
          this.single_user.avatar = user.avatar_url;
          Preferences.set({ key: 'user', value: JSON.stringify(this.single_user) });
        },
        error: () => { /* silent — fallback avatar applies */ },
      });
  }

  /**
   * Persist the "Promotions & reminders" toggle. The UI binds
   * `marketingEnabled` (ON = opted in); the API field is the inverse
   * (`marketing_opt_out`). Shows a saving state and reverts the toggle on
   * error so the UI never drifts from the server.
   */
  onMarketingToggle(): void {
    if (this.marketingSaving) return;
    // Capture the value the user just set; the API wants the opt-OUT flag.
    const desiredEnabled = this.marketingEnabled;
    const optOut = !desiredEnabled;

    if (!this.isOnline) {
      this.marketingEnabled = !desiredEnabled; // revert
      this.showError(this.i18n.t('text_offline_check_connection'));
      return;
    }

    this.marketingSaving = true;
    this.networkAdapter
      .patch_v3(
        'PATCH /me/notification-preferences',
        { marketing_opt_out: optOut },
        { authToken: this.single_user.token },
      )
      .subscribe({
        next: (response: any) => {
          this.marketingSaving = false;
          if (response?.response_code === 200) {
            this.showSuccess(this.i18n.t('settings_marketing_saved'));
          } else {
            this.marketingEnabled = !desiredEnabled; // revert
            this.showError(this.i18n.t('settings_marketing_error'));
          }
        },
        error: () => {
          this.marketingSaving = false;
          this.marketingEnabled = !desiredEnabled; // revert
          this.showError(this.i18n.t('settings_marketing_error'));
        },
      });
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

  openGiftCards(): void {
    // Open the gift-card WALLET (manage / see-all-by-status / pay pending);
    // the wallet has Buy + Redeem buttons, so buying stays reachable.
    this.router.navigate(['/my-gift-cards']);
  }

  openHelp(): void {
    window.open(supportWhatsappLink('Hello, I need assistance.'), '_system');
  }

  OpenEmail(): void {
    const email = 'support@3bayti.com';
    window.open(`mailto:${email}`);
  }

  // Tab bar navigation. Use a normal (push) navigation like the account tab
  // bar does — navigateRoot reset the stack to a single page, so hardware back
  // from the destination had nothing to pop and exited the app. A push keeps
  // history so native back returns here.
  user_home(): void {
    this.router.navigate(['/home']);
  }

  user_explore(): void {
    this.router.navigate(['/explore']);
  }

  user_cart(): void {
    this.router.navigate(['/cart']);
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
            this.showSuccess(this.i18n.t('text_location_updated'));
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
            // Full session teardown: revoke the server RefreshToken,
            // deactivate the device push token, clear local session +
            // guest cart, and reset the nav stack to /home.
            await this.authSession.logout();
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
