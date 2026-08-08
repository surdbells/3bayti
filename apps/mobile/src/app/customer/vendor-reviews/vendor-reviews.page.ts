import { Component, OnInit } from '@angular/core';

import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCard,
  IonContent,
  IonHeader,
  IonInfiniteScroll,
  IonInfiniteScrollContent,
  IonInput,
  IonItem,
  IonRefresher,
  IonRefresherContent,
  IonSelect,
  IonSelectOption,
  IonTextarea,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import {Products} from "../../class/products";
import {Labels} from "../../class/labels";
import {ConnectionService} from "../../service/connection.service";
import {ActivatedRoute, Router} from "@angular/router";
import {ActionSheetController, InfiniteScrollCustomEvent} from "@ionic/angular";
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
type Review = {
  id: number;
  author: string;
  authorInitial: string;
  relativeDate: string;
  title: string;
  text: string;
  rating: number; // 1..5
  helpfulCount: number;
  helpful: boolean;
  verified: boolean;
  createdAt: Date;
};

@Component({
  selector: 'app-vendor-reviews',
  templateUrl: './vendor-reviews.page.html',
  styleUrls: ['./vendor-reviews.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonCard,
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    IonInput,
    IonItem,
    IonRefresher,
    IonRefresherContent,
    IonSelect,
    IonSelectOption,
    IonTextarea,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AxBottomSheetComponent,
    AppTabBarComponent
  ]
})
export class VendorReviewsPage implements OnInit {
  reviews: Review[] = [];
  sortBy: 'recent' | 'ratingDesc' | 'ratingAsc' = 'recent';
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
    is_empty: false,
    is_loading: false,
    is_creating: false
  }
  rqst_param = {
    id: 0,
    token: "",
    label: 4,
    store_id: 0,
    store_name: ""
  }
  initial = {
    id: 0,
    store_id: 0,
    token: "",
    limit: 5,
    offset: 0
  }
  add_new_review = {
    id: 0,
    store_id: 0,
    token: "",
    star: 0,
    title: "",
    product_name: "",
    customer_name: "",
    customer_email: "",
    comment: ""
  }
  is_helpful = {
    id: 0,
    reviewId: 0,
    token: ""
  }
  view_vendor = {
    name: "",
    logo: "assets/images/placeholder.png",
    cover: "assets/images/placeholder.png",
    description: "",
    tagline: "",
    rating: 0,
    following: false
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
  isReviewOpen = false;

  applySort() {
    const arr = [...this.reviews];
    if (this.sortBy === 'recent') {
      arr.sort((a, b) => b.createdAt.getTime() - a.createdAt.getTime());
    } else if (this.sortBy === 'ratingDesc') {
      arr.sort((a, b) => b.rating - a.rating);
    } else {
      arr.sort((a, b) => a.rating - b.rating);
    }
    this.reviews = arr;
  }

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.rqst_param.id = this.single_user.id;
      this.rqst_param.token = this.single_user.token;
      this.get_reviews(true);
      this.get_vendor();
    }
  }
  ngOnInit() {
    this.add_new_review.store_id =  Number(this.route.snapshot.queryParamMap.get('id'));
    this.add_new_review.product_name =  this.route.snapshot.queryParamMap.get('name') || '';
    this.rqst_param.store_id = Number(this.route.snapshot.queryParamMap.get('id'));
    this.rqst_param.store_name = this.route.snapshot.queryParamMap.get('name') || '';
  }
  ionViewDidEnter(){
    this.rqst_param.store_id = Number(this.route.snapshot.queryParamMap.get('id'));
    this.getObject();
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

  onWriteReview() {
    this.isReviewOpen = true;
  }

  toggleHelpful(r: number) {
    this.set_isHelpful(r)
  }

  add_review() {
    this.add_new_review.id =  this.single_user.id;
    this.add_new_review.token =  this.single_user.token;
    this.add_new_review.customer_name = this.single_user.first_name + " " + this.single_user.last_name;
    this.add_new_review.customer_email = this.single_user.email;

    if (this.add_new_review.star == 0){
      this.error_notification("rating is required"); return;
    }
    if (this.add_new_review.title.length == 0){
      this.error_notification("title is required"); return;
    }
    if (this.add_new_review.product_name.length == 0){
      this.error_notification("product is required"); return;
    }
    if (this.add_new_review.comment.length == 0){
      this.error_notification("comment is required"); return;
    }
    this.ui_controls.is_creating = true;
    this.ui_controls.is_empty = true;
    // Direct v3 (POST /v3/vendors/:vendorId/reviews — CreateVendorReviewController,
    // authenticated). The legacy request transform (transformAddVendorReviewRequest)
    // moved store_id into the vendorId path param and reduced the body to
    // { star, title, comment }; replicated here. star comes from add_new_review.star.
    this.networkAdapter.post_v3('POST /vendors/:vendorId/reviews', {
      star: this.add_new_review.star,
      title: this.add_new_review.title,
      comment: this.add_new_review.comment,
    }, {
      authToken: this.single_user.token,
      pathParams: { vendorId: String(this.add_new_review.store_id) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
            this.ui_controls.is_creating = false;
            this.ui_controls.is_empty = false;
            this.isReviewOpen = false;
          }else {
            this.error_notification(response.message);
            this.ui_controls.is_creating = false;
            this.isReviewOpen = false;

          }
        }
      }))
  }
  get_vendor() {
    this.ui_controls.is_loading = true;
    // Direct v3 (GET /v3/vendors/by-legacy-id/:id) — public storefront header,
    // no authToken. The legacy request transform (transformReadVendorRequest)
    // moved store_id into the {id} path param; replicated here. The registered
    // response transform still applies via get_v3, so response.data keeps the
    // legacy view_vendor shape.
    this.networkAdapter.get_v3('GET /mobile/read-vendor', {
      pathParams: { id: String(this.rqst_param.store_id) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.view_vendor = response.data;
            this.ui_controls.is_loading = false;
          }else {
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  get_reviews(show_loading: boolean) {
    if(show_loading){ this.ui_controls.is_loading = true; }
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.store_id = Number(this.route.snapshot.queryParamMap.get('id'));
    // Direct v3 (GET /v3/vendors/:vendorId/reviews) — public list of a store's
    // approved reviews, no authToken. The legacy request transform
    // (transformVendorReviewsListRequest) moved store_id into the vendorId path
    // param; replicated here. The registered response transform still applies
    // via get_v3, so response.data keeps the legacy Review[] shape.
    this.networkAdapter.get_v3('GET /vendors/:vendorId/reviews', {
      pathParams: { vendorId: String(this.initial.store_id) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.reviews = response.data;
            this.ui_controls.is_loading = false;
          }else {
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  set_isHelpful(reviewId: number) {
    this.is_helpful.id =  this.single_user.id;
    this.is_helpful.token =  this.single_user.token;
    this.is_helpful.reviewId =  reviewId;
    // Direct v3 (POST /v3/reviews/:id/helpful — MarkReviewHelpfulController,
    // authenticated). The legacy request transform (transformMarkHelpfulRequest)
    // moved reviewId into the {id} path param and sends an empty body;
    // replicated here.
    this.networkAdapter.post_v3('POST /reviews/:id/helpful', {}, {
      authToken: this.single_user.token,
      pathParams: { id: String(this.is_helpful.reviewId) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.success_notification(response.message);
            this.get_reviews(false);
          }else {
            this.error_notification(response.message);
          }
        }
      }))
  }
  OnDidDismiss() {
    this.isReviewOpen = false;
  }

  handleRefresh(event: any) {
    setTimeout(() => {
      this.ui_controls.is_loading = true;
      this.get_reviews(true);
      event.target.complete();
    }, 200);
  }
  getMoreItems() {
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.store_id = Number(this.route.snapshot.queryParamMap.get('id'));
    this.initial.offset = this.initial.offset + this.initial.limit
    // Direct v3 (GET /v3/vendors/:vendorId/reviews) — same public endpoint as
    // get_reviews. The legacy request transform (transformVendorReviewsListRequest)
    // only maps store_id -> vendorId path param (it does NOT forward
    // limit/offset), so paging is replicated identically here.
    this.networkAdapter.get_v3('GET /vendors/:vendorId/reviews', {
      pathParams: { vendorId: String(this.initial.store_id) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.reviews.push(...response.data);
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
  triggerBack() {
    // Native pop — return to wherever we came from (vendor store, my-orders,
    // etc.). Force-navigating to '/vendors' here pushed a NEW page, which then
    // ping-ponged with the vendors page's native back and created a loop
    // (see [[mobile-back-nav-model]]).
    this.nav.back();
  }
}
