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
import {apiErrorMessage} from "../../../core/http/api-error";
import {GlobalComponent} from "../../../global-component";

import { AxIconComponent } from '../../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../../shared/app-tab-bar';
import { AxWishlistSheetComponent } from '../../../shared/ax-mobile/wishlist-sheet';
import { WishlistService } from '../../../core/services/wishlist.service';
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
    AxWishlistSheetComponent,
    AppTabBarComponent
  ]
})
export class StyleViewPage implements OnInit, OnDestroy {
  isOnline = true;
  isWishOpen = false;
  /** True while POST /me/wishlist/labels is in flight (inline label create). */
  isCreatingLabel = false;
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
    is_loading_category: false
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
    // the style by its slug (from the route) and rebuilding it, instead of
    // bouncing back to /styles, which broke deep links + refresh.
    this.style = history.state?.style;
    if (!this.style) {
      const slug = this.route.snapshot.paramMap.get('slug');
      if (slug) {
        this.loadStyleBySlug(slug);
      } else {
        // No state AND no slug, nothing to render; return to the hub.
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
          // Unknown / inactive style, bounce back to the hub.
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
    // so coerce with Number(), otherwise reduce string-concatenates ("1020"
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

  triggerBack() {
    this.nav.back();
  }

  // ========================================
  // Notifications
  // ========================================

  error_notification(message: string) {
    this.toast.error(message, { position: "top-center" });
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
    this.cdr.markForCheck();
    try {
      const label = await this.wishlistService.createLabel(this.single_user.token, name);
      if (label) {
        this.addToCloset(label.id);
      } else {
        this.error_notification(this.i18n.t('network_error_retry'));
      }
    } catch (err) {
      this.error_notification(apiErrorMessage(err, this.i18n.t('network_error_retry')));
    } finally {
      this.isCreatingLabel = false;
      this.cdr.markForCheck();
    }
  }

  success_notification(message: string) {
    this.toast.success(message, { position: 'top-center' });
  }
}
