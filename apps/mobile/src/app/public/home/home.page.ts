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
  // GET /v3/vendors (the PAGINATED public store directory) honours
  // limit/offset, so the "Popular stores" section supports real infinite
  // scroll. hasMoreStores stays true while the last page came back full
  // (length === limit) and flips false once a short/empty page signals the
  // end of the directory.
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
    this.get_featured.offset = 0;
    this.hasMoreStores = true;
    // Paginated PUBLIC store directory (GET /v3/vendors via 'GET /mobile/stores').
    // Anonymous catalog read (no authToken). limit/offset go straight through as
    // query params; the directory honours offset. transformFeaturedVendorsResponse
    // reshapes the directoryShape into the Store card (store_id/store_name/rating
    // + products[{product_id,image,price}]), so the binding is unchanged.
    this.networkAdapter.get_v3('GET /mobile/stores', {
      queryParams: { limit: this.get_featured.limit, offset: this.get_featured.offset },
    })
      .subscribe(({
        next: (response: any) => {
          const page: Store[] = response.data ?? [];
          this.vendor_featured = page;
          this.meta = response.message;
          this.ui_controls.is_loading = false;
          // A full page means there may be more; a short/empty page is the
          // end of the directory.
          this.hasMoreStores = page.length === this.get_featured.limit;
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
    // Guard against a fetch past the end of the directory.
    if (!this.hasMoreStores) return;
    this.get_featured.offset = this.get_featured.offset + this.get_featured.limit
    // Paginated PUBLIC store directory (GET /v3/vendors) — same read as
    // get_featured_products(), advancing offset for infinite scroll. Anonymous
    // catalog read (no authToken). Response shape unchanged (transform applies).
    this.networkAdapter.get_v3('GET /mobile/stores', {
      queryParams: { limit: this.get_featured.limit, offset: this.get_featured.offset },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            const page: Store[] = response.data ?? [];
            // Dedupe by store_id so a store can't appear twice across pages.
            const existingIds = new Set(this.vendor_featured.map(s => s.store_id));
            const deduped = page.filter(
              (s: Store) => s && !existingIds.has(s.store_id)
            );
            this.vendor_featured.push(...deduped);
            // Stop once a page comes back short (end of directory).
            this.hasMoreStores = page.length === this.get_featured.limit;
          }else{
            this.ui_controls.is_empty = true;
            this.hasMoreStores = false;
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
