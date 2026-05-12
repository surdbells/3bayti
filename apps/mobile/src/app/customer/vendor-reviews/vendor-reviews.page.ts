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
    this.networkService.post_request(this.add_new_review, GlobalComponent.add_review)
      .subscribe(({
        next: (response) => {
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
    this.networkService.post_request(this.rqst_param, GlobalComponent.read_vendor)
      .subscribe(({
        next: (response) => {
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
    this.networkService.post_request(this.initial, GlobalComponent.store_reviews)
      .subscribe(({
        next: (response) => {
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
    this.networkService.post_request(this.is_helpful, GlobalComponent.make_helpful)
      .subscribe(({
        next: (response) => {
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
    this.networkService.post_request(this.initial, GlobalComponent.store_reviews)
      .subscribe(({
        next: (response) => {
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
  triggerBack(id: number, name: string) {
    this.router.navigate(
      ['/', 'vendors'],
      { queryParams: { id, name } }
    );
  }
}
