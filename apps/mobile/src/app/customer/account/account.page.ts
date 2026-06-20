import {
  Component,
  Input,
  OnDestroy,
  OnInit,
  signal,
  ViewChild
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {Preferences} from "@capacitor/preferences";
import { Subscription } from 'rxjs';
import {
  IonAvatar,
  IonButton,
  IonButtons, IonCol,
  IonContent,
  IonFooter, IonGrid,
  IonHeader, IonIcon, IonInfiniteScroll, IonInfiniteScrollContent,
  IonLabel,
  IonRow,
  IonTabBar,
  IonTabButton,
  IonToolbar
} from '@ionic/angular/standalone';
import {Router} from "@angular/router";
import {ActionSheetController, InfiniteScrollCustomEvent, Platform} from '@ionic/angular';
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import { ConnectionService } from '../../service/connection.service';
import { I18nService } from '../../i18n.service';
import { WishlistService } from '../../core/services/wishlist.service';
import {Products} from "../../class/products";
import {Labels} from "../../class/labels";
import {CartIconComponent} from "../../cart-icon.component";
import {BlockerService} from "../../blocker.service";
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { cfImage } from '../../shared/cf-image';
interface Category {
  readonly id: number;
  readonly name: string;
}
type DualRange = { lower: number; upper: number };

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
  selector: 'app-account',
  templateUrl: './account.page.html',
  styleUrls: ['./account.page.scss'],
  standalone: true,
  imports: [IonContent, IonHeader, IonToolbar, CommonModule, FormsModule, IonButton, IonButtons, IonAvatar, IonTabBar, IonTabButton, IonLabel, IonFooter, IonRow, IonCol, IonGrid, IonIcon, CartIconComponent, TranslatePipe, IonInfiniteScroll, IonInfiniteScrollContent, AxIconComponent, AxLoaderComponent, AxBottomSheetComponent]
})

export class AccountPage implements OnInit, OnDestroy {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  best_sellers: Products[] = [];
  new_arrivals: Products[] = [];
  vendor_featured: Store[] = [];
  isOnline = true;
  categories: Labels[] = [];
  isWishOpen = false;
  isFilterOpen = false;
  @Input() rating: number = 4.5;
  @Input() ratingsCount: number | string = '100+';
  isActive = false;

  // Track image loading states
  imageLoaded: { [key: string]: boolean } = {};

  // Helper to return full / half / empty stars array for template
  get stars(): ('full' | 'half' | 'empty')[] {
    const out: ('full' | 'half' | 'empty')[] = [];
    let remaining = this.rating;
    for (let i = 0; i < 5; i++) {
      if (remaining >= 1) {
        out.push('full');
      } else if (remaining >= 0.5) {
        out.push('half');
      } else {
        out.push('empty');
      }
      remaining -= 1;
    }
    return out;
  }


