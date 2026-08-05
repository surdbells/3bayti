import {Component, OnDestroy, OnInit} from '@angular/core';

import { FormsModule } from '@angular/forms';
import { HttpClient, HttpHeaders } from '@angular/common/http';
import {
    IonButton,
    IonButtons,
    IonCard,
    IonCardContent, IonCardHeader, IonCardSubtitle, IonCardTitle, IonCol,
    IonContent,
    IonHeader, IonRow,
    IonSelect, IonSelectOption,
    IonTitle,
    IonToolbar, NavController, Platform
} from '@ionic/angular/standalone';
import {Reviews} from "../../class/reviews";
import {Subscription} from "rxjs";
import {ConnectionService} from "../../service/connection.service";
import {Router, RouterLink} from "@angular/router";
import {ActionSheetController} from "@ionic/angular";
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
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

const V3_BASE = 'https://api-v3.3bayti.ae';

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
    IonCardHeader,
    IonCardSubtitle,
    IonCardTitle,
    IonSelect,
    IonSelectOption,
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
  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private actionSheetCtrl: ActionSheetController,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private i18n: I18nService,
    private http: HttpClient,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  // Hardware back left to Ionic's native handling (pop / overlay-close)
  // instead of the old forced navigateRoot('/settings').
  ui_controls = {
    is_loading: false,
    is_updating: false,
    avatar_uploading: false
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
    phone: "",
    // Profile parity with web (UpdateProfileInput): gender, dob (YYYY-MM-DD),
    // locale. Empty string = unset / "prefer not to say" — the tristate is
    // respected in update_profile() (an empty value omits the key).
    gender: "",
    dob: "",
    locale: "en"
  };
  // Mirror web exactly (account-profile-page.ts).
  readonly genderOptions = ['male', 'female', 'other', 'prefer_not_to_say'];
  readonly localeOptions = ['en', 'ar', 'en-AE', 'ar-AE'];
  // Cap the date-of-birth picker at today.
  readonly maxDob = new Date().toISOString().slice(0, 10);
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
  // Hardware back is left to Ionic's default IonRouterOutlet handling so it
  // pops to the previous screen natively (and closes any open overlay first)
  // instead of the old priority-9999 override that force-reset the stack to
  // /settings.
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
    // Direct v3 (GET /v3/me/profile). No response transform is registered
    // for this route-key, so response.data is the raw v3 envelope payload
    // `{ user: {...} }` (UserSerializer::publicProfile). Map the v3 user
    // fields onto the page's `update` model explicitly — v3 returns
    // first_name/last_name/phone/country_code/avatar_url as discrete
    // fields (no legacy flat profile shape).
    this.networkAdapter.get_v3('GET /me/profile', { authToken: this.single_user.token })
      .subscribe(({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === "success") {
            const u = response.data?.user;
            if (u) {
              this.update.first_name = u.first_name ?? '';
              this.update.last_name = u.last_name ?? '';
              if (u.country_code) {
                this.update.countryCode = u.country_code;
              }
              this.update.phone = u.phone ?? '';
              // Profile parity: gender / dob / locale. dob comes back as an
              // ISO datetime — slice to YYYY-MM-DD for the date input. Empty
              // values map to the "unset" select option.
              this.update.gender = u.gender ?? '';
              this.update.dob = u.dob ? String(u.dob).slice(0, 10) : '';
              this.update.locale = u.locale ?? 'en';
              if (u.avatar_url) {
                this.single_user.avatar = u.avatar_url;
              }
            }
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
      this.ui_controls.is_updating = true;
      // Direct v3 (PATCH /v3/me/profile, RFC 7396 merge-patch). Build the
      // body explicitly — request transforms don't apply to direct calls.
      // The v3 UpdateProfileInput accepts first_name/last_name/gender/
      // dob/locale/timezone (all strings); phone/countryCode are not
      // editable here (disabled in the template), so they're never sent.
      //
      // Respect the DTO tristate: only include gender/dob/locale when they
      // hold a non-empty value. An empty value omits the key (do NOT send
      // "" expecting to clear).
      const body: {
        first_name: string;
        last_name: string;
        gender?: string;
        dob?: string;
        locale?: string;
      } = {
        first_name: this.update.first_name,
        last_name: this.update.last_name,
      };
      if (this.update.gender) {
        body.gender = this.update.gender;
      }
      if (this.update.dob) {
        body.dob = this.update.dob;
      }
      if (this.update.locale) {
        body.locale = this.update.locale;
      }
      this.networkAdapter.patch_v3('PATCH /me/profile', body, { authToken: this.single_user.token })
        .subscribe(({
          next: (response: any) => {

            if (response.response_code === 200 && response.status === "success") {
              this.ui_controls.is_updating = false;
              this.success_notification(this.i18n.t('text_profile_updated'));
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
  onAvatarPicked(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files && input.files[0];
    input.value = ''; // allow re-picking the same file
    if (!file) {
      return;
    }
    if (!/^image\/(jpeg|png|webp)$/.test(file.type)) {
      this.error_notification(this.i18n.t('text_avatar_invalid_type'));
      return;
    }
    if (file.size > 5 * 1024 * 1024) {
      this.error_notification(this.i18n.t('text_avatar_too_large'));
      return;
    }
    const reader = new FileReader();
    reader.onload = () => this.uploadAvatar(String(reader.result));
    reader.onerror = () => this.error_notification(this.i18n.t('text_avatar_upload_failed'));
    reader.readAsDataURL(file);
  }

  private uploadAvatar(dataUrl: string) {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    const token = this.single_user.token || '';
    const headers = new HttpHeaders(token ? { Authorization: `Bearer ${token}` } : {});
    this.ui_controls.avatar_uploading = true;
    this.http.post(`${V3_BASE}/v3/me/avatar`, { image: dataUrl }, { headers }).subscribe({
      next: async (response: any) => {
        const url = response?.data?.avatar_url;
        if (url) {
          this.single_user.avatar = url;
          await Preferences.set({ key: 'user', value: JSON.stringify(this.single_user) });
          this.success_notification(this.i18n.t('text_avatar_updated'));
        }
        this.ui_controls.avatar_uploading = false;
      },
      error: () => {
        this.ui_controls.avatar_uploading = false;
        this.error_notification(this.i18n.t('text_avatar_upload_failed'));
      },
    });
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
