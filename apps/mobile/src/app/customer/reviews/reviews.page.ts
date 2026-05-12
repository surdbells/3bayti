import {Component, HostListener, OnDestroy, OnInit} from '@angular/core';

import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCard,
  IonCardContent,
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import {Router, RouterLink} from "@angular/router";
import {Subscription} from "rxjs";
import {ConnectionService} from "../../service/connection.service";
import {NetworkService} from "../../service/network.service";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import { I18nService } from '../../i18n.service';
import {City} from "../../class/city";
import {Area} from "../../class/area";
import {Reviews} from "../../class/reviews";
import {ActionSheetController} from "@ionic/angular";
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
@Component({
  selector: 'app-reviews',
  templateUrl: './reviews.page.html',
  styleUrls: ['./reviews.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonCard,
    IonCardContent,
    RouterLink,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
  ]
})
export class ReviewsPage implements OnInit, OnDestroy {
  reviews: Reviews[] = [];
  isOnline = true;
  private sub: Subscription;
  private backSub?: Subscription;
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private toast: AxNotificationService,
    private i18n: I18nService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  @HostListener('window:ionBackButton', ['$event'])
  onHardwareBack(ev: Event) {
    (ev as CustomEvent).detail.register(100, () => {
      this.nav.navigateRoot('/settings');
    });
  }
  ui_controls = {
    is_empty: false,
    is_loading: false,
    is_loading_area: false,
    is_deleting: false
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
  delete = {
    id: 0,
    token: '',
    review: 0
  };

  ngOnInit() {
    this.getObject();
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }
  // Called when the page becomes active (Ionic RouterOutlet triggers this)
  ionViewDidEnter() {
    //  this.getObject();
    this.backSub = this.platform.backButton.subscribeWithPriority(9999, () => {
      this.nav.navigateRoot('/settings'); // or Router: navigateByUrl('/account', { replaceUrl: true })
    });
  }
  // Clean up when you leave the page
  ionViewWillLeave() {
    this.backSub?.unsubscribe();
  }
  star_items = Array(5).fill(0);
  rqst_param = {
    id: 0,
    token: ""
  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
    }else{
      this.single_user = JSON.parse(ret.value);
      this.rqst_param.id = this.single_user.id
      this.rqst_param.token = this.single_user.token
      this.get_reviews();
    }
  }
get_reviews() {
    this.ui_controls.is_empty = false;
    this.ui_controls.is_loading = true;
    this.networkService.post_request(this.rqst_param, GlobalComponent.readReviews)
      .subscribe(({
        next: (response) => {
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
delete_reviews(review: number) {
    if(this.isOnline){
      this.delete.id = this.single_user.id;
      this.delete.token = this.single_user.token;
      this.ui_controls.is_deleting = true;
      this.networkService.post_request(this.delete, GlobalComponent.deleteReview, )
        .subscribe(({
          next: (response) => {
            if (response.response_code === 200 && response.status === "success") {
              this.ui_controls.is_deleting = false;
              this.success_notification(response.message);
              this.get_reviews();
            }
          }
        }))
    }else {
      this.error_notification(this.i18n.t('text_offline_check_connection'))
    }
  }
  async start_delete_review(review: number) {
    const actionSheet = await this.actionSheetCtrl.create({
      header: this.i18n.t('confirm_delete_review'),
      buttons: [
        {
          text: this.i18n.t('button_delete'),
          role: 'destructive',
          handler: () => {
            this.delete_reviews(review);
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
}
