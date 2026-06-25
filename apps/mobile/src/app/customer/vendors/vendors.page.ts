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
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Labels} from "../../class/labels";
import {Products} from "../../class/products";
import {TranslatePipe} from "../../translate.pipe";
import {I18nService} from "../../i18n.service";
import {HScrollProgressComponent} from "../../h-scroll-progress/h-scroll-progress.component";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { cfImage } from '../../shared/cf-image';
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
    AxLoaderComponent,
    AppTabBarComponent
  ]
})
export class VendorsPage implements OnInit {
  /** Expose cfImage for template usage. */
  readonly cfImage = cfImage;
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
    private toast: AxNotificationService,
    private i18n: I18nService,
  ) {}
  ui_controls = {
    best_seller_empty: false,
    is_empty: false,
    is_loading: false,
    is_creating: false,
    is_deleting: false
  }
  rqst_param = {
    id: 0,
    token: "",
    // Active label filter. 0 === "no label filter" (the "All" view); a
    // positive value is the tapped chip's label id. Previously hard-coded
    // to 4, which made ONLY label-4 products ever load — the empty-labels
    // client bug this redesign fixes.
    label: 0,
    store_id: 0,
    store_name: ""
  }
  read_vendor = {
    id: 0,
    token: "",
    store_id: 0
  }
  view_vendor = {
    // v3 numeric vendor id — populated by transformVendorResponse from the
    // by-legacy-id read. This is the id the Follow/Unfollow routes expect
    // (NOT store_id, which is the legacy WP/CI id used only for the
    // by-legacy-id catalog shims). 0 until the vendor read returns.
    id: 0,
    name: "",
    logo: "assets/images/placeholder.png",
    cover: "assets/images/placeholder.png",
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
    if (this.view_vendor.following ){
      this.user_unfollow_vendor();
    }else{  this.user_follow_vendor(); }
  }

