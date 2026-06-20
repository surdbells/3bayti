import {Component, OnDestroy, OnInit} from '@angular/core';
import {
  IonContent,
  Platform,
  IonButton,
  IonText,
  IonCheckbox,
  IonCard,
  IonCardHeader,
  IonCardTitle,
  IonCardContent,
  IonSegment,
  IonSegmentButton,
  IonLabel,
  IonSearchbar,
} from '@ionic/angular/standalone';
import { ConnectionService } from '../../service/connection.service';
import { Subscription } from 'rxjs';

import { FormsModule } from '@angular/forms';
import {Router} from "@angular/router";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import {GlobalComponent} from "../../global-component";
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";
import {Preferences} from "@capacitor/preferences";
import {BlockerService} from "../../blocker.service";

import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';
import { AxIconComponent } from '../../shared/ax-mobile/icon';

import {transformV3LoginResponse} from "../login/login-response.transform";
import {CartMergeService} from "../../core/services/cart-merge.service";
import {PushManager} from "../../core/services/push-manager.service";
import {DIAL_CODES, DialCode, mapDialToIso} from "../shared/dial-codes";

/**
 * Mobile registration page — rebuilt for the v3 PHONE-FIRST 4-step flow.
 *
 * Flow (replaces the legacy 2-stage register/confirm flow)
 * ========================================================
 *
 *   Step 1 (Phone): country-code + phone.
 *     [Continue] -> POST /auth/register/initiate { phone, country_code }
 *       -> data { verification_id }. Stash verification_id, advance to Step 2.
 *       CONFLICT_PHONE_TAKEN (409) -> specific error + offer login link.
 *
 *   Step 2 (Verify phone): "We sent a code to <phone>" + OTP.
 *     [Verify] -> POST /auth/register/verify-phone { verification_id, code }
 *       -> data { registration_token }. Stash registration_token, advance
 *       to Step 3. OTP_VERIFICATION_FAILED (401) -> specific error.
 *     Resend -> re-call initiate. Change number -> back to Step 1.
 *
 *   Step 3 (Details): verified phone PREFILLED + READONLY, plus first_name,
 *     last_name, email, password, confirm_password, accept-terms (T&C sheet).
 *     [Continue] -> validate -> POST /auth/register/submit
 *       { registration_token, email, password, first_name, last_name }
 *       -> data { verification_id (email) }. Stash, advance to Step 4.
 *       CONFLICT_EMAIL_TAKEN (409) -> inline email error.
 *       AUTH_INVALID_TOKEN (401, token expired) -> toast + restart at Step 1.
 *
 *   Step 4 (Verify email): "We sent a code to <email>" + OTP.
 *     [Verify] -> POST /auth/register/confirm-email { verification_id, code }
 *       -> data { tokens, user } (same shape /auth/confirm returns).
 *       transformV3LoginResponse -> Preferences.set('user') ->
 *       cartMerge.mergeIfAny (non-blocking) -> pushManager.onSignedIn() ->
 *       navigate /account (auto-login).
 *     Resend -> re-call submit with same data. Back -> Step 3.
 *
 * Errors
 * ======
 * v3 errors arrive on the SUCCESS channel as
 * { response_code, status: 'error', message, error_code }. We read
 * response.error_code / response.message; we also defensively read
 * err?.error?.error?.code on the rxjs error cb. Known codes map to i18n.
 */
@Component({
  selector: 'app-register',
  templateUrl: './register.page.html',
  styleUrls: ['./register.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    FormsModule,
    IonButton,
    IonText,
    IonCheckbox,
    IonCard,
    IonCardHeader,
    IonCardTitle,
    IonCardContent,
    IonSegment,
    IonSegmentButton,
    IonLabel,
    IonSearchbar,
    TranslatePipe,
    AxLoaderComponent,
    AxTextFieldComponent,
    AxBottomSheetComponent,
    AxIconComponent,
  ]
})
export class RegisterPage implements OnInit, OnDestroy {
  isOnline = true;
  private sub: Subscription;
  isTermsOpen = false; // controls the terms-of-service modal visibility
  isCountryCodeOpen = false; // controls the dial-code picker sheet

  // Dial-code picker state (reuses the profile page pattern).
  readonly dialCodes: DialCode[] = DIAL_CODES;
  codeSearch = '';

  constructor(
    private net: ConnectionService,
    private platform: Platform,
    private blocker: BlockerService,
    private router: Router,
    private networkAdapter: MobileNetworkAdapter,
    private toast: AxNotificationService,
    private i18n: I18nService,
    private cartMerge: CartMergeService,
    private pushManager: PushManager,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
  }

  ngOnInit() {
    // v3 sends OTP internally on each step; page starts ready for input.
  }

  /**
   * UI flow state.
   *   step 1: phone
   *   step 2: verify phone OTP
   *   step 3: account details
   *   step 4: verify email OTP
   */
  ui_controls = {
    loading: false,
    step: 1 as 1 | 2 | 3 | 4,
    termsArabic: true,
    termsEnglish: false,
  };

