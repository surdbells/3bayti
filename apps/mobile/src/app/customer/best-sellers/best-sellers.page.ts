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
import { GlobalComponent } from "../../global-component";
import { Labels } from "../../class/labels";
import { TranslatePipe } from "../../translate.pipe";
import { Products } from "../../class/products";
import { InfiniteScrollCustomEvent } from "@ionic/angular";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { WishlistService } from '../../core/services/wishlist.service';
import { I18nService } from '../../i18n.service';
import { cfImage } from '../../shared/cf-image';
@Component({
  selector: 'app-best-sellers',
  templateUrl: './best-sellers.page.html',
  styleUrls: ['./best-sellers.page.scss'],
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
    TranslatePipe, AxIconComponent, AxLoaderComponent, AxBottomSheetComponent]
})
export class BestSellersPage implements OnInit, OnDestroy {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  best_sellers: Products[] = [];
  categories: Labels[] = [];
  isOnline = true;
  isWishOpen = false;
  private sub: Subscription | null = null;

  // Image loading state tracking
  imageLoaded: { [key: number]: boolean } = {};

  // Price filter options
  priceFilters: number[] = [100, 300, 500, 1000, 2000, 3000, 5000];
  selectedFilter: number | 'all' = 'all';

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
      this.bestSellers();
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

  bestSellers(): void {
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.initial.limit = 10;
    this.initial.offset = 0;
    this.initial.maxPrice = 20000;
    this.resetImageStates();
    this.cdr.markForCheck();

    // Direct v3 (GET /v3/products, sort=best_seller). This is a public
    // catalog read — no authToken. transformBestSellersListingRequest
    // maps limit/offset/maxPrice into the v3 query params, and the
    // registered response transform still applies via get_v3, so
    // response.data keeps the legacy Products[] shape.
    this.networkAdapter.get_v3('GET /mobile/best-sellers-listing', {
      queryParams: {
        sort: 'best_seller',
        limit: this.initial.limit,
        offset: this.initial.offset,
        max_price: this.initial.maxPrice,
      },
    })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.best_sellers = response.data;
          } else {
            this.best_sellers = [];
            this.error_notification(response.message);

          }
          this.ui_controls.is_loading = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.is_loading = false;
          this.best_sellers = [];
          this.cdr.markForCheck();
        }
      });
  }

  filterByPrice(maxPrice: number): void {
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.initial.maxPrice = maxPrice;
    this.initial.offset = 0;
    this.resetImageStates();
    this.cdr.markForCheck();

    // Direct v3 (GET /v3/products, sort=best_seller) — public catalog
    // read, no authToken. The price band rides through as the maxPrice
    // query param (mapped to max_price by transformBestSellersListingRequest).
    this.networkAdapter.get_v3('GET /mobile/best-sellers-listing', {
      queryParams: {
        sort: 'best_seller',
        limit: this.initial.limit,
        offset: this.initial.offset,
        max_price: this.initial.maxPrice,
      },
    })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.best_sellers = response.data;
          } else {
            this.best_sellers = [];
          }
          this.ui_controls.is_loading = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.is_loading = false;
          this.best_sellers = [];
          this.cdr.markForCheck();
        }
      });
  }

  getMoreItems(): void {
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.offset = this.initial.offset + this.initial.limit;

    // Direct v3 (GET /v3/products, sort=best_seller) — paginated read for
    // infinite scroll, advancing offset. Public catalog read, no authToken.
    // Shape unchanged (response transform still applies via get_v3).
    this.networkAdapter.get_v3('GET /mobile/best-sellers-listing', {
      queryParams: {
        sort: 'best_seller',
        limit: this.initial.limit,
        offset: this.initial.offset,
        max_price: this.initial.maxPrice,
      },
    })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.best_sellers.push(...response.data);
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
    this.resetImageStates();
    this.bestSellers();
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

  success_notification(message: string): void {
    this.toast.success(message, { position: 'top-center' });
  }
}
