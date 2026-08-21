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
  Platform
} from '@ionic/angular/standalone';
import {TranslatePipe} from "../../translate.pipe";
import {I18nService} from "../../i18n.service";
import {ConnectionService} from "../../service/connection.service";
import {ActivatedRoute, Router} from "@angular/router";
import {ActionSheetController, InfiniteScrollCustomEvent} from "@ionic/angular";
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import { apiErrorMessage } from '../../core/http/api-error';
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import {Products} from "../../class/products";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { cfImage } from '../../shared/cf-image';
// M3.1.7-I: widen OrderStatus to include v3 backend's full enum so
// the cancel button can show conditionally on pending_payment. The
// FilterStatus chips still reflect the customer-friendly subset.
type OrderStatus =
  | 'pending_payment'
  | 'paid'
  | 'processing'
  | 'fulfilling'
  | 'shipping'
  | 'shipped'
  | 'delivered'
  | 'cancelled'
  | 'refunded'
  | 'failed';
// M3.1.7-I.6 — status filter chips. 'all' = no server filter; every other
// value is a real Order status enum sent verbatim as ?status= to GET /v3/orders
// (server-side filtering, so it composes with offset/infinite-scroll).
type FilterStatus =
  | 'all'
  | 'pending_payment'
  | 'paid'
  | 'fulfilling'
  | 'shipped'
  | 'delivered'
  | 'cancelled';

interface StatusChip {
  /** Filter bucket — 'all' or a real Order status. */
  value: FilterStatus;
  /** i18n key for the chip label. */
  labelKey: string;
}
// Order type filter. 'all' = no server filter; 'product' / 'gift_card' are
// sent verbatim as ?type= to GET /v3/orders (server-side EXISTS/NOT EXISTS on
// a linked gift card). Composes with the status filter + offset pagination.
type FilterType = 'all' | 'product' | 'gift_card';

interface TypeChip {
  /** Filter bucket — 'all', 'product', or 'gift_card'. */
  value: FilterType;
  /** i18n key for the chip label. */
  labelKey: string;
}
interface OrderItem {
  product_id: number;
  store: number;
  product_name: string;
  price: number;
  quantity: number;
  status: string;
  product_image: string;
}

interface Order {
  id: string;
  order_reference?: string;
  date: Date;
  status: OrderStatus;
  items: OrderItem[];  // list of items in the order
  total: number;
  showItems?: boolean;// order total
}

