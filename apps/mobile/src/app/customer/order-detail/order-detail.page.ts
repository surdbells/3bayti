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
import { OrderPaymentService } from './order-payment.service';
import { PendingOrdersService } from '../../core/services/pending-orders.service';

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

interface TimelineStep {
  key: string;
  /** i18n key for the stage label. */
  labelKey: string;
  /** done = reached earlier, current = latest reached, upcoming = not yet,
   *  ended = a terminal state (cancelled/refunded/failed). */
  state: 'done' | 'current' | 'upcoming' | 'ended';
  /** ISO timestamp when known (placed + paid only); null otherwise. */
  at: string | null;
}

/** A real event from GET /orders/:id/timeline (the full server feed). */
interface TimelineEvent {
  id: string;
  occurred_at: string | null;
  summary: string;
  /** 'ended' = a terminal/negative event (cancel/refund/denied) → danger marker. */
  state: 'done' | 'ended';
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
  /** Full server-side event feed; when populated it replaces the derived
   *  stepper. Empty → the template falls back to timeline() (client-derived). */
  timelineEvents: TimelineEvent[] = [];

  ui_controls = {
    is_loading: false,
    is_cancelling: false,
    is_paying: false,
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
    private orderPayment: OrderPaymentService,
    private pendingOrders: PendingOrdersService,
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

  /** Show the "Complete payment" affordance only while the order awaits payment. */
  canPay(): boolean {
    return this.order?.status === 'pending_payment' && !this.ui_controls.is_paying;
  }

  /**
   * Resume payment for this pending order (mobile "Complete payment").
   * Delegates to OrderPaymentService — initiate → in-app browser → poll —
   * then reloads the order so its status reflects the outcome.
   */
  completePayment(): void {
    const order = this.order;
    if (order === null || !this.canPay()) return;
    this.ui_controls.is_paying = true;
    this.orderPayment.pay(order.order_reference, this.token, {
      onPaid: () => {
        this.ui_controls.is_paying = false;
        this.loadOrder();
        void this.pendingOrders.refresh();
      },
      onFailed: () => {
        this.ui_controls.is_paying = false;
      },
    });
  }

  /** Whether the money breakdown should show a discount line. */
  hasDiscount(): boolean {
    return (this.order?.discount ?? 0) > 0;
  }

  /** Canonical customer lifecycle stages, in order. A stage is "reached" when
   *  the order's current status is in its `statuses` set. */
  private static readonly LIFECYCLE: { key: string; labelKey: string; statuses: string[] }[] = [
    { key: 'paid', labelKey: 'orders_status_paid', statuses: ['paid', 'fulfilling', 'shipping', 'shipped', 'delivered'] },
    { key: 'fulfilling', labelKey: 'orders_status_fulfilling', statuses: ['fulfilling', 'shipping', 'shipped', 'delivered'] },
    { key: 'shipped', labelKey: 'orders_status_shipped', statuses: ['shipping', 'shipped', 'delivered'] },
    { key: 'delivered', labelKey: 'orders_status_delivered', statuses: ['delivered'] },
  ];
  private static readonly TERMINAL: Record<string, string> = {
    cancelled: 'orders_status_cancelled',
    refunded: 'orders_status_refunded',
    failed: 'orders_status_failed',
  };

  /**
   * Order status timeline derived from the fields the detail payload carries
   * (placed `date`, `paid_at`, current `status`). Deliberately honest: only
   * "placed" and "paid" have real timestamps; later stages render as
   * reached/current/upcoming without a time, since per-stage timestamps aren't
   * in the payload. Terminal states (cancelled/refunded/failed) end the track.
   */
  timeline(): TimelineStep[] {
    if (!this.order) return [];
    const status = this.order.status;
    const steps: TimelineStep[] = [
      { key: 'placed', labelKey: 'orders_timeline_placed', state: 'done', at: this.order.date },
    ];

    const terminalKey = OrderDetailPage.TERMINAL[status];
    if (terminalKey) {
      if (this.order.paid_at) {
        steps.push({ key: 'paid', labelKey: 'orders_status_paid', state: 'done', at: this.order.paid_at });
      }
      steps.push({ key: status, labelKey: terminalKey, state: 'ended', at: null });
      return steps;
    }

    const doneFlags = OrderDetailPage.LIFECYCLE.map((s) => s.statuses.includes(status));
    const lastDone = doneFlags.lastIndexOf(true);
    OrderDetailPage.LIFECYCLE.forEach((s, i) => {
      const state: TimelineStep['state'] = doneFlags[i]
        ? (i === lastDone ? 'current' : 'done')
        : 'upcoming';
      steps.push({
        key: s.key,
        labelKey: s.labelKey,
        state,
        at: s.key === 'paid' ? this.order!.paid_at : null,
      });
    });
    // Nothing past "placed" reached yet (pending_payment) → placed is current.
    if (lastDone === -1) {
      steps[0].state = 'current';
    }
    return steps;
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
              this.loadTimeline();
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

  /**
   * Fetch the full customer event feed (GET /orders/:id/timeline), requesting
   * chronological (oldest → newest) order. On ANY failure — including the
   * endpoint not being deployed yet — leaves timelineEvents empty so the
   * template gracefully falls back to the client-derived stepper. Never blocks
   * the order-detail render.
   */
  private loadTimeline() {
    this.mobileAdapter
      .get_v3('GET /orders/:id/timeline', {
        authToken: this.token,
        pathParams: { id: String(this.orderId) },
        queryParams: { order: 'asc' },
      })
      .subscribe({
        next: (response: any) => {
          const events = response?.data?.events;
          if (response?.response_code === 200 && Array.isArray(events)) {
            this.timelineEvents = events.map((e: any) => ({
              id: String(e?.id ?? ''),
              occurred_at: e?.occurred_at ?? null,
              summary: String(e?.summary ?? ''),
              state: /refund|denied|cancel|failed/i.test(String(e?.type ?? '')) ? 'ended' : 'done',
            }));
          }
        },
        error: () => {
          /* Fall back to the derived stepper — timelineEvents stays empty. */
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
            void this.pendingOrders.refresh();
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
