import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCard,
  IonContent,
  IonHeader,
  IonInfiniteScroll,
  IonInfiniteScrollContent,
  IonTitle,
  IonToolbar,
  NavController,
  Platform,
} from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';
import { InfiniteScrollCustomEvent } from '@ionic/angular';

import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';

/**
 * Vendor's view of their own orders.
 *
 * Backend contract (M3.1.7-C):
 *   GET /v3/vendor/orders?limit&offset&status
 *
 * Returns orders containing AT LEAST ONE item belonging to one of
 * the authenticated user's stores. Items from OTHER vendors in the
 * same order are filtered out server-side. The vendor never sees
 * items they don't own.
 *
 * Status filter
 * -------------
 * pending_payment is intentionally excluded from the vendor view —
 * those orders haven't been paid yet and aren't ready for vendor
 * action. The filter chips offer the post-payment statuses only.
 */
type VendorOrderStatus =
  | 'paid'
  | 'fulfilling'
  | 'shipped'
  | 'delivered'
  | 'cancelled'
  | 'refunded'
  | 'failed';
type VendorFilterStatus = 'all' | VendorOrderStatus;

interface VendorOrderItem {
  id: number;
  product_id: number;
  vendor_id: number;
  store: number;
  product_name: string;
  product_image: string | null;
  quantity: number;
  unit_price: string;
  subtotal: string;
  item_status: string;
}

interface VendorOrder {
  id: number;
  order_reference: string;
  status: VendorOrderStatus;
  total: string;
  currency: string;
  date: string;
  items: VendorOrderItem[];
  showItems?: boolean;
}

@Component({
  selector: 'app-vendor-orders',
  templateUrl: './vendor-orders.page.html',
  styleUrls: ['./vendor-orders.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonCard,
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    CommonModule,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AppTabBarComponent,
  ],
})
export class VendorOrdersPage implements OnInit {
  orders: VendorOrder[] = [];
  statuses: VendorFilterStatus[] = ['all', 'paid', 'fulfilling', 'shipped', 'delivered'];
  selectedStatus: VendorFilterStatus = 'all';

  ui_controls = {
    is_empty: false,
    is_loading: false,
    has_more: true,
  };

  private initial = {
    token: '',
    limit: 10,
    offset: 0,
  };

  constructor(
    private nav: NavController,
    private router: Router,
    private platform: Platform,
    private toast: AxNotificationService,
    private mobileAdapter: MobileNetworkAdapter,
  ) {}

  async ngOnInit() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
      return;
    }
    const user = JSON.parse(ret.value);
    this.initial.token = user.token;

    // Gate: only vendor users get past this page. If a non-vendor
    // somehow navigates here, kick them back to the dashboard.
    if (!user.is_vendor) {
      this.toast.error('Vendor access required for this page.');
      this.router.navigate(['/', 'home']);
      return;
    }

    this.loadOrders(true);
  }

  selectStatus(s: VendorFilterStatus) {
    this.selectedStatus = s;
    this.loadOrders(true);
  }

  toggleItems(index: number) {
    this.orders[index].showItems = !this.orders[index].showItems;
  }

  goBack() {
    this.nav.back();
  }

  openDetail(order: VendorOrder) {
    this.router.navigate(['/', 'vendor-orders', order.id]);
  }

  onIonInfinite(event: InfiniteScrollCustomEvent) {
    if (!this.ui_controls.has_more) {
      event.target.complete();
      return;
    }
    this.initial.offset += this.initial.limit;
    this.loadOrders(false).then(() => event.target.complete());
  }

  private loadOrders(reset: boolean): Promise<void> {
    return new Promise((resolve) => {
      if (reset) {
        this.initial.offset = 0;
        this.orders = [];
        this.ui_controls.is_loading = true;
      }

      const queryParams: Record<string, string | number> = {
        limit: this.initial.limit,
        offset: this.initial.offset,
      };
      if (this.selectedStatus !== 'all') {
        queryParams['status'] = this.selectedStatus;
      }

      this.mobileAdapter
        .get_v3('GET /vendor/orders', {
          authToken: this.initial.token,
          queryParams,
        })
        .subscribe({
          next: (response: any) => {
            this.ui_controls.is_loading = false;
            if (response.response_code === 200 && response.status === 'success') {
              const newOrders = this.extractOrders(response.data);
              this.orders.push(...newOrders);
              this.ui_controls.is_empty = this.orders.length === 0;
              this.ui_controls.has_more = newOrders.length >= this.initial.limit;
            } else {
              this.ui_controls.is_empty = this.orders.length === 0;
              this.ui_controls.has_more = false;
              if (response.message) {
                this.toast.error(response.message);
              }
            }
            resolve();
          },
          error: () => {
            this.ui_controls.is_loading = false;
            this.toast.error('Network error. Pull to refresh.');
            resolve();
          },
        });
    });
  }

  private extractOrders(data: any): VendorOrder[] {
    if (!data || typeof data !== 'object') return [];
    const arr = Array.isArray(data) ? data : (Array.isArray(data.orders) ? data.orders : []);
    return arr.map((o: any) => ({
      id: o.id,
      order_reference: o.order_reference,
      status: o.status,
      total: o.total,
      currency: o.currency,
      date: o.date,
      items: Array.isArray(o.items) ? o.items : [],
      showItems: false,
    }));
  }
}
