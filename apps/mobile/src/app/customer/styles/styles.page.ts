import { Component, OnInit, OnDestroy, ChangeDetectorRef, ChangeDetectionStrategy } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { InfiniteScrollCustomEvent, Platform } from "@ionic/angular";
import { TranslatePipe } from "../../translate.pipe";
import {
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonInfiniteScroll,
  IonInfiniteScrollContent,
  IonRefresher,
  IonRefresherContent,
  IonTitle,
  IonToolbar,
  NavController
} from "@ionic/angular/standalone";
import { Router } from "@angular/router";
import { Subscription } from "rxjs";
import { ConnectionService } from "../../service/connection.service";
import { NetworkService } from "../../service/network.service";
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
import { GlobalComponent } from "../../global-component";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
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

type TabType = 'community' | 'abayti' | 'personal';

@Component({
  selector: 'app-styles',
  templateUrl: './styles.page.html',
  styleUrls: ['./styles.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    IonHeader,
    IonToolbar,
    IonButtons,
    IonTitle,
    IonButton,
    IonContent,
    IonRefresher,
    IonRefresherContent,
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    TranslatePipe,
    AxIconComponent,
    AppTabBarComponent
  ]
})
export class StylesPage implements OnInit, OnDestroy {
  activeTab: TabType = 'community';
  styles: Styles[] = [];

  // Image loading tracking: "styleId-imageIndex" -> boolean
  imageLoaded: { [key: string]: boolean } = {};

  isOnline = true;
  private sub: Subscription;

  constructor(
    private router: Router,
    private nav: NavController,
    private platform: Platform,
    private net: ConnectionService,
    private networkService: NetworkService,
    private toast: AxNotificationService,
    private cdr: ChangeDetectorRef
  ) {
    this.platform.backButton.subscribeWithPriority(10, () => {
    });
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  ngOnInit() {
    this.getObject();
  }

  ngOnDestroy() {
    this.sub?.unsubscribe();
  }
  ionViewDidEnter(){
    this.getObject();
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

  initial = {
    id: 0,
    token: "",
    type: "community",
    limit: 10,
    offset: 0
  }

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
    } else {
      this.single_user = JSON.parse(ret.value);
      this.initial.id = this.single_user.id;
      this.initial.token = this.single_user.token;
      this.get_styles('community');
    }
  }

  // ========================================
  // Image Loading Handlers
  // ========================================

  onImageLoad(styleId: number, imageIndex: number) {
    const key = `${styleId}-${imageIndex}`;
    this.imageLoaded[key] = true;
    this.cdr.markForCheck();
  }

  onImageError(styleId: number, imageIndex: number) {
    const key = `${styleId}-${imageIndex}`;
    this.imageLoaded[key] = true; // Hide skeleton on error
    this.cdr.markForCheck();
  }

  private resetImageStates() {
    this.imageLoaded = {};
  }

  // ========================================
  // Tab Switching
  // ========================================

  switchTab(tab: TabType) {
    if (this.activeTab === tab) return;

    this.activeTab = tab;
    this.resetImageStates();
    this.get_styles(tab);
  }

  // ========================================
  // API Calls
  // ========================================

  get_styles(type: string) {
    this.styles = [];
    this.initial.type = type;
    this.initial.offset = 0;
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.cdr.markForCheck();

    this.networkService.post_request(this.initial, GlobalComponent.styles_list)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.styles = response.data;
            this.ui_controls.is_empty = this.styles.length === 0;
          } else {
            this.styles = [];
            this.ui_controls.is_empty = true;
          }
          this.ui_controls.is_loading = false;
          this.cdr.markForCheck();
        },
        error: () => {
          this.ui_controls.is_loading = false;
          this.ui_controls.is_empty = true;
          this.cdr.markForCheck();
        }
      });
  }

  getMoreItems() {
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.offset = this.initial.offset + this.initial.limit;

    this.networkService.post_request(this.initial, GlobalComponent.styles_list)
      .subscribe({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.styles.push(...response.data);
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
    this.resetImageStates();
    this.get_styles(this.activeTab);
    setTimeout(() => {
      event.target.complete();
    }, 500);
  }

  // ========================================
  // Navigation
  // ========================================

  open_style(style: Styles) {
    this.router.navigate(['/style-view'], {
      state: { style }
    });
  }

  triggerBack() {
    this.nav.back();
  }

  createStyle() {
    this.router.navigate(['/', 'create']);
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
