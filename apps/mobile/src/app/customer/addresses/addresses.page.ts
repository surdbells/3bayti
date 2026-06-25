import { Component, OnDestroy, OnInit } from '@angular/core';

import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCheckbox,
  IonCol,
  IonContent,
  IonHeader,
  IonRow,
  IonTitle,
  IonToolbar,
  NavController,
  Platform,
} from '@ionic/angular/standalone';
import { Router, RouterLink } from '@angular/router';
import { Subscription } from 'rxjs';
import { ConnectionService } from '../../service/connection.service';
import { NetworkService } from '../../service/network.service';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { Preferences } from '@capacitor/preferences';
import { CartIconComponent } from '../../cart-icon.component';
import { I18nService } from '../../i18n.service';
import { TranslatePipe } from '../../translate.pipe';

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { AxPlaceAutocompleteComponent, PlaceDetails } from '../../shared/ax-mobile/place-autocomplete';
import { AddressService, SavedAddress, NewAddress } from '../../core/services/address.service';

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
    IonCheckbox,
    CartIconComponent,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AxTextFieldComponent,
    AxBottomSheetComponent,
    AxPlaceAutocompleteComponent,
  ],
})
export class AddressesPage implements OnInit, OnDestroy {
  isOnline = true;
  private sub: Subscription;

  /* The 7 UAE emirates — same option set the checkout/legacy form used. */
  readonly emirates: string[] = [
    'Abu Dhabi', 'Dubai', 'Sharjah', 'Ajman',
    'Umm Al-Quwain', 'Ras Al Khaimah', 'Fujairah',
  ];

  /* The full saved-address book (v3 GET /me/addresses). */
  addresses: SavedAddress[] = [];

  ui_controls = {
    is_loading: false,
    is_saving: false,
    /** id of the address a set-default / delete round-trip is in flight for. */
    busy_id: 0,
  };

  /* Add/Edit bottom-sheet state. editingId === 0 means "add new". */
  isFormOpen = false;
  editingId = 0;
  form = {
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

  single_user = {
    id: 0,
    token: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
  };

  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private i18n: I18nService,
    private addressService: AddressService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe((v) => (this.isOnline = v));
  }

