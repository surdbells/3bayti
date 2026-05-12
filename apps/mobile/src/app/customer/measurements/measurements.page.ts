import {Component, HostListener, OnDestroy, OnInit} from '@angular/core';
import {Subscription} from "rxjs";
import {
  IonButton,
  IonButtons,
  IonCard,
  IonCardContent,
  IonCardHeader,
  IonCardSubtitle,
  IonCardTitle,
  IonCol,
  IonContent,
  IonHeader,
  IonRow,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import {ConnectionService} from "../../service/connection.service";
import {Router, RouterLink} from "@angular/router";
import {NetworkService} from "../../service/network.service";
import {AxNotificationService} from '../../shared/ax-mobile/notification';

import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import {List} from "../../class/list";
import {FormsModule} from "@angular/forms";
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
@Component({
  selector: 'app-measurements',
  standalone: true,
  templateUrl: './measurements.page.html',
  styleUrls: ['./measurements.page.scss'],
  imports: [
    IonHeader,
    IonToolbar,
    IonButtons,
    RouterLink,
    IonContent,
    IonCard,
    IonCardContent,
    FormsModule,
    IonCol,
    IonRow,
    IonTitle,
    IonCardHeader,
    IonCardSubtitle,
    IonCardTitle,
    IonButton,
    TranslatePipe,
    AxLoaderComponent,
    AxIconComponent,
    AxTextFieldComponent,
  ]
})
export class MeasurementsPage implements OnInit, OnDestroy {
  list: List[] = [];
  isOnline = true;
  private sub: Subscription;
  private backSub?: Subscription;
  protected index = 0;
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
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
    is_loading: false,
    is_creating: false,
    is_empty: false
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
    this.getObject();
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }
  update = {
    id: 0,
    token: '',
    bust: "",
    shoulder: "",
    armhole: "",
    length: "",
    hip: "",
    arm: ""
  };
  // Called when the page becomes active (Ionic RouterOutlet triggers this)
  ionViewDidEnter() {
  //  this.getObject();
    this.backSub = this.platform.backButton.subscribeWithPriority(9999, () => {
      this.nav.navigateRoot('/account'); // or Router: navigateByUrl('/account', { replaceUrl: true })
    });
  }

  // Clean up when you leave the page
  ionViewWillLeave() {
    this.backSub?.unsubscribe();
  }
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
      this.get_measurement();
    }
  }
  get_measurement() {
    this.ui_controls.is_loading = true;
    this.networkService.post_request(this.rqst_param, GlobalComponent.readMeasurement)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.update.bust =  response.data[0].bust
            this.update.armhole = response.data[0].armhole
            this.update.shoulder = response.data[0].shoulder
            this.update.length = response.data[0].length
            this.update.hip = response.data[0].hip
            this.update.arm = response.data[0].arm
            this.ui_controls.is_loading = false;
          }else{
            this.ui_controls.is_empty = true;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  update_measurement() {
    if(this.isOnline){
      this.update.id = this.single_user.id;
      this.update.token = this.single_user.token;
      this.ui_controls.is_loading = true;
      this.networkService.post_request(this.update, GlobalComponent.updateMeasurement)
        .subscribe(({
          next: (response) => {
            if (response.response_code === 200 && response.status === "success") {
              this.success_notification(response.message);
              this.ui_controls.is_loading = false;
              this.get_measurement();
            }else{
              this.ui_controls.is_loading = false
              this.error_notification(response.message);
            }
          },
          error: () => {
            this.ui_controls.is_loading = false;
            this.error_notification(this.i18n.t('text_unable_to_save_measurement'));
          }
        }))
    }else {
      this.error_notification(this.i18n.t('text_offline_check_connection'))
    }
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
