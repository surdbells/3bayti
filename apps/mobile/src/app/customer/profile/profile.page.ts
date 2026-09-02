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
import {ActivatedRoute, Router, RouterLink} from "@angular/router";
import {ActionSheetController} from "@ionic/angular";
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import { apiErrorMessage } from '../../core/http/api-error';
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {Preferences} from "@capacitor/preferences";
import {GlobalComponent} from "../../global-component";
import {DIAL_CODES, DialCode} from "../../public/shared/dial-codes";
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
    private route: ActivatedRoute,
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
    is_customer: false,
    is_email_verified: false,
    needs_email_update: false
  }
  update = {
    id: 0,
    token: '',
    first_name: "",
    last_name: "",
    countryCode: "+971",
    phone: "",
    // Profile parity with web (UpdateProfileInput): gender, dob (YYYY-MM-DD),
    // locale. Empty string = unset / "prefer not to say", the tristate is
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

  // ── Change-phone (OTP) flow ────────────────────────────────────────────
  // The phone field on the main form is DISABLED; editing the number happens
  // only through this two-step OTP flow (matches the register phone-after-
  // social gate that hits the same POST /me/phone + POST /me/phone/verify).
  //   step 1: enter new number  -> POST /me/phone        -> verification_id
  //   step 2: enter OTP          -> POST /me/phone/verify -> { phone, ... }
  changeFlow = {
    isOpen: false,
    step: 1 as 1 | 2,
    countryCode: '+971',
    phone: '',
    code: '',
    verificationId: '',
    loading: false,
    cooldown: 0, // remaining seconds the Resend control is disabled
    locked: false, // hard server rate-limit lockout (disables Verify too)
  };
  /** Controls the nested dial-code picker sheet for the change-phone flow. */
  isChangeCcOpen = false;
  /** Search query for the change-phone flow's dial-code picker. */
  changeCodeSearch = '';
  private changeOtpTimer: ReturnType<typeof setInterval> | null = null;
  private readonly CHANGE_RESEND_COOLDOWN = 30;
  private readonly CHANGE_DEFAULT_LOCKOUT = 60;

  /** Selected dial-code metadata (flag) for the change-flow picker button. */
  get changeSelectedDial(): DialCode | undefined {
    return this.dialCodes.find(d => d.code === this.changeFlow.countryCode);
  }

  /** Full E.164 phone for the change flow ("+971" + national digits). */
  get changeFullPhone(): string {
    return `${this.changeFlow.countryCode}${(this.changeFlow.phone || '').replace(/\D/g, '')}`;
  }

  filteredChangeCodes(): DialCode[] {
    const q = this.changeCodeSearch.trim().toLowerCase();
    if (!q) return this.dialCodes;
    return this.dialCodes.filter(d =>
      d.name.toLowerCase().includes(q) || d.code.includes(q)
    );
  }

  selectChangeCode(d: DialCode) {
    this.changeFlow.countryCode = d.code;
    this.changeCodeSearch = '';
  }

  /** Open the change-phone sheet, seeded from the current profile number. */
  openChangePhone() {
    this.clearChangeOtpTimer();
    this.changeFlow = {
      isOpen: true,
      step: 1,
      countryCode: this.update.countryCode || '+971',
      phone: '',
      code: '',
      verificationId: '',
      loading: false,
      cooldown: 0,
      locked: false,
    };
  }

  /** Reset the flow when the sheet dismisses (backdrop / swipe / Cancel). */
  onChangeSheetDismissed() {
    this.changeFlow.isOpen = false;
    this.clearChangeOtpTimer();
    this.changeFlow.cooldown = 0;
    this.changeFlow.locked = false;
  }

  /** Step 1 (and Resend): request an OTP for the entered number. */
  sendPhoneCode() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    if ((this.changeFlow.phone || '').replace(/\D/g, '').length === 0) {
      this.error_notification(this.i18n.t('text_phone_required'));
      return;
    }
    this.changeFlow.loading = true;
    this.networkAdapter
      .post_v3('POST /me/phone', { phone: this.changeFullPhone }, { authToken: this.single_user.token })
      .subscribe({
        next: (response: any) => {
          this.changeFlow.loading = false;
          if (response.response_code === 200 && response.status === 'success') {
            const vid = response.data?.verification_id;
            if (typeof vid !== 'string' || vid.length === 0) {
              this.error_notification(this.i18n.t('text_request_failed'));
              return;
            }
            this.changeFlow.verificationId = vid;
            this.changeFlow.code = '';
            this.changeFlow.step = 2;
            this.startChangeCooldown(this.CHANGE_RESEND_COOLDOWN);
            this.success_notification(this.i18n.t('text_otp_sent'));
          } else {
            this.handleChangeOtpError(response);
          }
        },
        error: (err: any) => {
          this.changeFlow.loading = false;
          this.error_notification(apiErrorMessage(err, this.i18n.t('text_request_failed')));
        },
      });
  }

  resendPhoneCode() {
    if (this.changeFlow.cooldown > 0) return; // cooldown / lockout active
    this.sendPhoneCode();
  }

  /** Step 2: verify the OTP; on success update the displayed number. */
  verifyPhoneCode() {
    const code = (this.changeFlow.code ?? '').trim();
    if (code.length === 0) {
      this.error_notification(this.i18n.t('text_otp_required'));
      return;
    }
    if (!this.changeFlow.verificationId) {
      this.error_notification(this.i18n.t('text_request_failed'));
      return;
    }
    this.changeFlow.loading = true;
    this.networkAdapter
      .post_v3(
        'POST /me/phone/verify',
        { verification_id: this.changeFlow.verificationId, code },
        { authToken: this.single_user.token },
      )
      .subscribe({
        next: async (response: any) => {
          this.changeFlow.loading = false;
          if (response.response_code === 200 && response.status === 'success') {
            const newPhone = response.data?.phone;
            // Reflect the verified number on the (disabled) main form.
            this.update.countryCode = this.changeFlow.countryCode;
            this.update.phone = (this.changeFlow.phone || '').replace(/\D/g, '');
            // Persist the full E.164 onto the cached user blob so other
            // pages see the new number (mirrors the avatar-upload persist).
            this.single_user.phone =
              typeof newPhone === 'string' && newPhone.length > 0 ? newPhone : this.changeFullPhone;
            try {
              await Preferences.set({ key: 'user', value: JSON.stringify(this.single_user) });
            } catch {
              /* non-fatal */
            }
            this.clearChangeOtpTimer();
            this.changeFlow.isOpen = false;
            this.success_notification(this.i18n.t('text_phone_updated'));
          } else {
            this.handleChangeOtpError(response);
          }
        },
        error: (err: any) => {
          this.changeFlow.loading = false;
          this.error_notification(apiErrorMessage(err, this.i18n.t('text_otp_verification_failed')));
        },
      });
  }

  /**
   * Map the v3 error envelope (surfaced through the success channel by
   * MobileNetworkAdapter) to a friendly message, and start a lockout on
   * OTP_RATE_LIMITED. Known codes map to i18n; otherwise the real server
   * message is shown (e.g. "That phone number is already registered.").
   */
  private handleChangeOtpError(response: any) {
    if (response?.error_code === 'OTP_RATE_LIMITED') {
      const ra = Number(response?.error_details?.retry_after ?? response?.retry_after);
      this.startChangeLockout(Number.isFinite(ra) && ra > 0 ? Math.ceil(ra) : this.CHANGE_DEFAULT_LOCKOUT);
    }
    this.error_notification(this.mapChangeError(response));
  }

  private mapChangeError(response: any): string {
    switch (response?.error_code) {
      case 'CONFLICT_PHONE_TAKEN':
        return this.i18n.t('text_phone_already_registered');
      case 'OTP_VERIFICATION_FAILED':
        return this.i18n.t('text_otp_verification_failed');
      case 'OTP_RATE_LIMITED':
        return this.i18n.t('text_otp_rate_limited');
      case 'OTP_PROVIDER_ERROR':
        return this.i18n.t('text_otp_provider_error');
      default:
        break;
    }
    if (typeof response?.message === 'string' && response.message.trim() !== '') {
      return response.message;
    }
    return this.i18n.t('text_request_failed');
  }

  /** Short cooldown after a successful send: disables Resend only. */
  private startChangeCooldown(seconds: number) {
    this.startChangeCountdown(seconds, false);
  }

  /** Longer server lockout: disables Resend AND Verify for `seconds`. */
  private startChangeLockout(seconds: number) {
    this.startChangeCountdown(seconds, true);
  }

  private startChangeCountdown(seconds: number, locked: boolean) {
    this.clearChangeOtpTimer();
    this.changeFlow.locked = locked;
    this.changeFlow.cooldown = Math.max(0, Math.ceil(seconds));
    if (this.changeFlow.cooldown === 0) {
      this.changeFlow.locked = false;
      return;
    }
    this.changeOtpTimer = setInterval(() => {
      this.changeFlow.cooldown -= 1;
      if (this.changeFlow.cooldown <= 0) {
        this.clearChangeOtpTimer();
        this.changeFlow.cooldown = 0;
        this.changeFlow.locked = false;
      }
    }, 1000);
  }

  private clearChangeOtpTimer() {
    if (this.changeOtpTimer) {
      clearInterval(this.changeOtpTimer);
      this.changeOtpTimer = null;
    }
  }

  // ── Change-email (OTP) flow ────────────────────────────────────────────
  // Mirrors the change-phone flow for customers whose email can't receive our
  // mail (Apple private-relay / social placeholder). The OTP is sent to the
  // NEW address, proving deliverability before the switch.
  //   step 1: enter new email -> POST /me/email        -> verification_id
  //   step 2: enter OTP        -> POST /me/email/verify -> { email, is_email_verified, needs_email_update }
  emailFlow = {
    isOpen: false,
    step: 1 as 1 | 2,
    email: '',
    code: '',
    verificationId: '',
    loading: false,
    cooldown: 0,
    locked: false,
  };
  private emailOtpTimer: ReturnType<typeof setInterval> | null = null;

  /** Open the change-email sheet (blank — the point is to move OFF the
      current, non-deliverable address). */
  openChangeEmail() {
    this.clearEmailOtpTimer();
    this.emailFlow = {
      isOpen: true,
      step: 1,
      email: '',
      code: '',
      verificationId: '',
      loading: false,
      cooldown: 0,
      locked: false,
    };
  }

  onEmailSheetDismissed() {
    this.emailFlow.isOpen = false;
    this.clearEmailOtpTimer();
    this.emailFlow.cooldown = 0;
    this.emailFlow.locked = false;
  }

  private isValidEmail(email: string): boolean {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
  }

  /** Step 1 (and Resend): request an OTP for the entered email. */
  sendEmailCode() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    const email = (this.emailFlow.email || '').trim();
    if (!this.isValidEmail(email)) {
      this.error_notification(this.i18n.t('text_email_required'));
      return;
    }
    this.emailFlow.loading = true;
    this.networkAdapter
      .post_v3('POST /me/email', { email }, { authToken: this.single_user.token })
      .subscribe({
        next: (response: any) => {
          this.emailFlow.loading = false;
          if (response.response_code === 200 && response.status === 'success') {
            const vid = response.data?.verification_id;
            if (typeof vid !== 'string' || vid.length === 0) {
              this.error_notification(this.i18n.t('text_request_failed'));
              return;
            }
            this.emailFlow.email = email;
            this.emailFlow.verificationId = vid;
            this.emailFlow.code = '';
            this.emailFlow.step = 2;
            this.startEmailCooldown(this.CHANGE_RESEND_COOLDOWN);
            this.success_notification(this.i18n.t('text_otp_sent'));
          } else {
            this.handleEmailOtpError(response);
          }
        },
        error: (err: any) => {
          this.emailFlow.loading = false;
          this.error_notification(apiErrorMessage(err, this.i18n.t('text_request_failed')));
        },
      });
  }

  resendEmailCode() {
    if (this.emailFlow.cooldown > 0) return;
    this.sendEmailCode();
  }

  /** Step 2: verify the OTP; on success promote + persist the new email. */
  verifyEmailCode() {
    const code = (this.emailFlow.code ?? '').trim();
    if (code.length === 0) {
      this.error_notification(this.i18n.t('text_otp_required'));
      return;
    }
    if (!this.emailFlow.verificationId) {
      this.error_notification(this.i18n.t('text_request_failed'));
      return;
    }
    this.emailFlow.loading = true;
    this.networkAdapter
      .post_v3(
        'POST /me/email/verify',
        { verification_id: this.emailFlow.verificationId, code },
        { authToken: this.single_user.token },
      )
      .subscribe({
        next: async (response: any) => {
          this.emailFlow.loading = false;
          if (response.response_code === 200 && response.status === 'success') {
            const newEmail = response.data?.email;
            this.single_user.email =
              typeof newEmail === 'string' && newEmail.length > 0 ? newEmail : this.emailFlow.email;
            this.single_user.is_email_verified = response.data?.is_email_verified === true;
            // Server returns false here; clears the "update your email" banner.
            this.single_user.needs_email_update = response.data?.needs_email_update === true;
            try {
              await Preferences.set({ key: 'user', value: JSON.stringify(this.single_user) });
            } catch {
              /* non-fatal */
            }
            this.clearEmailOtpTimer();
            this.emailFlow.isOpen = false;
            this.success_notification(this.i18n.t('text_email_updated'));
          } else {
            this.handleEmailOtpError(response);
          }
        },
        error: (err: any) => {
          this.emailFlow.loading = false;
          this.error_notification(apiErrorMessage(err, this.i18n.t('text_otp_verification_failed')));
        },
      });
  }

  private handleEmailOtpError(response: any) {
    if (response?.error_code === 'OTP_RATE_LIMITED') {
      const ra = Number(response?.error_details?.retry_after ?? response?.retry_after);
      this.startEmailLockout(Number.isFinite(ra) && ra > 0 ? Math.ceil(ra) : this.CHANGE_DEFAULT_LOCKOUT);
    }
    this.error_notification(this.mapEmailError(response));
  }

  private mapEmailError(response: any): string {
    switch (response?.error_code) {
      case 'CONFLICT_EMAIL_TAKEN':
        return this.i18n.t('text_email_already_registered');
      case 'VALIDATION_FAILED':
        // The new address is itself non-deliverable (relay / placeholder) or
        // malformed — the server rejects it with 422 VALIDATION_FAILED.
        return this.i18n.t('text_email_not_deliverable');
      case 'OTP_VERIFICATION_FAILED':
        return this.i18n.t('text_otp_verification_failed');
      case 'OTP_RATE_LIMITED':
        return this.i18n.t('text_otp_rate_limited');
      case 'OTP_PROVIDER_ERROR':
        return this.i18n.t('text_otp_provider_error');
      default:
        break;
    }
    if (typeof response?.message === 'string' && response.message.trim() !== '') {
      return response.message;
    }
    return this.i18n.t('text_request_failed');
  }

  private startEmailCooldown(seconds: number) {
    this.startEmailCountdown(seconds, false);
  }

  private startEmailLockout(seconds: number) {
    this.startEmailCountdown(seconds, true);
  }

  private startEmailCountdown(seconds: number, locked: boolean) {
    this.clearEmailOtpTimer();
    this.emailFlow.locked = locked;
    this.emailFlow.cooldown = Math.max(0, Math.ceil(seconds));
    if (this.emailFlow.cooldown === 0) {
      this.emailFlow.locked = false;
      return;
    }
    this.emailOtpTimer = setInterval(() => {
      this.emailFlow.cooldown -= 1;
      if (this.emailFlow.cooldown <= 0) {
        this.clearEmailOtpTimer();
        this.emailFlow.cooldown = 0;
        this.emailFlow.locked = false;
      }
    }, 1000);
  }

  private clearEmailOtpTimer() {
    if (this.emailOtpTimer) {
      clearInterval(this.emailOtpTimer);
      this.emailOtpTimer = null;
    }
  }

  ngOnInit() {
    this.getObject();
    // Opened from the "add your phone" banner (home / account) with
    // ?addPhone=1, jump straight into the add/change-phone OTP flow.
    if (this.route.snapshot.queryParamMap.get('addPhone')) {
      this.openChangePhone();
    }
    // Opened from the "update your email" banner with ?addEmail=1.
    if (this.route.snapshot.queryParamMap.get('addEmail')) {
      this.openChangeEmail();
    }
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
    this.clearChangeOtpTimer();
    this.clearEmailOtpTimer();
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
    // fields onto the page's `update` model explicitly, v3 returns
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
              // ISO datetime, slice to YYYY-MM-DD for the date input. Empty
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
      // body explicitly, request transforms don't apply to direct calls.
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
          error: (err: any) => {
            this.ui_controls.is_updating = false;
            this.error_notification(apiErrorMessage(err, this.i18n.t('text_unable_to_save_profile')));
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
      error: (err: any) => {
        this.ui_controls.avatar_uploading = false;
        this.error_notification(apiErrorMessage(err, this.i18n.t('text_avatar_upload_failed')));
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