  ngOnInit() {
    this.getObject();
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
    } else {
      this.single_user = JSON.parse(ret.value);
      this.loadAddresses();
    }
  }

  /** Load the user's full saved-address book. */
  async loadAddresses() {
    this.ui_controls.is_loading = true;
    try {
      this.addresses = await this.addressService.list(this.single_user.token);
    } catch {
      this.error_notification(this.i18n.t('text_unable_to_load_addresses'));
    } finally {
      this.ui_controls.is_loading = false;
    }
  }

  /** Is this address the current default (shipping or generic default)? */
  isDefault(addr: SavedAddress): boolean {
    return !!(addr.is_default || addr.is_default_shipping);
  }

  /** Human-readable one-line summary for an address card. */
  addressSummary(addr: SavedAddress): string {
    return [addr.street_address, addr.area, addr.emirate]
      .filter(Boolean)
      .join(', ');
  }

  // ── Add / Edit sheet ────────────────────────────────────────────────

  /** Open the sheet in "add new" mode with a clean form. */
  openAdd() {
    this.editingId = 0;
    this.form = {
      label: '',
      recipient_name: `${this.single_user.first_name ?? ''} ${this.single_user.last_name ?? ''}`.trim(),
      recipient_phone: this.single_user.phone ?? '',
      emirate: '',
      area: '',
      street_address: '',
      building_details: '',
      postal_code: '',
      is_default: this.addresses.length === 0,
    };
    this.isFormOpen = true;
  }

  /** Open the sheet in "edit" mode pre-filled from an existing address. */
  openEdit(addr: SavedAddress) {
    this.editingId = addr.id;
    this.form = {
      label: addr.label ?? '',
      recipient_name: addr.recipient_name ?? '',
      recipient_phone: addr.recipient_phone ?? '',
      emirate: addr.emirate ?? '',
      area: addr.area ?? '',
      street_address: addr.street_address ?? '',
      building_details: addr.building_details ?? '',
      postal_code: addr.postal_code ?? '',
      is_default: this.isDefault(addr),
    };
    this.isFormOpen = true;
  }

  closeForm() {
    this.isFormOpen = false;
  }

  /**
   * User picked a Google Places suggestion in the add/edit sheet. Autofill
   * the street field; emirate is a fixed dropdown and area is free text
   * (web parity), so we don't auto-match those.
   */
  async onPlaceSelected(place: PlaceDetails): Promise<void> {
    if (place.street) {
      this.form.street_address = place.street;
      this.success_notification(this.i18n.t('text_address_autofilled'));
    }
  }

  /**
   * Validate + persist the add/edit form. Required: recipient_name,
   * recipient_phone, emirate, area, street_address — each surfaces a
   * SPECIFIC error. Create (POST) when editingId === 0, otherwise update
   * (PUT). On success the book is reloaded and the sheet closes.
   */
  async saveForm(): Promise<void> {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }

    const name = this.form.recipient_name.trim();
    const phoneRaw = this.form.recipient_phone.trim();
    const emirate = this.form.emirate.trim();
    const area = this.form.area.trim();
    const street = this.form.street_address.trim();

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

    // E.164 normalization — identical to checkout.saveNewAddress(): prefix
    // +971 and strip leading zeros when there's no leading "+".
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
      label: optionalOrNull(this.form.label),
      building_details: optionalOrNull(this.form.building_details),
      postal_code: optionalOrNull(this.form.postal_code),
      // The v3 CreateAddressInput accepts a single `is_default` flag (NOT
      // is_default_shipping/is_default_billing — those were silently dropped
      // by RequestValidator, so a new "default" address was never promoted).
      is_default: this.form.is_default,
    };

    this.ui_controls.is_saving = true;
    try {
      const isEdit = this.editingId !== 0;
      const saved = isEdit
        ? await this.addressService.update(this.single_user.token, this.editingId, payload)
        : await this.addressService.create(this.single_user.token, payload);

      if (!saved) {
        this.error_notification(
          this.i18n.t(isEdit ? 'text_unable_to_update_address' : 'text_unable_to_save_billing_address'),
        );
        return;
      }

      this.isFormOpen = false;
      this.success_notification(
        this.i18n.t(isEdit ? 'text_address_updated' : 'text_address_saved'),
      );
      await this.loadAddresses();
    } catch (err: any) {
      const msg = err?.error?.error?.message || err?.error?.message;
      this.error_notification(msg || this.i18n.t('text_unable_to_save_billing_address'));
    } finally {
      this.ui_controls.is_saving = false;
    }
  }

  // ── Set default / Delete ────────────────────────────────────────────

  async makeDefault(addr: SavedAddress): Promise<void> {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    if (this.isDefault(addr)) {
      return;
    }
    this.ui_controls.busy_id = addr.id;
    try {
      const ok = await this.addressService.setDefault(this.single_user.token, addr.id);
      if (ok) {
        this.success_notification(this.i18n.t('text_default_address_updated'));
        await this.loadAddresses();
      } else {
        this.error_notification(this.i18n.t('text_unable_to_update_address'));
      }
    } catch {
      this.error_notification(this.i18n.t('text_unable_to_update_address'));
    } finally {
      this.ui_controls.busy_id = 0;
    }
  }

  async deleteAddress(addr: SavedAddress): Promise<void> {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    const confirmed = window.confirm(this.i18n.t('text_confirm_delete_address'));
    if (!confirmed) {
      return;
    }
    this.ui_controls.busy_id = addr.id;
    try {
      const ok = await this.addressService.remove(this.single_user.token, addr.id);
      if (ok) {
        this.success_notification(this.i18n.t('text_address_deleted'));
        await this.loadAddresses();
      } else {
        this.error_notification(this.i18n.t('text_unable_to_delete_address'));
      }
    } catch {
      this.error_notification(this.i18n.t('text_unable_to_delete_address'));
    } finally {
      this.ui_controls.busy_id = 0;
    }
  }

  // ── Header nav ──────────────────────────────────────────────────────
  user_wishlist() {
    this.router.navigate(['/', 'wishlist']);
  }
  user_cart() {
    this.router.navigate(['/', 'cart']);
  }

  error_notification(message: string) {
    this.toast.error(message, { position: 'top-center' });
  }
  success_notification(message: string) {
    this.toast.success(message, { position: 'top-center' });
  }
}
