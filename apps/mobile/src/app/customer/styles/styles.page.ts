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
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
import { GlobalComponent } from "../../global-component";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { cfImage } from '../../shared/cf-image';
export interface StyleProduct {
  product_id: number;
  product_name: string;
  price: number;
  image: string;
}

export interface Styles {
  id: number;
  slug: string;
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
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
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
    private networkAdapter: MobileNetworkAdapter,
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
  /** Set when navigating forward to a child (style detail) so back skips the reload. */
  private skipReloadOnEnter = false;
  ionViewDidEnter(){
    if (this.skipReloadOnEnter) { this.skipReloadOnEnter = false; return; }
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
      this.get_styles(this.activeTab);
    }
  }

  // ========================================
  // Tab -> backend mapping
  // ========================================

  /**
   * Resolve the route-key + request opts for a given tab. The three tabs
   * map to two backends:
   *   community -> GET /v3/styles ?type=community  (anonymous)
   *   abayti    -> GET /v3/styles ?type=editorial  (anonymous; "3bayti")
   *   personal  -> GET /v3/me/styles               (authed, no ?type)
   *
   * The personal call MUST pass authToken (owner-scoped); the others must
   * NOT. The user is guaranteed logged in here — getObject() redirects to
   * /login when there's no user blob.
   */
  private buildRequest(tab: TabType, offset: number): {
    routeKey: string;
    opts: {
      authToken?: string;
      queryParams: { [key: string]: string | number };
    };
  } {
    const paging = { limit: this.initial.limit, offset };

    if (tab === 'personal') {
      return {
        routeKey: 'GET /mobile/my-styles',
        opts: {
          authToken: this.single_user.token,
          queryParams: { ...paging },
        },
      };
    }

    const type = tab === 'abayti' ? 'editorial' : 'community';
    return {
      routeKey: 'GET /mobile/styles-list',
      opts: {
        queryParams: { type, ...paging },
      },
    };
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

  get_styles(tab: TabType) {
    this.activeTab = tab;
    this.styles = [];
    this.initial.type = tab;
    this.initial.offset = 0;
    this.ui_controls.is_loading = true;
    this.ui_controls.is_empty = false;
    this.cdr.markForCheck();

    // Resolve the route-key + opts for the active tab. Community/3bayti hit
    // the anonymous /v3/styles (type=community/editorial, no authToken); My
    // Styles hits owner-scoped /v3/me/styles WITH authToken and no ?type.
    // The registered response transform (transformStylesListResponse) is
    // wired for BOTH route-keys, so response.data keeps the legacy
    // Styles[] shape either way.
    const { routeKey, opts } = this.buildRequest(tab, this.initial.offset);

    this.networkAdapter.get_v3(routeKey, opts)
      .subscribe({
        next: (response: any) => {
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

    // Paginated read for infinite scroll, advancing offset against the
    // SAME endpoint as the active tab (My Styles paginates /v3/me/styles
    // with authToken; the others paginate /v3/styles anonymously). Shape
    // unchanged — the response transform still applies via get_v3.
    const { routeKey, opts } = this.buildRequest(this.activeTab, this.initial.offset);

    this.networkAdapter.get_v3(routeKey, opts)
      .subscribe({
        next: (response: any) => {
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
    this.skipReloadOnEnter = true;
    // Navigate by slug (deep-link-safe URL) AND pass the style in router
    // state (fast path — avoids a re-fetch when coming from the list). On a
    // hard reload the state is wiped, so style-view re-fetches by the slug.
    this.router.navigate(['/style-view', style.slug], {
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
