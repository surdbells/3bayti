import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonContent,
  IonHeader,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import {Preferences} from "@capacitor/preferences";
import {ConnectionService} from "../../service/connection.service";
import {ActivatedRoute, Router} from "@angular/router";
import {ActionSheetController} from "@ionic/angular";
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {HotlinkService} from "../../service/hotlink.service";
import { environment } from "../../../environments/environment";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Labels} from "../../class/labels";
import {Products} from "../../class/products";
import {TranslatePipe} from "../../translate.pipe";
import {I18nService} from "../../i18n.service";
import {HScrollProgressComponent} from "../../h-scroll-progress/h-scroll-progress.component";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import {
  AxSkeletonBlockComponent,
  AxSkeletonCardComponent,
  AxSkeletonLineComponent,
} from '../../shared/ax-mobile/skeleton';
import { cfImage } from '../../shared/cf-image';
import { storeFollowMessage } from '../../shared/store-follow-messages';

/** Default cover shown when a store has none (or its cover fails to load). */
const DEFAULT_VENDOR_COVER = 'assets/images/vendor-cover-default.png';
@Component({
  selector: 'app-vendors',
  templateUrl: './vendors.page.html',
  styleUrls: ['./vendors.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    CommonModule,
    FormsModule,
    TranslatePipe,
    HScrollProgressComponent,
    AxIconComponent,
    AxSkeletonBlockComponent,
    AxSkeletonCardComponent,
    AxSkeletonLineComponent,
    AppTabBarComponent
  ]
})
export class VendorsPage implements OnInit {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
  /** Skeleton placeholders (counts / widths only, geometry comes from the
      real wrappers). Chip widths vary so the row reads as labels. */
  readonly chipSkeletonWidths = ['52px', '88px', '74px', '96px', '66px'];
  readonly railSkeleton = [1, 2, 3];
  readonly gridSkeleton = [1, 2, 3, 4, 5, 6];
  /** Cover image decode state: fades the photo in over the placeholder, and
      falls back to the default cover when the store's URL is broken. */
  coverLoaded = false;
  coverFailed = false;
  latest: Products[] = [];
  products: Products[] = [];
  categories: Labels[] = [];
  /** Currently selected filter chip. null === the "All" chip (default),
      which shows the full vendor catalog. A numeric value is a label id. */
  selectedLabelId: number | null = null;
  /** Per-product image-loaded tracking for the m6d card skeleton overlay.
      Keys are prefixed ('latest-' or 'grid-') to distinguish products
      shared across the latest strip and the filtered product grid. */
  imageLoaded: { [key: string]: boolean } = {};
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private route: ActivatedRoute,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private hotlinks: HotlinkService,
    private toast: AxNotificationService,
    private i18n: I18nService,
  ) {}
  /** Each region has its OWN loading flag so it swaps skeleton -> content
      independently (a chip tap reloads only the grid; the header, chips and
      Latest rail stay put). All start `true`, so the very first paint is the
      skeleton rather than a flash of the empty state. */
  ui_controls = {
    best_seller_empty: false,
    is_empty: false,
    vendor_loading: true,
    latest_loading: true,
    labels_loading: true,
    products_loading: true,
    /** A follow / unfollow write is in flight (blocks double taps). */
    follow_busy: false,
    is_creating: false,
    is_deleting: false
  }
  /** Slug whose storefront is currently rendered. Re-entering the SAME store
      refreshes in place (no skeleton flash); a different store resets to the
      skeleton. null until the first load. */
  private loadedSlug: string | null = null;
  /** Id of the latest product-grid request. Responses from an earlier chip
      tap that land late are dropped, so the grid never shows the wrong
      collection. */
  private productsRequestSeq = 0;
  rqst_param = {
    id: 0,
    token: "",
    // Active label filter. 0 === "no label filter" (the "All" view); a
    // positive value is the tapped chip's label id. Previously hard-coded
    // to 4, which made ONLY label-4 products ever load, the empty-labels
    // client bug this redesign fixes.
    label: 0,
    store_id: 0,
    // Vendor slug, the storefront is resolved by slug now (legacy store_id is
    // 0 for v3-native stores, which used to open a blank storefront).
    store_slug: "",
    store_name: ""
  }
  read_vendor = {
    id: 0,
    token: "",
    store_id: 0,
    store_slug: ""
  }
  view_vendor = {
    // v3 numeric vendor id, populated by transformVendorResponse from the
    // by-legacy-id read. This is the id the Follow/Unfollow routes expect
    // (NOT store_id, which is the legacy WP/CI id used only for the
    // by-legacy-id catalog shims). 0 until the vendor read returns.
    id: 0,
    name: "",
    // Empty until the read lands (the header shows its skeleton meanwhile);
    // an empty logo renders the store initial, an empty cover the default.
    logo: "",
    cover: "",
    description: "",
    tagline: "",
    following: false
  }
  follow_vendor = {
    id: 0,
    token: "",
    store_id: 0,
    store_name: ""
  }
  unfollow_vendor = {
    id: 0,
    token: "",
    store_id: 0,
    store_name: ""
  }
  isFollowing = false;

  toggleFollow() {
    if (this.ui_controls.follow_busy) {
      return;
    }
    if (this.view_vendor.following ){
      this.user_unfollow_vendor();
    }else{  this.user_follow_vendor(); }
  }

  /** Store name for toasts / header fallback: the vendor read, else the
      name the caller passed in the query string. */
  get storeDisplayName(): string {
    return (this.view_vendor.name || this.rqst_param.store_name || '').trim();
  }

  /** Cover src: the store's cover, else (missing or failed) the default. */
  get coverSrc(): string {
    return !this.coverFailed && this.view_vendor.cover ? this.view_vendor.cover : DEFAULT_VENDOR_COVER;
  }

  onCoverLoad(): void {
    this.coverLoaded = true;
  }

  onCoverError(): void {
    if (!this.coverFailed && this.view_vendor.cover) {
      // Retry once with the default cover; its own load event fades it in.
      this.coverFailed = true;
      return;
    }
    this.coverLoaded = true;
  }

