import {Component, HostListener, OnDestroy, OnInit} from '@angular/core';

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
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import {Search} from "../../class/search";
import {Labels} from "../../class/labels";
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
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
    AxBottomSheetComponent,
    AppTabBarComponent
  ]
})
export class SearchPage implements OnInit, OnDestroy {
  products: Search[] = [];
  categories: Labels[] = [];
  isOnline = true;
  isWishOpen = false; // or control this as you like
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
    private toast: AxNotificationService
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  @HostListener('window:ionBackButton', ['$event'])
  onHardwareBack(ev: Event) {
    (ev as any).detail.register(100, () => {
      this.nav.navigateRoot('/account');
    });
  }
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
    this.networkService.post_request(this.search, GlobalComponent.search)
      .subscribe(({
        next: (response) => {
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
    this.router.navigate(['/', 'orders']);
  }
  get_label() {
    this.ui_controls.is_loading_category = true;
    this.networkService.post_request(this.rqst_param, GlobalComponent.readWishlistLabel)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.categories = response.data;
            this.ui_controls.is_loading_category = false;
          }
        }
      }))
  }
  addToCloset(label: number) {
    this.ui_controls.is_loading_category = true;
    this.addCloset.label_id = label;
    this.isWishOpen = false;
    this.networkService.post_request(this.addCloset, GlobalComponent.addWishlist)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
            this.ui_controls.is_loading_category = false;
          }else{
            this.ui_controls.is_loading_category = false;
          }
        }
      }))
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
    this.router.navigate(['/', 'orders']);
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
