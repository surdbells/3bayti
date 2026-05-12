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
import { GlobalComponent } from '../../global-component';
import { I18nService } from '../../i18n.service';
import { TranslatePipe } from '../../translate.pipe';
import { LanguageSwitcherComponent } from '../../language-switcher.component';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { AxPlaceAutocompleteComponent, PlaceDetails } from '../../shared/ax-mobile/place-autocomplete';

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

  constructor(
    private router: Router,
    private platform: Platform,
    private nav: NavController,
    private actionSheetCtrl: ActionSheetController,
    private net: ConnectionService,
    private networkService: NetworkService,
    private toast: AxNotificationService,
    private i18n: I18nService
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

    this.networkService.post_request(this.u_location, GlobalComponent.UpdateLocation)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === 'success') {
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
          handler: () => {
            Preferences.remove({ key: 'keep_session' });
            Preferences.remove({ key: 'user' });
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

  async confirmDelete(): Promise<void> {
    const actionSheet = await this.actionSheetCtrl.create({
      header: this.i18n.t('delete_account_action_header'),
      buttons: [
        {
          text: this.i18n.t('delete_account'),
          role: 'destructive',
          handler: () => {
            this.showSuccess(this.i18n.t('text_account_scheduled_for_removal'));
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
