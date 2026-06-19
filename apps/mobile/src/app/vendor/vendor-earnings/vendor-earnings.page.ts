import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  IonButton,
  IonButtons,
  IonRefresher,
  IonRefresherContent,
  NavController,
} from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';
import { RefresherCustomEvent } from '@ionic/angular';

import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { I18nService } from '../../i18n.service';

/**
 * Vendor self-serve earnings summary.
 *
 * Backend contract (M3.2.X.13-E):
 *   GET /v3/vendor/analytics?days=N
 *     -> { vendor, window, totals{revenue_aed,orders,items,aov_aed,
 *          unique_customers}, top_products_by_revenue[], customer_mix,
 *          status_mix }
 *
 * Single-store vendors need no vendor_id. Multi-store users get a 422
 * VENDOR_AMBIGUOUS — surfaced here as a message (store picking isn't built
 * on mobile yet).
 */
interface Totals {
  revenue_aed: string;
  orders: number;
  items: number;
  aov_aed: string;
  unique_customers: number;
}
interface TopProduct {
  product_id: number;
  name: string;
  units: number;
  revenue_aed: string;
}
interface CustomerMix { new: number; returning: number; total: number; }
interface StatusMix { delivered: number; cancelled: number; returned: number; total: number; }

@Component({
  selector: 'app-vendor-earnings',
  templateUrl: './vendor-earnings.page.html',
  styleUrls: ['./vendor-earnings.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonRefresher,
    IonRefresherContent,
    CommonModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
  ],
})
export class VendorEarningsPage implements OnInit {
  windows = [7, 30, 90];
  days = 30;

  totals: Totals = { revenue_aed: '0.00', orders: 0, items: 0, aov_aed: '0.00', unique_customers: 0 };
  topProducts: TopProduct[] = [];
  customerMix: CustomerMix = { new: 0, returning: 0, total: 0 };
  statusMix: StatusMix = { delivered: 0, cancelled: 0, returned: 0, total: 0 };

  ui_controls = {
    is_loading: false,
    error_message: '',
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

  setWindow(d: number) {
    if (this.days === d) return;
    this.days = d;
    this.load();
  }

  handleRefresh(event: RefresherCustomEvent) {
    this.load().then(() => event.target.complete());
  }

  private load(): Promise<void> {
    return new Promise((resolve) => {
      this.ui_controls.is_loading = true;
      this.ui_controls.error_message = '';

      this.adapter
        .get_v3('GET /vendor/analytics', { authToken: this.token, queryParams: { days: this.days } })
        .subscribe({
          next: (response: any) => {
            this.ui_controls.is_loading = false;
            if (response.response_code === 200 && response.status === 'success') {
              this.apply(response.data ?? {});
            } else {
              // 422 VENDOR_AMBIGUOUS (multi-store) or other non-success.
              this.ui_controls.error_message = response.message || this.i18n.t('earnings_unavailable');
            }
            resolve();
          },
          error: () => {
            this.ui_controls.is_loading = false;
            this.ui_controls.error_message = this.i18n.t('network_error_retry');
            resolve();
          },
        });
    });
  }

  private apply(data: any) {
    const t = data.totals ?? {};
    this.totals = {
      revenue_aed: String(t.revenue_aed ?? '0.00'),
      orders: Number(t.orders ?? 0),
      items: Number(t.items ?? 0),
      aov_aed: String(t.aov_aed ?? '0.00'),
      unique_customers: Number(t.unique_customers ?? 0),
    };
    this.topProducts = (Array.isArray(data.top_products_by_revenue) ? data.top_products_by_revenue : [])
      .map((p: any) => ({
        product_id: Number(p.product_id ?? 0),
        name: String(p.name ?? ''),
        units: Number(p.units ?? 0),
        revenue_aed: String(p.revenue_aed ?? '0.00'),
      }));
    const cm = data.customer_mix ?? {};
    this.customerMix = { new: Number(cm.new ?? 0), returning: Number(cm.returning ?? 0), total: Number(cm.total ?? 0) };
    const sm = data.status_mix ?? {};
    this.statusMix = {
      delivered: Number(sm.delivered ?? 0),
      cancelled: Number(sm.cancelled ?? 0),
      returned: Number(sm.returned ?? 0),
      total: Number(sm.total ?? 0),
    };
  }

  aed(v: string | number): string {
    const n = Number(v ?? 0);
    return `AED ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
  }
}
