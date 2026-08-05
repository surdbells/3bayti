import {Component, OnDestroy, OnInit} from '@angular/core';

import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCard,
  IonContent,
  IonHeader,
  IonItem,
  IonLabel,
  IonList,
  IonRefresher,
  IonRefresherContent,
  IonText,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import {Subscription} from "rxjs";
import {ConnectionService} from "../../service/connection.service";
import {Router, RouterLink} from "@angular/router";
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Cart} from "../../class/cart";
import {GlobalComponent} from "../../global-component";
import {Preferences} from "@capacitor/preferences";
import {ActionSheetController} from "@ionic/angular";
import {Labels} from "../../class/labels";
import { LocalCartService, type LocalCartItem } from '../../core/services/local-cart.service';
import { CartCountService } from '../../core/services/cart-count.service';
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxWishlistSheetComponent } from '../../shared/ax-mobile/wishlist-sheet';
import { WishlistService } from '../../core/services/wishlist.service';
@Component({
  selector: 'app-cart',
  templateUrl: './cart.page.html',
  styleUrls: ['./cart.page.scss'],
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
    IonItem,
    IonLabel,
    IonList,
    IonText,
    RouterLink,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AxWishlistSheetComponent,
    AppTabBarComponent
  ]
})
export class CartPage implements OnInit, OnDestroy {
  carts: Cart[] = [];
  categories: Labels[] = [];
  isOnline = true;
  isWishOpen = false; // or control this as you like
  /** True while POST /me/wishlist/labels is in flight (inline label create). */
  isCreatingLabel = false;
  private sub: Subscription;
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private wishlistService: WishlistService,
    private toast: AxNotificationService,
    private i18n: I18nService,
    private localCart: LocalCartService,
    private cartCount: CartCountService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ui_controls = {
    is_loading: false,
    is_creating: false,
    is_loading_category: false,
    is_empty: false
  }
  rqst_param = {
    id: 0,
    token: ""
  }
  request = {
    id: 0,
    token: ""
  }
  remove = {
    id: 0,
    token: "",
    item: 0,
  }
  increase = {
    id: 0,
    token: "",
    item: 0,
    quantity: 0,
  }
  decrease = {
    id: 0,
    token: "",
    item: 0,
    quantity: 0,
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
  addCloset = {
    id: 0,
    token: "",
    label_id: 0,
    product_id: 0,
    product_name: "",
    product_image: ""
  }
  bill: {
    count: number;
    discount: number | string;
    delivery: number | string;
    subtotal: number | string;
    total: number | string;
    f_discount: string;
    f_delivery: string;
    f_subtotal: string;
    f_total: string;
  } = {
    count: 0,
    discount: 0,
    delivery: 0,
    subtotal: 0,
    total: 0,
    f_discount: "",
    f_delivery: "",
    f_subtotal: "",
    f_total: ""
  };
  // Hardware back left to Ionic's native handling (pop / overlay-close)
  // instead of the old forced navigateRoot('/account').
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }
  ngOnInit() {
    // Cart contents are (re)loaded in ionViewWillEnter so they refresh on every
    // entry. ngOnInit runs only once because Ionic caches the page in the nav
    // stack — loading here left the cart showing stale/empty content after
    // navigating away and back, until a manual pull-to-refresh.
  }
  ionViewWillEnter() {
    // Fires on first entry AND every re-entry — re-pull the cart each time the
    // page is shown so it never shows a stale/empty snapshot.
    this.getObject();
  }
  ionViewDidEnter() {
    // Keep the reactive cart badge in sync whenever the cart page is shown.
    void this.cartCount.refresh();
  }
  // Track whether we're in guest mode (no auth). Used to route
  // cart operations to LocalCartService instead of NetworkService.
  isGuest = false;

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      // M3.1.6i.2-E: guest mode — show device-local cart instead of
      // redirecting to /login. Encourages adds-to-cart before sign-up.
      this.isGuest = true;
      this.load_cart();
    }else{
      this.isGuest = false;
      this.single_user = JSON.parse(ret.value);
      this.request.id = this.single_user.id
      this.request.token = this.single_user.token

      this.remove.id = this.single_user.id
      this.remove.token = this.single_user.token

      this.increase.id = this.single_user.id
      this.increase.token = this.single_user.token

      this.decrease.id = this.single_user.id
      this.decrease.token = this.single_user.token
      this.load_cart();
    }
  }
  load_cart() {
    this.carts = [];
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;

    // Guest path: read from IndexedDB, bypass network entirely.
    if (this.isGuest) {
      this.loadGuestCart();
      return;
    }

    this.request.id = this.single_user.id;
    // Direct v3 (GET /v3/cart). The transformCartListResponse response
    // transform still applies via get_v3, so response.data keeps the
    // {items, bill, ...} shape — the dual-shape handling below is left
    // intact (the v3 branch is the one that runs).
    this.networkAdapter.get_v3('GET /cart', { authToken: this.single_user.token })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200) {
            // Dual-shape support during M3.1.6 strangler-fig migration:
            //   Legacy shape: response.data = items[], response.message = bill
            //   v3 shape:     response.data = {items, bill, ...}, response.message = ''
            // The adapter's MUTATION_RESPONSE_TRANSFORMS wraps v3 responses
            // into the {items, bill} shape inside `data`. We detect which
            // shape we got by checking if `data` is an array (legacy) or
            // an object with `items` (v3).
            const data = response.data;
            if (Array.isArray(data)) {
              // Legacy shape
              this.carts = data;
              this.bill = response.message;
            } else if (data && typeof data === 'object' && Array.isArray(data.items)) {
              // v3 shape (post-transform)
              this.carts = data.items;
              // Merge transform's {count, subtotal, currency} over the
              // default bill so the strongly-typed shape remains intact.
              // v3 doesn't compute delivery/discount/total breakdowns
              // until checkout (they appear on the Order, not the Cart);
              // keep defaults until checkout.page.ts populates them.
              // Transform now provides the full legacy bill shape (count,
              // subtotal, f_subtotal, f_delivery, f_discount, total, f_total);
              // spread it over the default so the summary lines (which bind
              // f_* + total) populate. Delivery/discount stay 0.00 until
              // checkout.page.ts computes them on the Order.
              this.bill = {
                ...this.bill,
                ...(data.bill ?? {}),
              };
            } else {
              // Defensive fallback — empty cart
              this.carts = [];
              this.bill = { ...this.bill, count: 0, subtotal: 0 };
            }
            // Reactive badge: publish the authed count from the bill
            // (replaces the write-only Preferences('count')).
            this.cartCount.setCount(Number(this.bill.count) || 0);
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = this.carts.length === 0;
          }else{
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = true;
          }
        }
      }))
  }
  // M3.1.6i.2-E: load + map the device-local guest cart into the
  // same `this.carts` + `this.bill` shape the template binds to.
  // Map LocalCartItem -> the cart-item shape mobile expects: legacy
  // 'item' field for delete/inc/dec uses 'localId' for guests,
  // plain 'id' for authed users.
  private async loadGuestCart(): Promise<void> {
    try {
      const items = await this.localCart.list();
      const subtotal = await this.localCart.subtotal();
      const count = await this.localCart.count();

      this.carts = items.map((it) => {
        const unitPrice = parseFloat(it.unit_price || '0');
        // Build the variant/size+color description the template renders
        // via [innerHTML]="cart.description" (mirrors how the authed cart
        // surfaces a line subtitle). Skip blank parts so we don't show
        // stray separators for products without a size/colour.
        const descParts = [it.size, it.color].filter(
          (p) => typeof p === 'string' && p.trim().length > 0,
        );
        return {
          // For guest carts, the line key is localId; the cart.page
          // template references item.id for inc/dec/delete bindings,
          // so expose localId under id too.
          id: it.localId ?? 0,
          // The template's remove/increase/decrease handlers are bound to
          // `cart.item`, and open_product to `cart.product`. The guest
          // line key is the IndexedDB localId — expose it under `item` so
          // LocalCartService.updateQuantity/remove (which guard localId<=0)
          // receive the real id instead of undefined (silent no-op before).
          item: it.localId ?? 0,
          product: it.product_id,
          product_id: it.product_id,
          product_name: it.product_name,
          product_image: it.product_image,
          vendor_id: it.vendor_id,
          store: it.vendor_id,
          vendor_name: it.vendor_name,
          quantity: it.quantity,
          price: it.unit_price,
          unit_price: it.unit_price,
          // Template renders `cart.price_formatted` (2dp) and
          // `cart.description`; the local mapper must populate both or the
          // price/subtitle render blank for guests.
          price_formatted: (Number.isFinite(unitPrice) ? unitPrice : 0).toFixed(2),
          description: descParts.join(' / '),
          size: it.size,
          color: it.color,
          is_custom: it.is_custom,
          measurement: it.measurement,
          extra_measurement: it.extra_measurement,
          note: it.note,
          in_stock: true,
          // Computed line_total — local cart doesn't store it
          line_total: ((Number.isFinite(unitPrice) ? unitPrice : 0) * (it.quantity || 0)).toFixed(2),
        };
      }) as unknown as Cart[];

      this.bill = {
        ...this.bill,
        count,
        subtotal,
      };

      // Reactive badge: publish the guest count from the local cart
      // (replaces the write-only Preferences('count')).
      this.cartCount.setCount(count);
      this.ui_controls.is_loading = false;
      this.ui_controls.is_empty = this.carts.length === 0;
    } catch (err) {
      console.warn('[Cart] guest cart load failed', err);
      this.ui_controls.is_loading = false;
      this.ui_controls.is_empty = true;
    }
  }

  removeItem(item: number) {
    // Guest path
    if (this.isGuest) {
      this.localCart.remove(item).then(() => {
        this.success_notification(this.i18n.t('text_item_removed'));
        this.load_cart();
      }).catch((err) => console.warn('[Cart] guest remove failed', err));
      return;
    }

    this.remove.item = item;
    // Direct v3 (DELETE /v3/cart/items/:id). The cart item id is `item`
    // (bound from cart.item in the template). v3 returns the updated cart
    // shape (200, not 204), so the envelope check below still passes; the
    // page reloads the cart regardless.
    this.networkAdapter.delete_v3('DELETE /cart/items/:id', {
      authToken: this.single_user.token,
      pathParams: { id: String(item) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(this.i18n.t('text_item_removed'));
            this.load_cart();
          }
        }
      }))
  }
  IncreaseItem(item: number, quantity: number) {
    const newQty = quantity + 1;

    if (this.isGuest) {
      this.localCart.updateQuantity(item, newQty).then(() => {
        this.load_cart();
      }).catch((err) => console.warn('[Cart] guest increase failed', err));
      return;
    }

    this.increase.item = item;
    this.increase.quantity = newQty;
    // Direct v3 (PATCH /v3/cart/items/:id). `item` is the cart item id and
    // `quantity` passed in is the CURRENT quantity, so send the new absolute
    // quantity (newQty = quantity + 1). v3 caps at 999; the +1 step never
    // exceeds that in practice.
    this.networkAdapter.patch_v3('PATCH /cart/items/:id', { quantity: newQty }, {
      authToken: this.single_user.token,
      pathParams: { id: String(item) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.load_cart();
          }
        }
      }))
  }
  DecreaseItem(item: number, quantity: number) {
    const newQty = quantity - 1;

    if (this.isGuest) {
      // LocalCartService.updateQuantity treats qty<=0 as remove,
      // matching the user intent on the last decrement.
      this.localCart.updateQuantity(item, newQty).then(() => {
        this.load_cart();
      }).catch((err) => console.warn('[Cart] guest decrease failed', err));
      return;
    }

    this.decrease.item = item;
    this.decrease.quantity = newQty;
    // Direct v3 (PATCH /v3/cart/items/:id). `item` is the cart item id and
    // `quantity` passed in is the CURRENT quantity, so send the new absolute
    // quantity (newQty = quantity - 1). The decrease control is disabled at
    // quantity === 1 in cart.page.html, so newQty never drops below the v3
    // minimum of 1 (DELETE is the path to remove the last unit).
    this.networkAdapter.patch_v3('PATCH /cart/items/:id', { quantity: newQty }, {
      authToken: this.single_user.token,
      pathParams: { id: String(item) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.load_cart();
          }
        }
      }))
  }
  async startRemove(item: number, name: string) {
    const actionSheet = await this.actionSheetCtrl.create({
      header: this.i18n.t('confirm_remove_from_cart', { name }),
      buttons: [
        {
          text: this.i18n.t('button_remove'),
          role: 'destructive',
          handler: () => {
            this.removeItem(item);
          }
        }, {
          text: this.i18n.t('cancel'),
          role: 'cancel',
          data: {action: 'cancel'},
        },
      ],
    });
    await actionSheet.present();
  }
  user_wishlist() {
    this.router.navigate(['/', 'wishlist']);
  }
  user_orders() {
    // The orders LIST route is 'my-orders'; 'orders' only exists as
    // 'orders/:id' (detail), so the bare path matched nothing and the tap did
    // nothing.
    this.router.navigate(['/', 'my-orders']);
  }
  user_messages() {
    this.router.navigate(['/', 'chat-vendors']);
  }
  handleRefresh(event: any) {
    setTimeout(() => {
      this.load_cart();
      event.target.complete();
    }, 200);
  }
  get_label() {
    this.ui_controls.is_loading_category = true;
    this.wishlistService.listLabels(this.single_user.token)
      .then((labels) => {
        this.categories = labels.map((l) => ({ id: l.id, name: l.name, count: l.count })) as any;
        this.ui_controls.is_loading_category = false;
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
      });
  }
  addToCloset(label: number) {
    this.ui_controls.is_loading_category = true;
    this.addCloset.label_id = label;
    this.isWishOpen = false;
    this.wishlistService.add(this.single_user.token, this.addCloset.product_id, label)
      .then((ok) => {
        if (ok) {
          this.success_notification(this.i18n.t('text_added_to_wishlist'));
        }
        this.ui_controls.is_loading_category = false;
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
      });
  }
  startAddToCloset(product: number, product_name: string, image_1: string) {
    this.addCloset.id = this.single_user.id;
    this.addCloset.token = this.single_user.token;
    this.addCloset.product_id = product;
    this.addCloset.product_name = product_name;
    this.addCloset.product_image = image_1;
    this.rqst_param.id = this.single_user.id;
    this.rqst_param.token = this.single_user.token;
    this.get_label();
    this.isWishOpen = true;
  }

  error_notification(message: string) {
    this.toast.error(message, {
      position: "top-center"
    });
  }
  /**
   * Inline create from the wishlist sheet: POST the new label, then drop the
   * pending product straight into it (addToCloset closes the sheet + toasts).
   * On failure the sheet stays open so the user can retry.
   */
  async onCreateLabel(name: string): Promise<void> {
    if (this.isCreatingLabel) {
      return;
    }
    this.isCreatingLabel = true;
    try {
      const label = await this.wishlistService.createLabel(this.single_user.token, name);
      if (label) {
        this.addToCloset(label.id);
      } else {
        this.error_notification(this.i18n.t('network_error_retry'));
      }
    } catch {
      this.error_notification(this.i18n.t('network_error_retry'));
    } finally {
      this.isCreatingLabel = false;
    }
  }

  success_notification(message: string) {
    this.toast.success(message, {
      position: "top-center"
    });
  }

  check_out() {
    this.router.navigate(['/', 'checkout']);
  }
  triggerBack() {
    this.nav.back();
  }
  open_product(id: number) {
    this.router.navigate(
      ['/', 'product'],
      { queryParams: { id, name } }
    );
  }

  onDismiss() {
    this.isWishOpen= false;
  }
}