goToReviews(slug: string, vendorId: number, name: string) {
    this.skipReloadOnEnter = true;
    // Reviews header reads by slug; the reviews list + create use the v3 id
    // (numeric-only route). Pass both.
    this.router.navigate(
      ['/', 'vendor-reviews'],
      { queryParams: { slug, vendor_id: vendorId, name } }
    );
  }

  /**
   * Share this store (P9). Prefers a tracked hotlink (signed-in) → falls back
   * to the plain storefront URL. Web Share API with a clipboard fallback, no
   * @capacitor/share dependency (OTA-safe).
   */
  async shareStore(): Promise<void> {
    const slug = this.rqst_param.store_slug;
    if (!slug) {
      return;
    }
    const name = this.storeDisplayName;
    let url = `${this.storefrontBase()}/stores/${slug}`;
    if (this.single_user.token) {
      const tracked = await this.hotlinks.createShortUrl('store', slug, this.single_user.token);
      if (tracked !== null) {
        url = tracked;
      }
    }
    try {
      if ((navigator as any).share) {
        await (navigator as any).share({ title: name || '3bayti', text: name, url });
      } else {
        await navigator.clipboard.writeText(url);
        this.success_notification(this.i18n.t('store_link_copied'));
      }
    } catch {
      // User dismissed the share sheet; no-op.
    }
  }

  /** Canonical PUBLIC storefront origin for a shareable link — always prod,
   *  never derived from the (possibly staging) API host. */
  private storefrontBase(): string {
    return environment.publicWebUrl;
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
  ngOnInit() {
    this.rqst_param.store_slug = this.route.snapshot.queryParamMap.get('slug') || '';
    this.rqst_param.store_name = this.route.snapshot.queryParamMap.get('name') || '';
  }
  /** Set when navigating forward to a child (product / reviews / orders) so
   *  back keeps the storefront's scroll + loaded products instead of reloading. */
  private skipReloadOnEnter = false;
  ionViewDidEnter(){
    if (this.skipReloadOnEnter) { this.skipReloadOnEnter = false; return; }
    const slug = this.route.snapshot.queryParamMap.get('slug') || '';
    // Same store already on screen → refresh in place; otherwise reset to the
    // skeleton so the previous store's content never shows under a new name.
    const silent = this.loadedSlug !== null && this.loadedSlug === slug;
    this.rqst_param.store_slug = slug;
    this.rqst_param.store_name = this.route.snapshot.queryParamMap.get('name') || '';
    if (!silent) {
      this.resetStorefront();
    }
    this.getObject(silent);
  }

  /** Clear the previous store's content and put every region back into its
      skeleton state (first load, or a different store). */
  private resetStorefront(): void {
    this.view_vendor = {
      id: 0,
      name: "",
      logo: "",
      cover: "",
      description: "",
      tagline: "",
      following: false
    };
    this.latest = [];
    this.products = [];
    this.categories = [];
    this.selectedLabelId = null;
    this.rqst_param.label = 0;
    // Invalidate any grid request still in flight for the previous store.
    this.productsRequestSeq++;
    this.coverLoaded = false;
    this.coverFailed = false;
    this.ui_controls.vendor_loading = true;
    this.ui_controls.latest_loading = true;
    this.ui_controls.labels_loading = true;
    this.ui_controls.products_loading = true;
  }

  /**
   * @param silent  true when re-entering the store that is already rendered:
   *                data refreshes in place without flipping regions back to
   *                their skeletons.
   */
  async getObject(silent = false) {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.rqst_param.id = this.single_user.id;
      this.rqst_param.token = this.single_user.token;

      this.read_vendor.id = this.single_user.id;
      this.read_vendor.token = this.single_user.token;
      this.read_vendor.store_slug = this.rqst_param.store_slug;

      this.follow_vendor.id = this.single_user.id;
      this.follow_vendor.token = this.single_user.token;
      this.follow_vendor.store_name = this.route.snapshot.queryParamMap.get('name') || '';

      this.unfollow_vendor.id = this.single_user.id;
      this.unfollow_vendor.token = this.single_user.token;
      this.unfollow_vendor.store_name = this.route.snapshot.queryParamMap.get('name') || '';
      this.loadedSlug = this.rqst_param.store_slug;
      // Header, Latest rail and labels (→ grid) load in PARALLEL, each
      // region leaving its skeleton as soon as its own data lands. (The rail
      // used to gate the labels + grid, so a failed rail left the page empty.)
      this.get_vendor();
      this.get_latest(silent);
      this.get_label(silent);
    }
  }
  get_latest(silent = false) {
    if (!silent) {
      this.ui_controls.latest_loading = true;
    }
    // Direct v3 (GET /v3/vendors/by-legacy-id/{id}/products). Public catalog
    // read, no authToken. transformStoreLatestRequest maps store_id into the
    // {id} path param (and drops the legacy id/token/label); the v3 endpoint
    // hardcodes sort=newest, so no query params are needed. The registered
    // response transform still applies via get_v3, so response.data keeps the
    // legacy Products[] shape.
    const slug = this.rqst_param.store_slug;
    this.networkAdapter.get_v3('GET /mobile/store-latest', {
      pathParams: { slug },
    })
      .subscribe(({
        next: (response: any) => {
          if (this.isStaleStore(slug)) { return; }
          if (response.response_code === 200 && response.status === "success") {
            this.latest = response.data;
            this.ui_controls.best_seller_empty = false;
          }else{
            this.ui_controls.best_seller_empty = true;
          }
          this.ui_controls.latest_loading = false;
        },
        // Network-level failure (no response): drop the skeleton; with no
        // items the rail simply doesn't render.
        error: () => {
          if (this.isStaleStore(slug)) { return; }
          this.ui_controls.latest_loading = false;
        }
      }))
  }

  /** True when a response belongs to a store the page has since left (the
   *  page instance was re-entered for a different slug mid-flight). */
  private isStaleStore(slug: string): boolean {
    return slug !== this.rqst_param.store_slug;
  }
  get_vendor() {
    // Direct v3 (GET /v3/vendors/{slug}), read AUTHENTICATED so the
    // OptionalAuth detailShape resolves is_following for this viewer
    // (transformVendorResponse maps it to view_vendor.following, driving the
    // Follow/Following pill). Response transform applies via get_v3, so
    // response.data keeps the legacy storefront-header shape.
    const slug = this.rqst_param.store_slug;
    this.networkAdapter.get_v3('GET /mobile/read-vendor', {
      pathParams: { slug },
      authToken: this.read_vendor.token,
    })
      .subscribe(({
        next: (response: any) => {
          if (this.isStaleStore(slug)) { return; }
          if (response.response_code === 200 && response.status === "success") {
            this.view_vendor = response.data;
          }
          // On failure the header keeps the query-string name + default cover.
          this.ui_controls.vendor_loading = false;
        },
        error: () => {
          if (this.isStaleStore(slug)) { return; }
          this.ui_controls.vendor_loading = false;
        }
      }))
  }
  user_follow_vendor() {
    // Direct v3 (POST /v3/me/following/{vendorId}). Authenticated write.
    // The {vendorId} path param MUST be the v3 numeric primary key, which
    // the controller resolves via em->find(Vendor::class). It is NOT the
    // legacy store_id the rest of this page is keyed off (that is the
    // WP/CI id used only by the by-legacy-id catalog shims). view_vendor.id
    // is the v3 id surfaced by transformVendorResponse from get_vendor().
    // Guard: if the vendor read hasn't resolved an id yet, bail rather than
    // sending a bad id that 404s as "vendor not found".
    if (!this.view_vendor.id) {
      this.error_notification(this.i18n.t('error_vendor_not_loaded'));
      return;
    }
    this.ui_controls.follow_busy = true;
    this.networkAdapter.post_v3('POST /following/:vendorId', {}, {
      authToken: this.follow_vendor.token,
      pathParams: { vendorId: String(this.view_vendor.id) },
    })
      .subscribe(({
        next: (response: any) => {
          this.ui_controls.follow_busy = false;
          if (response.response_code === 200 && response.status === "success") {
            // Local flip (avoids a re-fetch for one flag); the authenticated
            // get_vendor() read already seeds the correct initial state.
            this.view_vendor.following = true;
            // The v3 response carries no message (the envelope's is always
            // ''), so toasting response.message showed an empty green toast.
            this.success_notification(storeFollowMessage(this.i18n, 'followed', this.storeDisplayName));
          }else {
            this.error_notification(storeFollowMessage(this.i18n, 'follow_failed'));
          }
        },
        error: () => {
          this.ui_controls.follow_busy = false;
          this.error_notification(storeFollowMessage(this.i18n, 'follow_failed'));
        }
      }))
  }
  user_unfollow_vendor() {
    // Direct v3 (DELETE /v3/me/following/{vendorId}). Authenticated write.
    // The {vendorId} path param MUST be the v3 numeric primary key (same as
    // follow above), view_vendor.id, NOT the legacy store_id. DELETE
    // carries no body. 204 → still passes the response_code === 200 envelope
    // check via the adapter's wrap.
    if (!this.view_vendor.id) {
      this.error_notification(this.i18n.t('error_vendor_not_loaded'));
      return;
    }
    this.ui_controls.follow_busy = true;
    this.networkAdapter.delete_v3('DELETE /following/:vendorId', {
      authToken: this.unfollow_vendor.token,
      pathParams: { vendorId: String(this.view_vendor.id) },
    })
      .subscribe(({
        next: (response: any) => {
          this.ui_controls.follow_busy = false;
          if (response.response_code === 200 && response.status === "success") {
            // Local flip (see the follow handler above).
            this.view_vendor.following = false;
            this.success_notification(storeFollowMessage(this.i18n, 'unfollowed', this.storeDisplayName));
          }else {
            this.error_notification(storeFollowMessage(this.i18n, 'unfollow_failed'));
          }
        },
        error: () => {
          this.ui_controls.follow_busy = false;
          this.error_notification(storeFollowMessage(this.i18n, 'unfollow_failed'));
        }
      }))
  }
  /**
   * Handle a filter-chip tap.
   *
   * @param labelId  null === the "All" chip → full vendor catalog
   *                 (incl. products with no label). A positive number is a
   *                 label id → only that collection's products.
   * @param silent   refresh the grid in place (same-store re-enter) instead
   *                 of showing the grid skeleton.
   */
  selectLabel(labelId: number | null, silent = false) {
    this.selectedLabelId = labelId;
    if (labelId === null) {
      this.rqst_param.label = 0;
      this.get_all_products(silent);
    } else {
      this.rqst_param.label = labelId;
      this.get_product_by_label(silent);
    }
  }

  /** Start a product-grid request: bump the sequence (so a slower, earlier
   *  response is ignored) and, unless silent, show the grid skeleton. */
  private beginProductsRequest(silent: boolean): number {
    this.ui_controls.is_empty = false;
    if (!silent) {
      this.ui_controls.products_loading = true;
    }
    return ++this.productsRequestSeq;
  }

  /** Apply a product-grid reply (`null` = network failure) unless a newer
   *  request superseded it. A failed silent refresh keeps the current grid. */
  private finishProductsRequest(seq: number, silent: boolean, response: any): void {
    if (seq !== this.productsRequestSeq) {
      return;
    }
    if (response?.response_code === 200 && response.status === "success") {
      this.products = response.data;
    }else if (!silent) {
      this.products = [];
    }
    this.ui_controls.is_empty = this.products.length === 0;
    this.ui_controls.products_loading = false;
  }

  /**
   * "All" view, load the FULL vendor catalog (every label + label-null
   * products). Uses GET /mobile/vendors-products → /v3/vendors/by-legacy-id/
   * {id}/products, which takes the vendor by path param and applies NO label
   * filter (unlike products-by-labels, which needs a label_id). This replaces
   * the old hard-coded label=4 fetch that only ever surfaced one collection.
   */
  get_all_products(silent = false) {
    const seq = this.beginProductsRequest(silent);
    this.networkAdapter.get_v3('GET /mobile/vendors-products', {
      pathParams: { slug: this.rqst_param.store_slug },
    })
      .subscribe(({
        next: (response: any) => this.finishProductsRequest(seq, silent, response),
        error: () => this.finishProductsRequest(seq, silent, null)
      }))
  }

  get_product_by_label(silent = false) {
    const seq = this.beginProductsRequest(silent);
    // Direct v3 (GET /v3/products). Public catalog read, no authToken.
    // transformProductsByLabelsRequest maps the legacy label → label_id and
    // store_id → vendor_id query params, omitting each when it's 0 (the
    // "no filter" signal). The label is now the TAPPED chip's id (not the old
    // hard-coded 4), so each collection chip surfaces its own products.
    const labelQuery: Record<string, string | number | boolean> = {};
    // Filter by label SLUG, not the legacy label_id: /v3/products resolves
    // label_id via findActiveByLegacyId, which never matches a v3-native store's
    // labels (they have no legacy id). The slug resolver works for every label.
    const selectedLabel = this.categories.find((c) => c.id === this.rqst_param.label);
    if (selectedLabel?.slug) {
      labelQuery['label'] = selectedLabel.slug;
    }
    // Scope to this vendor by slug (GET /v3/products?vendor={slug}); legacy ids
    // are gone, so v3-native stores filter correctly too.
    if (this.rqst_param.store_slug !== '') {
      labelQuery['vendor'] = this.rqst_param.store_slug;
    }
    this.networkAdapter.get_v3('GET /mobile/products-by-labels', {
      queryParams: labelQuery,
    })
      .subscribe(({
        next: (response: any) => this.finishProductsRequest(seq, silent, response),
        error: () => this.finishProductsRequest(seq, silent, null)
      }))
  }
  get_label(silent = false) {
    if (!silent) {
      this.ui_controls.labels_loading = true;
    }
    // Direct v3 (GET /v3/vendors/by-legacy-id/{id}/labels). Public read, no
    // authToken. transformStoreLabelsRequest maps store_id into the {id} path
    // param (dropping the legacy id/token/label/store_name). Response transform
    // applies via get_v3, so response.data keeps the legacy Labels[] shape.
    const slug = this.rqst_param.store_slug;
    this.networkAdapter.get_v3('GET /mobile/store-labels', {
      pathParams: { slug },
    })
      .subscribe(({
        next: (response: any) => {
          if (this.isStaleStore(slug)) { return; }
          if (response.response_code === 200 && response.status === "success") {
            this.categories = response.data;
          }else{
            // No labels is NOT an error, the vendor may simply have no
            // collections. Still load the full catalog under "All".
            this.categories = [];
          }
          this.ui_controls.labels_loading = false;
          this.loadGridAfterLabels(silent);
        },
        error: () => {
          if (this.isStaleStore(slug)) { return; }
          if (!silent) {
            this.categories = [];
          }
          this.ui_controls.labels_loading = false;
          this.loadGridAfterLabels(silent);
        }
      }))
  }

  /** After the labels land: a fresh load defaults to the "All" chip (full
   *  catalog, replacing the old hard-coded label-4 fetch); a silent refresh
   *  keeps the shopper's chip if that collection still exists. */
  private loadGridAfterLabels(silent: boolean): void {
    const keep = silent
      && this.selectedLabelId !== null
      && this.categories.some((c) => c.id === this.selectedLabelId);
    this.selectLabel(keep ? this.selectedLabelId : null, silent);
  }
  orders() {
    this.skipReloadOnEnter = true;
    this.router.navigate(['/', 'my-orders']);
  }
  open_product(id: number, name: string) {
    this.skipReloadOnEnter = true;
    this.router.navigate(
      ['/', 'product'],
      { queryParams: { id, name } }
    );
  }
  onImageLoad(key: string): void {
    this.imageLoaded[key] = true;
  }
  onImageError(key: string): void {
    /* Hide skeleton even on error so the placeholder doesn't loop */
    this.imageLoaded[key] = true;
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
  triggerBack() {
    this.nav.back();
  }
  goBack() {
    this.nav.back();
  }
}
