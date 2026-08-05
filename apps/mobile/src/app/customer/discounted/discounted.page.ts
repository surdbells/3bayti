import {
  Component,
  HostListener,
  OnDestroy,
  OnInit,
  ChangeDetectorRef,
  ChangeDetectionStrategy
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCol,
  IonContent,
  IonGrid,
  IonHeader,
  IonInfiniteScroll,
  IonInfiniteScrollContent,
  IonRefresher,
  IonRefresherContent,
  IonRow,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import { Subscription } from "rxjs";
import { ConnectionService } from "../../service/connection.service";
import { Router } from "@angular/router";
import { NetworkService } from "../../service/network.service";
import { MobileNetworkAdapter } from "../../core/http/mobile-network-adapter";
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
import { Labels } from "../../class/labels";
import { TranslatePipe } from "../../translate.pipe";
import { Products } from "../../class/products";
import { InfiniteScrollCustomEvent } from "@ionic/angular";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxWishlistSheetComponent } from '../../shared/ax-mobile/wishlist-sheet';
import { WishlistService } from '../../core/services/wishlist.service';
import { I18nService } from '../../i18n.service';
import { cfImage } from '../../shared/cf-image';

/**
 * Discounted (on-sale) product listing.
 *
 * Mirrors the best-sellers page but with a single, fixed filter: only
 * genuinely-discounted products (sale_price present, > 0, and < price).
 * The API enforces that filter server-side via ?sale=true
 * (ProductRepository::findActivePaginated -> salePrice IS NOT NULL AND
 * salePrice < price), so this page never has to filter client-side.
 *
 * Fetches through the transform-registered listing route key
 * 'GET /mobile/category-listing' (maps to /v3/products with
 * transformProductListResponse registered). No category_id is passed, so
 * with sale=true it returns ALL on-sale products. The registered response
 * transform reshapes the v3 list items into the legacy Products[] card
 * shape (incl. the sale_price the card rendering needs).
 */
@Component({
  selector: 'app-discounted',
  templateUrl: './discounted.page.html',
  styleUrls: ['./discounted.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommonModule,
    FormsModule,
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButtons,
    IonButton,
    IonCol,
    IonGrid,
    IonRow,
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    IonRefresher,
    IonRefresherContent,
    TranslatePipe, AxIconComponent,
    AxWishlistSheetComponent]
})
export class DiscountedPage implements OnInit, OnDestroy {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  discounted: Products[] = [];
  categories: Labels[] = [];
  isOnline = true;
  isWishOpen = false;
  /** True while POST /me/wishlist/labels is in flight (inline label create). */
  isCreatingLabel = false;
  private sub: Subscription | null = null;

  // Image loading state tracking
  imageLoaded: { [key: number]: boolean } = {};

  ui_controls = {
    is_loading: false,
    is_creating: false,
    is_loading_category: false,
    is_empty: false
  };

  initial = {
    id: 0,
    token: "",
    limit: 10,
    offset: 0
  };

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
  };

  addCloset = {
    id: 0,
    token: "",
    label_id: 0,
    product_id: 0,
    product_name: "",
    product_image: ""
  };

  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
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

  @HostListener('window:ionBackButton', ['$event'])
  onHardwareBack(ev: Event) {
    (ev as CustomEvent).detail.register(100, () => {
      this.nav.navigateRoot('/account');
    });
  }

  ngOnInit() {
    this.getObject();
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
    } else {
      this.single_user = JSON.parse(ret.value);
      this.initial.id = this.single_user.id;
      this.initial.token = this.single_user.token;
      this.loadDiscounted();
    }
  }

  // ========================================
  // Image Loading Handlers
  // ========================================

  onImageLoad(productId: number): void {
    this.imageLoaded[productId] = true;
    this.cdr.markForCheck();
  }

  onImageError(productId: number): void {
    // Hide skeleton even on error to prevent permanent loading state
    this.imageLoaded[productId] = true;
    this.cdr.markForCheck();
  }

  resetImageStates(): void {
    this.imageLoaded = {};
  }

  // ========================================
  // API Methods
  // ========================================

  /**
   * Build the discounted-listing query. sale=true is the fixed filter the
   * whole page is about; limit/offset drive pagination. No category_id, so
   * the server returns every on-sale product.
   */
  private buildQuery(): Record<string, string | number> {
    return {
      sale: 'true',
      limit: this.initial.limit,
      offset: this.initial.offset,
    };
  }

  loadDiscounted(): void {
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.initial.limit = 10;
    this.initial.offset = 0;
    this.resetImageStates();
    this.cdr.markForCheck();

    // Public catalog read — no authToken. The registered response transform
    // (transformProductListResponse on 'GET /mobile/category-listing')
    // applies via get_v3, so response.data keeps the legacy Products[] shape
    // with sale_price surfaced for the on-sale card rendering.
    this.networkAdapter.get_v3('GET /mobile/category-listing', {
      queryParams: this.buildQuery(),
    })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.discounted = response.data;
          } else {
            this.discounted = [];
            this.error_notification(response.message);
          }
          this.ui_controls.is_loading = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.is_loading = false;
          this.discounted = [];
          this.cdr.markForCheck();
        }
      });
  }

  getMoreItems(): void {
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.offset = this.initial.offset + this.initial.limit;

    this.networkAdapter.get_v3('GET /mobile/category-listing', {
      queryParams: this.buildQuery(),
    })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success" && response.data.length > 0) {
            this.discounted.push(...response.data);
            this.cdr.markForCheck();
          } else {
            this.ui_controls.is_empty = true;
            this.cdr.markForCheck();
          }
        },
        error: () => {
          this.ui_controls.is_empty = true;
          this.cdr.markForCheck();
        }
      });
  }

  onIonInfinite(event: InfiniteScrollCustomEvent): void {
    this.getMoreItems();
    setTimeout(() => {
      event.target.complete();
    }, 500);
  }

  handleRefresh(event: any): void {
    this.resetImageStates();
    this.loadDiscounted();
    setTimeout(() => {
      event.target.complete();
    }, 300);
  }

  // ========================================
  // Wishlist Methods
  // ========================================

  get_label(): void {
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

  addToCloset(label: number): void {
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

  startAddToCloset(productId: number, productName: string, image: string): void {
    this.addCloset.id = this.single_user.id;
    this.addCloset.token = this.single_user.token;
    this.addCloset.product_id = productId;
    this.addCloset.product_name = productName;
    this.addCloset.product_image = image;
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.get_label();
    this.isWishOpen = true;
    this.cdr.markForCheck();
  }

  // ========================================
  // Navigation
  // ========================================

  open_product(id: number, name: string): void {
    this.router.navigate(['/', 'product'], { queryParams: { id, name } });
  }

  triggerBack(): void {
    this.nav.back();
  }

  onDismiss(): void {
    this.isWishOpen = false;
    this.cdr.markForCheck();
  }

  // ========================================
  // Notifications
  // ========================================

  error_notification(message: string): void {
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
    } catch {
      this.error_notification(this.i18n.t('network_error_retry'));
    } finally {
      this.isCreatingLabel = false;
      this.cdr.markForCheck();
    }
  }

  success_notification(message: string): void {
    this.toast.success(message, { position: 'top-center' });
  }
}
