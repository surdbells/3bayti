import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonButton,
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
    IonButton,
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
  /** Per-product image-loaded tracking for the m6d card skeleton overlay.
      Keys are prefixed ('latest-' or 'cat-') to distinguish products
      shared across the latest and per-category sections. */
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
    label: 4,
    store_id: 0,
    store_name: ""
  }
  read_vendor = {
    id: 0,
    token: "",
    store_id: 0
  }
  view_vendor = {
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
    // transformFollowVendorRequest moves legacy body.store_id into the
    // {vendorId} path param and sends an empty body (the controller reads
    // the vendor from the path and the user from the token).
    this.networkAdapter.post_v3('POST /following/:vendorId', {}, {
      authToken: this.follow_vendor.token,
      pathParams: { vendorId: String(this.follow_vendor.store_id) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
            this.get_vendor();
          }else {
            this.error_notification(response.message);
          }
        }
      }))
  }
  user_unfollow_vendor() {
    // Direct v3 (DELETE /v3/me/following/{vendorId}). Authenticated write.
    // transformUnfollowVendorRequest moves legacy body.store_id into the
    // {vendorId} path param; DELETE carries no body. 204 → still passes the
    // response_code === 200 envelope check via the adapter's wrap.
    this.networkAdapter.delete_v3('DELETE /following/:vendorId', {
      authToken: this.unfollow_vendor.token,
      pathParams: { vendorId: String(this.unfollow_vendor.store_id) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
            this.get_vendor();
          }else {
            this.error_notification(response.message);
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
    // "no filter" signal). Replicate that exactly here.
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
            this.ui_controls.is_loading = false;
          }else{
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
            this.get_product_by_label();
          }else{
            this.ui_controls.is_empty = true;
            this.ui_controls.is_loading = false;
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
