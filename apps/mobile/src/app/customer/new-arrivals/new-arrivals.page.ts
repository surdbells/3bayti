import {
  Component,
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
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import { apiErrorMessage } from '../../core/http/api-error';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
import { GlobalComponent } from "../../global-component";
import { Labels } from "../../class/labels";
import { TranslatePipe } from "../../translate.pipe";
import { Products } from "../../class/products";
import { InfiniteScrollCustomEvent } from "@ionic/angular";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxWishlistSheetComponent } from '../../shared/ax-mobile/wishlist-sheet';
import {
  AxProductFilterSheetComponent,
  type ProductFacets,
  type ProductFilterState,
} from '../../shared/ax-mobile/product-filter-sheet';
import { WishlistService } from '../../core/services/wishlist.service';
import { I18nService } from '../../i18n.service';
import { cfImage } from '../../shared/cf-image';
@Component({
  selector: 'app-new-arrivals',
  templateUrl: './new-arrivals.page.html',
  styleUrls: ['./new-arrivals.page.scss'],
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
    AxWishlistSheetComponent,
    AxProductFilterSheetComponent]
})
export class NewArrivalsPage implements OnInit, OnDestroy {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  new_arrivals: Products[] = [];
  categories: Labels[] = [];
  isOnline = true;
  isWishOpen = false;
  /** True while POST /me/wishlist/labels is in flight (inline label create). */
  isCreatingLabel = false;
  private sub: Subscription | null = null;

  // Image loading state tracking
  imageLoaded: { [key: number]: boolean } = {};

  // Price filter options
  priceFilters: number[] = [100, 300, 500, 1000, 2000, 3000, 5000];
  selectedFilter: number | 'all' = 'all';

  // ── Web-parity filtering (sort / size / colour / price) ──────────────
  /** This page's identity sort. */
  readonly defaultSort = 'newest' as const;
  isFilterOpen = false;
  facets: ProductFacets | null = null;
  filterState: ProductFilterState = {
    sort: 'newest',
    sizes: [],
    colors: [],
    minPrice: null,
    maxPrice: null,
  };

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
    offset: 0,
    maxPrice: 20000
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

  // Hardware back left to Ionic's native handling (pop / overlay-close)
  // instead of the old forced navigateRoot('/account').

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
      this.loadFacets();
      this.newArrivals();
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
  // Filter Methods
  // ========================================

  selectFilter(filter: number | 'all'): void {
    this.selectedFilter = filter;
    this.cdr.markForCheck();
  }

  // ========================================
  // API Methods
  // ========================================

  /**
   * Build the GET /mobile/new-arrivals-listing query params from the
   * current `initial` paging state + the active filterState. Mirrors the
   * v3 GET /v3/products vocabulary: sort, min_price/max_price, sizes/colors
   * (CSV), limit/offset. max_price from the filter takes precedence over
   * the legacy price-chip ceiling when set.
   */
  private buildQuery(): Record<string, string | number> {
    const query: Record<string, string | number> = {
      sort: this.filterState.sort,
      limit: this.initial.limit,
      offset: this.initial.offset,
    };
    if (this.filterState.maxPrice !== null) {
      query['max_price'] = this.filterState.maxPrice;
    } else {
      query['max_price'] = this.initial.maxPrice;
    }
    if (this.filterState.minPrice !== null) {
      query['min_price'] = this.filterState.minPrice;
    }
    if (this.filterState.sizes.length > 0) {
      query['sizes'] = this.filterState.sizes.join(',');
    }
    if (this.filterState.colors.length > 0) {
      query['colors'] = this.filterState.colors.join(',');
    }
    return query;
  }

  /** Fetch facet counts once for the page's base context (sort only). */
  private loadFacets(): void {
    this.networkAdapter.get_v3('GET /products/facets', {
      queryParams: { sort: this.defaultSort },
    })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === 'success') {
            this.facets = response.data as ProductFacets;
            this.cdr.markForCheck();
          }
        },
      });
  }

  /** Filter sheet trigger + apply. */
  openFilter(): void {
    this.isFilterOpen = true;
    this.cdr.markForCheck();
  }

  onFilterApply(state: ProductFilterState): void {
    this.filterState = state;
    // The sheet is now the sole price authority, reset the legacy chip
    // ceiling so a stale chip value doesn't leak through buildQuery's
    // fallback when the sheet leaves max_price unset.
    this.selectedFilter = 'all';
    this.initial.maxPrice = 20000;
    this.newArrivals();
  }

  newArrivals(): void {
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.initial.limit = 10;
    this.initial.offset = 0;
    this.resetImageStates();
    this.cdr.markForCheck();

    // Direct v3 (GET /v3/products), public catalog read, no auth. The
    // registered response transform still applies via get_v3, so
    // response.data keeps its product-array shape. Query params now carry
    // the full sort/size/colour/price filter set (buildQuery).
    this.networkAdapter.get_v3('GET /mobile/new-arrivals-listing', {
      queryParams: this.buildQuery(),
    })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.new_arrivals = response.data;
          } else {
            this.new_arrivals = [];
          }
          this.ui_controls.is_loading = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.is_loading = false;
          this.new_arrivals = [];
          this.cdr.markForCheck();
        }
      });
  }

  filterByPrice(maxPrice: number): void {
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.initial.maxPrice = maxPrice;
    this.initial.offset = 0;
    // The legacy price chip is a max ceiling; clear any sheet price so the
    // chip's ceiling takes effect via buildQuery's fallback.
    this.filterState = { ...this.filterState, minPrice: null, maxPrice: null };
    this.resetImageStates();
    this.cdr.markForCheck();

    // Direct v3 (GET /v3/products), public catalog read, no auth. Same
    // paginated listing as newArrivals() with a price ceiling (buildQuery).
    this.networkAdapter.get_v3('GET /mobile/new-arrivals-listing', {
      queryParams: this.buildQuery(),
    })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.new_arrivals = response.data;
          } else {
            this.new_arrivals = [];
          }
          this.ui_controls.is_loading = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.is_loading = false;
          this.new_arrivals = [];
          this.cdr.markForCheck();
        }
      });
  }

  getMoreItems(): void {
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.offset = this.initial.offset + this.initial.limit;

    // Direct v3 (GET /v3/products), public catalog read, no auth. Advances
    // offset for infinite scroll; buildQuery preserves the active filter.
    this.networkAdapter.get_v3('GET /mobile/new-arrivals-listing', {
      queryParams: this.buildQuery(),
    })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.new_arrivals.push(...response.data);
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
    this.selectedFilter = 'all';
    this.initial.maxPrice = 20000;
    this.filterState = {
      sort: this.defaultSort,
      sizes: [],
      colors: [],
      minPrice: null,
      maxPrice: null,
    };
    this.resetImageStates();
    this.newArrivals();
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
    } catch (err) {
      this.error_notification(apiErrorMessage(err, this.i18n.t('network_error_retry')));
    } finally {
      this.isCreatingLabel = false;
      this.cdr.markForCheck();
    }
  }

  success_notification(message: string): void {
    this.toast.success(message, { position: 'top-center' });
  }
}