  register = {
    first_name: "",
    last_name: "",
    email: "",
    phone: "",
    password: "",
    confirm_password: "",
    countryCode: "+971",
    accepted_terms: false,
  };

  /** Issued by initiate; consumed by verify-phone. */
  phoneVerificationId = "";
  /** Issued by verify-phone; consumed by submit. */
  registrationToken = "";
  /** Issued by submit; consumed by confirm-email. */
  emailVerificationId = "";

  otp = { code: "" };

  /** Selected dial-code metadata (flag) for the picker button. */
  get selectedDial(): DialCode | undefined {
    return this.dialCodes.find(c => c.code === this.register.countryCode);
  }

  /** Full E.164-ish phone for display / API ("+971" + national digits). */
  get fullPhone(): string {
    const national = this.register.phone.replace(/^0+/, '').replace(/\D/g, '');
    return `${this.register.countryCode}${national}`;
  }

  filteredDialCodes(): DialCode[] {
    const q = this.codeSearch.trim().toLowerCase();
    if (q.length === 0) return this.dialCodes;
    return this.dialCodes.filter(c =>
      c.name.toLowerCase().includes(q) || c.code.includes(q));
  }

  selectCode(c: DialCode) {
    this.register.countryCode = c.code;
  }

  // --- Step 1: phone -> initiate -----------------------------------------

