import {Component, HostListener, OnDestroy, OnInit} from '@angular/core';

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
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
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
    AxBottomSheetComponent,
    AppTabBarComponent
  ]
})
export class CartPage implements OnInit, OnDestroy {
  carts: Cart[] = [];
  categories: Labels[] = [];
  isOnline = true;
  isWishOpen = false; // or control this as you like
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
  @HostListener('window:ionBackButton', ['$event'])
  onHardwareBack(ev: Event) {
    (ev as CustomEvent).detail.register(100, () => {
      this.nav.navigateRoot('/account');
    });
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }
  ngOnInit() {
    this.getObject();
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
    this.networkAdapter.post_request(this.request, GlobalComponent.customerCart)
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
            Preferences.set({key: 'count', value: String(this.bill.count)});
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

      this.carts = items.map((it) => ({
        // For guest carts, the line key is localId; the cart.page
        // template references item.id for inc/dec/delete bindings,
        // so expose localId under id too.
        id: it.localId ?? 0,
        product_id: it.product_id,
        product_name: it.product_name,
        product_image: it.product_image,
        vendor_id: it.vendor_id,
        store: it.vendor_id,
        vendor_name: it.vendor_name,
        quantity: it.quantity,
        price: it.unit_price,
        unit_price: it.unit_price,
        size: it.size,
        color: it.color,
        is_custom: it.is_custom,
        measurement: it.measurement,
        extra_measurement: it.extra_measurement,
        note: it.note,
        in_stock: true,
        // Computed line_total — local cart doesn't store it
        line_total: (parseFloat(it.unit_price || '0') * (it.quantity || 0)).toFixed(2),
      })) as unknown as Cart[];

      this.bill = {
        ...this.bill,
        count,
        subtotal,
      };

      Preferences.set({key: 'count', value: String(count)});
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
    this.networkAdapter.post_request(this.remove, GlobalComponent.RemoveCartItem)
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
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
    this.networkAdapter.post_request(this.increase, GlobalComponent.IncreaseItem)
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
    this.networkAdapter.post_request(this.decrease, GlobalComponent.DecreaseItem)
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
    this.router.navigate(['/', 'orders']);
  }
  user_messages() {
    this.router.navigate(['/', 'messages']);
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
