import {Component, Input, OnDestroy, OnInit, signal, ViewChild} from '@angular/core';

import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonCard,
  IonContent,
  IonHeader,
  IonInfiniteScroll,
  IonInfiniteScrollContent,
  IonTitle,
  IonToolbar, IonFooter } from '@ionic/angular/standalone';
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";
import {Products} from "../../class/products";
import {Labels} from "../../class/labels";
import {Subscription} from "rxjs";
import {Router} from "@angular/router";
import {ActionSheetController, InfiniteScrollCustomEvent, Platform} from "@ionic/angular";
import {ConnectionService} from "../../service/connection.service";
import {BlockerService} from "../../blocker.service";
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
interface Category {
  readonly id: number;
  readonly name: string;
}
export interface Product {
  product_id: number;
  product_name: string;
  price: string;
  image: string;
}

export interface Store {
  store_id: number;
  store_name: string;
  store_desc: string;
  rating: number | null;
  rating_count: number;
  products: Product[];
}
@Component({
  selector: 'app-home',
  templateUrl: './home.page.html',
  styleUrls: ['./home.page.scss'],
  standalone: true,
  imports: [IonFooter, 
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonCard,
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    CommonModule,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
  ]
})
export class HomePage implements OnInit, OnDestroy {
  best_sellers: Products[] = [];
  new_arrivals: Products[] = [];
  vendor_featured: Store[] = [];
  // GET /v3/featured-vendors is a CURATED, non-paginated spotlight (it ignores
  // offset and never returns has_more), so there is no "next page". Once the
  // first (and only) page is loaded this stays false to stop the infinite
  // scroll from re-appending the same stores.
  hasMoreStores = true;
  isOnline = true;
  categories: Labels[] = [];
  @Input() rating: number = 4.5;
  @Input() ratingsCount: number | string = '100+';
  imageLoaded: { [key: string]: boolean } = {};
  protected readonly category: Category[] = [
    {id: 1, name: 'Abayas'},
    {id: 2, name: 'Mukhawars'},
    {id: 3, name: 'Kaftans'},
    {id: 4, name: 'Bags'},
    {id: 5, name: 'Accessories'},
    {id: 6, name: 'Modest clothes'},
    {id: 7, name: 'Dresses'},
    {id: 8, name: 'Active wear'}
  ];
  protected value: Category | null = {id: 1, name: 'Abayas'}; // !== this.users[0]
  private sub: Subscription;
  constructor(
    private router: Router,
    private platform: Platform,
    private net: ConnectionService,
    private blocker: BlockerService,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private i18n: I18nService
  ) {
    this.platform.backButton.subscribeWithPriority(10, () => {
    });
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  ui_controls = {
    is_loading: false,
    is_empty: false,
    is_loading_category: false
  }
  best_seller = {
    id: 1,
    token:  "PBCTKT",
  }
  rqst_param = {
    id: 1,
    token: "PBCTKT",
  }
  get_featured = {
    id: 1,
    token: "PBCTKT",
    limit: 5,
    offset: 0
  }
  meta = {
    total: 0,
    page: 0,
    per_page: 0,
    total_pages: 0
  };
  rqst_param_products_by_category = {
    category: 0
  }
  ngOnInit() {
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
    this.get_best_sellers();
    this.get_new_arrivals();
    this.get_featured_products();
  }

  ngOnDestroy(): void {
    this.blocker.unblock(); // ✅ restore when leaving
    this.sub?.unsubscribe();
  }

  open_product(id: number) {
    // Use the full product detail page — same as best-sellers / new-arrivals /
    // category / cart. The legacy `/single` page is an incomplete preview (its
    // "Add to Cart" only redirects to sign-in and it has no size/measurement
    // UI), so home was the only surface sending users to a dead-end PDP.
    this.router.navigate(
      ['/', 'product'],
      { queryParams: { id } }
    );
  }

  get_best_sellers() {
    this.ui_controls.is_loading = true;
    this.rqst_param_products_by_category.category = 0;
    // M3.2.X.1-C2: third best_sellers call site (missed in -C; home.page
    // wasn't in the initial inspection of best_sellers usage).
    // Same shadow → flip lifecycle as account.page::get_best_sellers
    // and best-sellers.page methods.
    // Direct v3 (GET /v3/products with sort=best_seller). Public catalog
    // read — anonymous, so no authToken. transformBestSellersRequest drops
    // the legacy {id, token} body, so no query params carry over.
    // transformBestSellersResponse still applies via get_v3, so response.data
    // shape is unchanged.
    this.networkAdapter.get_v3('GET /mobile/best-sellers', { queryParams: { sort: 'best_seller', limit: 10 } })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.best_sellers = response.data;
            this.ui_controls.is_loading = false;
          }else{
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  get_new_arrivals() {
    this.ui_controls.is_loading = true;
    this.rqst_param_products_by_category.category = 0;
    // M3.2.X.1.5-A: route through MobileNetworkAdapter to consult
    // 'GET /mobile/new-arrivals' feature flag (already target='new'
    // since M3.1.5).
    // Direct v3 (GET /v3/products with sort=newest). Public catalog read —
    // anonymous, so no authToken. transformNewArrivalsRequest drops the
    // legacy {id, token} body, so no query params carry over. The response
    // transform still applies via get_v3, so response.data shape is unchanged.
    this.networkAdapter.get_v3('GET /mobile/new-arrivals', { queryParams: { sort: 'newest', limit: 10 } })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.new_arrivals = response.data;
            this.ui_controls.is_loading = false;
          }else{ this.ui_controls.is_loading = false; }
        }
      }))
  }
  get_featured_products() {
    this.ui_controls.is_loading = true;
    // M3.2.X.1.5-A: 'GET /mobile/featured' flag (target='new' since M3.1.5).
    // Direct v3 (GET /v3/featured-vendors). Public catalog read — anonymous,
    // so no authToken. transformFeaturedRequest carries over limit/offset from
    // the existing `get_featured` request object. The response transform still
    // applies via get_v3, so response.data shape is unchanged.
    this.networkAdapter.get_v3('GET /mobile/featured', {
      queryParams: { limit: this.get_featured.limit, offset: this.get_featured.offset },
    })
      .subscribe(({
        next: (response: any) => {
          this.vendor_featured = response.data;
          this.meta = response.message;
          this.ui_controls.is_loading = false;
          // Curated spotlight: there is no second page. Disable further
          // infinite-scroll fetches so the same stores aren't re-appended.
          this.hasMoreStores = false;
        }
      }))
  }

  // ========================================
  // Image loading handlers
  // ========================================

  onImageLoad(key: string) {
    this.imageLoaded[key] = true;
  }
  onImageError(key: string) {
    this.imageLoaded[key] = true;
  }

  user_register() {
    this.router.navigate(['/', 'register']);
  }
  user_login() {
    this.router.navigate(['/', 'login']);
  }
  getMoreItems() {
    // The featured-vendors endpoint is a curated, non-paginated spotlight, so
    // there is nothing to fetch beyond the first page. Guard against re-fetching
    // (and re-appending) the same stores.
    if (!this.hasMoreStores) return;
    this.get_featured.offset = this.get_featured.offset + this.get_featured.limit
    // Direct v3 (GET /v3/featured-vendors) — same paginated read as
    // get_featured_products(), advancing offset for infinite scroll.
    // Anonymous catalog read (no authToken); limit/offset carry over from
    // the `get_featured` request object. Response shape unchanged (transform
    // applies).
    this.networkAdapter.get_v3('GET /mobile/featured', {
      queryParams: { limit: this.get_featured.limit, offset: this.get_featured.offset },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            // Client-side dedupe by store_id as a guard against the curated set
            // re-appearing.
            const existingIds = new Set(this.vendor_featured.map(s => s.store_id));
            const deduped = (response.data ?? []).filter(
              (s: Store) => s && !existingIds.has(s.store_id)
            );
            this.vendor_featured.push(...deduped);
          }else{
            this.ui_controls.is_empty = true;
          }
          // Curated spotlight: no further pages — stop the infinite scroll.
          this.hasMoreStores = false;
        }
      }))
  }
  onIonInfinite(event: InfiniteScrollCustomEvent) {
    this.getMoreItems();
    setTimeout(() => {
      event.target.complete();
    }, 500);
  }
  open_category(id: number, name: string) {
    this.error_notification(this.i18n.t('text_signup_to_continue'));
  }
  bestSellers() {
    this.error_notification(this.i18n.t('text_signup_to_continue'));
  }
  open_vendor(store_id: number, store_name: string) {
    this.error_notification(this.i18n.t('text_signup_to_continue'));
  }

  open_reviews(store_id: number, store_name: string) {
    this.error_notification(this.i18n.t('text_signup_to_continue'));
  }

  startAddToCloset(product_id: number, product_name: string, image_1: string) {
    // Guest wishlist tap -> prompt sign-in/up with the wishlist-specific message.
    this.error_notification(this.i18n.t('sign_in_to_add_to_wishlist'));
  }

  newArrivals() {
    this.error_notification(this.i18n.t('text_signup_to_continue'));
  }
  error_notification(message: string) {
    this.toast.error(message, {
      position: "top-center"
    });
  }
  success_notification(message: string) {
    this.toast.success(message, {
      position: 'top-center'
    });
  }
}
