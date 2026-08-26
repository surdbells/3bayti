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
  IonBadge,
  IonButton,
  IonButtons, IonCol,
  IonContent,
  IonFab,
  IonFabButton, IonGrid,
  IonHeader, IonIcon, IonInfiniteScroll, IonInfiniteScrollContent,
  IonRefresher,
  IonRefresherContent,
  IonRow,
  IonToolbar
} from '@ionic/angular/standalone';
import {Router, RouterLink} from "@angular/router";
import {ActionSheetController, InfiniteScrollCustomEvent, Platform, RefresherCustomEvent} from '@ionic/angular';
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import { apiErrorMessage } from '../../core/http/api-error';
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import { ConnectionService } from '../../service/connection.service';
import { I18nService } from '../../i18n.service';
import { WishlistService } from '../../core/services/wishlist.service';
import { AuthSessionService } from '../../core/services/auth-session.service';
import { CartCountService } from '../../core/services/cart-count.service';
import { PendingOrdersService } from '../../core/services/pending-orders.service';
import { ChatService } from '../../service/chat.service';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import {Products} from "../../class/products";
import {Labels} from "../../class/labels";
import {CartIconComponent} from "../../cart-icon.component";
import {BlockerService} from "../../blocker.service";
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxWishlistSheetComponent } from '../../shared/ax-mobile/wishlist-sheet';
import { AddPhoneBannerComponent } from '../../shared/add-phone-banner.component';
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
  // Vendor slug — the featured card opens the storefront by slug (legacy ids
  // discarded), so v3-native stores resolve too.
  store_slug?: string;
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
  imports: [IonContent, IonHeader, IonToolbar, CommonModule, FormsModule, IonButton, IonButtons, IonAvatar, IonBadge, IonFab, IonFabButton, IonRow, IonCol, IonGrid, IonIcon, IonRefresher, IonRefresherContent, CartIconComponent, TranslatePipe, IonInfiniteScroll, IonInfiniteScrollContent, AxIconComponent, AxLoaderComponent, AxWishlistSheetComponent, RouterLink, AppTabBarComponent, AddPhoneBannerComponent]
})

export class AccountPage implements OnInit, OnDestroy {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  best_sellers: Products[] = [];
  new_arrivals: Products[] = [];
  vendor_featured: Store[] = [];
  // GET /v3/vendors (the PAGINATED public store directory) honours
  // limit/offset, so the "Popular stores" section supports real infinite
  // scroll. hasMoreStores stays true while the last page came back full
  // (length === limit) and flips false once a short/empty page signals the
  // end of the directory.
  hasMoreStores = true;
  isOnline = true;
  categories: Labels[] = [];
  isWishOpen = false;
  /** True while POST /me/wishlist/labels is in flight (inline label create). */
  isCreatingLabel = false;
  isFilterOpen = false;
  @Input() rating: number = 4.5;
  @Input() ratingsCount: number | string = '100+';
  isActive = false;

  // Customer unread-message badge (GET /v3/chat/unread-count). Refreshed
  // every time the account page is shown plus a light interval poll while
  // the page is active, so the header Messages icon reflects new vendor
  // replies without opening a thread.
  unreadMessages = signal(0);
  private unreadPoll?: ReturnType<typeof setInterval>;

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
    private authSession: AuthSessionService,
    public cartCount: CartCountService,
    public pendingOrders: PendingOrdersService,
    private chatService: ChatService,
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

  // Gift-card advertorial promo — dismissible, persisted in Preferences.
  giftPromoExpanded = false;

  get totalGiftCardBalance(): string {
    const total = this.activeGiftCards.reduce((sum: number, c: any) => sum + Number(c.balance), 0);
    return total.toFixed(2);
  }