@Component({
  selector: 'app-my-orders',
  templateUrl: './my-orders.page.html',
  styleUrls: ['./my-orders.page.scss'],
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
    AppTabBarComponent
  ]
})
export class MyOrdersPage implements OnInit {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  orders: Order[] = [];
  // filter chips — order matches the customer order lifecycle. Each value
  // (except 'all') is a real Order status sent server-side as ?status=.
  statuses: StatusChip[] = [
    { value: 'all', labelKey: 'orders_filter_all' },
    { value: 'pending_payment', labelKey: 'orders_filter_pending_payment' },
    { value: 'paid', labelKey: 'orders_filter_paid' },
    { value: 'fulfilling', labelKey: 'orders_filter_fulfilling' },
    { value: 'shipped', labelKey: 'orders_filter_shipped' },
    { value: 'delivered', labelKey: 'orders_filter_delivered' },
    { value: 'cancelled', labelKey: 'orders_filter_cancelled' },
  ];
  selectedStatus: FilterStatus = 'all';
  // Type filter chips — parallel to the status chips. 'all' sends no ?type=;
  // 'product' / 'gift_card' filter server-side by whether the order has a
  // linked gift card.
  types: TypeChip[] = [
    { value: 'all', labelKey: 'orders_type_all' },
    { value: 'product', labelKey: 'orders_type_products' },
    { value: 'gift_card', labelKey: 'orders_type_gift_cards' },
  ];
  selectedType: FilterType = 'all';
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private route: ActivatedRoute,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private mobileAdapter: MobileNetworkAdapter,
    private i18n: I18nService,
  ) {}
  ui_controls = {
    is_empty: false,
    is_loading: false,
    is_creating: false
  }
  ngOnInit() {
    // Loaded in ionViewWillEnter so the list refreshes on every entry (Ionic
    // caches the page, so ngOnInit runs only once — otherwise the orders show
    // a stale/empty snapshot after navigating away and back).
  }
  ionViewWillEnter() {
    // Honour a ?status= deep-link (e.g. the Home "payment pending" banner opens
    // this list already filtered to pending payment). Guarded against unknown
    // values so only real filter chips can be pre-selected.
    const qpStatus = this.route.snapshot.queryParamMap.get('status');
    if (qpStatus && this.statuses.some((s) => s.value === qpStatus)) {
      this.selectedStatus = qpStatus as FilterStatus;
    }
    this.getObject();
  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.initial.id = this.single_user.id;
      this.initial.token = this.single_user.token;
      this.order_listing();
    }
  }
  initial = {
    id: 0,
    token: "",
    limit: 10,
    offset: 0
  }
  single_user = {
    id: 0,
    token: "",
    first_name: "",
    last_name: "",
    user_type: "",
    email: "",
    phone: "",
    avatar: "",
    location: "",
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false
  }
  // Build the query params for a list fetch, appending ?status= only when a
  // non-'all' chip is selected. 'all' sends no status so the server returns
  // every status.
  private listQueryParams(): Record<string, string | number> {
    const params: Record<string, string | number> = {
      limit: this.initial.limit,
      offset: this.initial.offset,
    };
    if (this.selectedStatus !== 'all') {
      params['status'] = this.selectedStatus;
    }
    if (this.selectedType !== 'all') {
      params['type'] = this.selectedType;
    }
    return params;
  }

  order_listing() {
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.initial.limit = 10;
    this.initial.offset = 0;
    // Direct v3 (GET /v3/orders). transformOrderListResponse still applies
    // via get_v3, so response.data keeps the {orders, pagination} shape that
    // extractOrders() handles. limit/offset + the selected status filter
    // (when not 'all') are sent server-side so filtering composes with
    // offset/infinite-scroll.
    this.networkAdapter
      .get_v3('GET /orders', {
        authToken: this.single_user.token,
        queryParams: this.listQueryParams(),
      })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            // Dual-shape support for M3.1.6 strangler-fig migration:
            //   Legacy: response.data = orders[] (direct array)
            //   v3 (post-transform): response.data = {orders, pagination}
            this.orders = this.extractOrders(response.data);
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = this.orders.length === 0;
          } else {
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = true;
          }
        }
      }))
  }
  // Extract orders array from either legacy (data=array) or v3
  // (data={orders, pagination}) response shapes. Defensive against
  // null/malformed inputs — returns [] rather than throwing.
  private extractOrders(data: any): any[] {
    if (Array.isArray(data)) return data;
    if (data && typeof data === 'object' && Array.isArray(data.orders)) {
      return data.orders;
    }
    return [];
  }
  toggleItems(index: number) {
    this.orders[index].showItems = !this.orders[index].showItems;
  }

  // ── Item display helpers ───────────────────────────────────────────
  //
  // The list binds the API's item rows directly, so these normalise the
  // few fields whose names differ (or are absent) rather than remapping
  // every row on load.

  /**
   * A gift-card purchase is a synthetic order line: product_id 0 and no real
   * product image. Used to render a themed placeholder instead of a broken
   * <img>, and to hide the per-item fulfilment pill (nothing ships).
   */
  isGiftCardItem(item: any): boolean {
    return Number(item?.product_id ?? 0) === 0;
  }

  /** True when there is an image worth rendering. */
  hasItemImage(item: any): boolean {
    return typeof item?.product_image === 'string' && item.product_image.trim() !== '';
  }

  /**
   * Item rows to render for a card: just the first one when collapsed, all of
   * them when expanded. Collapsing to one row (rather than swapping to a
   * thumbnail strip) keeps the card the same shape either way, so expanding
   * grows the list in place instead of changing its layout.
   */
  visibleItems(order: any): any[] {
    const items: any[] = Array.isArray(order?.items) ? order.items : [];
    return order?.showItems ? items : items.slice(0, 1);
  }

  /**
   * Unit price. v3 returns `unit_price`; the legacy shape used `price`.
   * Without this the expanded card rendered a bare currency symbol.
   */
  itemPrice(item: any): number {
    return Number(item?.unit_price ?? item?.price ?? 0);
  }

  // i18n key for the per-filter empty state. Each non-'all' chip gets a
  // tailored "no <status> orders" message; 'all' falls back to the generic
  // "no orders yet" heading.
  emptyHeadingKey(): string {
    switch (this.selectedStatus) {
      case 'pending_payment': return 'orders_empty_pending_payment';
      case 'paid': return 'orders_empty_paid';
      case 'fulfilling': return 'orders_empty_fulfilling';
      case 'shipped': return 'orders_empty_shipped';
      case 'delivered': return 'orders_empty_delivered';
      case 'cancelled': return 'orders_empty_cancelled';
      default: return 'heading_no_orders';
    }
  }

  // i18n key for an order's status badge — maps the raw enum to a
  // customer-friendly label. Falls back to a humanised key so a new
  // backend status still renders something sensible.
  statusLabelKey(status: string): string {
    switch (status) {
      case 'pending_payment': return 'orders_status_pending_payment';
      case 'paid': return 'orders_status_paid';
      case 'processing': return 'orders_status_processing';
      case 'fulfilling': return 'orders_status_fulfilling';
      case 'shipping':
      case 'shipped': return 'orders_status_shipped';
      case 'delivered': return 'orders_status_delivered';
      case 'cancelled': return 'orders_status_cancelled';
      case 'refunded': return 'orders_status_refunded';
      case 'failed': return 'orders_status_failed';
      default: return 'orders_status_pending_payment';
    }
  }

  // itemCount() was dropped with the collapsed thumbnail-strip summary — the
  // card now lists item rows directly, each showing its own quantity.

  // Switch the active status chip and refetch from offset 0 so the list
  // authoritatively reflects the server-side filtered page (rather than
  // filtering the already-loaded — possibly partial — client list).
  selectStatus(s: FilterStatus) {
    if (this.selectedStatus === s) {
      return;
    }
    this.selectedStatus = s;
    this.orders = [];
    this.order_listing();
  }

  // Switch the active type chip and refetch from offset 0 (mirrors
  // selectStatus) so the list reflects the server-side ?type= filtered page.
  selectType(t: FilterType) {
    if (this.selectedType === t) {
      return;
    }
    this.selectedType = t;
    this.orders = [];
    this.order_listing();
  }

  goBack() {
    this.nav.back();
  }

  onView(order: Order) {
    this.router.navigate(['/', 'orders', order.id]);
  }

  onReview(order: Order) {
    // open review modal / route
  }

  getMoreItems() {
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.offset = this.initial.offset + this.initial.limit
    // Direct v3 (GET /v3/orders) — same paginated read as order_listing(),
    // advancing offset for infinite scroll. Carries the active status filter
    // so paged-in results stay scoped to the selected chip.
    this.networkAdapter
      .get_v3('GET /orders', {
        authToken: this.single_user.token,
        queryParams: this.listQueryParams(),
      })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.orders.push(...this.extractOrders(response.data));
          }else{
            this.ui_controls.is_empty = true;
          }
        }
      }))
  }
  onIonInfinite(event: InfiniteScrollCustomEvent) {
    this.getMoreItems();
    setTimeout(() => {
      event.target.complete();
    }, 500);
  }
  user_messages() {
    this.router.navigate(['/', 'chat-vendors']);
  }
  search() {
    this.router.navigate(['/', 'search']);
  }
  open_vendor(id: number, name: string) {
    // Tapping an order item opens the VENDOR STORE (the card's own "View
    // details" button opens the order). This previously pointed at
    // 'vendor-reviews' — a copy-paste slip that sent shoppers to a reviews
    // page and fed the vendors<->reviews back-navigation loop.
    this.router.navigate(
      ['/', 'vendors'],
      { queryParams: { id, name } }
    );
  }

  // ===================================================================
  // M3.1.7-F/I — Customer self-serve order cancellation
  // ===================================================================
  // Only orders in 'pending_payment' can be cancelled by the customer
  // themselves (paid orders require admin / support contact). The
  // template shows the button conditionally; this method runs the
  // confirmation + API call.
  //
  // On success:
  //   - Backend marks the order CANCELLED + audits the override
  //   - We update the local order's status optimistically so the UI
  //     reflects the change without a re-fetch round-trip
  //   - Toast confirms the cancellation
  //
  // On failure (network / 422 cancellation_not_allowed / 502 gateway):
  //   - Toast surfaces the message
  //   - Local order stays in its original state
  // ===================================================================
  canCancel(order: Order): boolean {
    return order.status === 'pending_payment';
  }

  async confirmCancel(order: Order) {
    const sheet = await this.actionSheetCtrl.create({
      header: this.i18n.t('cancel_order_confirm_header'),
      subHeader: this.i18n.t('cancel_order_confirm_subheader'),
      buttons: [
        {
          text: this.i18n.t('cancel_order_confirm_yes'),
          role: 'destructive',
          handler: () => {
            this.executeCancel(order);
            return true;
          },
        },
        {
          text: this.i18n.t('cancel_order_keep'),
          role: 'cancel',
        },
      ],
    });
    await sheet.present();
  }

  private executeCancel(order: Order) {
    this.mobileAdapter
      .post_v3('POST /orders/:id/cancel', { reason: 'customer self-serve' }, {
        authToken: this.single_user.token,
        pathParams: { id: String(order.id) },
      })
      .subscribe({
        next: (response: any) => {
          // Diagnostic: surfaces the exact API outcome (404=route not found,
          // 401=auth, 422=cancellation_not_allowed) when a cancel appears to
          // "do nothing". Check the device console if cancel still fails.
          console.warn('[cancel-order]', {
            id: order.id,
            response_code: response?.response_code,
            status: response?.status,
            error_code: response?.error_code,
            message: response?.message,
          });
          if (response.response_code === 200 && response.status === 'success') {
            order.status = 'cancelled';
            const wasIdempotent = response.data?.cancellation?.was_already_cancelled === true;
            this.toast.success(
              wasIdempotent
                ? this.i18n.t('cancel_order_already_cancelled')
                : this.i18n.t('cancel_order_success'),
            );
            // Re-fetch so the list authoritatively reflects the new status
            // (covers any optimistic-render edge / stale list).
            this.order_listing();
          } else {
            this.toast.error(response.message || this.i18n.t('cancel_order_unable'));
          }
        },
        error: (err: any) => {
          console.warn('[cancel-order] network/transport error', err);
          this.toast.error(apiErrorMessage(err, this.i18n.t('cancel_order_network_error')));
        },
      });
  }
}
