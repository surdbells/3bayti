import { Component, HostListener, OnDestroy, OnInit, ChangeDetectorRef, ChangeDetectionStrategy } from '@angular/core';
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
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
import { GlobalComponent } from "../../global-component";
import { InfiniteScrollCustomEvent } from "@ionic/angular";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
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
    TranslatePipe, AxIconComponent, AxLoaderComponent, AxBottomSheetComponent]
})
export class CategoryPage implements OnInit, OnDestroy {
  category_listing: Products[] = [];
  categories: Labels[] = [];
  isOnline = true;
  isWishOpen = false;
  private sub: Subscription;

  // Image loading tracking
  imageLoaded: { [key: number]: boolean } = {};

  // Price filter options
  priceFilters: number[] = [100, 300, 500, 1000, 2000, 3000, 5000];

  // Selected filter for UI feedback
  selectedFilter: number | 'all' = 'all';

  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private route: ActivatedRoute,
    private networkService: NetworkService,
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

    this.networkService.post_request(this.initial, GlobalComponent.category_listing)
      .subscribe({
        next: (response) => {
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

  filterByPrice(maxPrice: number) {
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.initial.maxPrice = maxPrice;
    this.initial.offset = 0;
    this.resetImageStates();
    this.cdr.markForCheck();

    this.networkService.post_request(this.initial, GlobalComponent.category_listing)
      .subscribe({
        next: (response) => {
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

    this.networkService.post_request(this.initial, GlobalComponent.readWishlistLabel)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.categories = response.data;
          }
          this.ui_controls.is_loading_category = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.is_loading_category = false;
          this.cdr.markForCheck();
        }
      });
  }

  addToCloset(label: number) {
    this.ui_controls.is_loading_category = true;
    this.addCloset.label_id = label;
    this.isWishOpen = false;
    this.cdr.markForCheck();

    this.networkService.post_request(this.addCloset, GlobalComponent.addWishlist)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
          }
          this.ui_controls.is_loading_category = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.is_loading_category = false;
          this.cdr.markForCheck();
        }
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

    this.networkService.post_request(this.initial, GlobalComponent.category_listing)
      .subscribe({
        next: (response) => {
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

  success_notification(message: string) {
    this.toast.success(message, { position: 'top-center' });
  }
}
