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
import { AxSkeletonBlockComponent, AxSkeletonCircleComponent, AxSkeletonLineComponent } from '../../shared/ax-mobile/skeleton';
import { I18nService } from '../../i18n.service';
import { formatRelativeDate } from '../../core/utils/api-date';
/** A review card, as shaped by transformReviewDisplayResponse. */
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
  /** Epoch ms (0 when unknown), the sort key. Parsed engine-independently. */
  createdAtMs: number;
  created_at?: string;
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
    AxSkeletonBlockComponent,
    AxSkeletonCircleComponent,
    AxSkeletonLineComponent,
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
    private i18n: I18nService,
  ) {}
  ui_controls = {
    // Reviews list request in flight (drives the card skeletons). Starts
    // true: the page loads on every entry, so the enter transition shows
    // skeletons instead of a flash of "No reviews".
    is_loading: true,
    // Store header request in flight (drives the summary-card skeleton).
    // Separate from is_loading so whichever request lands first can't
    // flash "No reviews" before the list arrives.
    is_loading_vendor: true,
    // The list request failed; shows a retry state instead of a blank list.
    is_error: false,
    is_loading_more: false,
    is_creating: false
  }
  /** Total approved reviews (v3 meta.total), not just the loaded page. */
  reviewsTotal = 0;
  /** More pages exist on the server (drives ion-infinite-scroll). */
  hasMore = false;
  /** Skeleton placeholders shown while the list loads. */
  readonly skeletonRows = [0, 1, 2];
  rqst_param = {
    id: 0,
    token: "",
    label: 4,
    store_id: 0,
    // Vendor slug, the storefront header read is slug-keyed now. The reviews
    // list + create still use the v3 numeric vendor id (numeric-only route),
    // carried separately as `vendor_id` in the nav params.
    store_slug: "",
    store_name: ""
  }
  initial = {
    id: 0,
    store_id: 0,
    token: "",
    limit: 10,
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
      // createdAtMs is a plain number from parseApiDate (0 when unknown):
      // the old `createdAt.getTime()` read a field the transform never set
      // and threw as soon as "Most recent" was picked.
      arr.sort((a, b) => (b.createdAtMs || 0) - (a.createdAtMs || 0));
    } else if (this.sortBy === 'ratingDesc') {
      arr.sort((a, b) => b.rating - a.rating);
    } else {
      arr.sort((a, b) => a.rating - b.rating);
    }
    this.reviews = arr;
  }

  /**
   * Store rating for the summary card. The v3 storefront read carries no
   * rating, so fall back to the average of the loaded reviews.
   */
  get summaryRating(): number {
    const fromVendor = Number((this.view_vendor as { rating?: unknown }).rating);
    if (Number.isFinite(fromVendor) && fromVendor > 0) {
      return Math.round(fromVendor * 10) / 10;
    }
    const rated = this.reviews.filter(r => r.rating > 0);
    if (rated.length === 0) {
      return 0;
    }
    const avg = rated.reduce((sum, r) => sum + r.rating, 0) / rated.length;
    return Math.round(avg * 10) / 10;
  }

  /**
   * Coerce a list payload into Review cards: never a non-array, and the
   * relative date re-rendered in the app language (the transform has no
   * i18n context, so it emits English).
   */
  private toReviews(data: unknown): Review[] {
    if (!Array.isArray(data)) {
      return [];
    }
    return data
      .filter((r): r is Review => r !== null && typeof r === 'object')
      .map(r => ({
        ...r,
        helpful: r.helpful === true,
        relativeDate: formatRelativeDate(r.created_at, this.i18n.lang) || r.relativeDate || '',
      }));
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
    // `vendor_id` = v3 numeric id (reviews list + create); `slug` = storefront
    // header read. Legacy `id` is gone.
    this.add_new_review.store_id =  Number(this.route.snapshot.queryParamMap.get('vendor_id'));
    this.add_new_review.product_name =  this.route.snapshot.queryParamMap.get('name') || '';
    this.rqst_param.store_slug = this.route.snapshot.queryParamMap.get('slug') || '';
    this.rqst_param.store_name = this.route.snapshot.queryParamMap.get('name') || '';
  }
  // WillEnter (not DidEnter) so the requests start with the enter transition
  // rather than after it; the skeletons cover the wait.
  ionViewWillEnter(){
    this.rqst_param.store_slug = this.route.snapshot.queryParamMap.get('slug') || '';
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
      this.error_notification(this.i18n.t('review_select_rating')); return;
    }
    if (this.add_new_review.title.trim().length == 0){
      this.error_notification(this.i18n.t('review_title_required')); return;
    }
    // (No product-name check: that field is a read-only display of the
    // store name, it isn't sent, and an empty nav param made the form
    // impossible to submit.)
    if (this.add_new_review.comment.trim().length == 0){
      this.error_notification(this.i18n.t('review_comment_required')); return;
    }
    this.ui_controls.is_creating = true;
    // Direct v3 (POST /v3/vendors/:vendorId/reviews, CreateVendorReviewController,
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
          this.ui_controls.is_creating = false;
          if (response.response_code === 200 && response.status === "success") {
            // The create lands PENDING moderation and the v3 envelope carries
            // no message, so say so ourselves (an empty toast otherwise).
            this.success_notification(this.i18n.t('review_submitted_for_approval'));
            this.isReviewOpen = false;
            this.add_new_review.star = 0;
            this.add_new_review.title = '';
            this.add_new_review.comment = '';
          }else {
            // Keep the sheet open so the shopper doesn't lose what they typed.
            this.error_notification(response.message || this.i18n.t('review_submit_failed'));
          }
        },
        error: () => {
          this.ui_controls.is_creating = false;
          this.error_notification(this.i18n.t('review_submit_failed'));
        }
      }))
  }
  get_vendor() {
    this.ui_controls.is_loading_vendor = true;
    // Direct v3 (GET /v3/vendors/by-legacy-id/:id), public storefront header,
    // no authToken. The legacy request transform (transformReadVendorRequest)
    // moved store_id into the {id} path param; replicated here. The registered
    // response transform still applies via get_v3, so response.data keeps the
    // legacy view_vendor shape.
    this.networkAdapter.get_v3('GET /mobile/read-vendor', {
      pathParams: { slug: this.rqst_param.store_slug },
    })
      .subscribe(({
        next: (response: any) => {
          this.ui_controls.is_loading_vendor = false;
          if (response.response_code === 200 && response.status === "success" && response.data) {
            this.view_vendor = response.data;
          }
        },
        error: () => {
          this.ui_controls.is_loading_vendor = false;
        }
      }))
  }
  get_reviews(show_loading: boolean, done?: () => void) {
    if(show_loading){ this.ui_controls.is_loading = true; }
    this.ui_controls.is_error = false;
    this.initial.id = this.single_user.id;
    this.initial.token = this.single_user.token;
    this.initial.store_id = Number(this.route.snapshot.queryParamMap.get('vendor_id'));
    this.initial.offset = 0;
    // Direct v3 (GET /v3/vendors/:vendorId/reviews), public list of a store's
    // approved reviews, no authToken. The registered response transform still
    // applies via get_v3, so response.data keeps the Review[] card shape.
    // limit/offset are sent explicitly: without them the API always answers
    // page one, so every infinite-scroll trigger re-appended the same reviews.
    this.networkAdapter.get_v3('GET /vendors/:vendorId/reviews', {
      pathParams: { vendorId: String(this.initial.store_id) },
      queryParams: { limit: this.initial.limit, offset: 0 },
    })
      .subscribe(({
        next: (response: any) => {
          this.ui_controls.is_loading = false;
          if (response.response_code === 200 && response.status === "success") {
            this.reviews = this.toReviews(response.data);
            this.reviewsTotal = Math.max(Number(response.meta?.total) || 0, this.reviews.length);
            this.initial.offset = this.reviews.length;
            this.hasMore = this.reviews.length < this.reviewsTotal;
            if (this.sortBy !== 'recent') { this.applySort(); }
          } else if (response.response_code === 404) {
            // Unknown store: nothing to list, same as a store with no reviews.
            this.reviews = [];
            this.reviewsTotal = 0;
            this.hasMore = false;
          } else {
            this.ui_controls.is_error = true;
            this.hasMore = false;
          }
          done?.();
        },
        error: () => {
          // Transport error (offline, or a request iOS dropped while the app
          // was backgrounded): a retry state, never a stuck/blank list.
          this.ui_controls.is_loading = false;
          this.ui_controls.is_error = true;
          this.hasMore = false;
          done?.();
        }
      }))
  }
  set_isHelpful(reviewId: number) {
    const card = this.reviews.find(r => r.id === reviewId);
    if (card?.helpful) { return; }
    this.is_helpful.id =  this.single_user.id;
    this.is_helpful.token =  this.single_user.token;
    this.is_helpful.reviewId =  reviewId;
    // Direct v3 (POST /v3/reviews/:id/helpful, MarkReviewHelpfulController,
    // authenticated). The legacy request transform (transformMarkHelpfulRequest)
    // moved reviewId into the {id} path param and sends an empty body;
    // replicated here. It answers { id, helpful_count } (no message), so the
    // card updates in place instead of an empty toast + full list reload.
    this.networkAdapter.post_v3('POST /reviews/:id/helpful', {}, {
      authToken: this.single_user.token,
      pathParams: { id: String(this.is_helpful.reviewId) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            if (card) {
              const count = Number(response.data?.helpful_count);
              card.helpfulCount = Number.isFinite(count) ? count : card.helpfulCount + 1;
              card.helpful = true;
            }
          }else {
            this.error_notification(response.message || this.i18n.t('text_something_went_wrong'));
          }
        },
        error: () => {
          this.error_notification(this.i18n.t('text_offline_check_connection'));
        }
      }))
  }
  OnDidDismiss() {
    this.isReviewOpen = false;
  }

  handleRefresh(event: any) {
    // Reviews only (the store header rarely changes); the spinner closes when
    // the request settles instead of after a fixed delay.
    this.get_reviews(false, () => event.target.complete());
  }
  getMoreItems(done?: () => void) {
    if (!this.hasMore || this.ui_controls.is_loading_more) {
      done?.();
      return;
    }
    this.ui_controls.is_loading_more = true;
    this.initial.store_id = Number(this.route.snapshot.queryParamMap.get('vendor_id'));
    const offset = this.reviews.length;
    // Direct v3 (GET /v3/vendors/:vendorId/reviews), same public endpoint as
    // get_reviews, next page via explicit limit/offset.
    this.networkAdapter.get_v3('GET /vendors/:vendorId/reviews', {
      pathParams: { vendorId: String(this.initial.store_id) },
      queryParams: { limit: this.initial.limit, offset },
    })
      .subscribe(({
        next: (response: any) => {
          this.ui_controls.is_loading_more = false;
          if (response.response_code === 200 && response.status === "success") {
            const seen = new Set(this.reviews.map(r => r.id));
            const page = this.toReviews(response.data);
            this.reviews = [...this.reviews, ...page.filter(r => !seen.has(r.id))];
            this.reviewsTotal = Math.max(Number(response.meta?.total) || 0, this.reviews.length);
            this.initial.offset = this.reviews.length;
            // An empty page also ends paging (defensive against a stale total).
            this.hasMore = page.length > 0 && this.reviews.length < this.reviewsTotal;
            if (this.sortBy !== 'recent') { this.applySort(); }
          }else{
            this.hasMore = false;
          }
          done?.();
        },
        error: () => {
          this.ui_controls.is_loading_more = false;
          done?.();
        }
      }))
  }

  onIonInfinite(event: InfiniteScrollCustomEvent) {
    this.getMoreItems(() => event.target.complete());
  }
  triggerBack() {
    // Native pop, return to wherever we came from (vendor store, my-orders,
    // etc.). Force-navigating to '/vendors' here pushed a NEW page, which then
    // ping-ponged with the vendors page's native back and created a loop
    // (see [[mobile-back-nav-model]]).
    this.nav.back();
  }
}
