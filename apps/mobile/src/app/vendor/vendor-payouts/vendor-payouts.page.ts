import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  IonButton,
  IonButtons,
  NavController,
} from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';

import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { I18nService } from '../../i18n.service';

/**
 * Vendor payout / bank details.
 *
 * Backend contract (M3.4-A):
 *   GET   /v3/vendor/store/payment -> { store_bank_name, store_account_name,
 *                                       store_account_number }
 *   PATCH /v3/vendor/store/payment -> same (partial; only sent fields apply)
 *
 * This is where payouts land — the API exposes no payout *ledger* for
 * vendors, so this page configures destination bank details rather than
 * showing a statement.
 */
interface Payment {
  store_bank_name: string;
  store_account_name: string;
  store_account_number: string;
}

@Component({
  selector: 'app-vendor-payouts',
  templateUrl: './vendor-payouts.page.html',
  styleUrls: ['./vendor-payouts.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    CommonModule,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
  ],
})
export class VendorPayoutsPage implements OnInit {
  form: Payment = { store_bank_name: '', store_account_name: '', store_account_number: '' };

  ui_controls = {
    is_loading: false,
    is_saving: false,
  };

  private token = '';

  constructor(
    private nav: NavController,
    private router: Router,
    private toast: AxNotificationService,
    private adapter: MobileNetworkAdapter,
    private i18n: I18nService,
  ) {}

  async ngOnInit() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
      return;
    }
    const user = JSON.parse(ret.value);
    this.token = user.token;

    if (!user.is_vendor) {
      this.toast.error(this.i18n.t('vendor_access_required'), { position: 'top-center' });
      this.router.navigate(['/', 'home']);
      return;
    }

    this.load();
  }

  goBack() {
    this.nav.back();
  }

  private load() {
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /vendor/store/payment', { authToken: this.token }).subscribe({
      next: (response: any) => {
        this.ui_controls.is_loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const d = response.data ?? {};
          this.form = {
            store_bank_name: d.store_bank_name ?? '',
            store_account_name: d.store_account_name ?? '',
            store_account_number: d.store_account_number ?? '',
          };
        } else if (response.message) {
          this.toast.error(response.message, { position: 'top-center' });
        }
      },
      error: () => {
        this.ui_controls.is_loading = false;
        this.toast.error(this.i18n.t('network_error_retry'), { position: 'top-center' });
      },
    });
  }

  save() {
    if (!this.form.store_bank_name.trim() || !this.form.store_account_name.trim() || !this.form.store_account_number.trim()) {
      this.toast.error(this.i18n.t('payout_all_fields_required'), { position: 'top-center' });
      return;
    }

    this.ui_controls.is_saving = true;
    this.adapter
      .patch_v3('PATCH /vendor/store/payment', {
        store_bank_name: this.form.store_bank_name.trim(),
        store_account_name: this.form.store_account_name.trim(),
        store_account_number: this.form.store_account_number.trim(),
      }, { authToken: this.token })
      .subscribe({
        next: (response: any) => {
          this.ui_controls.is_saving = false;
          if (response.response_code === 200 && response.status === 'success') {
            this.toast.success(this.i18n.t('payout_details_saved'), { position: 'top-center' });
          } else {
            this.toast.error(response.message ?? this.i18n.t('update_failed'), { position: 'top-center' });
          }
        },
        error: () => {
          this.ui_controls.is_saving = false;
          this.toast.error(this.i18n.t('update_failed'), { position: 'top-center' });
        },
      });
  }
}
