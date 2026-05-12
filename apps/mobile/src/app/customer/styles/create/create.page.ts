import {Component} from '@angular/core';

import { FormsModule } from '@angular/forms';
import {Subscription} from "rxjs";
import {Router} from "@angular/router";
import {ConnectionService} from "../../../service/connection.service";
import {BlockerService} from "../../../blocker.service";
import {InfiniteScrollCustomEvent} from "@ionic/angular";
import {NetworkService} from "../../../service/network.service";
import {AxNotificationService} from '../../../shared/ax-mobile/notification';
import {
  IonAccordion,
  IonAccordionGroup,
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
  IonInfiniteScroll,
  IonInfiniteScrollContent,
  IonInput,
  IonItem,
  IonLabel,
  IonRow,
  IonSelect,
  IonSelectOption,
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
export interface Store {
  id: number;
  token: string;
  store_name: string;
  store_id: number;
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
    IonAccordion,
    IonAccordionGroup,
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
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    IonInput,
    IonItem,
    IonLabel,
    IonRow,
    IonSelect,
    IonSelectOption,
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
  stores: Store[] = [];
  products: Product[] = [];
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
    is_loading_category: false
  }
  initial = {
    id: 0,
    token: "",
    limit: 15,
    offset: 0
  }
  product_handler = {
    id: 0,
    token: "",
    storeId: 5
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
      this.get_vendors();
    }
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
  get_vendors() {
    this.ui_controls.is_loading = true;
    this.networkService.post_request(this.initial, GlobalComponent.vendors_listing)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.stores = response.data;
            this.ui_controls.is_loading = false;
          }else {
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  get_vendor_product(storeId: number) {
    this.ui_controls.is_loading = true;
    this.product_handler.id = this.single_user.id;
    this.product_handler.token = this.single_user.token;
    this.product_handler.storeId = storeId;

    this.networkService.post_request(this.product_handler, GlobalComponent.vendors_products_listing)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.products = response.data;
            this.ui_controls.is_loading = false;
          }else {
            this.ui_controls.is_loading = false;
          }
        }
      }))
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
    this.networkService.post_request(this.create_style, GlobalComponent.create_style)
      .subscribe(({
        next: (response) => {
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
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.offset = this.initial.offset + this.initial.limit
    this.networkService.post_request(this.initial, GlobalComponent.vendors_listing)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.stores.push(...response.data);
          }else{
            this.ui_controls.is_empty = true;
          }
        }
      }))
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
  onIonInfinite(event: InfiniteScrollCustomEvent) {
    this.getMoreItems();
    setTimeout(() => {
      event.target.complete();
    }, 500);
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
