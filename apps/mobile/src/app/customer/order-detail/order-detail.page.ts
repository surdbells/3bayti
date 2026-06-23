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
  IonCard,
  IonChip,
  IonAvatar,
  IonLabel,
  IonRefresher,
  IonRefresherContent,
  NavController,
  ActionSheetController,
  RefresherCustomEvent,
} from '@ionic/angular/standalone';
import { ActivatedRoute, Router } from '@angular/router';
import { TranslatePipe } from '../../translate.pipe';
import { Preferences } from '@capacitor/preferences';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { I18nService } from '../../i18n.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { cfImage } from '../../shared/cf-image';
import { supportWhatsappLink } from '../../core/constants/support.constants';

/**
 * Customer order-detail screen — M3.2.Z.1.
 *
 * Full per-order view at /orders/:id, replacing the empty onView()
 * stub on my-orders. Reads GET /v3/orders/:id (OrderSerializer
 * detailShape): items (with snapshot name/image/size/color),
 * a money breakdown (subtotal / delivery / discount / total),
 * billing + shipping addresses, applied promo, and inline return
 * summaries. Mirrors the Stream Y web order-detail UX (status pill
 * vocabulary, cancel affordance on pending_payment).
 */

interface OrderDetailItem {
  id: number;
  product_id: number;
  vendor_id: number;
  product_name: string;
  product_image: string | null;
  quantity: number;
  unit_price: number;
  subtotal: number;
  size: string | null;
  color: string | null;
  is_custom: boolean;
  item_status: string;
}

interface OrderAddress {
  first_name: string | null;
  last_name: string | null;
  phone: string | null;
  email: string | null;
  street: string | null;
  city: string | null;
  state_province: string | null;
  country_code: string | null;
  postal_code: string | null;
}

interface AppliedPromo {
  code: string;
  type: string;
  value: number;
  discount_amount: number;
}

interface ReturnSummary {
  id: number;
  status: string;
}

interface OrderDetail {
  id: number;
  order_reference: string;
  status: string;
  date: string;
  subtotal: number;
  delivery_fee: number;
  discount: number;
  total: number;
  currency: string;
  paid_at: string | null;
  items: OrderDetailItem[];
  applied_promo: AppliedPromo | null;
  returns: ReturnSummary[];
  billing_address: OrderAddress | null;
  shipping_address: OrderAddress | null;
}

@Component({
  selector: 'app-order-detail',
  templateUrl: './order-detail.page.html',
  styleUrls: ['./order-detail.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonCard,
    IonChip,
    IonAvatar,
    IonLabel,
    IonRefresher,
    IonRefresherContent,
    CommonModule,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AppTabBarComponent,
  ],
})
export class OrderDetailPage implements OnInit {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  order: OrderDetail | null = null;
  orderId = 0;

  ui_controls = {
    is_loading: false,
    is_cancelling: false,
    not_found: false,
  };

  private token = '';

  constructor(
    private nav: NavController,
    private router: Router,
    private route: ActivatedRoute,
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

    const idParam = this.route.snapshot.paramMap.get('id');
    this.orderId = idParam ? parseInt(idParam, 10) : 0;
    if (this.orderId <= 0) {
      this.toast.error(this.i18n.t('order_detail_invalid_id'));
      this.router.navigate(['/', 'my-orders']);
      return;
    }

    this.loadOrder();
  }

  goBack() {
    this.nav.back();
  }

  handleRefresh(event: RefresherCustomEvent) {
    this.loadOrder(() => event.target.complete());
  }

  /** True only for pending_payment — mirrors the web/my-orders rule. */
  canCancel(): boolean {
    return this.order?.status === 'pending_payment' && !this.ui_controls.is_cancelling;
  }

  /** Whether the money breakdown should show a discount line. */
  hasDiscount(): boolean {
    return (this.order?.discount ?? 0) > 0;
  }

  private loadOrder(done?: () => void) {
    this.ui_controls.is_loading = true;
    this.ui_controls.not_found = false;
    this.mobileAdapter
      .get_v3('GET /orders/:id', {
        authToken: this.token,
        pathParams: { id: String(this.orderId) },
      })
      .subscribe({
        next: (response: any) => {
          this.ui_controls.is_loading = false;
          if (done) done();
          if (response.response_code === 200 && response.status === 'success') {
            const o = response.data?.order;
            if (o) {
              this.order = this.mapOrder(o);
            } else {
              this.ui_controls.not_found = true;
            }
          } else if (response.response_code === 404) {
            this.ui_controls.not_found = true;
          } else {
            this.toast.error(response.message || this.i18n.t('order_detail_load_failed'));
          }
        },
        error: () => {
          this.ui_controls.is_loading = false;
          if (done) done();
          this.toast.error(this.i18n.t('order_detail_network_error'));
        },
      });
  }

