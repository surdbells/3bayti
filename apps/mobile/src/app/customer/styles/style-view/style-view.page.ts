import { Component, OnInit, OnDestroy, ChangeDetectorRef, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import { Router } from "@angular/router";
import { AxNotificationService } from '../../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
import { Subscription } from 'rxjs';
import {TranslatePipe} from "../../../translate.pipe";
import {Labels} from "../../../class/labels";
import {ConnectionService} from "../../../service/connection.service";
import {NetworkService} from "../../../service/network.service";
import {GlobalComponent} from "../../../global-component";

import { AxIconComponent } from '../../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../../shared/ax-mobile/loader';
import { AxBottomSheetComponent } from '../../../shared/ax-mobile/bottom-sheet';
export interface StyleProduct {
  product_id: number;
  product_name: string;
  price: number;
  image: string;
}

export interface Styles {
  id: number;
  total_price: number;
  category: string;
  style_name: string;
  products: StyleProduct[];
}

@Component({
  selector: 'app-style-view',
  templateUrl: './style-view.page.html',
  styleUrls: ['./style-view.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommonModule,
    FormsModule,
    DecimalPipe,
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButtons,
    IonButton,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AxBottomSheetComponent,
    AppTabBarComponent
  ]
})
export class StyleViewPage implements OnInit, OnDestroy {
  isOnline = true;
  isWishOpen = false;
  categories: Labels[] = [];
  style!: Styles;

  // Image loading tracking
  imageLoaded: { [key: number]: boolean } = {};

  private sub: Subscription | null = null;

  addCloset = {
    id: 0,
    token: "",
    label_id: 0,
    product_id: 0,
    product_name: "",
    product_image: ""
  }

  rqst_param = {
    id: 0,
    token: ""
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

  ui_controls = {
    is_loading: false,
    is_empty: false,
    is_loading_category: false
  }

  constructor(
    private router: Router,
    private platform: Platform,
    private nav: NavController,
    private net: ConnectionService,
    private networkService: NetworkService,
    private toast: AxNotificationService,
    private cdr: ChangeDetectorRef
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  ngOnInit() {
    this.style = history.state?.style;
    if (!this.style) {
      this.router.navigate(['/styles']);
      return;
    }
    this.getObject();
  }

  ngOnDestroy() {
    this.sub?.unsubscribe();
  }

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
    } else {
      this.single_user = JSON.parse(ret.value);
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
    this.imageLoaded[productId] = true; // Hide skeleton on error
    this.cdr.markForCheck();
  }

  // ========================================
  // Calculations
  // ========================================

  getTotal(): number {
    if (!this.style?.products) return 0;
    return this.style.products.reduce((sum, item) => sum + item.price, 0);
  }

  // ========================================
  // Wishlist / Closet
  // ========================================

  get_label() {
    this.ui_controls.is_loading_category = true;
    this.cdr.markForCheck();

    this.networkService.post_request(this.rqst_param, GlobalComponent.readWishlistLabel)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.categories = response.data;
          }else {
            this.ui_controls.is_loading_category = false;
            this.cdr.markForCheck();
            this.error_notification(response.message);
          }
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
          }else {
            this.ui_controls.is_loading_category = false;
            this.cdr.markForCheck();
            this.error_notification(response.message);
          }

        },
        error: () => {
          this.ui_controls.is_loading_category = false;
          this.cdr.markForCheck();
        }
      });
  }

  startAddToCloset(productId: number, productName: string, image: string) {
    this.addCloset.id = this.single_user.id;
    this.addCloset.token = this.single_user.token;
    this.addCloset.product_id = productId;
    this.addCloset.product_name = productName;
    this.addCloset.product_image = image;
    this.rqst_param.id = this.single_user.id;
    this.rqst_param.token = this.single_user.token;
    this.get_label();
    this.isWishOpen = true;
  }

  OnDidDismiss() {
    this.isWishOpen = false;
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
