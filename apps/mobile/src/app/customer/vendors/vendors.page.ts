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
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {GlobalComponent} from "../../global-component";
import {Labels} from "../../class/labels";
import {Products} from "../../class/products";
import {TranslatePipe} from "../../translate.pipe";
import {HScrollProgressComponent} from "../../h-scroll-progress/h-scroll-progress.component";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
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
    this.networkService.post_request(this.rqst_param, GlobalComponent.store_latest)
      .subscribe(({
        next: (response) => {
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
    this.networkService.post_request(this.rqst_param, GlobalComponent.read_vendor)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.view_vendor = response.data;
          }
        }
      }))
  }
  user_follow_vendor() {
    this.networkService.post_request(this.rqst_param, GlobalComponent.follow_vendor)
      .subscribe(({
        next: (response) => {
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
    this.networkService.post_request(this.rqst_param, GlobalComponent.unfollow_vendor)
      .subscribe(({
        next: (response) => {
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
    this.networkService.post_request(this.rqst_param, GlobalComponent.products_by_labels)
      .subscribe(({
        next: (response) => {
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
    this.networkService.post_request(this.rqst_param, GlobalComponent.store_labels)
      .subscribe(({
        next: (response) => {
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
    this.router.navigate(['/', 'orders']);
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
