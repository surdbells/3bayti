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
} from '@ionic/angular/standalone';
import { TranslatePipe } from "../../translate.pipe";
import { Products } from "../../class/products";
import { Labels } from "../../class/labels";
import { Subscription } from "rxjs";
import { ConnectionService } from "../../service/connection.service";
import { ActivatedRoute, Router } from "@angular/router";
import { MobileNetworkAdapter } from "../../core/http/mobile-network-adapter";
import { apiErrorMessage } from '../../core/http/api-error';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
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

/**
 * Admin-curated collection PLP (?slug=<collection slug>&name=<display name>).
 *
 * A clone of the category PLP keyed by the collection SLUG instead of a
 * category id: the listing is GET /v3/products?collection=<slug> (route key
 * 'GET /mobile/collection-listing', same product-card transform as
 * category-listing) and the facets are GET /v3/products/facets?collection=<slug>.
 * Sort / size / colour / price filtering, the legacy price chips, infinite
 * scroll and pull-to-refresh behave exactly like the category page.
 */
@Component({
  selector: 'app-collection',
  templateUrl: './collection.page.html',
  styleUrls: ['./collection.page.scss'],
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
export class CollectionPage implements OnInit, OnDestroy {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  collection_listing: Products[] = [];
  /** The customer's wishlist labels, for the add-to-closet sheet. */
  wishlistLabels: Labels[] = [];
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
    private router: Router,
    private route: ActivatedRoute,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private cdr: ChangeDetectorRef,
    private wishlistService: WishlistService,
    private i18n: I18nService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  // Hardware back left to Ionic's native handling (pop / overlay-close).

  ui_controls = {
    is_loading: false,
    is_creating: false,
    is_loading_labels: false,
    is_empty: false
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }

  initial = {
    id: 0,
    token: "",
    /** Collection SLUG (never a legacy id). */
    slug: "",
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

  /** True when any sheet filter / price chip narrows the listing. */
  get hasActiveFilters(): boolean {
    return this.selectedFilter !== 'all'
      || this.filterState.sort !== this.defaultSort
      || this.filterState.sizes.length > 0
      || this.filterState.colors.length > 0
      || this.filterState.minPrice !== null
      || this.filterState.maxPrice !== null;
  }

  ngOnInit(): Promise<void> {
    this.initial.slug = (this.route.snapshot.queryParamMap.get('slug') || '').trim();
    this.initial.name = this.route.snapshot.queryParamMap.get('name') || '';
    return this.getObject();
  }

  async getObject(): Promise<void> {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
    } else {
      this.single_user = JSON.parse(ret.value);
      this.initial.id = this.single_user.id;
      this.initial.token = this.single_user.token;
      this.loadFacets();
      this.loadCollection();
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

  loadCollection() {
    this.initial.limit = 10;
    this.initial.offset = 0;
    this.initial.maxPrice = 20000;
    this.resetImageStates();
    this.fetchFirstPage();
  }

  /**
   * Build the GET /mobile/collection-listing query params from the current
   * `initial` request object + the active filterState. collection (slug) +
   * limit/offset always; sort + min_price/max_price + sizes/colors (CSV) from
   * the filter set. A sheet max_price takes precedence over the legacy
   * price-chip ceiling.
   */
  private buildListingQuery(): Record<string, string | number | boolean> {
    const query: Record<string, string | number | boolean> = {
      collection: this.initial.slug,
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
    return query;
  }

  /**
   * Fetch facet counts once for the page's base context: the page sort +
   * collection (so size/colour/price options reflect this collection).
   */
  private loadFacets(): void {
    if (!this.initial.slug) {
      return;
    }
    const query: Record<string, string | number> = {
      sort: this.defaultSort,
      collection: this.initial.slug,
    };
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

  /**
   * First page of the listing for the current filters (offset already reset
   * by the caller). Public catalog read, no authToken. Without a slug there is
   * nothing to scope to, so show the empty state instead of the whole catalog.
   */
  private fetchFirstPage(): void {
    this.ui_controls.is_empty = false;
    if (!this.initial.slug) {
      this.collection_listing = [];
      this.ui_controls.is_loading = false;
      this.ui_controls.is_empty = true;
      this.cdr.markForCheck();
      return;
    }
    this.ui_controls.is_loading = true;
    this.cdr.markForCheck();

    this.networkAdapter.get_v3('GET /mobile/collection-listing', { queryParams: this.buildListingQuery() })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.collection_listing = Array.isArray(response.data) ? response.data : [];
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = this.collection_listing.length === 0;
          } else {
            this.collection_listing = [];
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
    this.loadCollection();
  }

  filterByPrice(maxPrice: number) {
    this.initial.maxPrice = maxPrice;
    this.initial.offset = 0;
    // The legacy price chip is a max ceiling; clear any sheet price so the
    // chip's ceiling takes effect via buildListingQuery's fallback.
    this.filterState = { ...this.filterState, minPrice: null, maxPrice: null };
    this.resetImageStates();
    this.fetchFirstPage();
  }

  /** Empty-state reset: drop every chip + sheet filter and reload. */
  resetFilters(): void {
    this.selectedFilter = 'all';
    this.filterState = {
      sort: this.defaultSort,
      sizes: [],
      colors: [],
      minPrice: null,
      maxPrice: null,
    };
    this.loadCollection();
  }

  get_label() {
    this.ui_controls.is_loading_labels = true;
    this.cdr.markForCheck();

    this.wishlistService.listLabels(this.single_user.token)
      .then((labels) => {
        this.wishlistLabels = labels.map((l) => ({ id: l.id, name: l.name, count: l.count })) as any;
        this.ui_controls.is_loading_labels = false;
        this.cdr.markForCheck();
      })
      .catch(() => {
        this.ui_controls.is_loading_labels = false;
        this.cdr.markForCheck();
      });
  }

  addToCloset(label: number) {
    this.ui_controls.is_loading_labels = true;
    this.addCloset.label_id = label;
    this.isWishOpen = false;
    this.cdr.markForCheck();

    this.wishlistService.add(this.single_user.token, this.addCloset.product_id, label)
      .then((ok) => {
        if (ok) {
          this.success_notification(this.i18n.t('text_added_to_wishlist'));
        }
        this.ui_controls.is_loading_labels = false;
        this.cdr.markForCheck();
      })
      .catch(() => {
        this.ui_controls.is_loading_labels = false;
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
    if (!this.initial.slug) {
      return;
    }
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.offset = this.initial.offset + this.initial.limit;

    // Direct v3 (GET /v3/products?collection=<slug>), public catalog read.
    this.networkAdapter.get_v3('GET /mobile/collection-listing', { queryParams: this.buildListingQuery() })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.collection_listing.push(...(Array.isArray(response.data) ? response.data : []));
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
    this.loadCollection();
    setTimeout(() => {
      event.target.complete();
    }, 500);
  }

  // ========================================
  // Navigation
  // ========================================

  /** Open the PDP by v3 product id (the list transform's product_id). */
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