  initiate_phone() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    if (this.register.phone.replace(/\D/g, '').length === 0) {
      this.error_notification(this.i18n.t('text_phone_required'));
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/register/initiate', {
      phone: this.fullPhone,
      country_code: mapDialToIso(this.register.countryCode),
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const vid = response.data?.verification_id;
          if (typeof vid !== 'string' || vid.length === 0) {
            this.error_notification(this.i18n.t('text_request_failed'));
            return;
          }
          this.phoneVerificationId = vid;
          this.otp.code = '';
          this.ui_controls.step = 2;
          this.success_notification(this.i18n.t('text_otp_sent'));
        } else {
          this.error_notification(this.mapErrorMessage(response));
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  // --- Step 2: verify phone OTP -> registration_token --------------------

  verify_phone() {
    if (this.otp.code.length === 0) {
      this.error_notification(this.i18n.t('text_otp_required'));
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/register/verify-phone', {
      verification_id: this.phoneVerificationId,
      code: this.otp.code,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const token = response.data?.registration_token;
          if (typeof token !== 'string' || token.length === 0) {
            this.error_notification(this.i18n.t('text_request_failed'));
            return;
          }
          this.registrationToken = token;
          this.ui_controls.step = 3;
        } else {
          this.error_notification(this.mapErrorMessage(response));
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  resend_phone_otp() {
    // Re-issue the phone OTP by re-calling initiate. Keeps us on Step 2.
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/register/initiate', {
      phone: this.fullPhone,
      country_code: mapDialToIso(this.register.countryCode),
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const vid = response.data?.verification_id;
          if (typeof vid === 'string' && vid.length > 0) {
            this.phoneVerificationId = vid;
            this.otp.code = '';
          }
          this.success_notification(this.i18n.t('text_otp_sent'));
        } else {
          this.error_notification(this.mapErrorMessage(response));
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  change_phone() {
    this.ui_controls.step = 1;
    this.otp.code = '';
  }

  // --- Step 3: details -> submit -> email verification_id ----------------

  submit_details() {
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    if (this.register.first_name.length === 0) {
      this.error_notification(this.i18n.t('text_first_name_required'));
      return;
    }
    if (this.register.last_name.length === 0) {
      this.error_notification(this.i18n.t('text_last_name_required'));
      return;
    }
    if (this.register.email.length === 0) {
      this.error_notification(this.i18n.t('text_email_required'));
      return;
    }
    if (!GlobalComponent.validateEmail(this.register.email)) {
      this.error_notification(this.i18n.t('text_invalid_email_format'));
      return;
    }
    if (this.register.password.length < 8) {
      this.error_notification(this.i18n.t('text_password_min_length'));
      return;
    }
    if (this.register.password !== this.register.confirm_password) {
      this.error_notification(this.i18n.t('text_passwords_do_not_match'));
      return;
    }
    if (!this.register.accepted_terms) {
      this.error_notification(this.i18n.t('text_accept_terms_required'));
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/register/submit', {
      registration_token: this.registrationToken,
      email: this.register.email,
      password: this.register.password,
      first_name: this.register.first_name,
      last_name: this.register.last_name,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const vid = response.data?.verification_id;
          if (typeof vid !== 'string' || vid.length === 0) {
            this.error_notification(this.i18n.t('text_request_failed'));
            return;
          }
          this.emailVerificationId = vid;
          this.otp.code = '';
          this.ui_controls.step = 4;
          this.success_notification(this.i18n.t('text_otp_sent'));
        } else {
          this.handleSubmitError(response);
        }
      },
      error: (e) => {
        const code = e?.error?.error?.code;
        if (code === 'AUTH_INVALID_TOKEN') {
          this.restartExpired();
          return;
        }
        this.handleHttpError(e);
      },
    });
  }

  /** Map submit-specific errors: email-taken inline; expired token -> restart. */
  private handleSubmitError(response: any) {
    const code = response?.error_code;
    if (code === 'AUTH_INVALID_TOKEN') {
      this.restartExpired();
      return;
    }
    this.error_notification(this.mapErrorMessage(response));
  }

  private restartExpired() {
    this.ui_controls.loading = false;
    this.ui_controls.step = 1;
    this.registrationToken = '';
    this.phoneVerificationId = '';
    this.emailVerificationId = '';
    this.otp.code = '';
    this.error_notification(this.i18n.t('text_session_expired_restart'));
  }

  // --- Step 4: confirm email OTP -> tokens -> auto-login -----------------

  confirm_email() {
    if (this.otp.code.length === 0) {
      this.error_notification(this.i18n.t('text_otp_required'));
      return;
    }

    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/register/confirm-email', {
      verification_id: this.emailVerificationId,
      code: this.otp.code,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const userToStore = transformV3LoginResponse(response.data) ?? response.data;
          Preferences.set({ key: 'user', value: JSON.stringify(userToStore) });

          const authBody = {
            id: typeof (userToStore as any)?.id === 'number' ? (userToStore as any).id : 0,
            token: typeof (userToStore as any)?.token === 'string' ? (userToStore as any).token : '',
          };
          this.cartMerge.mergeIfAny(authBody)
            .catch((err) => console.warn('[Register] cart merge failed (non-fatal)', err));

          void this.pushManager.onSignedIn();

          this.router.navigate(['/account'], { replaceUrl: true });
          this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
        } else {
          this.error_notification(this.mapErrorMessage(response));
        }
      },
      error: (e) => this.handleHttpError(e),
    });
  }

  resend_email_otp() {
    // Re-issue the email OTP by re-calling submit with the same data.
    if (!this.isOnline) {
      this.error_notification(this.i18n.t('text_offline_check_connection'));
      return;
    }
    this.ui_controls.loading = true;
    this.networkAdapter.post_v3('POST /auth/register/submit', {
      registration_token: this.registrationToken,
      email: this.register.email,
      password: this.register.password,
      first_name: this.register.first_name,
      last_name: this.register.last_name,
    }).subscribe({
      next: (response: any) => {
        this.ui_controls.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          const vid = response.data?.verification_id;
          if (typeof vid === 'string' && vid.length > 0) {
            this.emailVerificationId = vid;
            this.otp.code = '';
          }
          this.success_notification(this.i18n.t('text_otp_sent'));
        } else {
          this.handleSubmitError(response);
        }
      },
      error: (e) => {
        const code = e?.error?.error?.code;
        if (code === 'AUTH_INVALID_TOKEN') {
          this.restartExpired();
          return;
        }
        this.handleHttpError(e);
      },
    });
  }

  back_to_details() {
    this.ui_controls.step = 3;
    this.otp.code = '';
  }

  // --- Error mapping ------------------------------------------------------

  /**
   * Map known v3 error codes to user-friendly i18n strings, falling
   * back to the server-provided message.
   */
  private mapErrorMessage(response: any): string {
    const code = response?.error_code;
    if (typeof code === 'string') {
      switch (code) {
        case 'CONFLICT_EMAIL_TAKEN':
          return this.i18n.t('text_email_already_registered');
        case 'CONFLICT_PHONE_TAKEN':
          return this.i18n.t('text_phone_already_registered');
        case 'OTP_VERIFICATION_FAILED':
          return this.i18n.t('text_otp_verification_failed');
        case 'OTP_RATE_LIMITED':
          return this.i18n.t('text_otp_rate_limited');
        case 'OTP_PROVIDER_ERROR':
          return this.i18n.t('text_otp_provider_error');
        case 'AUTH_INVALID_TOKEN':
          return this.i18n.t('text_session_expired_restart');
        default:
          break;
      }
    }
    return response?.message ?? this.i18n.t('text_request_failed');
  }

  /** Defensive rxjs-error-channel mapping (err?.error?.error?.code). */
  private handleHttpError(e: any) {
    this.ui_controls.loading = false;
    const code = e?.error?.error?.code;
    if (typeof code === 'string') {
      this.error_notification(this.mapErrorMessage({ error_code: code }));
      return;
    }
    console.error(e);
    this.error_notification(this.i18n.t('text_request_failed'));
  }

  error_notification(message: string) {
    this.toast.error(message, { position: 'top-center' });
  }

  success_notification(message: string) {
    this.toast.success(message, { position: 'top-center' });
  }

  sign_in() {
    this.router.navigate(['/', 'login']);
  }

  forgot_password() {
    this.router.navigate(['/', 'reset']);
  }
}
