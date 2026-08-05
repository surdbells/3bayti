import {Component, OnDestroy, OnInit} from '@angular/core';

import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCol,
  IonContent,
  IonGrid,
  IonHeader,
  IonRow,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import {Subscription} from "rxjs";
import {ConnectionService} from "../../service/connection.service";
import {Router, RouterLink} from "@angular/router";
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {Search} from "../../class/search";
import {Labels} from "../../class/labels";
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxWishlistSheetComponent } from '../../shared/ax-mobile/wishlist-sheet';
import { WishlistService } from '../../core/services/wishlist.service';
import { I18nService } from '../../i18n.service';
import { cfImage } from '../../shared/cf-image';
@Component({
  selector: 'app-search',
  templateUrl: './search.page.html',
  styleUrls: ['./search.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonGrid,
    IonRow,
    IonCol,
    RouterLink,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AxTextFieldComponent,
    AxWishlistSheetComponent,
    AppTabBarComponent
  ]
})
export class SearchPage implements OnInit, OnDestroy {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  products: Search[] = [];
  categories: Labels[] = [];
  isOnline = true;
  isWishOpen = false; // or control this as you like
  /** True while POST /me/wishlist/labels is in flight (inline label create). */
  isCreatingLabel = false;
  private sub: Subscription;
  product = {
    name: "",
    isFavorite: false,
    description: "",
    price: "",
    quantity: "",
    size: undefined
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
    private toast: AxNotificationService
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
  /** Per-product image-loaded tracking for the m6d card skeleton overlay */
  imageLoaded: { [key: number]: boolean } = {};
  /** Debounce timer for onInputChange — prevents firing a search request
      on every keystroke. Reset on each input change, fires after 300ms
      of inactivity. */
  private searchTimer: any = null;
  private readonly SEARCH_DEBOUNCE_MS = 300;
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
    if (this.searchTimer) {
      clearTimeout(this.searchTimer);
      this.searchTimer = null;
    }
  }
  rqst_param = {
    id: 0,
    token: ""
  }
  search = {
    id: 0,
    token: "",
    search: ""
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
    this.getObject();
  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.search.id = this.single_user.id
      this.search.token = this.single_user.token
    }
  }
  searchProduct() {
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.imageLoaded = {};
    // Direct v3 (GET /v3/products via 'GET /mobile/search'). Public catalog
    // read — no auth token. The search request transform maps the legacy
    // body's `search` field to the v3 `q` query param; pass it explicitly.
    // The response transform still applies via get_v3, so response.data keeps
    // the legacy Search[] shape — subscribe logic below is unchanged.
    this.networkAdapter.get_v3('GET /mobile/search', { queryParams: { q: this.search.search } })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.products = response.data;
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = false;
          }else{
            this.ui_controls.is_loading = false;
            this.ui_controls.is_empty = true;
          }
        }
      }))
  }
  onImageLoad(productId: number): void {
    this.imageLoaded[productId] = true;
  }
  onImageError(productId: number): void {
    /* Hide skeleton even on error so the placeholder doesn't loop forever */
    this.imageLoaded[productId] = true;
  }
  user_wishlist() {
    this.router.navigate(['/', 'wishlist']);
  }
  user_support() {
    this.router.navigate(['/', 'my-orders']);
  }
  get_label() {
    this.ui_controls.is_loading_category = true;
    this.wishlistService.listLabels(this.single_user.token)
      .then((labels) => {
        this.categories = labels.map((l) => ({ id: l.id, name: l.name, count: l.count })) as any;
        this.ui_controls.is_loading_category = false;
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
      });
  }
  addToCloset(label: number) {
    this.ui_controls.is_loading_category = true;
    this.addCloset.label_id = label;
    this.isWishOpen = false;
    this.wishlistService.add(this.single_user.token, this.addCloset.product_id, label)
      .then((ok) => {
        if (ok) {
          this.success_notification(this.i18n.t('text_added_to_wishlist'));
        }
        this.ui_controls.is_loading_category = false;
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
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
  open_product(id: number, name: string) {
    this.router.navigate(
      ['/', 'product'],
      { queryParams: { id, name } }
    );
  }
  error_notification(message: string) {
    this.toast.error(message, {
      position: "top-center"
    });
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
    }
  }

  success_notification(message: string) {
    this.toast.success(message, {
      position: 'top-center'
    });
  }

  onInputChange(value: string) {
    this.search.search = value;
    /* Clear any pending search */
    if (this.searchTimer) {
      clearTimeout(this.searchTimer);
      this.searchTimer = null;
    }
    /* Empty input — clear results immediately, show initial prompt */
    if (!value || !value.trim()) {
      this.products = [];
      this.imageLoaded = {};
      this.ui_controls.is_loading = false;
      this.ui_controls.is_empty = false;
      return;
    }
    /* Non-empty input — debounce the actual network call */
    this.searchTimer = setTimeout(() => {
      this.searchProduct();
      this.searchTimer = null;
    }, this.SEARCH_DEBOUNCE_MS);
  }

  user_orders() {
    this.router.navigate(['/', 'my-orders']);
  }
  open_vendor(id: number, name: string) {
    this.router.navigate(
      ['/', 'vendors'],
      { queryParams: { id, name } }
    );
  }

  onDismiss() {
    this.isWishOpen= false;
  }
}