  range = signal<DualRange>({ lower: 5, upper: 500 });
  protected readonly category: Category[] = [
    {id: 1, name: 'Abayas'},
    {id: 2, name: 'Mukhawars'},
    {id: 3, name: 'Kaftans'},
    {id: 4, name: 'Bags'},
    {id: 5, name: 'Accessories'},
    {id: 6, name: 'Modest clothes'},
    {id: 7, name: 'Dresses'},
    {id: 8, name: 'Pyjamas'}
  ];
  protected value: Category | null = {id: 1, name: 'Abayas'};
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
    private i18n: I18nService,
    private wishlistService: WishlistService,
  ) {
    this.platform.backButton.subscribeWithPriority(10, () => {
    });
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }


  ui_controls = {
    is_loading: true,
    is_empty: false,
    is_loading_category: false
  }
  best_seller = {
    id: 0,
    token: ""
  }
  rqst_param = {
    id: 0,
    token: ""
  }
  get_featured = {
    id: 0,
    token: "",
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
    id: 0,
    token: "",
    category: 0
  }
  addCloset = {
    id: 0,
    token: "",
    label_id: 0,
    product_id: 0,
    product_name: "",
    product_image: ""
  }
  bill = {
    count: 0,
    discount: 0,
    delivery: 0,
    subtotal: 0,
    total: 0,
    f_discount: "",
    f_delivery: "",
    f_subtotal: "",
    f_total: ""
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
    is_store_active: false,
    is_store_approved: false,
    is_customer: false
  }


  // ── Gift card balance widget ──────────────────────────────────────
  activeGiftCards: any[] = [];

  get totalGiftCardBalance(): string {
    const total = this.activeGiftCards.reduce((sum: number, c: any) => sum + Number(c.balance), 0);
    return total.toFixed(2);
  }

  loadGiftCardBalance() {
    this.networkAdapter.get_v3('/v3/gift-cards/mine').subscribe({
      next: (res: any) => {
        this.activeGiftCards = (res?.data ?? []).filter(
          (c: any) => c.status === 'active' || c.status === 'partially_used'
        );
      },
      error: () => { /* silent fail — widget just doesn't show */ },
    });
  }

  openGiftCards() {
    this.router.navigate(['/my-gift-cards']);
  }

  ngOnInit() {
    this.loadGiftCardBalance();
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
    this.getObject();
  }

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.get_best_sellers();
      this.get_new_arrivals();
      this.get_featured_products();
      this.load_cart();
    }
  }

  ionViewDidEnter(){
    this.load_cart();
    }

  ngOnDestroy(): void {
    this.blocker.unblock();
    this.sub?.unsubscribe();
  }

  // ========================================
  // Image loading handlers
  // ========================================

  onImageLoad(key: string) {
    this.imageLoaded[key] = true;
  }

  onImageError(key: string) {
    // Mark as loaded even on error to hide skeleton
    this.imageLoaded[key] = true;
  }

  // ========================================
  // Navigation methods
  // ========================================

  user_profile() {
    this.router.navigate(['/', 'settings']);
  }
  user_wishlist() {
    this.router.navigate(['/', 'wishlist']);
  }
  user_messages() {
    this.router.navigate(['/', 'chat-vendors']);
  }
  bestSellers() {
    this.router.navigate(['/', 'best-sellers']);
  }
  newArrivals() {
    this.router.navigate(['/', 'new-arrivals']);
  }
  search() {
    this.router.navigate(['/', 'search']);
  }
  user_explore() {
    this.router.navigate(['/', 'explore']);
  }
  user_cart() {
    this.router.navigate(['/', 'cart']);
  }
  user_search() {
    this.router.navigate(['/', 'search']);
  }
  user_styles() {
    this.router.navigate(['/', 'styles']);
  }
  open_product(id: number) {
    this.router.navigate(
      ['/', 'product'],
      { queryParams: { id, name } }
    );
  }


  async user_sign_out() {
    const actionSheet = await this.actionSheetCtrl.create({
      header: this.i18n.t('confirm_sign_out'),
      buttons: [
        {
          text: this.i18n.t('button_sign_out'),
          role: 'destructive',
          handler: () => {
            Preferences.remove({key: 'keep_session'});
            Preferences.remove({key: 'user'});
            this.router.navigate(['/', 'login']);
          }
        }, {
          text: this.i18n.t('cancel'),
          role: 'cancel',
          data: {action: 'cancel'},
        },
      ],
    });
    await actionSheet.present();
  }

  get_best_sellers() {
    this.ui_controls.is_loading = true;
    this.best_seller.id = this.single_user.id;
    this.best_seller.token = this.single_user.token;
    this.rqst_param_products_by_category.category = 0;
    // Direct v3 (GET /v3/products with sort=best_seller). Public catalog
    // read — anonymous, so no authToken. transformBestSellersRequest drops
    // the legacy {id, token} body, so only the sort query param carries over.
    // transformBestSellersResponse still applies via get_v3, so response.data
    // shape is unchanged.
    this.networkAdapter.get_v3('GET /mobile/best-sellers', { queryParams: { sort: 'best_seller' } })
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
    this.best_seller.id = this.single_user.id;
    this.best_seller.token = this.single_user.token;
    this.rqst_param_products_by_category.category = 0;
    // Direct v3 (GET /v3/products with sort=newest). Public catalog read —
    // anonymous, so no authToken. transformNewArrivalsRequest drops the
    // legacy {id, token} body, so only the sort query param carries over.
    // The response transform still applies via get_v3, so response.data
    // shape is unchanged.
    this.networkAdapter.get_v3('GET /mobile/new-arrivals', { queryParams: { sort: 'newest' } })
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
    this.get_featured.id = this.single_user.id;
    this.get_featured.token = this.single_user.token;
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
        }
      }))
  }

  get_label() {
    this.ui_controls.is_loading_category = true;
    // M3.2.Z.3-Mobile: migrated to v3 label-aware wishlist.
    this.wishlistService.listLabels(this.single_user.token)
      .then((labels) => {
        this.categories = labels.map((l) => ({ id: l.id, name: l.name, count: l.count })) as any;
        this.ui_controls.is_loading_category = false;
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
        this.error_notification(this.i18n.t('text_something_went_wrong'));
      });
  }

  addToCloset(label: number) {
    this.ui_controls.is_loading_category = true;
    this.isWishOpen = false;
    // M3.2.Z.3-Mobile: save to v3 wishlist under the chosen label.
    this.wishlistService.add(this.single_user.token, this.addCloset.product_id, label)
      .then((ok) => {
        this.ui_controls.is_loading_category = false;
        if (ok) {
          this.success_notification(this.i18n.t('text_added_to_wishlist'));
        } else {
          this.error_notification(this.i18n.t('text_something_went_wrong'));
        }
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
        this.error_notification(this.i18n.t('text_something_went_wrong'));
      });
  }

  startAddToCloset(product: number, product_name: string, image_1: string) {
    this.addCloset.id = this.single_user.id;
    this.addCloset.token = this.single_user.token;
    this.addCloset.product_id = product;
    this.addCloset.product_name = product_name;
    this.addCloset.product_image = image_1;
    this.rqst_param.id = this.single_user.id;
    this.rqst_param.token = this.single_user.token;
    this.get_label();
    this.isWishOpen = true;
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

  openCart() {
    this.router.navigate(['/', 'cart']);
  }

  open_reviews(id: any, name: any) {
    this.router.navigate(
      ['/', 'store_reviews'],
      { queryParams: { id, name } }
    );
  }

  open_vendor(id: number, name: string) {
    this.router.navigate(
      ['/', 'vendors'],
      { queryParams: { id, name } }
    );
  }

  open_category(id: number, name: string) {
    this.router.navigate(
      ['/', 'category'],
      { queryParams: { id, name } }
    );
  }

  refresh_products() {
    this.ui_controls.is_loading = true;
    this.imageLoaded = {}; // Reset image loading states
    this.get_best_sellers();
    this.get_new_arrivals();
    this.get_featured_products();
  }

  load_cart() {
    this.rqst_param.id = this.single_user.id;
    this.rqst_param.token = this.single_user.token;
    // Direct v3 (GET /v3/cart). Authed read — pass the user token as authToken.
    // transformCartListResponse still applies via get_v3, preserving the legacy
    // v2 envelope: response.message carries the bill summary the strip reads.
    this.networkAdapter.get_v3('GET /cart', { authToken: this.single_user.token })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200) {
            this.bill = response.message;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }

  OnDidDismiss() {
    this.isWishOpen = false;
  }

  getMoreItems() {
    this.get_featured.id = this.single_user.id;
    this.get_featured.token = this.single_user.token;
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
            this.vendor_featured.push(...response.data);
          }else{
            this.ui_controls.is_empty = true;
            this.error_notification(response.message);
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

  user_store() {
    this.router.navigate(['/', 'store-dashboard']);
  }
}