  private mapOrder(o: any): OrderDetail {
    return {
      id: o.id,
      order_reference: o.order_reference ?? '',
      status: o.status ?? '',
      date: o.date ?? '',
      subtotal: Number(o.subtotal ?? 0),
      delivery_fee: Number(o.delivery_fee ?? 0),
      discount: Number(o.discount ?? 0),
      total: Number(o.total ?? 0),
      currency: o.currency ?? 'AED',
      paid_at: o.paid_at ?? null,
      items: Array.isArray(o.items)
        ? o.items.map((it: any) => ({
            id: it.id,
            product_id: it.product_id,
            vendor_id: it.vendor_id,
            product_name: it.product_name ?? '',
            product_image: it.product_image ?? null,
            quantity: Number(it.quantity ?? 0),
            unit_price: Number(it.unit_price ?? 0),
            subtotal: Number(it.subtotal ?? 0),
            size: it.size ?? null,
            color: it.color ?? null,
            is_custom: it.is_custom === true,
            item_status: it.item_status ?? '',
          }))
        : [],
      applied_promo: o.applied_promo
        ? {
            code: o.applied_promo.code,
            type: o.applied_promo.discount_type,
            value: Number(o.applied_promo.discount_value ?? 0),
            discount_amount: Number(o.applied_promo.discount_amount ?? 0),
          }
        : null,
      returns: Array.isArray(o.returns)
        ? o.returns.map((r: any) => ({ id: r.id, status: r.status }))
        : [],
      billing_address: o.billing_address ?? null,
      shipping_address: o.shipping_address ?? null,
    };
  }

  async confirmCancel() {
    const order = this.order;
    if (order === null || !this.canCancel()) return;

    const sheet = await this.actionSheetCtrl.create({
      header: this.i18n.t('order_detail_cancel_confirm'),
      buttons: [
        {
          text: this.i18n.t('order_detail_cancel_yes'),
          role: 'destructive',
          handler: () => this.executeCancel(),
        },
        { text: this.i18n.t('order_detail_cancel_keep'), role: 'cancel' },
      ],
    });
    await sheet.present();
  }

  private executeCancel() {
    const order = this.order;
    if (order === null) return;
    this.ui_controls.is_cancelling = true;
    this.mobileAdapter
      .post_v3('POST /orders/:id/cancel', { reason: 'customer self-serve' }, {
        authToken: this.token,
        pathParams: { id: String(order.id) },
      })
      .subscribe({
        next: (response: any) => {
          this.ui_controls.is_cancelling = false;
          // Diagnostic: exact API outcome (404 route / 401 auth / 422
          // cancellation_not_allowed) if cancel appears to "do nothing".
          console.warn('[cancel-order:detail]', {
            id: order.id,
            response_code: response?.response_code,
            status: response?.status,
            error_code: response?.error_code,
            message: response?.message,
          });
          if (response.response_code === 200 && response.status === 'success') {
            order.status = 'cancelled';
            const wasIdempotent =
              response.data?.cancellation?.was_already_cancelled === true;
            this.toast.success(
              wasIdempotent
                ? this.i18n.t('order_detail_already_cancelled')
                : this.i18n.t('order_detail_cancelled'),
            );
            // Re-load so the detail authoritatively reflects the new status.
            this.loadOrder();
          } else {
            this.toast.error(response.message || this.i18n.t('order_detail_cancel_failed'));
          }
        },
        error: (err: any) => {
          this.ui_controls.is_cancelling = false;
          console.warn('[cancel-order:detail] network/transport error', err);
          this.toast.error(this.i18n.t('order_detail_network_error'));
        },
      });
  }

  open_vendor(id: number, name: string) {
    this.router.navigate(['/', 'vendor-reviews'], { queryParams: { id, name } });
  }

  /** Open WhatsApp support — keeps a support affordance on the order screen. */
  openSupport() {
    window.open(supportWhatsappLink('Hello, I need assistance.'), '_system');
  }
}
