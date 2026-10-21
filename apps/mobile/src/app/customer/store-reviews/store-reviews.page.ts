import {Component, OnDestroy, OnInit} from '@angular/core';

import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import {Reviews} from "../../class/reviews";
import {Subscription} from "rxjs";
import {ConnectionService} from "../../service/connection.service";
import {ActivatedRoute, Router} from "@angular/router";
import {ActionSheetController} from "@ionic/angular";
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxSkeletonCircleComponent, AxSkeletonLineComponent } from '../../shared/ax-mobile/skeleton';
@Component({
  selector: 'app-store-reviews',
  templateUrl: './store-reviews.page.html',
  styleUrls: ['./store-reviews.page.scss'],
  standalone: true,
  imports: [IonContent, IonHeader, IonTitle, IonToolbar, FormsModule, IonButton, IonButtons, TranslatePipe, AxIconComponent, AxSkeletonCircleComponent, AxSkeletonLineComponent]
})
export class StoreReviewsPage implements OnInit, OnDestroy {
  reviews: Reviews[] = [];
  isOnline = true;
  private sub: Subscription;
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
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  // Hardware back is left to Ionic's default IonRouterOutlet handling so it
  // pops to the previous screen natively (and closes any open overlay first)
  // instead of the old forced navigateRoot('/settings') overrides.
  ui_controls = {
    is_empty: false,
    // The list request failed; shows a retry state instead of a blank page.
    is_error: false,
    // Starts true: the list loads on every entry, so the first frame is the
    // skeleton rather than an empty page.
    is_loading: true,
  }
  /** i18n key for the failure message (offline vs any other error). */
  errorKey = 'text_something_went_wrong';
  /** Skeleton placeholders shown while the list loads. */
  readonly skeletonRows = [0, 1, 2];
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
  add_review = {
    id: 0,
    token: '',
    store_id: 0,
    customer_name: ""
  };

  ngOnInit() {
    // The store is addressed by its SLUG (v3), never the legacy store id:
    // v3-native stores have no legacy id (it arrived as 0 -> 404 -> "No
    // reviews"), and the API resolves a number as a v3 id FIRST, so a legacy
    // id equal to another store's v3 id showed that other store's reviews.
    this.store_reviews.store = (this.route.snapshot.queryParamMap.get('slug') || '').trim();
    this.rqst_param.store_name = this.route.snapshot.queryParamMap.get('name') || '';
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }
  // Load on every entry (Ionic caches the page, so ngOnInit runs once).
  // WillEnter, not DidEnter: the request starts with the transition instead
  // of after it, and this is the ONLY load (ngOnInit used to fire a second,
  // racing request for the same list).
  ionViewWillEnter() {
    this.getObject();
  }
  rqst_param = {
    id: 0,
    token: "",
    store_name: ""
  }
  store_reviews = {
    id: 0,
    /** Store slug; the reviews endpoint's {vendorId} accepts a slug. */
    store: '',
    token: "",
  }

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.rqst_param.id = this.single_user.id
      this.rqst_param.token = this.single_user.token

      this.store_reviews.id = this.single_user.id
      this.store_reviews.token = this.single_user.token


      this.get_reviews();
    }
  }
  get_reviews() {
    this.ui_controls.is_empty = false;
    this.ui_controls.is_error = false;
    if (!this.store_reviews.store) {
      // Opened without a store slug: nothing to load.
      this.reviews = [];
      this.ui_controls.is_loading = false;
      this.ui_controls.is_empty = true;
      return;
    }
    this.ui_controls.is_loading = true;
    // Direct v3 (GET /v3/vendors/:vendorId/reviews), public list of a
    // store's approved reviews, no authToken. The legacy request transform
    // (transformVendorReviewsListRequest) moved the store id from the body
    // (store_id/store) into the vendorId path param; replicated here. The
    // registered response transform still applies via get_v3, so
    // response.data keeps the legacy Reviews[] shape.
    //
    // v3 answers "no reviews" with 200 + data: [] (legacy answered it with a
    // non-success envelope), so the empty state follows the list length, and
    // transport errors (rx error channel) get a retry state, never a blank page.
    this.networkAdapter.get_v3('GET /vendors/:vendorId/reviews', {
      pathParams: { vendorId: this.store_reviews.store },
    })
      .subscribe(({
        next: (response: any) => {
          this.ui_controls.is_loading = false;
          if (response.response_code === 200 && response.status === "success") {
            this.reviews = Array.isArray(response.data) ? response.data : [];
            this.ui_controls.is_empty = this.reviews.length === 0;
          } else if (response.response_code === 404) {
            // Unknown store id: nothing to show, same as an empty store.
            this.reviews = [];
            this.ui_controls.is_empty = true;
          } else {
            this.reviews = [];
            this.errorKey = 'text_something_went_wrong';
            this.ui_controls.is_error = true;
          }
        },
        error: () => {
          this.ui_controls.is_loading = false;
          this.reviews = [];
          this.errorKey = this.isOnline ? 'text_something_went_wrong' : 'text_offline_check_connection';
          this.ui_controls.is_error = true;
        }
      }))
  }
  goBack() {
    this.nav.back();
  }
}
