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
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
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
  protected index = 0;
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private i18n: I18nService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
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
  // Hardware back is left to Ionic's default IonRouterOutlet handling so it
  // pops to the previous screen natively (and closes any open overlay first)
  // instead of the old priority-9999 override that force-reset the stack to
  // /account.
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
    // Direct v3 (GET /v3/me/measurements). The response transform still applies
    // via get_v3, so response.data keeps the legacy [{...values}] shape. v3
    // values are numbers, so coerce to the string-typed form fields.
    this.networkAdapter.get_v3('GET /me/measurements', { authToken: this.single_user.token })
      .subscribe(({
        next: (response: any) => {
          const m = response.data?.[0];
          if (response.response_code === 200 && response.status === "success" && m) {
            this.update.bust = m.bust != null ? String(m.bust) : '';
            this.update.armhole = m.armhole != null ? String(m.armhole) : '';
            this.update.shoulder = m.shoulder != null ? String(m.shoulder) : '';
            this.update.length = m.length != null ? String(m.length) : '';
            this.update.hip = m.hip != null ? String(m.hip) : '';
            this.update.arm = m.arm != null ? String(m.arm) : '';
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
      this.ui_controls.is_loading = true;
      // Direct v3 (PUT /v3/me/measurements/default). v3 wants a numeric `values`
      // map (cm, 0-500); send only the fields the user filled.
      const values: Record<string, number> = {};
      for (const k of ['bust', 'shoulder', 'armhole', 'length', 'hip', 'arm'] as const) {
        const n = Number(this.update[k]);
        // Clamp to the v3 range (cm, 0-500). Drop out-of-range values exactly
        // as the old request transform did — sending >500 would 422 the whole save.
        if (Number.isFinite(n) && n > 0 && n <= 500) values[k] = n;
      }
      this.networkAdapter.put_v3('PUT /me/measurements/default', { values }, { authToken: this.single_user.token })
        .subscribe(({
          next: (response: any) => {
            if (response.response_code === 200 && response.status === "success") {
              this.success_notification(response.message || this.i18n.t('text_measurement_saved'));
              this.ui_controls.is_loading = false;
              this.get_measurement();
            }else{
              this.ui_controls.is_loading = false
              this.error_notification(response.message || this.i18n.t('text_unable_to_save_measurement'));
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
