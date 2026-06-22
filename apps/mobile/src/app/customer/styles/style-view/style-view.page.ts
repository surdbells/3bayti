import { Component, OnInit, OnDestroy, ChangeDetectorRef, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import { ActivatedRoute, Router } from "@angular/router";
import { AxNotificationService } from '../../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
import { Subscription } from 'rxjs';
import {TranslatePipe} from "../../../translate.pipe";
import {Labels} from "../../../class/labels";
import {ConnectionService} from "../../../service/connection.service";
import {NetworkService} from "../../../service/network.service";
import {MobileNetworkAdapter} from "../../../core/http/mobile-network-adapter";
import {GlobalComponent} from "../../../global-component";

import { AxIconComponent } from '../../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../../shared/ax-mobile/loader';
import { AxBottomSheetComponent } from '../../../shared/ax-mobile/bottom-sheet';
import { WishlistService } from '../../../core/services/wishlist.service';
import { CartCountService } from '../../../core/services/cart-count.service';
import { I18nService } from '../../../i18n.service';

export interface StyleProduct {
  product_id: number;
  product_name: string;
  price: number;
  image: string;
}

export interface Styles {
  id: number;
  slug: string;
  total_price: number;
  category: string;
  style_name: string;
  products: StyleProduct[];
}

@Component({
  selector: 'app-style-view',
  templateUrl: './style-view.page.html',
  styleUrls: ['./style-view.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommonModule,
    FormsModule,
    DecimalPipe,
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButtons,
    IonButton,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AxBottomSheetComponent,
    AppTabBarComponent
  ]
})
export class StyleViewPage implements OnInit, OnDestroy {
  isOnline = true;
  isWishOpen = false;
  categories: Labels[] = [];
  // Undefined until loaded (router state fast-path OR slug re-fetch). The
  // template gates on @if(style) and shows skeletons while it's undefined.
  style?: Styles;

  // Image loading tracking
  imageLoaded: { [key: number]: boolean } = {};

  private sub: Subscription | null = null;

  addCloset = {
    id: 0,
    token: "",
    label_id: 0,
    product_id: 0,
    product_name: "",
    product_image: ""
  }

  rqst_param = {
    id: 0,
    token: ""
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

  ui_controls = {
    is_loading: false,
    is_empty: false,
    is_loading_category: false,
    is_adding_all: false
  }

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private platform: Platform,
    private nav: NavController,
    private net: ConnectionService,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private wishlistService: WishlistService,
    private cartCount: CartCountService,
    private i18n: I18nService,
    private toast: AxNotificationService,
    private cdr: ChangeDetectorRef
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  ngOnInit() {
    // Fast path: the style was passed in router state from the list. On a
    // hard reload / deep link that state is wiped, so fall back to fetching
    // the style by its slug (from the route) and rebuilding it — instead of
    // bouncing back to /styles, which broke deep links + refresh.
    this.style = history.state?.style;
    if (!this.style) {
      const slug = this.route.snapshot.paramMap.get('slug');
      if (slug) {
        this.loadStyleBySlug(slug);
      } else {
        // No state AND no slug — nothing to render; return to the hub.
        this.router.navigate(['/styles']);
        return;
      }
    }
    this.getObject();
  }

  /**
   * Re-fetch a single style by slug for the deep-link / hard-reload path.
   * Uses the same response transform as the list, so `style` ends up in the
   * identical legacy shape (products carry product_id = legacy id).
   */
  private loadStyleBySlug(slug: string) {
    this.ui_controls.is_loading = true;
    this.cdr.markForCheck();

    this.networkAdapter.get_v3('GET /mobile/style-detail', {
      pathParams: { slug },
    }).subscribe({
      next: (response: any) => {
        if (
          response.response_code === 200 &&
          response.status === 'success' &&
          response.data &&
          response.data.id
        ) {
          this.style = response.data;
        } else {
          // Unknown / inactive style — bounce back to the hub.
          this.router.navigate(['/styles']);
        }
        this.ui_controls.is_loading = false;
        this.cdr.markForCheck();
      },
      error: () => {
        this.ui_controls.is_loading = false;
        this.router.navigate(['/styles']);
      }
    });
  }

  ngOnDestroy() {
    this.sub?.unsubscribe();
  }

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
    } else {
      this.single_user = JSON.parse(ret.value);
    }
  }

  // ========================================
  // Image Loading Handlers
  // ========================================

  onImageLoad(productId: number) {
    this.imageLoaded[productId] = true;
    this.cdr.markForCheck();
  }

  onImageError(productId: number) {
    this.imageLoaded[productId] = true; // Hide skeleton on error
    this.cdr.markForCheck();
  }

  // ========================================
  // Calculations
  // ========================================

  getTotal(): number {
    if (!this.style?.products) return 0;
    // item.price is a STRING post-transform (v3 DECIMAL preserved as text),
    // so coerce with Number() — otherwise reduce string-concatenates ("1020"
    // instead of 30) and the total renders garbage.
    return this.style.products.reduce((sum, item) => sum + Number(item.price), 0);
  }

  // ========================================
  // Wishlist / Closet
  // ========================================

  get_label() {
    this.ui_controls.is_loading_category = true;
    this.cdr.markForCheck();

    this.wishlistService.listLabels(this.single_user.token)
      .then((labels) => {
        this.categories = labels.map((l) => ({ id: l.id, name: l.name, count: l.count })) as any;
        this.ui_controls.is_loading_category = false;
        this.cdr?.markForCheck();
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
        this.cdr?.markForCheck();
      });
  }

  addToCloset(label: number) {
    this.ui_controls.is_loading_category = true;
    this.addCloset.label_id = label;
    this.isWishOpen = false;
    this.cdr.markForCheck();

    this.wishlistService.add(this.single_user.token, this.addCloset.product_id, label)
      .then((ok) => {
        if (ok) {
          this.success_notification(this.i18n.t('text_added_to_wishlist'));
        }
        this.ui_controls.is_loading_category = false;
        this.cdr?.markForCheck();
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
        this.cdr?.markForCheck();
      });
  }

  startAddToCloset(productId: number, productName: string, image: string) {
    this.addCloset.id = this.single_user.id;
    this.addCloset.token = this.single_user.token;
    this.addCloset.product_id = productId;
    this.addCloset.product_name = productName;
    this.addCloset.product_image = image;
    this.rqst_param.id = this.single_user.id;
    this.rqst_param.token = this.single_user.token;
    this.get_label();
    this.isWishOpen = true;
  }

  OnDidDismiss() {
    this.isWishOpen = false;
  }

  // ========================================
  // Navigation
  // ========================================

  open_product(id: number, name: string) {
    this.router.navigate(['/', 'product'], { queryParams: { id, name } });
  }

  // ========================================
  // Add all to cart
  // ========================================

  /**
   * Add every product in the style to the cart in one tap. Loops the
   * style's products and POSTs each to /v3/cart/items (quantity 1). Cart
   * adds require a signed-in user (the server derives price/store/snapshot),
   * so guests are sent to login. Best-effort: failures per item are tallied
   * and surfaced, the rest still go in. product_id is the LEGACY id (the
   * server resolves it the same way the PDP add does).
   */
  async addAllToCart() {
    const products = this.style?.products ?? [];
    if (products.length === 0) {
      return;
    }

    // Cart adds are authed — guests can't add. Mirror the PDP gate.
    if (!this.single_user.token) {
      this.error_notification(this.i18n.t('sign_in_to_add_to_cart'));
      return;
    }

    this.ui_controls.is_adding_all = true;
    this.cdr.markForCheck();

    let added = 0;
    let failed = 0;

    for (const item of products) {
      try {
        const response: any = await new Promise((resolve, reject) => {
          this.networkAdapter.post_v3(
            'POST /cart/items',
            {
              product_id: item.product_id,
              quantity: 1,
              size: '',
              color: '',
              is_custom: false,
              measurement: '',
              extra_measurement: '',
              note: '',
            },
            { authToken: this.single_user.token },
          ).subscribe({ next: resolve, error: reject });
        });

        const ok =
          response?.response_code === 200 &&
          (response.status === 'success' ||
            (response.data && typeof response.data === 'object' && response.data.success === true));
        if (ok) {
          added++;
        } else {
          failed++;
        }
      } catch {
        failed++;
      }
    }

    this.ui_controls.is_adding_all = false;
    this.cdr.markForCheck();

    if (added > 0) {
      this.cartCount.bump(added);
      void this.cartCount.refresh();
      this.success_notification(this.i18n.t('text_style_added_to_cart'));
    }
    if (added === 0 && failed > 0) {
      this.error_notification(this.i18n.t('text_style_add_failed'));
    }
  }

  triggerBack() {
    this.nav.back();
  }

  // ========================================
  // Notifications
  // ========================================

  error_notification(message: string) {
    this.toast.error(message, { position: "top-center" });
  }

  success_notification(message: string) {
    this.toast.success(message, { position: 'top-center' });
  }
}