goToReviews(id: number, name: string) {
    this.router.navigate(
      ['/', 'vendor-reviews'],
      { queryParams: { id, name } }
    );
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
    this.rqst_param.store_id = Number(this.route.snapshot.queryParamMap.get('id'));
    this.rqst_param.store_name = this.route.snapshot.queryParamMap.get('name') || '';
  }
  ionViewDidEnter(){
    this.rqst_param.store_id = Number(this.route.snapshot.queryParamMap.get('id'));
    this.getObject();
  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.rqst_param.id = this.single_user.id;
      this.rqst_param.token = this.single_user.token;

      this.read_vendor.id = this.single_user.id;
      this.read_vendor.token = this.single_user.token;
      this.read_vendor.store_id = Number(this.route.snapshot.queryParamMap.get('id'));

      this.follow_vendor.id = this.single_user.id;
      this.follow_vendor.token = this.single_user.token;
      this.follow_vendor.store_id = Number(this.route.snapshot.queryParamMap.get('id'));
      this.follow_vendor.store_name = this.route.snapshot.queryParamMap.get('name') || '';

      this.unfollow_vendor.id = this.single_user.id;
      this.unfollow_vendor.token = this.single_user.token;
      this.unfollow_vendor.store_id = Number(this.route.snapshot.queryParamMap.get('id'));
      this.unfollow_vendor.store_name = this.route.snapshot.queryParamMap.get('name') || '';
      this.get_latest();
      this.get_vendor();
    }
  }
  get_latest() {
    this.ui_controls.is_empty = false;
    this.ui_controls.is_loading = true;
    // Direct v3 (GET /v3/vendors/by-legacy-id/{id}/products). Public catalog
    // read — no authToken. transformStoreLatestRequest maps store_id into the
    // {id} path param (and drops the legacy id/token/label); the v3 endpoint
    // hardcodes sort=newest, so no query params are needed. The registered
    // response transform still applies via get_v3, so response.data keeps the
    // legacy Products[] shape.
    this.networkAdapter.get_v3('GET /mobile/store-latest', {
      pathParams: { id: String(this.rqst_param.store_id) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.latest = response.data;
            this.ui_controls.is_loading = false;
            this.get_label();
          }else{
            this.ui_controls.best_seller_empty = true;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  get_vendor() {
    // Direct v3 (GET /v3/vendors/by-legacy-id/{id}). Public read — no
    // authToken. transformReadVendorRequest maps store_id into the {id} path
    // param. Response transform applies via get_v3, so response.data keeps the
    // legacy storefront-header shape.
    this.networkAdapter.get_v3('GET /mobile/read-vendor', {
      pathParams: { id: String(this.rqst_param.store_id) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.view_vendor = response.data;
          }
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
    this.networkAdapter.post_v3('POST /following/:vendorId', {}, {
      authToken: this.follow_vendor.token,
      pathParams: { vendorId: String(this.view_vendor.id) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
            // Reflect the new state locally. We do NOT re-read via
            // get_vendor() because the v3 public vendor read is anonymous
            // and always reports following:false (transformVendorResponse),
            // which would immediately flip the pill back to "Follow".
            this.view_vendor.following = true;
          }else {
            this.error_notification(response.message);
          }
        }
      }))
  }
  user_unfollow_vendor() {
    // Direct v3 (DELETE /v3/me/following/{vendorId}). Authenticated write.
    // The {vendorId} path param MUST be the v3 numeric primary key (same as
    // follow above) — view_vendor.id, NOT the legacy store_id. DELETE
    // carries no body. 204 → still passes the response_code === 200 envelope
    // check via the adapter's wrap.
    if (!this.view_vendor.id) {
      this.error_notification(this.i18n.t('error_vendor_not_loaded'));
      return;
    }
    this.networkAdapter.delete_v3('DELETE /following/:vendorId', {
      authToken: this.unfollow_vendor.token,
      pathParams: { vendorId: String(this.view_vendor.id) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
            // Reflect locally instead of re-reading (anonymous vendor read
            // always reports following:false — see follow handler above).
            this.view_vendor.following = false;
          }else {
            this.error_notification(response.message);
          }
        }
      }))
  }
  /**
   * Handle a filter-chip tap.
   *
   * @param labelId  null === the "All" chip → full vendor catalog
   *                 (incl. products with no label). A positive number is a
   *                 label id → only that collection's products.
   */
  selectLabel(labelId: number | null) {
    this.selectedLabelId = labelId;
    if (labelId === null) {
      this.rqst_param.label = 0;
      this.get_all_products();
    } else {
      this.rqst_param.label = labelId;
      this.get_product_by_label();
    }
  }

  /**
   * "All" view — load the FULL vendor catalog (every label + label-null
   * products). Uses GET /mobile/vendors-products → /v3/vendors/by-legacy-id/
   * {id}/products, which takes the vendor by path param and applies NO label
   * filter (unlike products-by-labels, which needs a label_id). This replaces
   * the old hard-coded label=4 fetch that only ever surfaced one collection.
   */
  get_all_products() {
    this.ui_controls.is_empty = false;
    this.ui_controls.is_loading = true;
    this.networkAdapter.get_v3('GET /mobile/vendors-products', {
      pathParams: { id: String(this.rqst_param.store_id) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.products = response.data;
            this.ui_controls.is_empty = this.products.length === 0;
            this.ui_controls.is_loading = false;
          }else{
            this.products = [];
            this.ui_controls.is_empty = true;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }

  get_product_by_label() {
    this.ui_controls.is_empty = false;
    this.ui_controls.is_loading = true;
    // Direct v3 (GET /v3/products). Public catalog read — no authToken.
    // transformProductsByLabelsRequest maps the legacy label → label_id and
    // store_id → vendor_id query params, omitting each when it's 0 (the
    // "no filter" signal). The label is now the TAPPED chip's id (not the old
    // hard-coded 4), so each collection chip surfaces its own products.
    const labelQuery: Record<string, string | number | boolean> = {};
    if (this.rqst_param.label !== 0) {
      labelQuery['label_id'] = this.rqst_param.label;
    }
    if (this.rqst_param.store_id !== 0) {
      labelQuery['vendor_id'] = this.rqst_param.store_id;
    }
    this.networkAdapter.get_v3('GET /mobile/products-by-labels', {
      queryParams: labelQuery,
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.products = response.data;
            this.ui_controls.is_empty = this.products.length === 0;
            this.ui_controls.is_loading = false;
          }else{
            this.products = [];
            this.ui_controls.is_empty = true;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  get_label() {
    this.ui_controls.is_empty = false;
    this.ui_controls.is_loading = true;
    // Direct v3 (GET /v3/vendors/by-legacy-id/{id}/labels). Public read — no
    // authToken. transformStoreLabelsRequest maps store_id into the {id} path
    // param (dropping the legacy id/token/label/store_name). Response transform
    // applies via get_v3, so response.data keeps the legacy Labels[] shape.
    this.networkAdapter.get_v3('GET /mobile/store-labels', {
      pathParams: { id: String(this.rqst_param.store_id) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.categories = response.data;
            this.ui_controls.is_loading = false;
            // Default to the "All" chip → full vendor catalog. This replaces
            // the old get_product_by_label() call that fetched only label 4.
            this.selectLabel(null);
          }else{
            // No labels is NOT an error — the vendor may simply have no
            // collections. Still load the full catalog under "All".
            this.categories = [];
            this.ui_controls.is_loading = false;
            this.selectLabel(null);
          }
        }
      }))
  }
  orders() {
    this.router.navigate(['/', 'my-orders']);
  }
  open_product(id: number, name: string) {
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
