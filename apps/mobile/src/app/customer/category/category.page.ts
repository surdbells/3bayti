import { Component, OnDestroy, OnInit, ChangeDetectorRef, ChangeDetectionStrategy } from '@angular/core';
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
import { TranslatePipe } from "../../translate.pipe";
import { Products } from "../../class/products";
import { Labels } from "../../class/labels";
import { Subscription } from "rxjs";
import { ConnectionService } from "../../service/connection.service";
import { ActivatedRoute, Router } from "@angular/router";
import { NetworkService } from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import { apiErrorMessage } from '../../core/http/api-error';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
import { GlobalComponent } from "../../global-component";
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
  selector: 'app-category',
  templateUrl: './category.page.html',
  styleUrls: ['./category.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    FormsModule,
    IonButton,
    IonButtons,
    IonCol,
    IonGrid,
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    IonRefresher,
    IonRefresherContent,
    IonRow,
    TranslatePipe, AxIconComponent,
    AxWishlistSheetComponent,
    AxProductFilterSheetComponent]
})
export class CategoryPage implements OnInit, OnDestroy {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  category_listing: Products[] = [];
  categories: Labels[] = [];
  isOnline = true;
  isWishOpen = false;
  /** True while POST /me/wishlist/labels is in flight (inline label create). */
  isCreatingLabel = false;
  private sub: Subscription;

  // Image loading tracking
  imageLoaded: { [key: number]: boolean } = {};

  // Price filter options
  priceFilters: number[] = [100, 300, 500, 1000, 2000, 3000, 5000];

  // Selected filter for UI feedback
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

  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private route: ActivatedRoute,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private cdr: ChangeDetectorRef,
    private wishlistService: WishlistService,
    private i18n: I18nService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  // Hardware back left to Ionic's native handling (pop / overlay-close)
  // instead of the old forced navigateRoot('/account').

  ui_controls = {
    is_loading: false,
    is_creating: false,
    is_loading_category: false,
    is_empty: false
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }

  initial = {
    id: 0,
    token: "",
    category: 0,
    name: "",
    limit: 10,
    offset: 0,
    maxPrice: 20000
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

  ngOnInit() {
    this.initial.category = Number(this.route.snapshot.queryParamMap.get('id'));
    this.initial.name = this.route.snapshot.queryParamMap.get('name') || '';
    this.getObject();
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
      this.productCategory();
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
    // Mark as loaded even on error to hide skeleton
    this.imageLoaded[productId] = true;
    this.cdr.markForCheck();
  }

  // Reset image states when fetching new data
  private resetImageStates() {
    this.imageLoaded = {};
  }

  // ========================================
  // Filter Selection
  // ========================================

  selectFilter(filter: number | 'all') {
    this.selectedFilter = filter;
    this.cdr.markForCheck();
  }

  // ========================================
  // API Calls
  // ========================================

  productCategory() {
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.initial.limit = 10;
    this.initial.offset = 0;
    this.initial.maxPrice = 20000;
    this.resetImageStates();
    this.cdr.markForCheck();

    // Direct v3 (GET /v3/products). transformCategoryListingResponse still
    // applies via get_v3, so response.data keeps the legacy Products[] shape.
    // Public catalog read, no authToken. Query params mirror what
    // transformCategoryListingRequest produced from `initial`: limit/offset
    // always, category_id only when category !== 0, max_price from maxPrice.
    this.networkAdapter.get_v3('GET /mobile/category-listing', { queryParams: this.buildListingQuery() })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.category_listing = response.data;
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = this.category_listing.length === 0;
          } else {
            this.category_listing = [];
            this.ui_controls.is_empty = true;
            this.ui_controls.is_loading = false;
          }
          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.is_loading = false;
          this.ui_controls.is_empty = true;
          this.cdr.markForCheck();
        }
      });
  }

  /**
   * Build the GET /mobile/category-listing query params from the current
   * `initial` request object + the active filterState. limit/offset always;
   * category_id omitted when category === 0 (the "all products" signal);
   * sort + min_price/max_price + sizes/colors (CSV) from the filter set.
   * A sheet max_price takes precedence over the legacy price-chip ceiling.
   */
  private buildListingQuery(): Record<string, string | number | boolean> {
    const query: Record<string, string | number | boolean> = {
      sort: this.filterState.sort,
      limit: this.initial.limit,
      offset: this.initial.offset,
      max_price: this.filterState.maxPrice !== null ? this.filterState.maxPrice : this.initial.maxPrice,
    };
    if (this.filterState.minPrice !== null) {
      query['min_price'] = this.filterState.minPrice;
    }
    if (this.filterState.sizes.length > 0) {
      query['sizes'] = this.filterState.sizes.join(',');
    }
    if (this.filterState.colors.length > 0) {
      query['colors'] = this.filterState.colors.join(',');
    }
    if (this.initial.category !== 0) {
      query['category_id'] = this.initial.category;
    }
    return query;
  }

  /**
   * Fetch facet counts once for the page's base context: the page sort +
   * category_id (so size/colour/price options reflect this category).
   */
  private loadFacets(): void {
    const query: Record<string, string | number> = { sort: this.defaultSort };
    if (this.initial.category !== 0) {
      query['category_id'] = this.initial.category;
    }
    this.networkAdapter.get_v3('GET /products/facets', { queryParams: query })
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
    // ceiling so a stale chip value doesn't leak through buildListingQuery's
    // fallback when the sheet leaves max_price unset.
    this.selectedFilter = 'all';
    this.initial.maxPrice = 20000;
    this.productCategory();
  }

  filterByPrice(maxPrice: number) {
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.initial.maxPrice = maxPrice;
    this.initial.offset = 0;
    // The legacy price chip is a max ceiling; clear any sheet price so the
    // chip's ceiling takes effect via buildListingQuery's fallback.
    this.filterState = { ...this.filterState, minPrice: null, maxPrice: null };
    this.resetImageStates();
    this.cdr.markForCheck();

    // Direct v3 (GET /v3/products), public catalog read, no authToken.
    this.networkAdapter.get_v3('GET /mobile/category-listing', { queryParams: this.buildListingQuery() })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.category_listing = response.data;
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = this.category_listing.length === 0;
          } else {
            this.category_listing = [];
            this.ui_controls.is_empty = true;
            this.ui_controls.is_loading = false;
          }
          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.is_loading = false;
          this.ui_controls.is_empty = true;
          this.cdr.markForCheck();
        }
      });
  }

  get_label() {
    this.ui_controls.is_loading_category = true;
    this.cdr.markForCheck();

    this.wishlistService.listLabels(this.single_user.token)
      .then((labels) => {
        this.categories = labels.map((l) => ({ id: l.id, name: l.name, count: l.count })) as any;
        this.ui_controls.is_loading_category = false;
        this.cdr.markForCheck();
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
        this.cdr.markForCheck();
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
        this.cdr.markForCheck();
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
        this.cdr.markForCheck();
      });
  }

  startAddToCloset(product: number, product_name: string, image_1: string) {
    this.addCloset.id = this.single_user.id;
    this.addCloset.token = this.single_user.token;
    this.addCloset.product_id = product;
    this.addCloset.product_name = product_name;
    this.addCloset.product_image = image_1;
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.get_label();
    this.isWishOpen = true;
  }

  // ========================================
  // Infinite Scroll
  // ========================================

  getMoreItems() {
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.offset = this.initial.offset + this.initial.limit;

    // Direct v3 (GET /v3/products), public catalog read, no authToken.
    this.networkAdapter.get_v3('GET /mobile/category-listing', { queryParams: this.buildListingQuery() })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.category_listing.push(...response.data);
            this.cdr.markForCheck();
          } else {
            this.ui_controls.is_empty = true;
            this.cdr.markForCheck();
          }
        }
      });
  }

  onIonInfinite(event: InfiniteScrollCustomEvent) {
    this.getMoreItems();
    setTimeout(() => {
      event.target.complete();
    }, 500);
  }

  // ========================================
  // Refresh
  // ========================================

  handleRefresh(event: any) {
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
    this.productCategory();
    setTimeout(() => {
      event.target.complete();
    }, 500);
  }

  // ========================================
  // Navigation
  // ========================================

  open_product(id: number, name: string) {
    this.router.navigate(['/', 'product'], { queryParams: { id, name } });
  }

  triggerBack() {
    this.nav.back();
  }

  onDismiss() {
    this.isWishOpen = false;
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
