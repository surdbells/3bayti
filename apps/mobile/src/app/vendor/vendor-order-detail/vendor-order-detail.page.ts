import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCard,
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  NavController,
  Platform,
} from '@ionic/angular/standalone';
import { ActivatedRoute, Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';
import { ActionSheetController } from '@ionic/angular';

import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';

/**
 * Vendor order detail page with per-item transition controls.
 *
 * Backend contracts (M3.1.7-C):
 *   GET   /v3/vendor/orders/:id
 *   PATCH /v3/vendor/orders/:orderId/items/:itemId/status
 *
 * Item state machine (vendor-allowed transitions):
 *   pending    → accepted | rejected
 *   accepted   → preparing
 *   preparing  → shipped
 *   shipped    → delivered
 *
 * (cancellation/returned/refunded require admin; vendor pages don't
 * expose those buttons.)
 *
 * Per-item buttons are shown ONLY when the transition is legal from
 * the current item status, mirroring the server's state machine
 * enforced in OrderItem::legalTransitions(). The server is still
 * authoritative — if a stale client tries an illegal transition,
 * it gets a 422 with allowed_transitions in the details payload.
 */
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
  status: string;
  total: string;
  currency: string;
  date: string;
  items: VendorOrderItem[];
}

@Component({
  selector: 'app-vendor-order-detail',
  templateUrl: './vendor-order-detail.page.html',
  styleUrls: ['./vendor-order-detail.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonCard,
    CommonModule,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AppTabBarComponent,
  ],
})
export class VendorOrderDetailPage implements OnInit {
  order: VendorOrder | null = null;
  orderId = 0;

  ui_controls = {
    is_loading: false,
    is_transitioning: false,
  };

  private token = '';

  constructor(
    private nav: NavController,
    private router: Router,
    private route: ActivatedRoute,
    private platform: Platform,
    private toast: AxNotificationService,
    private actionSheetCtrl: ActionSheetController,
    private mobileAdapter: MobileNetworkAdapter,
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
      this.toast.error(this.i18n.t('vendor_access_required'));
      this.router.navigate(['/', 'home']);
      return;
    }

    const idParam = this.route.snapshot.paramMap.get('id');
    this.orderId = idParam ? parseInt(idParam, 10) : 0;
    if (this.orderId <= 0) {
      this.toast.error(this.i18n.t('vendor_order_invalid_id'));
      this.router.navigate(['/', 'vendor-orders']);
      return;
    }

    this.loadOrder();
  }

  goBack() {
    this.nav.back();
  }

  private loadOrder() {
    this.ui_controls.is_loading = true;
    this.mobileAdapter
      .get_v3('GET /vendor/orders/:id', {
        authToken: this.token,
        pathParams: { id: String(this.orderId) },
      })
      .subscribe({
        next: (response: any) => {
          this.ui_controls.is_loading = false;
          if (response.response_code === 200 && response.status === 'success') {
            const o = response.data?.order;
            if (o) {
              this.order = {
                id: o.id,
                order_reference: o.order_reference,
                status: o.status,
                total: o.total,
                currency: o.currency,
                date: o.date,
                items: Array.isArray(o.items) ? o.items : [],
              };
            } else {
              this.toast.error(this.i18n.t('vendor_order_not_found'));
              this.router.navigate(['/', 'vendor-orders']);
            }
          } else if (response.response_code === 404) {
            this.toast.error(this.i18n.t('vendor_order_not_found'));
            this.router.navigate(['/', 'vendor-orders']);
          } else {
            this.toast.error(response.message || this.i18n.t('vendor_order_load_failed'));
          }
        },
        error: () => {
          this.ui_controls.is_loading = false;
          this.toast.error(this.i18n.t('vendor_order_network_error'));
        },
      });
  }

  // ===================================================================
  // State machine — which transitions show as buttons for an item
  // ===================================================================
  // Mirrors backend OrderItem::legalTransitions() — but limited to the
  // vendor-allowed subset (no cancelled/returned/refunded buttons).
  // Server is authoritative; this is just UX gating.
  // ===================================================================
  canAccept(item: VendorOrderItem): boolean {
    return item.item_status === 'pending';
  }
  canReject(item: VendorOrderItem): boolean {
    return item.item_status === 'pending';
  }
  canMarkPreparing(item: VendorOrderItem): boolean {
    return item.item_status === 'accepted';
  }
  canMarkShipped(item: VendorOrderItem): boolean {
    return item.item_status === 'preparing';
  }
  canMarkDelivered(item: VendorOrderItem): boolean {
    return item.item_status === 'shipped';
  }

  async confirmTransition(item: VendorOrderItem, newStatus: string, labelKey: string) {
    const label = this.i18n.t(labelKey);
    const sheet = await this.actionSheetCtrl.create({
      header: `${label}?`,
      subHeader: item.product_name,
      buttons: [
        {
          text: this.i18n.t('vendor_order_confirm'),
          role: newStatus === 'rejected' ? 'destructive' : undefined,
          handler: () => {
            this.executeTransition(item, newStatus);
            return true;
          },
        },
        { text: this.i18n.t('cancel'), role: 'cancel' },
      ],
    });
    await sheet.present();
  }

  private executeTransition(item: VendorOrderItem, newStatus: string) {
    if (this.ui_controls.is_transitioning) return; // debounce
    this.ui_controls.is_transitioning = true;

    const originalStatus = item.item_status;
    // Optimistic update for snappier feedback
    item.item_status = newStatus;

    this.mobileAdapter
      .patch_v3(
        'PATCH /vendor/orders/:orderId/items/:itemId/status',
        { status: newStatus },
        {
          authToken: this.token,
          pathParams: {
            orderId: String(this.orderId),
            itemId: String(item.id),
          },
        },
      )
      .subscribe({
        next: (response: any) => {
          this.ui_controls.is_transitioning = false;
          if (response.response_code === 200 && response.status === 'success') {
            // Refresh from server to pick up order-level rollup
            this.toast.success(
              this.i18n.t('vendor_order_item_marked', { status: newStatus }),
            );
            this.loadOrder();
          } else {
            // Rollback optimistic update
            item.item_status = originalStatus;
            this.toast.error(response.message || this.i18n.t('vendor_order_transition_failed'));
          }
        },
        error: () => {
          // Rollback optimistic update
          item.item_status = originalStatus;
          this.ui_controls.is_transitioning = false;
          this.toast.error(this.i18n.t('vendor_order_transition_network_error'));
        },
      });
  }
}
