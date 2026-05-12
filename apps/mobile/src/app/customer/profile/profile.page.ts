import {Component, HostListener, OnDestroy, OnInit} from '@angular/core';

import { FormsModule } from '@angular/forms';
import {
    IonButton,
    IonButtons,
    IonCard,
    IonCardContent, IonCardHeader, IonCardSubtitle, IonCardTitle, IonCol,
    IonContent,
    IonHeader, IonRow, IonSearchbar,
    IonTitle,
    IonToolbar, NavController, Platform
} from '@ionic/angular/standalone';
import {Reviews} from "../../class/reviews";
import {Subscription} from "rxjs";
import {ConnectionService} from "../../service/connection.service";
import {Router, RouterLink} from "@angular/router";
import {ActionSheetController} from "@ionic/angular";
import {NetworkService} from "../../service/network.service";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import {DIAL_CODES, DialCode} from "../../dial-codes";
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
@Component({
  selector: 'app-profile',
  templateUrl: './profile.page.html',
  styleUrls: ['./profile.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    FormsModule,
    IonButtons,
    IonCard,
    IonCardContent,
    RouterLink,
    IonCol,
    IonRow,
    IonButton,
    IonSearchbar,
    IonCardHeader,
    IonCardSubtitle,
    IonCardTitle,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AxTextFieldComponent,
    AxBottomSheetComponent,
  ]
})
export class ProfilePage implements OnInit, OnDestroy {
  reviews: Reviews[] = [];
  isOnline = true;
  isCountryCodeOpen = false;
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
    is_loading: false,
    is_updating: false
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
  update = {
    id: 0,
    token: '',
    first_name: "",
    last_name: "",
    countryCode: "+971",
    phone: ""
  };
  dialCodes: DialCode[] = DIAL_CODES;
  codeSearch = '';
  get selectedDial(): DialCode | undefined {
    return this.dialCodes.find(d => d.code === this.update.countryCode);
  }
  filteredDialCodes(): DialCode[] {
    const q = this.codeSearch.trim().toLowerCase();
    if (!q) return this.dialCodes;
    return this.dialCodes.filter(d =>
      d.name.toLowerCase().includes(q) || d.code.includes(q)
    );
  }
  selectCode(d: DialCode) {
    this.update.countryCode = d.code;
    this.codeSearch = '';
  }

// Optional: use when submitting
  get fullPhone(): string {
    return `${this.update.countryCode}${(this.update.phone || '').replace(/\D/g, '')}`;
  }
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
      this.get_profile();
    }
  }
  get_profile() {
    this.ui_controls.is_loading = true;
    this.networkService.post_request(this.rqst_param, GlobalComponent.readProfile)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.update = response.data;
            this.ui_controls.is_loading = false;
          }
        }
      }))
  }
  update_profile() {
    if(this.isOnline){
      this.update.id = this.single_user.id;
      this.update.token = this.single_user.token;
      if (this.update.first_name.length == 0) {
        this.error_notification(this.i18n.t('text_first_name_required'));
        return;
      }
      if (this.update.last_name.length == 0) {
        this.error_notification(this.i18n.t('text_last_name_required'));
        return;
      }
      if (this.update.phone.length == 0) {
        this.error_notification(this.i18n.t('text_phone_required'));
        return;
      }
      if (this.update.countryCode.length === 0) {
        this.error_notification(this.i18n.t('text_country_code_required'));
        return;
      }
      this.ui_controls.is_updating = true;
      this.networkService.post_request(this.update, GlobalComponent.updateProfile)
        .subscribe(({
          next: (response) => {

            if (response.response_code === 200 && response.status === "success") {
              this.ui_controls.is_updating = false;
              this.success_notification(response.message);
              this.get_profile();
            }else{
              this.ui_controls.is_updating = false
              this.error_notification(response.message);
            }
          },
          error: () => {
            this.ui_controls.is_updating = false;
            this.error_notification(this.i18n.t('text_unable_to_save_profile'));
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
