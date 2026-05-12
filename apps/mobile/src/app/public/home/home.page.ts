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
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {GlobalComponent} from "../../global-component";
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
    this.router.navigate(
      ['/', 'single'],
      { queryParams: { id } }
    );
  }

  get_best_sellers() {
    this.ui_controls.is_loading = true;
    this.rqst_param_products_by_category.category = 0;
    this.networkService.post_request(this.best_seller, GlobalComponent.best_sellers)
      .subscribe(({
        next: (response) => {
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
    this.networkService.post_request(this.best_seller, GlobalComponent.new_arrivals)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.new_arrivals = response.data;
            this.ui_controls.is_loading = false;
          }else{ this.ui_controls.is_loading = false; }
        }
      }))
  }
  get_featured_products() {
    this.ui_controls.is_loading = true;
    this.networkService.post_request(this.get_featured, GlobalComponent.featured)
      .subscribe(({
        next: (response) => {
          this.vendor_featured = response.data;
          this.meta = response.message;
          this.ui_controls.is_loading = false;
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
    this.get_featured.offset = this.get_featured.offset + this.get_featured.limit
    this.networkService.post_request(this.get_featured, GlobalComponent.featured)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.vendor_featured.push(...response.data);
          }else{
            this.ui_controls.is_empty = true;
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
    this.error_notification(this.i18n.t('text_signup_to_continue'));
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