  loadGiftCardBalance() {
    this.networkAdapter.get_v3('GET /gift-cards/mine', { authToken: this.single_user.token }).subscribe({
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

  async toggleGiftPromo() {
    this.giftPromoExpanded = !this.giftPromoExpanded;
    await Preferences.set({ key: 'gift_promo_expanded', value: this.giftPromoExpanded ? '1' : '0' });
  }

  ngOnInit() {
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
    this.getObject();
  }

  async getObject() {
    const promoState = await Preferences.get({ key: 'gift_promo_expanded' });
    this.giftPromoExpanded = promoState.value === '1';
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      // Refresh the profile (avatar + vendor/store flags) now that the token
      // is loaded. ionViewDidEnter's refreshProfile races ahead of this async
      // Preferences read on first entry and early-returns on the empty token,
      // so doing it here is what makes the vendor FAB appear for an approved
      // store (it re-derives is_vendor from roles + is_store_approved).
      this.refreshProfile();
      // Load gift-card balance only after the token is available — the call
      // is authed (GET /gift-cards/mine with single_user.token).
      this.loadGiftCardBalance();
      this.get_best_sellers();
      this.get_new_arrivals();
      this.get_featured_products();
      this.load_cart();
    }
  }

  ionViewWillEnter() {
    // Refresh the unread-message badge each time the page is shown and start
    // a light poll so vendor replies surface without reopening the inbox.
    this.refreshUnreadMessages();
    this.unreadPoll = setInterval(() => this.refreshUnreadMessages(), 30000);
    // Keep the unpaid-orders reminder (banner + Settings tab dot) current.
    void this.pendingOrders.refresh();
  }

  /** Reminder subtitle — singular vs plural, with the AED total. */
  get pendingBannerBody(): string {
    const c = this.pendingOrders.count();
    const amount = Math.round(this.pendingOrders.amount());
    return c === 1
      ? this.i18n.t('home_pending_payment_body_one', { amount })
      : this.i18n.t('home_pending_payment_body_many', { count: c, amount });
  }

  /** Open the My orders list filtered to pending payment. */
  openPendingOrders(): void {
    this.router.navigate(['/', 'my-orders'], { queryParams: { status: 'pending_payment' } });
  }

  ionViewWillLeave() {
    // Stop polling while the page is not visible.
    if (this.unreadPoll) {
      clearInterval(this.unreadPoll);
      this.unreadPoll = undefined;
    }
  }

  /** Pull the customer's unread chat count (GET /v3/chat/unread-count). */
  refreshUnreadMessages() {
    this.chatService.getUnreadCount('customer').subscribe((n) => {
      this.unreadMessages.set(n);
    });
  }

  /**
   * Pull-to-refresh: re-pull everything the dashboard shows (profile + vendor
   * flags, gift-card balance, the three product rails, the cart badge and the
   * unread-message count). Each loader drives its own skeleton state, so we
   * just fire them all and dismiss the refresher spinner after a short settle
   * window (the catalog GETs are quick and the rails cover the rest with their
   * own loading state).
   */
  async doRefresh(event: RefresherCustomEvent) {
    this.refreshProfile();
    this.loadGiftCardBalance();
    this.get_best_sellers();
    this.get_new_arrivals();
    this.get_featured_products();
    this.load_cart();
    void this.cartCount.refresh();
    void this.pendingOrders.refresh();
    this.refreshUnreadMessages();
    setTimeout(() => event.target.complete(), 700);
  }

  ionViewDidEnter(){
    this.load_cart();
    // Reactive cart badge: recompute from the authoritative store
    // (guest local cart or authed GET /cart) every time the account
    // page is shown, so the header badge reflects add/remove made
    // elsewhere in the session.
    void this.cartCount.refresh();
    // Refresh the avatar from the server so sessions that pre-date the
    // login-time avatar persistence (cached 'user' blob without avatar)
    // still show it.
    this.refreshProfile();
    }

  /**
   * Pull the freshest profile from GET /v3/me/profile and sync the avatar AND
   * the vendor/store flags into single_user + the cached 'user' Preferences
   * blob. The v3 profile (UserSerializer::publicProfile) emits:
   *   - avatar_url        -> single_user.avatar     (template binds `avatar`)
   *   - roles[] inc 'vendor' -> single_user.is_vendor
   *   - is_store_active   -> single_user.is_store_active
   *   - is_store_approved -> single_user.is_store_approved
   *
   * Why sync the flags here: the header "Store" button is gated on these
   * flags, which previously came ONLY from the login transform. Existing
   * sessions (or logins that omitted them) leave the flags stale/missing so
   * the button stays hidden even for an active store. Re-reading the profile
   * on every page entry re-derives them and persists back, so the @if gate
   * re-evaluates and the button appears. Silent on failure (the placeholder
   * avatar applies and flags keep their cached value).
   */
  refreshProfile() {
    if (!this.single_user.token) return;
    this.networkAdapter.get_v3('GET /me/profile', { authToken: this.single_user.token })
      .subscribe({
        next: (response: any) => {
          if (response.response_code !== 200) return;
          const user = response?.data?.user;
          if (!user) return;

          let changed = false;

          if (typeof user.avatar_url === 'string' && user.avatar_url !== this.single_user.avatar) {
            this.single_user.avatar = user.avatar_url;
            changed = true;
          }

          // Vendor/store flags: re-derive from the profile so the header
          // "Store" button reflects the current store state.
          const isVendor = Array.isArray(user.roles) && user.roles.includes('vendor');
          if (isVendor !== this.single_user.is_vendor) {
            this.single_user.is_vendor = isVendor;
            changed = true;
          }
          if (typeof user.is_store_active === 'boolean'
              && user.is_store_active !== this.single_user.is_store_active) {
            this.single_user.is_store_active = user.is_store_active;
            changed = true;
          }
          if (typeof user.is_store_approved === 'boolean'
              && user.is_store_approved !== this.single_user.is_store_approved) {
            this.single_user.is_store_approved = user.is_store_approved;
            changed = true;
          }

          if (changed) {
            Preferences.set({ key: 'user', value: JSON.stringify(this.single_user) });
          }
        },
        error: () => { /* silent — fallback avatar applies, flags keep cached value */ },
      });
  }

  ngOnDestroy(): void {
    this.blocker.unblock();
    this.sub?.unsubscribe();
    if (this.unreadPoll) {
      clearInterval(this.unreadPoll);
      this.unreadPoll = undefined;
    }
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
          handler: async () => {
            // Full session teardown: revoke the server RefreshToken,
            // deactivate the device push token, clear local session +
            // guest cart, and reset the nav stack to /home.
            await this.authSession.logout();
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
    this.get_featured.offset = 0;
    this.hasMoreStores = true;
    // Paginated PUBLIC store directory (GET /v3/vendors via 'GET /mobile/stores').
    // Anonymous catalog read (no authToken). limit/offset go straight through as
    // query params; the directory honours offset. transformFeaturedVendorsResponse
    // reshapes the directoryShape into the Store card (store_id/store_name/rating
    // + products[{product_id,image,price}]), so the binding is unchanged.
    this.networkAdapter.get_v3('GET /mobile/stores', {
      queryParams: { limit: this.get_featured.limit, offset: this.get_featured.offset },
    })
      .subscribe(({
        next: (response: any) => {
          const page: Store[] = response.data ?? [];
          this.vendor_featured = page;
          this.meta = response.message;
          this.ui_controls.is_loading = false;
          // A full page means there may be more; a short/empty page is the
          // end of the directory.
          this.hasMoreStores = page.length === this.get_featured.limit;
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
      .catch((err) => {
        this.ui_controls.is_loading_category = false;
        this.error_notification(apiErrorMessage(err, this.i18n.t('text_something_went_wrong')));
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
      .catch((err) => {
        this.ui_controls.is_loading_category = false;
        this.error_notification(apiErrorMessage(err, this.i18n.t('text_something_went_wrong')));
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
    } catch (err) {
      this.error_notification(apiErrorMessage(err, this.i18n.t('network_error_retry')));
    } finally {
      this.isCreatingLabel = false;
    }
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

  open_vendor(slug: string | undefined, name: string) {
    if (!slug) { return; }
    this.router.navigate(
      ['/', 'vendors'],
      { queryParams: { slug, name } }
    );
  }

  open_category(id: number, name: string) {
    this.router.navigate(
      ['/', 'category'],
      { queryParams: { id, name } }
    );
  }

  /** Open the discounted (on-sale) listing page. */
  open_discounted() {
    this.router.navigate(['/', 'discounted']);
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
    // Guard against a fetch past the end of the directory.
    if (!this.hasMoreStores) return;
    this.get_featured.id = this.single_user.id;
    this.get_featured.token = this.single_user.token;
    this.get_featured.offset = this.get_featured.offset + this.get_featured.limit
    // Paginated PUBLIC store directory (GET /v3/vendors) — same read as
    // get_featured_products(), advancing offset for infinite scroll. Anonymous
    // catalog read (no authToken). Response shape unchanged (transform applies).
    this.networkAdapter.get_v3('GET /mobile/stores', {
      queryParams: { limit: this.get_featured.limit, offset: this.get_featured.offset },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            const page: Store[] = response.data ?? [];
            // Dedupe by slug so a store can't appear twice across pages. (Was
            // store_id, but that's 0 for every v3-native store, so all newly
            // onboarded stores collapsed to one after the first page.)
            const existingIds = new Set(this.vendor_featured.map(s => s.store_slug));
            const deduped = page.filter(
              (s: Store) => s && s.store_slug && !existingIds.has(s.store_slug)
            );
            this.vendor_featured.push(...deduped);
            // Stop once a page comes back short (end of directory).
            this.hasMoreStores = page.length === this.get_featured.limit;
          }else{
            this.ui_controls.is_empty = true;
            this.error_notification(response.message);
            this.hasMoreStores = false;
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
