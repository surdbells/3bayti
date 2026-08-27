import {Component} from '@angular/core';

import { FormsModule } from '@angular/forms';
import {Subscription} from "rxjs";
import {Router} from "@angular/router";
import {ConnectionService} from "../../../service/connection.service";
import {BlockerService} from "../../../blocker.service";
import {NetworkService} from "../../../service/network.service";
import {MobileNetworkAdapter} from "../../../core/http/mobile-network-adapter";
import {AxNotificationService} from '../../../shared/ax-mobile/notification';
import {
  IonButton,
  IonButtons,
  IonCard,
  IonCardContent,
  IonCardHeader,
  IonCardTitle,
  IonCheckbox,
  IonCol,
  IonContent,
  IonGrid,
  IonHeader,
  IonImg,
  IonInput,
  IonItem,
  IonRow,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from "@ionic/angular/standalone";
import {TranslatePipe} from "../../../translate.pipe";
import {GlobalComponent} from "../../../global-component";
import {Preferences} from "@capacitor/preferences";
import { AxIconComponent } from '../../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../../shared/ax-mobile/loader';
import { AxBottomSheetComponent } from '../../../shared/ax-mobile/bottom-sheet';
/** A selectable aesthetic style category (drives create_style.category). */
export interface StyleCategoryOption {
  value: string;
  labelKey: string;
}
/** A selectable PRODUCT category, numeric legacy category_id + i18n label. */
export interface ProductCategoryOption {
  id: number;
  labelKey: string;
}
export interface Product {
  id: number;
  token: string;
  product_id: number;
  product_name: string;
  image_1: string;
  price: string;
}
export interface selectedProduct {
  id: number;
  token: string;
  product_id: number;
  product_name: string;
  image_1: string;
  price: string;
}
@Component({
  selector: 'app-create',
  templateUrl: './create.page.html',
  styleUrls: ['./create.page.scss'],
  standalone: true,
  imports: [
    IonButton,
    IonButtons,
    IonCard,
    IonCardContent,
    IonCardHeader,
    IonCardTitle,
    IonCheckbox,
    IonCol,
    IonContent,
    IonGrid,
    IonHeader,
    IonImg,
    IonInput,
    IonItem,
    IonRow,
    IonTitle,
    IonToolbar,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AxBottomSheetComponent,
    AppTabBarComponent
  ]
})
export class CreatePage {
  products: Product[] = [];

  /**
   * Aesthetic style categories (bound to create_style.category via the
   * category picker sheet). These are the same 24 options the legacy
   * ion-select carried, labels resolve through the style_category_*
   * i18n keys.
   */
  readonly styleCategories: StyleCategoryOption[] = [
    { value: 'classic', labelKey: 'style_category_classic' },
    { value: 'minimalist', labelKey: 'style_category_minimalist' },
    { value: 'preppy', labelKey: 'style_category_preppy' },
    { value: 'elegant', labelKey: 'style_category_elegant' },
    { value: 'casual', labelKey: 'style_category_casual' },
    { value: 'streetwear', labelKey: 'style_category_streetwear' },
    { value: 'athleisure', labelKey: 'style_category_athleisure' },
    { value: 'normcore', labelKey: 'style_category_normcore' },
    { value: 'bohemian', labelKey: 'style_category_bohemian' },
    { value: 'vintage', labelKey: 'style_category_vintage' },
    { value: 'retro', labelKey: 'style_category_retro' },
    { value: 'artsy', labelKey: 'style_category_artsy' },
    { value: 'gothic', labelKey: 'style_category_gothic' },
    { value: 'punk', labelKey: 'style_category_punk' },
    { value: 'grunge', labelKey: 'style_category_grunge' },
    { value: 'emo', labelKey: 'style_category_emo' },
    { value: 'traditional', labelKey: 'style_category_traditional' },
    { value: 'modest', labelKey: 'style_category_modest' },
    { value: 'afrocentric', labelKey: 'style_category_afrocentric' },
    { value: 'western', labelKey: 'style_category_western' },
    { value: 'high-fashion', labelKey: 'style_category_high_fashion' },
    { value: 'chic', labelKey: 'style_category_chic' },
    { value: 'glam', labelKey: 'style_category_glam' },
    { value: 'y2k', labelKey: 'style_category_y2k' },
  ];
  isCategoryOpen = false;

  /**
   * PRODUCT categories for the picker sheet. These are the real numeric
   * legacy category_id values the catalog uses (mirrors the account page's
   * category chips, Abayas..Pyjamas → ids 1..8). GET /mobile/category-listing
   * filters server-side on category_id (resolved to a v3 category in
   * ListProductsController), so these ids drive the product grid.
   */
  readonly productCategories: ProductCategoryOption[] = [
    { id: 1, labelKey: 'abayas' },
    { id: 2, labelKey: 'mukhawars' },
    { id: 3, labelKey: 'kaftans' },
    { id: 4, labelKey: 'bags' },
    { id: 5, labelKey: 'accessories' },
    { id: 6, labelKey: 'modest_clothes' },
    { id: 7, labelKey: 'dresses' },
    { id: 8, labelKey: 'pyjamas' },
  ];
  selectedProductCategoryId = 1;

  /** Product-picker fulltext search (global ?q= over the catalog). */
  productSearchQuery = '';
  private searchDebounce: any = null;
  /** Discards stale responses when search / category change rapidly. */
  private reqToken = 0;

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
  selectedCount = 0;
  isOnline = true;
  private sub: Subscription;
  constructor(
    private router: Router,
    private platform: Platform,
    private nav: NavController,
    private net: ConnectionService,
    private blocker: BlockerService,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService
  ) {
    this.platform.backButton.subscribeWithPriority(10, () => {
    });
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  ionViewDidEnter(){
    this.getObject();
  }
  ui_controls = {
    is_loading: false,
    is_creating: false,
    is_empty: false,
    is_loading_category: false,
    is_loading_more: false,
    hasMore: true
  }
  initial = {
    id: 0,
    token: "",
    limit: 15,
    offset: 0
  }
  create_style = {
    id: 0,
    token: "",
    name: "",
    category: "classic",
    products: "",
    isPrivate: false
  }
  isProductsOpen =  false;
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.initial.id = this.single_user.id;
      this.initial.token = this.single_user.token;
      // Prime the product picker with the first product category so the
      // grid is populated the moment the sheet opens.
      this.loadProductsForCategory(this.selectedProductCategoryId);
    }
  }

  /** Human label for the currently selected aesthetic style category. */
  get selectedCategoryLabelKey(): string {
    const match = this.styleCategories.find(c => c.value === this.create_style.category);
    return match ? match.labelKey : '';
  }

  /** Open / close the aesthetic-style-category picker sheet. */
  openCategorySheet() {
    this.isCategoryOpen = true;
  }
  onCategoryDidDismiss() {
    this.isCategoryOpen = false;
  }
  selectStyleCategory(option: StyleCategoryOption) {
    this.create_style.category = option.value;
    this.isCategoryOpen = false;
  }

  /**
   * Switch the product picker to a different PRODUCT category. Resets
   * paging and reloads the grid for that category_id.
   */
  selectProductCategory(option: ProductCategoryOption) {
    if (this.selectedProductCategoryId === option.id && this.productSearchQuery.trim() === '') return;
    this.selectedProductCategoryId = option.id;
    // Switching category clears any active search so the chip drives the grid.
    this.productSearchQuery = '';
    if (this.searchDebounce) { clearTimeout(this.searchDebounce); this.searchDebounce = null; }
    this.fetchProducts(true);
  }

  /**
   * Product-picker search. Debounced ~300ms; a non-empty query runs a GLOBAL
   * fulltext search (?q=) across the catalog (ignoring the category chip), so
   * a shopper can find a product without knowing its category. Clearing the
   * box reverts to the selected category.
   */
  onProductSearch(query: string) {
    this.productSearchQuery = query;
    if (this.searchDebounce) { clearTimeout(this.searchDebounce); }
    this.searchDebounce = setTimeout(() => {
      this.searchDebounce = null;
      this.fetchProducts(true);
    }, 300);
  }

  /**
   * Load the first page of products for a product category_id.
   *
   * Direct v3 via GET /mobile/category-listing (→ /v3/products). The v3
   * ListProductsController resolves category_id to a category and filters
   * server-side; the registered transformProductListResponse reshapes the
   * v3 envelope back into the legacy product-card shape
   * ({product_id, product_name, image_1, price}) the grid + addProductToStyle
   * expect. Public read, no authToken.
   */
  loadProductsForCategory(categoryId: number) {
    this.selectedProductCategoryId = categoryId;
    this.productSearchQuery = '';
    this.fetchProducts(true);
  }

  /**
   * Build the product-listing query. A non-empty search query runs a global
   * fulltext search (?q=) across the catalog; otherwise it filters by the
   * selected product category_id. Always carries the current limit/offset.
   */
  private productQueryParams(): Record<string, string | number> {
    const params: Record<string, string | number> = {
      limit: this.initial.limit,
      offset: this.initial.offset,
    };
    const q = this.productSearchQuery.trim();
    if (q.length > 0) {
      params['q'] = q;
    } else {
      params['category_id'] = this.selectedProductCategoryId;
    }
    return params;
  }

  /**
   * Fetch products for the current category/search. reset=true replaces the
   * grid (first load / category switch / new search); reset=false appends the
   * next page (infinite scroll, driven by the sheet's scrolledToBottom). A
   * per-request token discards stale responses when the user changes
   * category/search mid-flight, and hasMore stops paging once a short page
   * comes back. GET /mobile/category-listing → /v3/products with the mobile
   * transform applied. Public read, no authToken.
   */
  private fetchProducts(reset: boolean) {
    if (!reset && (this.ui_controls.is_loading || this.ui_controls.is_loading_more || !this.ui_controls.hasMore)) {
      return;
    }
    if (reset) {
      this.initial.offset = 0;
      this.ui_controls.hasMore = true;
      this.ui_controls.is_loading = true;
    } else {
      this.initial.offset += this.initial.limit;
      this.ui_controls.is_loading_more = true;
    }
    const token = ++this.reqToken;
    this.networkAdapter.get_v3('GET /mobile/category-listing', {
      queryParams: this.productQueryParams(),
    })
      .subscribe({
        next: (response: any) => {
          if (token !== this.reqToken) return;
          const data = (response.response_code === 200 && response.status === 'success')
            ? (response.data ?? [])
            : [];
          if (reset) {
            this.products = data;
          } else {
            this.products.push(...data);
          }
          if (data.length < this.initial.limit) {
            this.ui_controls.hasMore = false;
          }
          this.ui_controls.is_empty = this.products.length === 0;
          this.ui_controls.is_loading = false;
          this.ui_controls.is_loading_more = false;
        },
        error: () => {
          if (token !== this.reqToken) return;
          this.ui_controls.is_loading = false;
          this.ui_controls.is_loading_more = false;
        },
      });
  }
  error_notification(message: string) {
    this.toast.error(message, {
      position: "top-center"
    });
  }
  success_notification(message: string) {
    this.toast.success(message, {
      position: 'bottom-center'
    });
  }
  createStyle() {
    if (this.create_style.name.length == 0){
      this.error_notification("name is required"); return;
    }
    if (this.create_style.category.length == 0){
      this.error_notification("category is required"); return;
    }
    this.ui_controls.is_creating = true;
    this.create_style.id = this.single_user.id;
    this.create_style.token = this.single_user.token;
    // Direct v3 (POST /v3/me/styles). Authed, token rides the
    // Authorization header via opts.authToken, not the body. The v3
    // CreateStyleController reads only `name` and `products` (CSV);
    // legacy `category`/`isPrivate` have no v3 equivalent and are dropped.
    this.networkAdapter.post_v3(
      'POST /me/styles',
      {
        name: this.create_style.name,
        products: this.create_style.products,
      },
      { authToken: this.single_user.token },
    )
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.products = response.data;
            this.ui_controls.is_creating = false;
            this.router.navigate(['/', 'styles']);
            this.success_notification(response.message);
          }else {
            this.ui_controls.is_creating = false;
            this.error_notification(response.message);
            this.router.navigate(['/', 'styles']);
          }
        }
      }))
  }
  addProductToStyle(style: any, product: Product, max = 4) {
    if (!product.product_id) return style;
    const productsArray = style.products
      ? style.products.split(',').map((id: string) => id.trim())
      : [];
    // Prevent duplicates
    if (productsArray.includes(String(product.product_id))) {
      return style;
    }
    // Enforce max limit
    if (productsArray.length >= max) {
      this.error_notification("maximum of 4 products allowed.");
      return style;
    }
    productsArray.push(String(product.product_id));
    style.products = productsArray.join(',');
    this.success_notification(product.product_name + ' added successfully');
    this.selectProduct(product);
    return style;
  }

  selectedProducts: selectedProduct[] = [];
  selectProduct(product: Product) {
    const exists = this.selectedProducts.some(
      p => p.product_id === product.product_id
    );
    if (exists) {
      this.error_notification('product already selected.')
      return;
    }
    if (!exists) {
      this.selectedProducts.push({ ...product });
    }
  }
  getMoreItems() {
    this.fetchProducts(false);
  }
  handleRefresh(event: any) {
    setTimeout(() => {
      this.ui_controls.is_loading = true;
      // this.get_best_sellers();
      //  this.get_featured_products();
      event.target.complete();
    }, 200);
  }
  triggerBack() {
    this.nav.back();
  }
  add_selection() {

  }
  OnDidDismiss() {
    this.isProductsOpen = false;
  }
  addProduct(id: number) {

  }

  removeProduct(product_id: number): void {
    // Remove from comma-separated products
    const products = String(this.create_style.products || '');
    this.create_style.products = products
      .split(',')
      .map((id: string) => id.trim())
      .filter((id: string) => id !== String(product_id))
      .join(',');

    // Remove from selectedProducts (handle string/number mismatch)
    this.selectedProducts = this.selectedProducts.filter(
      (product: selectedProduct) =>
        String(product.product_id) !== String(product_id)
    );
  }

  onToggleChange(event: any) {
  }
}
