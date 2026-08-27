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
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
@Component({
  selector: 'app-store-reviews',
  templateUrl: './store-reviews.page.html',
  styleUrls: ['./store-reviews.page.scss'],
  standalone: true,
  imports: [IonContent, IonHeader, IonTitle, IonToolbar, FormsModule, IonButton, IonButtons, TranslatePipe, AxIconComponent, AxLoaderComponent]
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
    is_loading: false,
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
  add_review = {
    id: 0,
    token: '',
    store_id: 0,
    customer_name: ""
  };

  ngOnInit() {
    this.rqst_param.store = Number(this.route.snapshot.queryParamMap.get('id'));
    this.store_reviews.store = Number(this.route.snapshot.queryParamMap.get('id'));
    this.rqst_param.store_name = this.route.snapshot.queryParamMap.get('name') || '';
    this.getObject();
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }
  // Called when the page becomes active (Ionic RouterOutlet triggers this)
  ionViewDidEnter() {
    this.getObject();
  }
  rqst_param = {
    id: 0,
    store: 0,
    token: "",
    store_name: ""
  }
  store_reviews = {
    id: 0,
    store: 0,
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
    this.ui_controls.is_loading = true;
    // Direct v3 (GET /v3/vendors/:vendorId/reviews), public list of a
    // store's approved reviews, no authToken. The legacy request transform
    // (transformVendorReviewsListRequest) moved the store id from the body
    // (store_id/store) into the vendorId path param; replicated here. The
    // registered response transform still applies via get_v3, so
    // response.data keeps the legacy Reviews[] shape.
    this.networkAdapter.get_v3('GET /vendors/:vendorId/reviews', {
      pathParams: { vendorId: String(this.store_reviews.store) },
    })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            this.reviews = response.data;
            this.ui_controls.is_loading = false;
          }else{
            this.ui_controls.is_empty = true;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  goBack() {
    this.nav.back();
  }
}
